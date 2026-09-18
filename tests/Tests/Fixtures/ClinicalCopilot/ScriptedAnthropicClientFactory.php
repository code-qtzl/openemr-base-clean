<?php

/**
 * Test double for AnthropicClientFactory: scripts a fake model's turns
 * instead of calling the real Anthropic API.
 *
 * Build a conversation by chaining toolUse()/submitAnswer()/finalText()
 * calls, then hand the factory to CopilotService (or CopilotChatController,
 * which accepts the same interface). Each create() call returns a fresh
 * Anthropic\Client wired to a FakeAnthropicTransporter that replays the
 * scripted turns from the start -- so the same factory instance can be
 * reused for more than one ask() call in a test.
 *
 * A typical script for one tool call plus a cited answer:
 *
 *   (new ScriptedAnthropicClientFactory())
 *       ->toolUse('get_active_problems')
 *       ->submitAnswer(['insufficient_information' => false, 'claims' => [
 *           ['text' => '...', 'source_tool' => 'get_active_problems'],
 *       ]])
 *       ->finalText();
 *
 * The trailing finalText() is required: after the submit_answer tool_result
 * is sent back, BetaToolRunner always makes one more create() call, and it
 * must end the loop (stop_reason: end_turn) or the runner keeps going.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

require_once __DIR__ . '/../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/AnthropicClientFactory.php';

use Anthropic\Client;
use OpenEMR\Modules\ClinicalCopilot\Service\AnthropicClientFactory;

final class ScriptedAnthropicClientFactory implements AnthropicClientFactory
{
    private const MODEL = 'claude-opus-5';

    /** @var list<array<string, mixed>> */
    private array $responses = [];

    private int $turn = 0;

    private ?FakeAnthropicTransporter $lastTransporter = null;

    /**
     * Script a turn where the model calls one tool (a chart-reading tool, or
     * submit_answer -- see submitAnswer()). Chart-reading tools ignore
     * $input by design, so an empty array is the realistic default.
     *
     * @param array<string, mixed> $input
     */
    public function toolUse(string $toolName, array $input = []): static
    {
        ++$this->turn;
        $this->responses[] = $this->message(
            content: [[
                'type' => 'tool_use',
                'id' => sprintf('toolu_test_%d', $this->turn),
                'name' => $toolName,
                'input' => $input,
            ]],
            stopReason: 'tool_use',
        );

        return $this;
    }

    /**
     * Script the model's final, cited answer via the submit_answer tool --
     * CopilotService forces this instead of accepting free text.
     *
     * @param array<string, mixed> $submitAnswerInput
     */
    public function submitAnswer(array $submitAnswerInput): static
    {
        return $this->toolUse('submit_answer', $submitAnswerInput);
    }

    /**
     * Script a closing turn with no further tool calls, ending the loop.
     * Required after any toolUse()/submitAnswer() turn -- see class docblock.
     */
    public function finalText(string $text = ''): static
    {
        ++$this->turn;
        $content = $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        $this->responses[] = $this->message(content: $content, stopReason: 'end_turn');

        return $this;
    }

    public function create(string $apiKey, string $correlationId): Client
    {
        $this->lastTransporter = new FakeAnthropicTransporter($this->responses);

        return new Client(apiKey: $apiKey, requestOptions: ['transporter' => $this->lastTransporter]);
    }

    /**
     * Scripted conversations never retry -- FakeAnthropicTransporter always
     * succeeds on the first attempt -- so this is always 0. Satisfies
     * AnthropicClientFactory's interface for PUNCH_LIST.md 3.3's retry-count
     * metric without needing retry behavior in the test double.
     */
    public function retryCount(): int
    {
        return 0;
    }

    /**
     * The transporter behind the most recent create() call -- lets a test
     * inspect every request body sent across that conversation after
     * CopilotService::ask() returns. Null until create() has been called.
     */
    public function lastTransporter(): ?FakeAnthropicTransporter
    {
        return $this->lastTransporter;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function message(array $content, string $stopReason): array
    {
        return [
            'id' => sprintf('msg_test_%d', $this->turn),
            'type' => 'message',
            'role' => 'assistant',
            'model' => self::MODEL,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }
}
