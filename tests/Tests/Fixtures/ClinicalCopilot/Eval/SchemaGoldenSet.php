<?php

/**
 * Golden set for the `schema_valid` eval
 * (.claude/skills/eval-schema-validator/SKILL.md). Synthetic/demo cases
 * only -- no real patient data -- covering every case category the skill
 * calls out: fully populated lab_pdf and intake_form extractions, a lab_pdf
 * missing several fields, an intake_form missing only source_citation, the
 * empty-list negative control (current_medications/allergies), an empty
 * demographics object, non-string scalar values, and a structurally empty
 * source_citation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final class SchemaGoldenSet
{
    /**
     * @return list<SchemaGoldenSetCase>
     */
    public static function cases(): array
    {
        return [
            new SchemaGoldenSetCase(
                id: 'fully-populated-lab-pdf',
                description: 'A lab_pdf extraction with every required field present.',
                document: [
                    'doc_type' => 'lab_pdf',
                    'fields' => [
                        'test_name' => 'HbA1c',
                        'value' => '7.2',
                        'unit' => '%',
                        'reference_range' => '4.0-5.6',
                        'collection_date' => '2026-01-15',
                        'abnormal_flag' => true,
                        'source_citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-001',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'hba1c',
                            'quote_or_value' => '7.2%',
                        ],
                    ],
                ],
                expectedSchemaValid: true,
                tags: ['positive', 'lab_pdf'],
            ),
            new SchemaGoldenSetCase(
                id: 'fully-populated-intake-form',
                description: 'An intake_form extraction with every required field present.',
                document: [
                    'doc_type' => 'intake_form',
                    'fields' => [
                        'demographics' => ['age' => 54, 'sex' => 'F'],
                        'chief_concern' => 'Follow-up for elevated A1C.',
                        'current_medications' => ['metformin 500mg'],
                        'allergies' => ['penicillin'],
                        'family_history' => 'Mother: type 2 diabetes.',
                        'source_citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-2026-01-05',
                            'page_or_section' => 'history',
                            'field_or_chunk_id' => 'family_history',
                            'quote_or_value' => 'Mother: type 2 diabetes.',
                        ],
                    ],
                ],
                expectedSchemaValid: true,
                tags: ['positive', 'intake_form'],
            ),
            new SchemaGoldenSetCase(
                id: 'lab-pdf-missing-several-fields',
                description: 'A lab_pdf extraction with only test_name and value present.',
                document: [
                    'doc_type' => 'lab_pdf',
                    'fields' => [
                        'test_name' => 'HbA1c',
                        'value' => '7.2',
                    ],
                ],
                expectedSchemaValid: false,
                tags: ['negative', 'lab_pdf', 'missing'],
            ),
            new SchemaGoldenSetCase(
                id: 'intake-form-missing-source-citation-only',
                description: 'An intake_form extraction with every clinical field present but no source_citation.',
                document: [
                    'doc_type' => 'intake_form',
                    'fields' => [
                        'demographics' => ['age' => 54, 'sex' => 'F'],
                        'chief_concern' => 'Follow-up for elevated A1C.',
                        'current_medications' => [],
                        'allergies' => [],
                        'family_history' => 'Mother: type 2 diabetes.',
                    ],
                ],
                expectedSchemaValid: false,
                tags: ['negative', 'intake_form', 'missing'],
            ),
            new SchemaGoldenSetCase(
                id: 'empty-medication-and-allergy-lists-are-valid',
                description: 'An intake_form with genuinely empty current_medications/allergies lists -- healthy-patient content, not missing fields.',
                document: [
                    'doc_type' => 'intake_form',
                    'fields' => [
                        'demographics' => ['age' => 29, 'sex' => 'M'],
                        'chief_concern' => 'Annual physical.',
                        'current_medications' => [],
                        'allergies' => [],
                        'family_history' => 'Noncontributory.',
                        'source_citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-2026-02-01',
                            'page_or_section' => 'allergies',
                            'field_or_chunk_id' => 'allergies',
                            'quote_or_value' => 'NKDA',
                        ],
                    ],
                ],
                expectedSchemaValid: true,
                tags: ['positive', 'intake_form', 'empty_list_boundary'],
            ),
            new SchemaGoldenSetCase(
                id: 'empty-demographics-object-fails',
                description: 'An intake_form whose demographics object is present but empty.',
                document: [
                    'doc_type' => 'intake_form',
                    'fields' => [
                        'demographics' => [],
                        'chief_concern' => 'Annual physical.',
                        'current_medications' => [],
                        'allergies' => [],
                        'family_history' => 'Noncontributory.',
                        'source_citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-2026-02-01',
                            'page_or_section' => 'demographics',
                            'field_or_chunk_id' => 'demographics',
                            'quote_or_value' => '29 y/o male',
                        ],
                    ],
                ],
                expectedSchemaValid: false,
                tags: ['negative', 'intake_form', 'empty_object'],
            ),
            new SchemaGoldenSetCase(
                id: 'non-string-scalars-are-valid',
                description: 'A lab_pdf with a numeric value and boolean abnormal_flag rather than strings.',
                document: [
                    'doc_type' => 'lab_pdf',
                    'fields' => [
                        'test_name' => 'HbA1c',
                        'value' => 7.2,
                        'unit' => '%',
                        'reference_range' => '4.0-5.6',
                        'collection_date' => '2026-01-15',
                        'abnormal_flag' => false,
                        'source_citation' => [
                            'source_type' => 'lab_pdf',
                            'source_id' => 'lab-002',
                            'page_or_section' => 'page_1',
                            'field_or_chunk_id' => 'hba1c',
                            'quote_or_value' => '7.2',
                        ],
                    ],
                ],
                expectedSchemaValid: true,
                tags: ['positive', 'lab_pdf', 'non_string_scalar'],
            ),
            new SchemaGoldenSetCase(
                id: 'structurally-empty-source-citation-fails',
                description: 'A lab_pdf whose source_citation key is present but an empty object.',
                document: [
                    'doc_type' => 'lab_pdf',
                    'fields' => [
                        'test_name' => 'HbA1c',
                        'value' => '7.2',
                        'unit' => '%',
                        'reference_range' => '4.0-5.6',
                        'collection_date' => '2026-01-15',
                        'abnormal_flag' => true,
                        'source_citation' => [],
                    ],
                ],
                expectedSchemaValid: false,
                tags: ['negative', 'lab_pdf', 'empty_object'],
            ),
            new SchemaGoldenSetCase(
                id: 'intake-form-list-field-wrong-shape-fails',
                description: 'An intake_form where current_medications is a string instead of a list.',
                document: [
                    'doc_type' => 'intake_form',
                    'fields' => [
                        'demographics' => ['age' => 61, 'sex' => 'M'],
                        'chief_concern' => 'Medication review.',
                        'current_medications' => 'metformin 500mg twice daily',
                        'allergies' => [],
                        'family_history' => 'Father: hypertension.',
                        'source_citation' => [
                            'source_type' => 'intake_form',
                            'source_id' => 'intake-2026-03-01',
                            'page_or_section' => 'medications',
                            'field_or_chunk_id' => 'current_medications',
                            'quote_or_value' => 'metformin 500mg twice daily',
                        ],
                    ],
                ],
                expectedSchemaValid: false,
                tags: ['negative', 'intake_form', 'wrong_shape'],
            ),
        ];
    }
}
