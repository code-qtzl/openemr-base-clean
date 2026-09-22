<?php

/**
 * One case in the citation-eval golden set: a stable id, a response's raw
 * claims (same untrusted shape CitationValidator::validateBatch consumes
 * via ClinicalClaim::fromMixed), and the expected `citation_present`
 * verdict. See .claude/skills/eval-citation-validator/SKILL.md's "Golden
 * Set Integration" section for the case categories this fixture set covers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot\Eval;

final readonly class CitationGoldenSetCase
{
    /**
     * @param list<array<string, mixed>> $claims Raw claim payloads.
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $description,
        public array $claims,
        public bool $expectedCitationPresent,
        public array $tags,
    ) {
    }
}
