<?php

/**
 * The result of running a co-pilot answer through ResponseVerifier: either
 * the verified reply text plus its per-claim citations, or a rejection
 * reason to log (never shown to the clinician -- the browser only ever sees
 * $reply, which is the safe fallback on rejection).
 *
 * $claims is always empty on rejection -- nothing partial ever reaches the
 * browser, matching ResponseVerifier's class docblock. It is only ever
 * populated by a fully-verified passed() outcome, never insufficient_information's
 * passed() call (which has no claims to carry).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

final readonly class VerificationOutcome
{
    /**
     * @param list<VerificationClaim> $claims
     */
    private function __construct(
        public bool $passed,
        public string $reply,
        public ?string $reason,
        public array $claims,
    ) {
    }

    /**
     * @param list<VerificationClaim> $claims
     */
    public static function passed(string $reply, array $claims = []): self
    {
        return new self(true, $reply, null, $claims);
    }

    public static function rejected(string $fallbackReply, string $reason): self
    {
        return new self(false, $fallbackReply, $reason, []);
    }
}
