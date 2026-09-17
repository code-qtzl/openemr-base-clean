<?php

/**
 * Clinical Co-Pilot conversation service.
 *
 * Asks Claude a clinical question and lets it pull the chart slices it needs
 * through ChartContextTools, then returns the grounded answer.
 *
 * The chart is deliberately NOT placed in the prompt. An open-ended chat cannot
 * know in advance which fields a question needs, so a fixed context bundle would
 * send more than necessary on every turn. Tool-calling moves the choice to the
 * model while keeping enforcement in our code -- see ChartContextTools.
 *
 * The SDK's tool runner drives the request/execute/loop cycle, so this class
 * does not hand-assemble tool_result blocks or replay message history.
 * `submit_answer` (see submitAnswerToolDefinition()) is registered as a
 * fifth tool alongside ChartContextTools' four read tools and is how the
 * model must give its final answer -- as structured, cited claims captured
 * by that tool's run() callback, never as free text -- so ResponseVerifier
 * can check every clinical claim against a tool actually called this turn
 * (PUNCH_LIST.md 1.3) before anything reaches the browser. If the model
 * never calls it, the empty capture fails ResponseVerifier closed to the
 * same safe fallback as a rejected answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\ToolCallSpan;
use OpenEMR\Modules\ClinicalCopilot\Service\Verification\ResponseVerifier;
use RuntimeException;

final class CopilotService
{
    private const MODEL = 'claude-opus-5';
    private const MAX_TOKENS = 8000;

    /** Hard ceiling on tool round-trips, so a loop cannot run away. */
    private const MAX_ITERATIONS = 8;

    /** Tool name the model must call to give its final, cited answer -- see class docblock. */
    private const SUBMIT_ANSWER_TOOL = 'submit_answer';

    /**
     * Outbound header carrying the request's correlation id on every call to
     * the Anthropic API, so a trace can be tied back to a specific request
     * from either side (this application's logs, or Anthropic's own).
     */
    private const CORRELATION_HEADER = 'X-Correlation-Id';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a clinical co-pilot embedded in an electronic health record, assisting a
        licensed clinician who is currently viewing one patient's chart.

        You can only see what the provided tools return. You have no other access to the
        record. Call the tools you need to answer the question, then answer from what they
        returned.

        Rules:
        - Ground every clinical claim in tool output. If the data does not support an
          answer, say so plainly rather than inferring.
        - If a tool returns no rows, say the chart has no such data recorded. Do not treat
          absence of data as a normal or negative finding.
        - Quote concrete values and dates when discussing trends.
        - Be concise and clinical. The reader is a clinician, not a patient.
        - You are decision support, not a decision maker. Do not issue orders or
          prescriptions; surface findings and let the clinician decide.
        - Call the chart-reading tools as many times as you need first. Once you are
          ready to answer, you must give your final answer by calling submit_answer --
          never as plain text. Every clinical claim you pass to submit_answer must cite
          the chart-reading tool call that supports it.
        PROMPT;

    public function __construct(private readonly ChartContextTools $tools)
    {
    }

    /**
     * Resolve the API key from the environment.
     *
     * Deliberately not a DATA_TYPE_ENCRYPTED global: globals travel inside the
     * demo database dump, and CryptoGen's drive keys are wrapped under the
     * database key, so an encrypted global does not survive a restore cleanly.
     * An environment variable sidesteps both problems. Note php.ini sets
     * variables_order="GPCS" (no E), so $_ENV is empty and it is OEEnvBag's
     * getenv() merge that actually resolves this.
     */
    public static function apiKey(): ?string
    {
        $key = OEEnvBag::getInstance()->getString('OPENEMR__COPILOT_API_KEY');

        return $key !== '' ? $key : null;
    }

    public static function isConfigured(): bool
    {
        return self::apiKey() !== null;
    }

    public static function model(): string
    {
        return self::MODEL;
    }

    /**
     * Answer one question: gather chart data through tool calls, capture the
     * model's structured, cited final answer from its submit_answer call,
     * and verify it before returning.
     */
    public function ask(string $question, string $correlationId): AskResult
    {
        $apiKey = self::apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Clinical Co-Pilot is not configured.');
        }

        /** @var list<string> $toolsUsed */
        $toolsUsed = [];
        /** @var array<string, int> $toolRowCounts */
        $toolRowCounts = [];
        /** @var list<ToolCallSpan> $toolCallSpans */
        $toolCallSpans = [];
        /** @var array<string, mixed>|null $submitAnswerInput */
        $submitAnswerInput = null;

        $runnableTools = [];
        foreach (ChartContextTools::definitions() as $definition) {
            $name = $definition['name'];
            $runnableTools[] = new BetaRunnableTool(
                definition: $definition,
                run: function (array $input) use ($name, &$toolsUsed, &$toolRowCounts, &$toolCallSpans): string {
                    // $input is ignored on purpose: none of these tools take
                    // parameters, because the patient is fixed by the session.
                    $toolsUsed[] = $name;
                    $startedAt = microtime(true);
                    $result = $this->tools->call($name);
                    $toolCallSpans[] = new ToolCallSpan($name, $startedAt, microtime(true), $result->ok);
                    $toolRowCounts[$name] = ($toolRowCounts[$name] ?? 0) + $result->count();

                    return json_encode($result->toArray(), JSON_THROW_ON_ERROR);
                },
            );
        }

        $runnableTools[] = new BetaRunnableTool(
            definition: self::submitAnswerToolDefinition(),
            run: function (array $input) use (&$submitAnswerInput, &$toolCallSpans): string {
                $startedAt = microtime(true);
                $submitAnswerInput = $input;
                $toolCallSpans[] = new ToolCallSpan(self::SUBMIT_ANSWER_TOOL, $startedAt, microtime(true), true);

                return 'Recorded.';
            },
        );

        $client = new Client(
            apiKey: $apiKey,
            requestOptions: ['extraHeaders' => [self::CORRELATION_HEADER => $correlationId]],
        );

        $runner = $client->beta->messages->toolRunner(
            maxTokens: self::MAX_TOKENS,
            messages: [['role' => 'user', 'content' => $question]],
            model: self::MODEL,
            tools: $runnableTools,
            maxIterations: self::MAX_ITERATIONS,
            extraParams: [
                'system' => self::SYSTEM_PROMPT,
                'thinking' => ['type' => 'adaptive'],
            ],
        );

        $inputTokens = 0;
        $outputTokens = 0;
        foreach ($runner as $message) {
            $inputTokens += $message->usage->inputTokens;
            $outputTokens += $message->usage->outputTokens;
        }

        $toolsUsed = array_values(array_unique($toolsUsed));
        $outcome = ResponseVerifier::verify($submitAnswerInput ?? [], $toolsUsed, $toolRowCounts);

        return new AskResult(
            reply: $outcome->reply,
            toolsUsed: $toolsUsed,
            verificationPassed: $outcome->passed,
            verificationReason: $outcome->reason,
            toolCalls: $toolCallSpans,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    /** @return array<string, mixed> */
    private static function submitAnswerToolDefinition(): array
    {
        return [
            'name' => self::SUBMIT_ANSWER_TOOL,
            'description' => 'Give your final answer to the clinician. Every clinical claim must cite '
                . 'exactly one tool call made this turn as its source_tool. If the tool results do not '
                . 'contain enough information to answer the question, set insufficient_information to '
                . 'true instead of guessing.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'insufficient_information' => [
                        'type' => 'boolean',
                        'description' => 'True if the tool results do not contain enough information to '
                            . 'answer the question.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Optional framing text with no clinical claims of its own (e.g. '
                            . '"Based on the chart:"), or the full honest answer when '
                            . 'insufficient_information is true.',
                    ],
                    'claims' => [
                        'type' => 'array',
                        'description' => 'One entry per clinical claim in the answer. Omit or leave empty '
                            . 'when insufficient_information is true.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => [
                                    'type' => 'string',
                                    'description' => 'The clinical claim, stated plainly.',
                                ],
                                'source_tool' => [
                                    'type' => 'string',
                                    'description' => 'Name of the tool call made this turn whose result '
                                        . 'supports this claim.',
                                ],
                            ],
                            'required' => ['text', 'source_tool'],
                        ],
                    ],
                ],
                'required' => ['insufficient_information', 'claims'],
            ],
        ];
    }
}
