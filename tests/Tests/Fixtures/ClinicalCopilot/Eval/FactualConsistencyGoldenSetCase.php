<?php

/**
 * One case in the factual-consistency golden set: a stable id, one
 * response's raw claims (same untrusted shape
 * FactualConsistencyValidator::evaluateBatch consumes via
 * FactualConsistencyClaim::fromMixed), and the expected
 * `factually_consistent` verdict. See
 * .claude/skills/eval-factually-consistent-validator/SKILL.md's "Golden
 * Set Integration" section for the case categories this fixture set
 * covers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final readonly class FactualConsistencyGoldenSetCase
{
    /**
     * @param list<array<string, mixed>> $claims Raw claim payloads.
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $claims,
        public bool $expectedFactuallyConsistent,
        public array $tags,
    ) {
    }
}
