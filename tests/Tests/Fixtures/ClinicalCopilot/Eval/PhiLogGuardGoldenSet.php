<?php

/**
 * Golden set for the `no_phi_in_logs` eval
 * (.claude/skills/eval-phi-log-guard/SKILL.md). Synthetic/demo cases only --
 * no real patient data -- covering every case category the skill calls out:
 * a correlation-id-only trace, raw extracted document text, a direct
 * patient identifier, document/screenshot bytes, an exception message
 * embedding patient data, the application-response negative control (a
 * full citation object including `quote_or_value` is fine there), and
 * redacted/aliased identifiers only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final class PhiLogGuardGoldenSet
{
    /**
     * @return list<PhiLogGuardGoldenSetCase>
     */
    public static function cases(): array
    {
        return [
            new PhiLogGuardGoldenSetCase(
                id: 'correlation-id-only-trace',
                description: 'A Langfuse trace carrying only opaque identifiers and metrics.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-123',
                        'tool' => 'attach_and_extract',
                        'doc_type' => 'lab_pdf',
                        'extraction_confidence' => 0.92,
                        'fields_extracted' => ['test_name', 'value', 'unit'],
                    ],
                ],
                expectedNoPhiInLogs: true,
                tags: ['positive', 'telemetry'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'raw-extracted-text',
                description: 'A trace field dumping the full extracted document text, including identifiers.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-123',
                        'extracted_text' => "Patient Jane Doe, DOB 1958-02-03, A1C 7.2%, MRN 00219...",
                    ],
                ],
                expectedNoPhiInLogs: false,
                tags: ['negative', 'raw_text'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'direct-identifier-field',
                description: 'A trace field named for a direct patient identifier.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-124',
                        'patient_name' => 'Jane Doe',
                        'dob' => '1958-02-03',
                    ],
                ],
                expectedNoPhiInLogs: false,
                tags: ['negative', 'identifier'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'screenshot-bytes',
                description: 'A trace embedding the intake-form scan as an image data URI.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-125',
                        'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB',
                    ],
                ],
                expectedNoPhiInLogs: false,
                tags: ['negative', 'image'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'exception-message-with-phi',
                description: 'A PHP error log line interpolating an exception message that embeds patient data.',
                emission: [
                    'log_target' => 'php_error_log',
                    'payload' => [
                        'correlation_id' => 'c-126',
                        'message' => 'Extraction failed for Patient Jane Doe: timeout after 30s',
                    ],
                ],
                expectedNoPhiInLogs: false,
                tags: ['negative', 'exception_message'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'application-response-citation-boundary',
                description: 'A full citation object, including quote_or_value, in the clinician-facing response -- not telemetry.',
                emission: [
                    'log_target' => 'application_response',
                    'payload' => [
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
                expectedNoPhiInLogs: true,
                tags: ['positive', 'boundary', 'application_response'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'redacted-identifiers-only',
                description: 'A trace referencing the patient and session only by opaque identifiers.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-127',
                        'session_uuid' => '9c9e7b1a-2f3d-4e2a-8f0a-1234567890ab',
                        'pid' => 4821,
                    ],
                ],
                expectedNoPhiInLogs: true,
                tags: ['positive', 'redacted'],
            ),
            new PhiLogGuardGoldenSetCase(
                id: 'ssn-shaped-value-in-generic-field',
                description: 'A trace field with a generic name (not on the identifier denylist) whose value matches an SSN-shaped pattern.',
                emission: [
                    'log_target' => 'langfuse_trace',
                    'payload' => [
                        'correlation_id' => 'c-128',
                        'note' => 'Verified against 123-45-6789 on file.',
                    ],
                ],
                expectedNoPhiInLogs: false,
                tags: ['negative', 'value_pattern', 'ssn'],
            ),
        ];
    }
}
