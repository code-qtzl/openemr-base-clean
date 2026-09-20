<?php

/**
 * Real-model correctness eval for the Clinical Co-Pilot.
 *
 * EVAL_RESULTS.md's suite scripts the model's responses (ScriptedAnthropic-
 * ClientFactory) to test the surrounding code -- citation verification,
 * staleness policies, audit logging -- deterministically and for free. It
 * never calls the real Anthropic API, so it cannot tell you whether the
 * model's actual answers are clinically correct. This script closes that
 * gap: it seeds a patient with exact, known ground truth, asks the real
 * model a fixed set of questions, and checks the real reply against that
 * ground truth (Appendix_CheckList.md Phase 2 Item 9's "how will you
 * measure correctness" / "ground truth data sources").
 *
 * Deliberately NOT part of `openemr-cmd st` / integration-tests.yml: it
 * costs real Anthropic spend per run (~$0.30 for the cases below, per
 * AI_SPEND.md's per-question rates) and the model's exact wording is not
 * fully deterministic, so it does not belong in the suite that gates every
 * PR. It runs from .github/workflows/eval.yml instead, on workflow_dispatch
 * and a weekly schedule -- see that file for why not on every push.
 *
 * Run as the web user, not root (see OpenEMR\Common\Command\RootCliGuard):
 *   php tests/eval/clinical-copilot-correctness-eval.php
 *
 * Requires a real OPENEMR__COPILOT_API_KEY and ANTHROPIC_BASE_URL unset.
 * Exits 0 if every case passes, 1 if any case fails or the chart tools
 * themselves are unavailable (see require_module.php below -- a bare `./cli
 * install` never registers the module the way the admin UI does, so its
 * classes are not autoloaded without this same explicit require chain the
 * DB-backed PHPUnit suite uses).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Eval\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use OpenEMR\Modules\ClinicalCopilot\Service\DefaultAnthropicClientFactory;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;

chdir(__DIR__ . '/../..');
$_GET['site'] = 'default';
$ignoreAuth = true;
require_once 'interface/globals.php';
require_once __DIR__ . '/../Tests/Fixtures/ClinicalCopilot/require_module.php';

/**
 * One eval case: a question, and how to tell a real answer apart from a
 * wrong or fabricated one. Matching is substring/case-insensitive on
 * purpose -- this checks whether the clinically material fact is present,
 * not exact wording, since the model is not scripted here.
 *
 * @param list<string> $mustContainAny at least one of these must appear
 */
final readonly class EvalCase
{
    /**
     * @param list<string> $mustContainAny
     */
    public function __construct(
        public string $label,
        public string $question,
        public array $mustContainAny,
    ) {
    }
}

/**
 * @return list<EvalCase>
 */
function evalCases(): array
{
    return [
        new EvalCase(
            'active-problem-recall',
            'What conditions does this patient have on file?',
            ['diabetes'],
        ),
        new EvalCase(
            'medication-recall',
            'What medications is this patient currently taking?',
            ['metformin'],
        ),
        new EvalCase(
            'a1c-trend-direction',
            "What was the result of this patient's most recent A1c, and is it trending up or down?",
            ['up', 'increas', 'worsen', 'rising', 'higher', 'risen'],
        ),
    ];
}

function seedGroundTruth(ClinicalCopilotFixtureManager $fixtures, int $pid): void
{
    // Fixed, known-correct values -- not the shared Synthea seed data, whose
    // "correct" answer for any given patient isn't independently verified.
    $fixtures->seedActiveProblem($pid, 'Type 2 diabetes mellitus', 'E11.9', '2019-03-01');
    $fixtures->seedMedication($pid, 'Metformin', '2019-03-01', dosage: '500mg');
    $fixtures->seedA1c($pid, '2024-01-15', '6.4');
    $fixtures->seedA1c($pid, '2024-07-15', '7.1');
}

function runCase(EvalCase $case, ChartContextTools $tools, string $correlationId): bool
{
    $service = new CopilotService($tools, new DefaultAnthropicClientFactory());
    $result = $service->ask($case->question, $correlationId);

    $replyLower = strtolower($result->reply);
    $matched = false;
    foreach ($case->mustContainAny as $needle) {
        if (str_contains($replyLower, strtolower($needle))) {
            $matched = true;

            break;
        }
    }

    $passed = $result->verificationPassed && $matched;

    echo "=== {$case->label} ===\n";
    echo "question: {$case->question}\n";
    echo 'toolsUsed: ' . implode(',', $result->toolsUsed) . "\n";
    echo 'verificationPassed: ' . ($result->verificationPassed ? 'true' : 'false') . "\n";
    echo 'expectedAnyOf: ' . implode(' | ', $case->mustContainAny) . "\n";
    echo 'reply: ' . $result->reply . "\n";
    echo 'result: ' . ($passed ? 'PASS' : 'FAIL') . "\n\n";

    return $passed;
}

$fixtures = new ClinicalCopilotFixtureManager();
$pid = $fixtures->installPrimaryPatient();

try {
    seedGroundTruth($fixtures, $pid);

    $allPassed = true;
    foreach (evalCases() as $case) {
        $tools = new ChartContextTools($pid, 'eval', 'Default', 'eval-' . $case->label);
        $allPassed = runCase($case, $tools, 'eval-' . $case->label) && $allPassed;
    }
} finally {
    $fixtures->removeFixtures([$pid]);
}

if ($allPassed) {
    echo "ALL CASES PASSED\n";
    exit(0);
}

echo "ONE OR MORE CASES FAILED\n";
exit(1);
