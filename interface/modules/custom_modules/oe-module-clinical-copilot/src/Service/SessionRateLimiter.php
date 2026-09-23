<?php

/**
 * Sliding-window request-rate limit, scoped to a browser session. Shared by
 * CopilotChatController and CopilotDocumentUploadController -- both trigger
 * a real Anthropic call, so both count against the same per-session budget
 * rather than each having its own unbounded one. Checked before any
 * Anthropic call is made, so a rejected request costs nothing. Not a
 * defense against a determined multi-session attacker -- every request here
 * already required a valid, ACL-checked login -- this bounds the ordinary
 * failure mode of a stuck client-side retry loop or one session working far
 * outside realistic use.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Session\SessionUtil;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SessionRateLimiter
{
    private const SESSION_KEY = 'clinical_copilot_rate_limit';

    /**
     * Requests allowed per session per WINDOW_SECONDS. Sized generously
     * above realistic use (USERS.md's Dr. Ruiz persona asks a handful of
     * questions per patient visit, plus the occasional document upload)
     * while still bounding worst-case real Anthropic spend from a stuck
     * client-side retry loop or a misbehaving session to roughly this count
     * times AI_SPEND.md's per-question rate, not an unbounded amount.
     */
    private const MAX_REQUESTS = 15;

    private const WINDOW_SECONDS = 300;

    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? ServiceContainer::getClock();
    }

    public function withinLimit(SessionInterface $session): bool
    {
        $now = $this->clock->now()->getTimestamp();

        /** @var mixed $state */
        $state = $session->get(self::SESSION_KEY);
        $windowStart = is_array($state) && is_int($state['windowStart'] ?? null) ? $state['windowStart'] : $now;
        $count = is_array($state) && is_int($state['count'] ?? null) ? $state['count'] : 0;

        if ($now - $windowStart >= self::WINDOW_SECONDS) {
            $windowStart = $now;
            $count = 0;
        }

        ++$count;
        SessionUtil::setSession(self::SESSION_KEY, ['windowStart' => $windowStart, 'count' => $count]);

        return $count <= self::MAX_REQUESTS;
    }
}
