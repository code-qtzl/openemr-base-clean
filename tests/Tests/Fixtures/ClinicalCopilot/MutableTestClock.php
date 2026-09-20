<?php

/**
 * A ClockInterface test double whose "now" can be advanced mid-test --
 * needed for CopilotChatControllerTest's rate-limit window-reset case,
 * where a single test must observe both sides of the window boundary
 * without a real sleep().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableTestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceBySeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
