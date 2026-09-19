<?php

/**
 * Real-API cost/token measurement for the Clinical Co-Pilot -- backs
 * AI_SPEND.md's "actual observed cost" numbers.
 *
 * Unlike k6-copilot-chat.js's SMOKE mode (tests/loadtest/README.md), which
 * measures HTTP latency against the live endpoint but has no visibility into
 * token counts, this calls CopilotService::ask() directly so it can read
 * AskResult::inputTokens/outputTokens -- the exact usage the Anthropic SDK
 * itself reports, not an estimate. Requires a real OPENEMR__COPILOT_API_KEY
 * (real Anthropic spend, a few cents per run) and ANTHROPIC_BASE_URL unset
 * (not pointed at tests/loadtest/mock-anthropic-stub.php).
 *
 * Run as the web user, not root (see OpenEMR\Common\Command\RootCliGuard):
 *   php tests/loadtest/real-api-cost-smoke.php [pid]
 *
 * Prints tokens/cost/latency per question; does not compute cost itself --
 * see AI_SPEND.md for the current published per-token rate this should be
 * multiplied against (rates change; don't hardcode one here).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotInteractionLogger;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use OpenEMR\Modules\ClinicalCopilot\Service\DefaultAnthropicClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\LangfuseTracer;
use Ramsey\Uuid\Uuid;

chdir(__DIR__ . '/../..');
$_GET['site'] = 'default';
$ignoreAuth = true;
require_once 'interface/globals.php';

$pid = isset($argv[1]) ? (int) $argv[1] : 1;
$authUser = 'admin';
$authProvider = 'Default';

/** @var list<string> $questions */
$questions = [
    'What conditions does this patient have on file?',
    'What medications is this patient currently taking?',
    'What was the result of their most recent A1c, and is it trending up or down?',
];

$logger = new CopilotInteractionLogger();
$tracer = new LangfuseTracer();

foreach ($questions as $question) {
    $correlationId = Uuid::uuid4()->toString();
    $tools = new ChartContextTools($pid, $authUser, $authProvider, $correlationId);
    $service = new CopilotService($tools, new DefaultAnthropicClientFactory());

    $startedAt = microtime(true);
    $result = $service->ask($question, $correlationId);
    $endedAt = microtime(true);
    $latencyMs = (int) round(($endedAt - $startedAt) * 1000);

    $logger->logSuccess(
        $correlationId,
        $pid,
        $authUser,
        $question,
        $result->reply,
        $result->toolsUsed,
        CopilotService::model(),
        $latencyMs,
        $result->verificationPassed,
    );
    $tracer->traceAsk($correlationId, $pid, $authUser, CopilotService::model(), $question, $result, $startedAt, $endedAt);

    echo "=== {$correlationId} ===\n";
    echo "question: {$question}\n";
    echo 'toolsUsed: ' . implode(',', $result->toolsUsed) . "\n";
    echo "inputTokens: {$result->inputTokens}\n";
    echo "outputTokens: {$result->outputTokens}\n";
    echo "latencyMs: {$latencyMs}\n";
    echo 'verificationPassed: ' . ($result->verificationPassed ? 'true' : 'false') . "\n";
    echo "\n";
}

echo "DONE\n";
