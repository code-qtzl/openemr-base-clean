<?php

/**
 * A patient-and-session-scoped Clinical Co-Pilot conversation: the prior
 * turns CopilotService::ask() prepends to a new question so a follow-up like
 * "how long has that been on the list?" resolves against what was just
 * discussed (USERS.md UC2, PUNCH_LIST.md Tier 4.1).
 *
 * Bounded to MAX_TURNS so a long mid-visit conversation cannot grow the
 * Anthropic request unboundedly turn over turn -- append() keeps only the
 * most recent turns, oldest first.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Conversation;

final readonly class Conversation
{
    /** Ten question/answer exchanges -- enough for a realistic mid-visit follow-up chain. */
    private const MAX_TURNS = 20;

    /** @param list<ConversationTurn> $turns Oldest first. */
    public function __construct(public array $turns = [])
    {
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    /**
     * Returns a new Conversation with one question/answer exchange appended,
     * trimmed to the most recent MAX_TURNS turns.
     */
    public function append(ConversationTurn $userTurn, ConversationTurn $assistantTurn): self
    {
        $turns = [...$this->turns, $userTurn, $assistantTurn];
        if (count($turns) > self::MAX_TURNS) {
            $turns = array_slice($turns, -self::MAX_TURNS);
        }

        return new self($turns);
    }

    /**
     * The Anthropic Messages API's own `messages` shape, oldest first --
     * CopilotService prepends this to the current question.
     *
     * @return list<array{role: string, content: string}>
     */
    public function toMessages(): array
    {
        return array_map(static fn (ConversationTurn $turn): array => $turn->toArray(), $this->turns);
    }
}
