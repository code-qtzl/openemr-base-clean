<?php

/**
 * One PHI-in-telemetry violation: which field, where it was headed, and why
 * it was flagged.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\PhiLogGuard;

final readonly class PhiFinding
{
    public function __construct(
        public string $field,
        public string $destination,
        public string $reason,
    ) {
    }

    /**
     * @return array{field: string, destination: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'destination' => $this->destination,
            'reason' => $this->reason,
        ];
    }
}
