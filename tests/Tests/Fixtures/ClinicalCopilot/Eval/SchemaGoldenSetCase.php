<?php

/**
 * One case in the schema-validity golden set: a stable id, a raw
 * `{"doc_type": ..., "fields": {...}}` shape (same untrusted input
 * SchemaValidator::validate consumes via ExtractedDocument::fromMixed),
 * and the expected `schema_valid` verdict. See
 * .claude/skills/eval-schema-validator/SKILL.md's "Golden Set Integration"
 * section for the case categories this fixture set covers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final readonly class SchemaGoldenSetCase
{
    /**
     * @param array<string, mixed> $document Raw {"doc_type": ..., "fields": {...}} shape.
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $document,
        public bool $expectedSchemaValid,
        public array $tags,
    ) {
    }
}
