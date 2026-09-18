<?php

/**
 * Stateless stub for the Anthropic Messages API, used only during PUNCH_LIST.md
 * 3.2's mocked load-test runs.
 *
 * CopilotService's tool loop (Anthropic\Lib\Tools\BetaToolRunner) drives its whole
 * request/execute/loop cycle through plain, non-streaming POSTs to
 * POST /v1/messages (see CopilotService's class docblock and
 * tests/Tests/Fixtures/ClinicalCopilot/FakeAnthropicTransporter.php, which this
 * script mirrors at the HTTP layer instead of the PSR-18 layer, so a real running
 * app under real concurrent load can be pointed at it via ANTHROPIC_BASE_URL with
 * zero application code changes -- see tests/loadtest/README.md).
 *
 * Response shape and per-turn scripting are modeled directly on
 * ScriptedAnthropicClientFactory::message()/toolUse()/submitAnswer()/finalText().
 * This script always plays the same three-turn conversation:
 *   turn 1 (1 message in the request)  -> tool_use get_active_problems
 *   turn 2 (3 messages in the request) -> tool_use submit_answer (a cited claim)
 *   turn 3 (5 messages in the request) -> end_turn, closing the loop
 *
 * get_active_problems is used deliberately: unlike get_medications it has no
 * zero-row special case in ResponseVerifier, so this script's canned claim always
 * passes verification regardless of which demo patient a load-test VU is scoped
 * to.
 *
 * Deliberately stateless: the turn is derived only from count(messages) in the
 * incoming request body, so concurrent requests from different VUs never share or
 * contend on state -- safe under k6's 10/50 VU load.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'Not found']]);
    return;
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody === false ? '' : $rawBody, true);
$messages = is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])
    ? $decoded['messages']
    : [];

// Request N (1-indexed) carries 2N-1 messages: the running conversation grows by
// one assistant turn + one tool_result turn per round trip.
$turn = (int) ((count($messages) - 1) / 2) + 1;

$content = match (true) {
    $turn <= 1 => [[
        'type' => 'tool_use',
        'id' => 'toolu_loadtest_1',
        'name' => 'get_active_problems',
        'input' => new stdClass(),
    ]],
    $turn === 2 => [[
        'type' => 'tool_use',
        'id' => 'toolu_loadtest_2',
        'name' => 'submit_answer',
        'input' => [
            'insufficient_information' => false,
            'claims' => [[
                'text' => 'See the active problems on file for this patient.',
                'source_tool' => 'get_active_problems',
            ]],
        ],
    ]],
    default => [],
};

$stopReason = $turn <= 2 ? 'tool_use' : 'end_turn';

$response = [
    'id' => sprintf('msg_loadtest_%d_%d', $turn, random_int(1, PHP_INT_MAX)),
    'type' => 'message',
    'role' => 'assistant',
    'model' => 'claude-opus-5',
    'content' => $content,
    'stop_reason' => $stopReason,
    'stop_sequence' => null,
    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
