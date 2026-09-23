<?php

/**
 * Deterministic PHI-in-telemetry check for the `no_phi_in_logs` eval
 * category (.claude/skills/eval-phi-log-guard/SKILL.md). A log emission
 * passes automatically when it targets the authenticated
 * `application_response` -- the citation contract's `quote_or_value` is
 * required there. Everything else (Langfuse traces, PHP error logs, audit
 * rows, or any other sink) is checked two ways: field names that guarantee
 * raw content regardless of value (extracted text, image bytes, direct
 * identifiers, citation quote content), and value patterns that catch PHI
 * embedded under a generic field name (an SSN shape, a labeled DOB/MRN, a
 * "Patient <Name> <Name>" fragment, a base64 image blob). This is pure
 * structural/pattern validation: it never repairs a flagged payload, only
 * reports it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard;

final class PhiLogGuardValidator
{
    /**
     * The one destination the citation contract's raw content (including
     * `quote_or_value`) is required in. Everything else is telemetry and
     * gets scanned.
     */
    private const EXEMPT_DESTINATION = 'application_response';

    /**
     * Field names that guarantee raw document text regardless of value.
     *
     * @var array<string, string>
     */
    private const RAW_TEXT_FIELDS = [
        'extracted_text' => 'raw extracted document text',
        'raw_text' => 'raw extracted document text',
        'ocr_text' => 'raw extracted document text',
        'document_text' => 'raw extracted document text',
        'full_text' => 'raw extracted document text',
    ];

    /**
     * Field names that guarantee a direct patient identifier regardless of
     * value.
     *
     * @var array<string, string>
     */
    private const IDENTIFIER_FIELDS = [
        'patient_name' => 'direct patient identifier',
        'name' => 'direct patient identifier',
        'first_name' => 'direct patient identifier',
        'last_name' => 'direct patient identifier',
        'dob' => 'direct patient identifier',
        'date_of_birth' => 'direct patient identifier',
        'ssn' => 'direct patient identifier',
        'mrn' => 'direct patient identifier',
        'address' => 'direct patient identifier',
        'phone' => 'direct patient identifier',
        'email' => 'direct patient identifier',
    ];

    /**
     * Field names that guarantee document/screenshot bytes regardless of
     * value.
     *
     * @var array<string, string>
     */
    private const IMAGE_FIELDS = [
        'image' => 'document/screenshot bytes',
        'image_data' => 'document/screenshot bytes',
        'image_base64' => 'document/screenshot bytes',
        'screenshot' => 'document/screenshot bytes',
        'file_bytes' => 'document/screenshot bytes',
        'document_bytes' => 'document/screenshot bytes',
    ];

    /**
     * The one citation-contract field that belongs only in
     * `application_response`, never in telemetry.
     *
     * @var array<string, string>
     */
    private const CITATION_CONTENT_FIELDS = [
        'quote_or_value' => 'citation quote/value content sent to telemetry instead of the clinician-facing response',
    ];

    public static function scan(LogEmission $emission): PhiScanResult
    {
        if ($emission->logTarget === self::EXEMPT_DESTINATION) {
            return new PhiScanResult(true, []);
        }

        $denylist = self::RAW_TEXT_FIELDS
            + self::IDENTIFIER_FIELDS
            + self::IMAGE_FIELDS
            + self::CITATION_CONTENT_FIELDS;

        $findings = [];
        foreach ($emission->payload as $field => $value) {
            $reason = $denylist[strtolower($field)] ?? null;

            if ($reason === null && is_string($value)) {
                $reason = self::matchValuePattern($value);
            }

            if ($reason !== null) {
                $findings[] = new PhiFinding($field, $emission->logTarget, $reason);
            }
        }

        return new PhiScanResult($findings === [], $findings);
    }

    /**
     * Catches PHI embedded in a generically-named field (e.g. a `message`
     * or `log_line` key) that the field-name denylist above cannot see,
     * because that denylist only looks at field names, not free text.
     */
    private static function matchValuePattern(string $value): ?string
    {
        if (preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $value) === 1) {
            return 'value matches an SSN-shaped pattern';
        }

        if (preg_match('/\bDOB[:\s]+\d{4}-\d{2}-\d{2}\b/i', $value) === 1) {
            return 'value contains a labeled date of birth';
        }

        if (preg_match('/\bMRN[:\s]+\S+/i', $value) === 1) {
            return 'value contains a labeled MRN';
        }

        if (preg_match('/\bPatient\s+[A-Z][a-z]+\s+[A-Z][a-z]+\b/', $value) === 1) {
            return 'value contains a "Patient <Name>" pattern';
        }

        if (str_starts_with($value, 'data:image/')) {
            return 'value is an embedded image data URI';
        }

        if (strlen($value) > 200 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value) === 1) {
            return 'value looks like a base64-encoded binary blob';
        }

        return null;
    }
}
