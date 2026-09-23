<?php

/**
 * One case in the safe-refusal golden set: a stable id, a raw
 * `{"field": ..., "case_type": ..., "claims": [...]}` shape (same
 * untrusted input SafeRefusalValidator::evaluate consumes via
 * SafeRefusalCase::fromMixed), and the expected `safe_refusal` verdict.
 * See .claude/skills/eval-safe-refusal-validator/SKILL.md's "Golden Set
 * Integration" section for the case categories this fixture set covers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final readonly class SafeRefusalGoldenSetCase
{
    /**
     * @param array<string, mixed> $case Raw {"field": ..., "case_type": ..., "claims": [...]} shape.
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $case,
        public bool $expectedSafeRefusal,
        public array $tags,
    ) {
    }
}
