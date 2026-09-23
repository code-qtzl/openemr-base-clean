<?php

/**
 * One document extraction under test: its type and the raw field map
 * `attach_and_extract` produced for it. See
 * .claude/skills/eval-schema-validator/SKILL.md.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

final readonly class ExtractedDocument
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(
        public SchemaDocType $docType,
        public array $fields,
    ) {
    }

    /**
     * Parses `{"doc_type": ..., "fields": {...}}` (from a golden-set
     * fixture, or an extraction result under review) into a typed value.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $rawDocType = $raw['doc_type'] ?? null;
        $docType = is_string($rawDocType) ? SchemaDocType::tryFrom($rawDocType) : null;
        if ($docType === null) {
            return null;
        }

        $fields = $raw['fields'] ?? null;
        if (!is_array($fields)) {
            return null;
        }

        /** @var array<string, mixed> $fields */
        return new self($docType, $fields);
    }
}
