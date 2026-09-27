<?php

/**
 * Golden set for the `citation_present` eval
 * (.claude/skills/eval-citation-validator/SKILL.md). Synthetic/demo cases
 * only -- no real patient data -- covering every case category the skill
 * calls out: valid citations from different source types, a missing
 * citation, a partially-filled citation, a citation whose fields are present
 * but empty, a multi-claim response where only one claim lacks grounding,
 * an evidence-retrieval response, and an unsupported clinical claim.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final class CitationGoldenSet
{
    /**
     * @return list<CitationGoldenSetCase>
     */
    public static function cases(): array
    {
        return [
            new CitationGoldenSetCase(
                id: 'valid-lab-pdf',
                description: 'A lab-value claim grounded in a scanned lab PDF.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.2%.",
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
                expectedCitationPresent: true,
                tags: ['positive', 'lab_pdf'],
            ),
            new CitationGoldenSetCase(
                id: 'valid-intake-form',
                description: 'A history claim grounded in a structured intake-form field.',
                claims: [
                    [
                        'claim' => 'The patient reports a known penicillin allergy.',
                        'citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-2026-01-05',
                            'page_or_section' => 'allergies',
                            'field_or_chunk_id' => 'allergy_1',
                            'quote_or_value' => 'Penicillin - rash',
                        ],
                    ],
                ],
                expectedCitationPresent: true,
                tags: ['positive', 'intake_form'],
            ),
            new CitationGoldenSetCase(
                id: 'missing-citation',
                description: 'A claim with no citation object at all.',
                claims: [
                    [
                        'claim' => 'The patient is improving.',
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'missing'],
            ),
            new CitationGoldenSetCase(
                id: 'partial-citation',
                description: 'A citation missing several required fields.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.2%.",
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                        ],
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'partial'],
            ),
            new CitationGoldenSetCase(
                id: 'empty-citation-fields',
                description: 'A citation with all required keys present but blank.',
                claims: [
                    [
                        'claim' => 'The patient has a documented penicillin allergy.',
                        'citation' => [
                            'source_type' => '',
                            'source_id' => '',
                            'page_or_section' => '',
                            'field_or_chunk_id' => '',
                            'quote_or_value' => '   ',
                        ],
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'empty'],
            ),
            new CitationGoldenSetCase(
                id: 'multi-claim-one-missing',
                description: 'Two claims where only the second lacks a citation.',
                claims: [
                    [
                        'claim' => "The patient's most recent A1C was 6.4% on 2026-01-15.",
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-002',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '6.4%',
                        ],
                    ],
                    [
                        'claim' => 'The patient is at low risk for complications.',
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'multi_claim'],
            ),
            new CitationGoldenSetCase(
                id: 'evidence-retrieval-with-source',
                description: 'A retrieval-tool response citing a vector-store chunk.',
                claims: [
                    [
                        'claim' => 'The care plan notes a follow-up in 3 months.',
                        'citation' => [
                            'source_type' => 'vector_chunk',
                            'source_id' => 'note-2026-02-10',
                            'page_or_section' => 'plan',
                            'field_or_chunk_id' => 'chunk-42',
                            'quote_or_value' => 'Follow up with endocrinology in 3 months.',
                        ],
                    ],
                ],
                expectedCitationPresent: true,
                tags: ['positive', 'evidence_retrieval'],
            ),
            new CitationGoldenSetCase(
                id: 'unsupported-claim',
                description: 'A clinical claim asserted without any supporting evidence.',
                claims: [
                    [
                        'claim' => 'The patient will respond well to this medication.',
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'unsupported'],
            ),
            new CitationGoldenSetCase(
                id: 'valid-guideline-evidence',
                description: 'A medication-safety claim grounded in EvidenceRetrieverWorker\'s hybrid RAG over the guideline corpus.',
                claims: [
                    [
                        'claim' => 'Metformin is contraindicated in patients with severe renal impairment.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'metformin-hcl-label',
                            'page_or_section' => 'contraindications',
                            'field_or_chunk_id' => 'chunk-3',
                            'quote_or_value' => 'Metformin is contraindicated in patients with severe renal impairment.',
                        ],
                    ],
                ],
                expectedCitationPresent: true,
                tags: ['positive', 'guideline'],
            ),
            new CitationGoldenSetCase(
                id: 'guideline-citation-missing-quote',
                description: 'A guideline-sourced citation with every field present except the grounding quote.',
                claims: [
                    [
                        'claim' => 'Metformin is contraindicated in patients with severe renal impairment.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'metformin-hcl-label',
                            'page_or_section' => 'contraindications',
                            'field_or_chunk_id' => 'chunk-3',
                            'quote_or_value' => '',
                        ],
                    ],
                ],
                expectedCitationPresent: false,
                tags: ['negative', 'guideline', 'partial'],
            ),
            new CitationGoldenSetCase(
                id: 'mixed-source-types-all-cited',
                description: 'A response combining a chart-data claim and a guideline-evidence claim, both fully grounded.',
                claims: [
                    [
                        'claim' => "The patient's most recent A1C was 6.4% on 2026-01-15.",
                        'citation' => [
                            'source_type' => 'chart_tool',
                            'source_id' => 'get_a1c_series',
                            'page_or_section' => 'n/a',
                            'field_or_chunk_id' => 'value',
                            'quote_or_value' => '6.4%',
                        ],
                    ],
                    [
                        'claim' => 'Given her renal history, metformin would be contraindicated if adjusted.',
                        'citation' => [
                            'source_type' => 'guideline',
                            'source_id' => 'metformin-hcl-label',
                            'page_or_section' => 'contraindications',
                            'field_or_chunk_id' => 'chunk-3',
                            'quote_or_value' => 'Metformin is contraindicated in patients with severe renal impairment.',
                        ],
                    ],
                ],
                expectedCitationPresent: true,
                tags: ['positive', 'guideline', 'multi_claim'],
            ),
        ];
    }
}
