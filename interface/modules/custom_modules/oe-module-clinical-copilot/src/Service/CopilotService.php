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
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;
use OpenEMR\Core\OEEnvBag;
use RuntimeException;

final class CopilotService
{
    private const MODEL = 'claude-opus-5';
    private const MAX_TOKENS = 8000;

    /** Hard ceiling on tool round-trips, so a loop cannot run away. */
    private const MAX_ITERATIONS = 8;

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

    /**
     * Answer one question, including any tool round-trips.
     *
     * @return array{reply: string, toolsUsed: list<string>}
     */
    public function ask(string $question): array
    {
        $apiKey = self::apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Clinical Co-Pilot is not configured.');
        }

        /** @var list<string> $toolsUsed */
        $toolsUsed = [];

        $runnableTools = [];
        foreach (ChartContextTools::definitions() as $definition) {
            $name = $definition['name'];
            $runnableTools[] = new BetaRunnableTool(
                definition: $definition,
                run: function (array $input) use ($name, &$toolsUsed): string {
                    // $input is ignored on purpose: none of these tools take
                    // parameters, because the patient is fixed by the session.
                    $toolsUsed[] = $name;

                    return json_encode($this->tools->call($name), JSON_THROW_ON_ERROR);
                },
            );
        }

        $runner = (new Client(apiKey: $apiKey))->beta->messages->toolRunner(
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

        $final = $runner->runUntilDone();

        $parts = [];
        foreach ($final->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $parts[] = $block->text;
            }
        }

        return [
            'reply' => trim(implode("\n", $parts)),
            'toolsUsed' => array_values(array_unique($toolsUsed)),
        ];
    }
}
