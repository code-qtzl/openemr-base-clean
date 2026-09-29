<?php

/**
 * Isolated ResponseVerifier Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Verification;

use OpenEMR\Modules\ClinicalCopilot\Service\Verification\ResponseVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Citation/Citation.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Citation/CitationBoundingBox.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/VerificationClaim.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/VerificationOutcome.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Verification/ResponseVerifier.php';

class ResponseVerifierTest extends TestCase
{
    public function testHonestInsufficientInformationPasses(): void
    {
        // PUNCH_LIST.md 2.1's boundary case: an empty chart must produce an
        // honest "I don't know", not a rejection -- this is the model doing
        // the right thing, not a violation to flag.
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => true, 'summary' => 'The chart has no recorded A1c results.'],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 0],
        );

        self::assertTrue($outcome->passed);
        self::assertSame('The chart has no recorded A1c results.', $outcome->reply);
    }

    public function testInsufficientInformationWithoutSummaryUsesFallback(): void
    {
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => true, 'summary' => ''],
            calledTools: [],
            toolRowCounts: [],
        );

        self::assertTrue($outcome->passed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $outcome->reply);
    }

    public function testFullyCitedChartToolClaimPasses(): void
    {
        // chart_tool citations are the documented divergence from
        // CitationValidator's blanket rubric: no page_or_section is supplied
        // here (a database row has no page), and the claim still passes.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'summary' => 'Based on the chart:',
                'claims' => [
                    [
                        'text' => 'A1c was 7.2% on 2026-01-10.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_a1c_series',
                            'field_or_chunk_id' => 'value',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertTrue($outcome->passed);
        self::assertStringContainsString('A1c was 7.2%', $outcome->reply);
        self::assertStringContainsString('Based on the chart:', $outcome->reply);
    }

    public function testUncitedClaimIsRejected(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient is improving.', 'citation' => null],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
        self::assertSame(ResponseVerifier::FALLBACK_REPLY, $outcome->reply);
        self::assertNotNull($outcome->reason);
    }

    public function testClaimCitingAToolNotCalledThisTurnIsRejected(): void
    {
        // Guards against a stale/hallucinated citation to a tool the model
        // never actually invoked in this turn's transcript.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'The patient has type 2 diabetes.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_active_problems',
                            'field_or_chunk_id' => 'problem',
                            'quote_or_value' => 'Type 2 diabetes mellitus',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
    }

    public function testClaimCitingAWorkerNameInsteadOfAToolIsRejected(): void
    {
        // CITATION GRANULARITY: consult_chart_worker/consult_document_worker/
        // consult_evidence_worker are dispatch/handoff concepts, never valid
        // citations -- a claim citing the worker's own name instead of the
        // specific tool it called must be rejected the same as citing any
        // other tool never actually invoked.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'The patient has type 2 diabetes.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'consult_chart_worker',
                            'field_or_chunk_id' => 'problem',
                            'quote_or_value' => 'Type 2 diabetes mellitus',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_active_problems'],
            toolRowCounts: ['get_active_problems' => 1],
        );

        self::assertFalse($outcome->passed);
    }

    public function testNoClaimsWithoutInsufficientInformationFlagIsRejected(): void
    {
        // The model must not silently produce a claim-free "definitive"
        // answer without either citing something or admitting it can't.
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => false, 'claims' => []],
            calledTools: [],
            toolRowCounts: [],
        );

        self::assertFalse($outcome->passed);
    }

    public function testMissingSubmitAnswerCallIsRejected(): void
    {
        // CopilotService passes [] when the forced tool_choice turn produced
        // no submit_answer block at all (e.g. a refusal) -- must fail closed.
        $outcome = ResponseVerifier::verify([], calledTools: [], toolRowCounts: []);

        self::assertFalse($outcome->passed);
    }

    public function testMalformedClaimIsRejected(): void
    {
        $outcome = ResponseVerifier::verify(
            ['insufficient_information' => false, 'claims' => ['not-an-object']],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
    }

    public function testCitationMissingRequiredFieldIsRejected(): void
    {
        // field_or_chunk_id is always required, even for chart_tool claims
        // that are exempt from page_or_section.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'A1c was 7.2% on 2026-01-10.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_a1c_series',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_a1c_series'],
            toolRowCounts: ['get_a1c_series' => 3],
        );

        self::assertFalse($outcome->passed);
    }

    public function testGuidelineCitationMissingPageOrSectionIsRejected(): void
    {
        // Unlike chart_tool, a guideline citation DOES need page_or_section
        // -- a drug label section is a real, meaningful location.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'Metformin is contraindicated in severe renal impairment.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'search_guideline_evidence',
                            'field_or_chunk_id' => 'chunk-1',
                            'quote_or_value' => 'Contraindicated in patients with severe renal impairment.',
                        ],
                    ],
                ],
            ],
            calledTools: ['search_guideline_evidence'],
            toolRowCounts: ['search_guideline_evidence' => 1],
        );

        self::assertFalse($outcome->passed);
    }

    public function testLabPdfCitationMissingBboxIsRejected(): void
    {
        // lab_pdf/intake_form citations need document_id + bbox for the
        // click-to-source PDF overlay -- unlike chart_tool/guideline, which
        // have no PDF page to point at.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => "The patient's A1C is 7.2%.",
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'get_extracted_documents',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                            'document_id' => '4242',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_extracted_documents'],
            toolRowCounts: ['get_extracted_documents' => 1],
        );

        self::assertFalse($outcome->passed);
        self::assertSame([], $outcome->claims);
    }

    public function testFullyCitedLabPdfClaimWithDocumentLinkagePasses(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => "The patient's A1C is 7.2%.",
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'get_extracted_documents',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                            'document_id' => '4242',
                            'bbox' => ['page' => 0, 'x0' => 0.12, 'y0' => 0.30, 'x1' => 0.44, 'y1' => 0.35],
                        ],
                    ],
                ],
            ],
            calledTools: ['get_extracted_documents'],
            toolRowCounts: ['get_extracted_documents' => 1],
        );

        self::assertTrue($outcome->passed);
        self::assertCount(1, $outcome->claims);
        $citation = $outcome->claims[0]->citation;
        self::assertNotNull($citation);
        self::assertSame('4242', $citation->documentId);
        self::assertNotNull($citation->bbox);
        self::assertSame(0, $citation->bbox->page);
    }

    public function testRejectedOutcomeNeverCarriesClaims(): void
    {
        // Nothing partial ever reaches the browser -- a rejected outcome's
        // claims must always be empty, even when the model supplied
        // well-formed-looking ones.
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    ['text' => 'The patient is improving.', 'citation' => null],
                ],
            ],
            calledTools: [],
            toolRowCounts: [],
        );

        self::assertFalse($outcome->passed);
        self::assertSame([], $outcome->claims);
    }

    public function testFullyCitedGuidelineClaimPasses(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'Metformin is contraindicated in severe renal impairment.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'search_guideline_evidence',
                            'page_or_section' => 'contraindications',
                            'field_or_chunk_id' => 'chunk-1',
                            'quote_or_value' => 'Contraindicated in patients with severe renal impairment.',
                        ],
                    ],
                ],
            ],
            calledTools: ['search_guideline_evidence'],
            toolRowCounts: ['search_guideline_evidence' => 1],
        );

        self::assertTrue($outcome->passed);
    }

    /**
     * PUNCH_LIST.md 1.4(b): a patient with zero medication rows cannot
     * produce a "patient is on X" claim that passes verification, no matter
     * how the model phrases it or how confidently.
     */
    public function testMedicationClaimWithZeroRowsIsRejectedRegardlessOfWording(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'The patient is currently taking Metformin.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_medications',
                            'field_or_chunk_id' => 'drug',
                            'quote_or_value' => 'Metformin',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_medications'],
            toolRowCounts: ['get_medications' => 0],
        );

        self::assertFalse($outcome->passed);
        self::assertStringContainsString('get_medications', (string) $outcome->reason);
    }

    public function testMedicationClaimWithRowsPasses(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'The patient takes Metformin 500mg BID.',
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_medications',
                            'field_or_chunk_id' => 'drug',
                            'quote_or_value' => 'Metformin 500mg BID',
                        ],
                    ],
                ],
            ],
            calledTools: ['get_medications'],
            toolRowCounts: ['get_medications' => 1],
        );

        self::assertTrue($outcome->passed);
    }

    /**
     * Same failure mode as the medication guard, for the guideline worker: a
     * claim quoting guideline text when retrieval returned zero chunks is a
     * fabricated citation regardless of wording.
     */
    public function testGuidelineClaimWithZeroChunksIsRejectedRegardlessOfWording(): void
    {
        $outcome = ResponseVerifier::verify(
            [
                'insufficient_information' => false,
                'claims' => [
                    [
                        'text' => 'Per the label, this drug has no known interactions.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'search_guideline_evidence',
                            'page_or_section' => 'drug_interactions',
                            'field_or_chunk_id' => 'chunk-1',
                            'quote_or_value' => 'No known interactions.',
                        ],
                    ],
                ],
            ],
            calledTools: ['search_guideline_evidence'],
            toolRowCounts: ['search_guideline_evidence' => 0],
        );

        self::assertFalse($outcome->passed);
        self::assertStringContainsString('search_guideline_evidence', (string) $outcome->reason);
    }
}
