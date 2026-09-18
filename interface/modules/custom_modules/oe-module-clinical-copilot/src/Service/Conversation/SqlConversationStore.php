<?php

/**
 * `clinical_copilot_conversation`-backed ConversationStore.
 *
 * TTL/eviction mirrors OpenEMR\Common\Session\SessionTracker's own
 * session-timeout pattern: expiry is measured against the same `timeout`
 * global that governs the browser session itself (via MySQL server time, not
 * PHP time, so PHP/DB clock skew cannot mask or exaggerate expiry), and a
 * conversation past that age is deleted on next read rather than swept by a
 * separate cron job -- there is no meaningful difference, for a mid-visit
 * chat, between "the session timed out" and "this conversation is stale."
 *
 * A read or write failure here must never take down an otherwise-successful
 * reply -- same posture as CopilotInteractionLogger -- so every database or
 * decode error is logged and swallowed, falling back to an empty
 * Conversation (a fresh, memory-less turn) rather than propagating.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Conversation;

use InvalidArgumentException;
use JsonException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Core\OEGlobalsBag;

final class SqlConversationStore implements ConversationStore
{
    public function load(string $sessionUuid, int $patientId): Conversation
    {
        try {
            $row = QueryUtils::querySingleRow(
                'SELECT `turns_json`, `last_updated`, NOW() as `current_time`
                   FROM `clinical_copilot_conversation`
                  WHERE `session_uuid` = ? AND `pid` = ?',
                [$sessionUuid, $patientId],
            );
        } catch (SqlQueryException $e) {
            $this->logFailure('read', $e);

            return new Conversation();
        }

        if ($row === false) {
            return new Conversation();
        }

        $lastUpdated = $row['last_updated'] ?? null;
        $currentTime = $row['current_time'] ?? null;
        $turnsJson = $row['turns_json'] ?? null;
        if (!is_string($lastUpdated) || !is_string($currentTime) || !is_string($turnsJson)) {
            $this->logFailure('read', new InvalidArgumentException(
                'Malformed clinical_copilot_conversation row: expected string columns.',
            ));

            return new Conversation();
        }

        if ($this->isExpired($lastUpdated, $currentTime)) {
            $this->delete($sessionUuid, $patientId);

            return new Conversation();
        }

        try {
            /** @var list<array<array-key, mixed>> $decoded */
            $decoded = json_decode($turnsJson, associative: true, flags: JSON_THROW_ON_ERROR);

            return new Conversation(array_map(ConversationTurn::fromArray(...), $decoded));
        } catch (JsonException | InvalidArgumentException $e) {
            $this->logFailure('decode', $e);

            return new Conversation();
        }
    }

    public function save(string $sessionUuid, int $patientId, Conversation $conversation): void
    {
        try {
            $turnsJson = json_encode(
                array_map(static fn (ConversationTurn $turn): array => $turn->toArray(), $conversation->turns),
                JSON_THROW_ON_ERROR,
            );

            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `clinical_copilot_conversation`
                    (`session_uuid`, `pid`, `turns_json`, `created_at`, `last_updated`)
                 VALUES (?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE `turns_json` = VALUES(`turns_json`), `last_updated` = NOW()',
                [$sessionUuid, $patientId, $turnsJson],
            );
        } catch (SqlQueryException | JsonException $e) {
            $this->logFailure('write', $e);
        }
    }

    private function isExpired(string $lastUpdated, string $currentTime): bool
    {
        $elapsedSeconds = strtotime($currentTime) - strtotime($lastUpdated);

        return $elapsedSeconds > OEGlobalsBag::getInstance()->getInt('timeout');
    }

    private function delete(string $sessionUuid, int $patientId): void
    {
        try {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_conversation` WHERE `session_uuid` = ? AND `pid` = ?',
                [$sessionUuid, $patientId],
            );
        } catch (SqlQueryException $e) {
            $this->logFailure('evict', $e);
        }
    }

    private function logFailure(string $operation, JsonException | SqlQueryException | InvalidArgumentException $e): void
    {
        ServiceContainer::getLogger()->error('Clinical Co-Pilot conversation store operation failed', [
            'operation' => $operation,
            'exception' => $e,
        ]);
    }
}
