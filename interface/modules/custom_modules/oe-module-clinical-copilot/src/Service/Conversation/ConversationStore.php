<?php

/**
 * Persists and retrieves a Clinical Co-Pilot conversation, keyed by browser
 * session and patient id -- see ARCHITECTURE.md's Persistent State
 * Management section and PUNCH_LIST.md Tier 4.1.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Conversation;

interface ConversationStore
{
    /**
     * Loads the conversation for this session/patient pair, or an empty
     * Conversation if none is stored or the stored one has aged past the
     * session inactivity timeout -- a mid-visit timeout must not resurrect a
     * stale conversation for whichever patient is opened next.
     */
    public function load(string $sessionUuid, int $patientId): Conversation;

    public function save(string $sessionUuid, int $patientId, Conversation $conversation): void;
}
