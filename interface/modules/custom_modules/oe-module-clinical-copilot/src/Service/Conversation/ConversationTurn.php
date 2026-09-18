<?php

/**
 * One turn of a persisted Clinical Co-Pilot conversation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Conversation;

use InvalidArgumentException;

final readonly class ConversationTurn
{
    public function __construct(
        public ConversationRole $role,
        public string $content,
    ) {
    }

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }

    /**
     * @param array<array-key, mixed> $data Decoded from
     *                                      `clinical_copilot_conversation.turns_json`
     *                                      -- app-controlled, but parsed
     *                                      defensively rather than trusted,
     *                                      since a future schema change or a
     *                                      hand-edited row could leave a row
     *                                      malformed.
     */
    public static function fromArray(array $data): self
    {
        $role = $data['role'] ?? null;
        $content = $data['content'] ?? null;
        if (!is_string($role) || !is_string($content)) {
            throw new InvalidArgumentException('Malformed conversation turn: role and content must be strings.');
        }

        $roleEnum = ConversationRole::tryFrom($role);
        if ($roleEnum === null) {
            throw new InvalidArgumentException("Malformed conversation turn: unknown role '{$role}'.");
        }

        return new self($roleEnum, $content);
    }
}
