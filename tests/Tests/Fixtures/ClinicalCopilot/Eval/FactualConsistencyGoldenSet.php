<?php

/**
 * Golden set for the `factually_consistent` eval
 * (.claude/skills/eval-factually-consistent-validator/SKILL.md).
 * Synthetic/demo cases only -- no real patient data -- covering every case
 * category the skill calls out: an exact numeric match, a
 * precision-formatting difference that must still pass, a genuine numeric
 * drift, a matching and a drifting non-numeric discrete value (date /
 * diagnosis code), a narrative claim skipped for having no digit, a claim
 * with no citation skipped rather than double-counted against
 * citation_present, and a batch where one failing checkable claim fails
 * the whole response even though the other claims in it pass or are
 * skipped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final class FactualConsistencyGoldenSet
{
    /**
     * @return list<FactualConsistencyGoldenSetCase>
     */
    public static function cases(): array
    {
        return [
            new FactualConsistencyGoldenSetCase(
                id: 'exact-numeric-match',
                description: 'Asserted A1C value matches the cited lab value exactly.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.2%.",
                        'asserted_value' => '7.2%',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: true,
                tags: ['positive', 'numeric'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'precision-formatting-difference-passes',
                description: 'Asserted value differs only in trailing-zero precision from the cited value.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.20%.",
                        'asserted_value' => '7.20%',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: true,
                tags: ['positive', 'numeric', 'precision_boundary'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'numeric-drift-fails',
                description: 'Asserted A1C value drifted away from the cited lab value.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.9%.",
                        'asserted_value' => '7.9%',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: false,
                tags: ['negative', 'numeric', 'drift'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'exact-date-match-passes',
                description: 'Asserted collection date matches the cited date exactly.',
                claims: [
                    [
                        'claim' => 'The sample was collected on 2026-01-15.',
                        'asserted_value' => '2026-01-15',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'collection_date',
                            'quote_or_value' => '2026-01-15',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: true,
                tags: ['positive', 'discrete_non_numeric'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'diagnosis-code-drift-fails',
                description: 'Asserted diagnosis code does not match the cited code.',
                claims: [
                    [
                        'claim' => 'The chart lists diagnosis E11.0.',
                        'asserted_value' => 'E11.0',
                        'citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-1',
                            'page_or_section' => 'diagnoses',
                            'field_or_chunk_id' => 'primary_diagnosis',
                            'quote_or_value' => 'E11.9',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: false,
                tags: ['negative', 'discrete_non_numeric', 'drift'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'narrative-claim-skipped',
                description: 'A narrative allergy claim has no digit in its citation and is skipped, not checked.',
                claims: [
                    [
                        'claim' => 'The patient reports a known penicillin allergy.',
                        'asserted_value' => 'Penicillin allergy noted',
                        'citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-1',
                            'page_or_section' => 'allergies',
                            'field_or_chunk_id' => 'allergy_1',
                            'quote_or_value' => 'Penicillin - rash',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: true,
                tags: ['positive', 'narrative_skipped'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'missing-citation-skipped',
                description: 'A claim with no citation at all is skipped here -- citation_present owns that failure.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.2%.",
                        'asserted_value' => '7.2%',
                    ],
                ],
                expectedFactuallyConsistent: true,
                tags: ['positive', 'missing_citation_skipped'],
            ),
            new FactualConsistencyGoldenSetCase(
                id: 'batch-one-drifting-claim-fails-whole-response',
                description: 'One genuinely drifting numeric claim fails the batch even though the other claims pass or are skipped.',
                claims: [
                    [
                        'claim' => "The patient's A1C is 7.2%.",
                        'asserted_value' => '7.2%',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'a1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                    [
                        'claim' => 'The patient reports a known penicillin allergy.',
                        'asserted_value' => 'Penicillin allergy noted',
                        'citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-1',
                            'page_or_section' => 'allergies',
                            'field_or_chunk_id' => 'allergy_1',
                            'quote_or_value' => 'Penicillin - rash',
                        ],
                    ],
                    [
                        'claim' => 'The most recent LDL was 90 mg/dL.',
                        'asserted_value' => '90 mg/dL',
                        'citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-003',
                            'page_or_section' => 'page_2',
                            'field_or_chunk_id' => 'ldl',
                            'quote_or_value' => '130 mg/dL',
                        ],
                    ],
                ],
                expectedFactuallyConsistent: false,
                tags: ['negative', 'batch'],
            ),
        ];
    }
}
