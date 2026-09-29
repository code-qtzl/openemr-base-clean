<?php

/**
 * PR-blocking eval gate for AgentForge2 Core Requirement #6: runs the 5
 * eval-category validator test classes (schema_valid, citation_present,
 * factually_consistent, safe_refusal, no_phi_in_logs -- see
 * src/Service/Eval/README.md), scores each category's golden-set pass rate,
 * and fails if any category regresses beyond what
 * bin/data/eval-gate-baseline.json allows.
 *
 * No OpenEMR bootstrap is needed here (no DB, no session) -- this script
 * only shells out to PHPUnit and parses its JUnit XML output, so it stays
 * fully decoupled from interface/globals.php.
 *
 * Scoring is deliberately scoped to only the `testGoldenSetCase` data-set
 * tests each *ValidatorTest class runs (via #[DataProvider('goldenSetProvider')]),
 * not that class's other fixed unit/contract-shape tests -- those aren't
 * part of the "50-case golden set" the requirement is about.
 *
 * Two independent gate rules, both always evaluated (see this module's
 * eval-phi-log-guard SKILL.md and Core Requirement #6's own wording):
 *   - no_phi_in_logs is zero-tolerance: any single failing case fails the
 *     gate outright, regardless of aggregate pass rate. A PHI disclosure is
 *     a disclosure, not a quality regression to average away.
 *   - every other category fails if it regresses by more than
 *     regression_threshold_points versus its stored baseline, OR drops
 *     below the baseline outright. These 5 validators are deterministic
 *     pure functions tested against hand-verified golden fixtures, not live
 *     model output with natural variance -- under correct code every case
 *     should always pass, so a failure here only ever means a real
 *     regression or a bad fixture, never unlucky sampling. Both rules stay
 *     separate, not collapsed into one, even though they currently overlap
 *     for every category: it matches the spec's literal two-part wording,
 *     and keeps the mechanism generic for if a baseline ever legitimately
 *     needs to sit below 1.0 in the future.
 *
 * Usage: php interface/modules/custom_modules/oe-module-clinical-copilot/bin/eval-gate.php
 * Exit code 0 on pass, 1 on any gate failure or unparseable/missing output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

/** One category's scored result, computed from the JUnit XML. */
final readonly class EvalGateCategoryScore
{
    /** @param list<string> $failingCaseIds */
    public function __construct(
        public string $category,
        public int $total,
        public int $passed,
        public array $failingCaseIds,
    ) {
    }

    public function passRate(): float
    {
        return $this->total === 0 ? 0.0 : $this->passed / $this->total;
    }
}

/** Parses PHPUnit's JUnit XML output into per-category golden-set scores. */
final class EvalGateJunitParser
{
    /** @var array<string, string> Fully-qualified test class name => category key. */
    private const CATEGORY_BY_CLASS = [
        'OpenEMR\\Tests\\Isolated\\Modules\\ClinicalCopilot\\Service\\Eval\\Citation\\CitationValidatorTest' => 'citation_present',
        'OpenEMR\\Tests\\Isolated\\Modules\\ClinicalCopilot\\Service\\Eval\\Schema\\SchemaValidatorTest' => 'schema_valid',
        'OpenEMR\\Tests\\Isolated\\Modules\\ClinicalCopilot\\Service\\Eval\\FactualConsistency\\FactualConsistencyValidatorTest' => 'factually_consistent',
        'OpenEMR\\Tests\\Isolated\\Modules\\ClinicalCopilot\\Service\\Eval\\SafeRefusal\\SafeRefusalValidatorTest' => 'safe_refusal',
        'OpenEMR\\Tests\\Isolated\\Modules\\ClinicalCopilot\\Service\\Eval\\PhiLogGuard\\PhiLogGuardValidatorTest' => 'no_phi_in_logs',
    ];

    private const GOLDEN_CASE_TEST_PREFIX = 'testGoldenSetCase';

    /**
     * @return array<string, EvalGateCategoryScore> Keyed by category, one
     *                                               entry per CATEGORY_BY_CLASS
     *                                               value -- a category with
     *                                               zero matched testcases
     *                                               still appears, with
     *                                               total=0, so the caller's
     *                                               integrity guard can
     *                                               catch a broken mapping
     *                                               instead of it silently
     *                                               vanishing from the report.
     */
    public function parse(string $junitPath): array
    {
        if (!is_file($junitPath)) {
            throw new RuntimeException("JUnit output not found at {$junitPath}.");
        }

        $document = new DOMDocument();
        $loaded = @$document->load($junitPath);
        if ($loaded === false) {
            throw new RuntimeException("Could not parse JUnit XML at {$junitPath}.");
        }

        $failingCaseIdsByCategory = [];
        $totalByCategory = [];
        $passedByCategory = [];
        foreach (self::CATEGORY_BY_CLASS as $category) {
            $failingCaseIdsByCategory[$category] = [];
            $totalByCategory[$category] = 0;
            $passedByCategory[$category] = 0;
        }

        foreach ($document->getElementsByTagName('testcase') as $testcase) {
            $name = $testcase->getAttribute('name');
            if (!str_starts_with($name, self::GOLDEN_CASE_TEST_PREFIX)) {
                continue;
            }

            $className = $testcase->getAttribute('class');
            $category = self::CATEGORY_BY_CLASS[$className] ?? null;
            if ($category === null) {
                continue;
            }

            $totalByCategory[$category]++;

            $failed = $testcase->getElementsByTagName('failure')->length > 0
                || $testcase->getElementsByTagName('error')->length > 0;

            if ($failed) {
                $failingCaseIdsByCategory[$category][] = self::extractCaseId($name);
            } else {
                $passedByCategory[$category]++;
            }
        }

        $scores = [];
        foreach (self::CATEGORY_BY_CLASS as $category) {
            $scores[$category] = new EvalGateCategoryScore(
                $category,
                $totalByCategory[$category],
                $passedByCategory[$category],
                $failingCaseIdsByCategory[$category],
            );
        }

        return $scores;
    }

    private static function extractCaseId(string $testcaseName): string
    {
        if (preg_match('/with data set "([^"]+)"/', $testcaseName, $matches) === 1) {
            return $matches[1];
        }

        return $testcaseName;
    }
}

/** Runs the 5 eval validator test classes and returns their JUnit XML path. */
final class EvalGateTestRunner
{
    private const EVAL_TEST_DIRECTORY = 'tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/';

    public function __construct(private readonly string $repoRoot)
    {
    }

    public function run(): string
    {
        $junitPath = getenv('EVAL_GATE_JUNIT_PATH');
        if (!is_string($junitPath) || $junitPath === '') {
            $junitPath = sys_get_temp_dir() . '/eval-gate-junit-' . bin2hex(random_bytes(8)) . '.xml';
        }

        $process = new Process(
            [
                'vendor/bin/phpunit',
                '-c',
                'phpunit-isolated.xml',
                '--log-junit=' . $junitPath,
                self::EVAL_TEST_DIRECTORY,
            ],
            $this->repoRoot,
        );
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);

                return;
            }

            echo $buffer;
        });

        // A non-zero PHPUnit exit code here just means "at least one test
        // failed" -- expected and meaningful (a broken golden-set case), not
        // itself a reason to abort before scoring. Only a missing JUnit
        // output file (PHPUnit couldn't even start/write it) is fatal.
        if (!is_file($junitPath)) {
            throw new RuntimeException(sprintf(
                'PHPUnit did not produce JUnit output at %s (exit code %d).',
                $junitPath,
                $process->getExitCode() ?? -1,
            ));
        }

        return $junitPath;
    }
}

/** Loads and validates bin/data/eval-gate-baseline.json. */
final readonly class EvalGateBaseline
{
    /** @param array<string, float> $categoryBaselines */
    private function __construct(
        public float $regressionThresholdPoints,
        /** @var list<string> */
        public array $zeroToleranceCategories,
        public array $categoryBaselines,
    ) {
    }

    public static function loadFrom(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Eval-gate baseline not found at {$path}.");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read eval-gate baseline at {$path}.");
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Eval-gate baseline at {$path} did not decode to an object.");
        }

        $threshold = $decoded['regression_threshold_points'] ?? null;
        if (!is_numeric($threshold)) {
            throw new RuntimeException('Eval-gate baseline is missing a numeric regression_threshold_points.');
        }

        $zeroTolerance = $decoded['zero_tolerance_categories'] ?? null;
        if (!is_array($zeroTolerance)) {
            throw new RuntimeException('Eval-gate baseline is missing a zero_tolerance_categories list.');
        }

        $categories = $decoded['categories'] ?? null;
        if (!is_array($categories)) {
            throw new RuntimeException('Eval-gate baseline is missing a categories object.');
        }

        $categoryBaselines = [];
        foreach ($categories as $category => $value) {
            if (!is_string($category) || !is_numeric($value)) {
                throw new RuntimeException('Eval-gate baseline has a non-numeric category entry.');
            }

            $categoryBaselines[$category] = (float) $value;
        }

        return new self(
            (float) $threshold,
            array_values(array_filter($zeroTolerance, static fn (mixed $v): bool => is_string($v))),
            $categoryBaselines,
        );
    }
}

/** One category's gate verdict. */
final readonly class EvalGateVerdict
{
    public function __construct(
        public string $category,
        public bool $passedGate,
        public string $reason,
    ) {
    }
}

final class EvalGate
{
    public function __construct(
        private readonly EvalGateBaseline $baseline,
    ) {
    }

    public function evaluate(EvalGateCategoryScore $score): EvalGateVerdict
    {
        if ($score->total === 0) {
            return new EvalGateVerdict(
                $score->category,
                false,
                'integrity guard: zero golden-set testcases matched -- the classname mapping or test directory scoping is broken.',
            );
        }

        $baselineRate = $this->baseline->categoryBaselines[$score->category] ?? null;
        if ($baselineRate === null) {
            return new EvalGateVerdict(
                $score->category,
                false,
                'no baseline entry exists for this category.',
            );
        }

        $passRate = $score->passRate();

        if (in_array($score->category, $this->baseline->zeroToleranceCategories, true)) {
            if ($passRate < 1.0) {
                return new EvalGateVerdict(
                    $score->category,
                    false,
                    sprintf('zero-tolerance category below 100%% (%.1f%%).', $passRate * 100),
                );
            }

            return new EvalGateVerdict($score->category, true, 'zero-tolerance category at 100%.');
        }

        $regression = $baselineRate - $passRate;
        if ($regression > $this->baseline->regressionThresholdPoints) {
            return new EvalGateVerdict(
                $score->category,
                false,
                sprintf(
                    'regressed %.1f percentage points versus baseline (allowed: %.1f).',
                    $regression * 100,
                    $this->baseline->regressionThresholdPoints * 100,
                ),
            );
        }

        if ($passRate < $baselineRate) {
            return new EvalGateVerdict(
                $score->category,
                false,
                sprintf('below baseline pass threshold (%.1f%% < %.1f%%).', $passRate * 100, $baselineRate * 100),
            );
        }

        return new EvalGateVerdict($score->category, true, 'within threshold.');
    }
}

/** CLI entry point, wrapped in a class rather than a global-namespace function (forbidden here outside tests/). */
final class EvalGateCli
{
    public static function main(): int
    {
        $repoRoot = dirname(__DIR__, 5);
        $baselinePath = __DIR__ . '/data/eval-gate-baseline.json';

        try {
            $junitPath = (new EvalGateTestRunner($repoRoot))->run();
            $scores = (new EvalGateJunitParser())->parse($junitPath);
            $baseline = EvalGateBaseline::loadFrom($baselinePath);
        } catch (RuntimeException|JsonException $e) {
            fwrite(STDERR, "Eval gate could not run: {$e->getMessage()}\n");

            return 1;
        }

        $gate = new EvalGate($baseline);

        $totalCases = 0;
        $overallPassed = true;

        echo "\n=== Clinical Co-Pilot Eval Gate ===\n\n";

        foreach ($scores as $score) {
            $totalCases += $score->total;
            $verdict = $gate->evaluate($score);
            $overallPassed = $overallPassed && $verdict->passedGate;

            $baselineRate = $baseline->categoryBaselines[$score->category] ?? null;
            $baselineText = $baselineRate === null ? 'n/a' : sprintf('%.1f%%', $baselineRate * 100);

            printf(
                "%-22s %2d/%-2d passed (%5.1f%%)  baseline: %-6s  %s -- %s\n",
                $score->category,
                $score->passed,
                $score->total,
                $score->passRate() * 100,
                $baselineText,
                $verdict->passedGate ? 'PASS' : 'FAIL',
                $verdict->reason,
            );

            if ($score->failingCaseIds !== []) {
                foreach ($score->failingCaseIds as $caseId) {
                    echo "    failing case: {$caseId}\n";
                }
            }
        }

        echo "\nTotal golden-set cases: {$totalCases}";
        if ($totalCases !== 52) {
            echo ' (warning: expected 52 -- the 50-case AgentForge2 Core Requirement #6 floor'
                . ' plus 2 bbox-linkage cases for the citation contract\'s PDF-overlay requirement'
                . ' -- not a gate failure, but check for an accidental case addition/removal)';
        }
        echo "\n\n";

        echo $overallPassed ? "Eval gate: PASS\n" : "Eval gate: FAIL\n";

        return $overallPassed ? 0 : 1;
    }
}

exit(EvalGateCli::main());
