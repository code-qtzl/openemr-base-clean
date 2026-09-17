<?php

/**
 * The result of running a co-pilot answer through ResponseVerifier: either
 * the verified reply text, or a rejection reason to log (never shown to the
 * clinician -- the browser only ever sees $reply, which is the safe fallback
 * on rejection).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

final readonly class VerificationOutcome
{
    private function __construct(
        public bool $passed,
        public string $reply,
        public ?string $reason,
    ) {
    }

    public static function passed(string $reply): self
    {
        return new self(true, $reply, null);
    }

    public static function rejected(string $fallbackReply, string $reason): self
    {
        return new self(false, $fallbackReply, $reason);
    }
}
