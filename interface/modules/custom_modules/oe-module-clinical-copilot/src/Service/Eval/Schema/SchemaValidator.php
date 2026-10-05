<?php

/**
 * Deterministic required-field check for the `schema_valid` eval category
 * (.claude/skills/eval-schema-validator/SKILL.md). Field presence and shape
 * only -- never clinical correctness (`factually_consistent`'s job) and
 * never citation completeness (`citation_present`'s job; this validator
 * only checks that a `source_citation` object is present, not that its own
 * sub-fields are complete).
 *
 * Required fields are not all judged the same way: list fields
 * (current_medications, allergies) accept an empty array as valid content
 * ("no known allergies"), while object fields (demographics,
 * source_citation) require a non-empty array. Getting that distinction
 * backwards is the most likely way to misimplement this eval -- see
 * SKILL.md's "Field Shape Rules".
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

final class SchemaValidator
{
    /**
     * @var array<string, SchemaFieldKind>
     */
    private const LAB_FIELDS = [
        'test_name' => SchemaFieldKind::Scalar,
        'value' => SchemaFieldKind::Scalar,
        'unit' => SchemaFieldKind::Scalar,
        'reference_range' => SchemaFieldKind::Scalar,
        'collection_date' => SchemaFieldKind::Scalar,
        'abnormal_flag' => SchemaFieldKind::PresentScalar,
        'source_citation' => SchemaFieldKind::ObjectField,
    ];

    /**
     * @var array<string, SchemaFieldKind>
     */
    private const INTAKE_FIELDS = [
        'demographics' => SchemaFieldKind::ObjectField,
        'chief_concern' => SchemaFieldKind::Scalar,
        'current_medications' => SchemaFieldKind::ListField,
        'allergies' => SchemaFieldKind::ListField,
        'family_history' => SchemaFieldKind::Scalar,
        'source_citation' => SchemaFieldKind::ObjectField,
    ];

    public static function validate(ExtractedDocument $document): SchemaValidationResult
    {
        $requiredFields = self::requiredFields($document->docType);

        $findings = [];
        foreach ($requiredFields as $field => $kind) {
            $reason = self::checkField($document->fields, $field, $kind);
            if ($reason !== null) {
                $findings[] = new SchemaFinding($field, $reason);
            }
        }

        return new SchemaValidationResult($findings === [], $findings);
    }

    /**
     * The required field names for a document type, in the same order
     * `validate()` checks them -- the single source of truth
     * `ExtractionPromptBuilder` reads from rather than hand-duplicating
     * this list, so the extraction prompt can never silently drift from
     * what this validator enforces.
     *
     * @return list<string>
     */
    public static function requiredFieldNames(SchemaDocType $docType): array
    {
        return array_keys(self::requiredFields($docType));
    }

    /**
     * @return array<string, SchemaFieldKind>
     */
    private static function requiredFields(SchemaDocType $docType): array
    {
        return match ($docType) {
            SchemaDocType::LabPdf => self::LAB_FIELDS,
            SchemaDocType::IntakeForm => self::INTAKE_FIELDS,
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function checkField(array $fields, string $field, SchemaFieldKind $kind): ?string
    {
        if (!array_key_exists($field, $fields) || $fields[$field] === null) {
            return 'missing';
        }

        $value = $fields[$field];

        return match ($kind) {
            SchemaFieldKind::Scalar => self::checkScalar($value),
            SchemaFieldKind::PresentScalar => null,
            SchemaFieldKind::ListField => is_array($value) ? null : 'wrong_shape',
            SchemaFieldKind::ObjectField => self::checkObject($value),
        };
    }

    private static function checkScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? 'empty' : null;
        }

        return null;
    }

    private static function checkObject(mixed $value): ?string
    {
        if (!is_array($value)) {
            return 'wrong_shape';
        }

        return $value === [] ? 'empty' : null;
    }
}
