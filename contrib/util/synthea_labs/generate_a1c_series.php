<?php

declare(strict_types=1);

/**
 * Generate clinically coherent HbA1c series for the diabetes demo cohort.
 *
 * WHY THIS EXISTS
 * ---------------
 * Synthea emits HbA1c values that are not usable for a diabetes co-pilot:
 * measured across this cohort's source bundles, 17% of values fall below 4.0%
 * (physiologically impossible — A1c measures glycated haemoglobin and cannot go
 * that low in a living patient), the maximum is 7.84%, and not one value reaches
 * 9%. Real diabetic populations routinely run 9-12%, and those poorly-controlled
 * patients are precisely the ones a co-pilot needs to surface. Synthea's
 * trajectories are also flat, so "A1c is climbing, therapy needs escalation" —
 * the central reasoning task — never occurs.
 *
 * This script replaces that series with values derived from each patient's OWN
 * recorded clinical timeline: prediabetes onset, type 2 diabetes onset,
 * complication diagnoses, and prescribed therapy. The result is synthetic but
 * internally consistent — the A1c curve agrees with the problem list and the
 * medication history, so a verification layer that cross-checks claims against
 * source records will find them coherent rather than contradictory.
 *
 * The data is explicitly synthetic demo data and must never be represented as
 * real patient measurements.
 *
 * Deterministic: seeded per-patient from the pid, so repeated runs reproduce the
 * same cohort exactly. Idempotent: deletes previously generated A1c rows first.
 *
 * Usage:
 *   php generate_a1c_series.php [--site=default] [--dryRun]
 *
 * Must be run as the web user, not root (see RootCliGuard).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (!getenv('OPENEMR_ENABLE_SYNTHEA_LAB_IMPORT')) {
    die("Set OPENEMR_ENABLE_SYNTHEA_LAB_IMPORT=1 to enable this script.\n");
}

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

const A1C_LOINC = '4548-4';
const A1C_NAME = 'Hemoglobin A1c/Hemoglobin.total in Blood';
const A1C_RANGE = '4.0-5.6';
const A1C_FLOOR = 4.0;
const A1C_CEILING = 14.0;

/** SNOMED codes that mark points on the glycaemic timeline. */
const SNOMED_PREDIABETES = '714628002';
const SNOMED_T2DM = '44054006';

/** Complications imply sustained hyperglycaemia, so they bias control downward. */
const COMPLICATION_PATTERNS = ['neuropathy', 'retinopathy', 'nephropathy', 'microalbumin', 'renal disease'];

$options = [];
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--')) {
        [$key, $value] = explode('=', substr((string) $arg, 2), 2) + [1 => true];
        $options[$key] = $value;
    }
}
$dryRun = isset($options['dryRun']);
$site = is_string($options['site'] ?? null) ? $options['site'] : 'default';

$_GET['site'] = $site;
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once __DIR__ . "/../../../interface/globals.php";

use OpenEMR\Common\Uuid\UuidRegistry;

/**
 * Control archetypes, with the share of the cohort each represents.
 *
 * `target` is the A1c the patient settles toward once established on therapy;
 * `drift` is the annual change after that, which is what produces the
 * "deteriorating despite treatment" cases worth flagging.
 */
const ARCHETYPES = [
    'well-controlled'  => ['weight' => 50, 'target' => [5.9, 6.7], 'drift' => [-0.05, 0.03]],
    'moderate'         => ['weight' => 30, 'target' => [6.9, 7.7], 'drift' => [0.0, 0.08]],
    'poorly-controlled' => ['weight' => 12, 'target' => [8.2, 9.4], 'drift' => [0.05, 0.20]],
    'deteriorating'    => ['weight' => 8,  'target' => [7.3, 8.2], 'drift' => [0.15, 0.35]],
];

function pickArchetype(int $pid, bool $hasComplications, bool $onInsulin): string
{
    // Complications and insulin both imply a more advanced disease course, so
    // steer those patients away from the well-controlled bucket rather than
    // letting the draw contradict the rest of their record.
    $pool = [];
    foreach (ARCHETYPES as $name => $spec) {
        $weight = $spec['weight'];
        if (($hasComplications || $onInsulin) && $name === 'well-controlled') {
            $weight = 15;
        }
        if (($hasComplications || $onInsulin) && in_array($name, ['poorly-controlled', 'deteriorating'], true)) {
            $weight += 8;
        }
        $pool = array_merge($pool, array_fill(0, (int) $weight, $name));
    }

    return $pool[mt_rand(0, count($pool) - 1)];
}

/** Uniform random float helper — mt_rand only yields integers. */
function randFloat(float $min, float $max): float
{
    return $min + (mt_rand(0, PHP_INT_MAX - 1) / (PHP_INT_MAX - 1)) * ($max - $min);
}

function clampA1c(float $v): float
{
    return round(max(A1C_FLOOR, min(A1C_CEILING, $v)), 1);
}

function classify(float $v): string
{
    return $v > 5.6 ? 'high' : ($v < 4.0 ? 'low' : 'no');
}

function resolveLabId(): int
{
    $row = sqlQuery("SELECT ppid FROM procedure_providers WHERE name = ?", ['External Lab']);

    return !empty($row['ppid'])
        ? (int) $row['ppid']
        : (int) sqlInsert("INSERT INTO procedure_providers SET name = ?", ['External Lab']);
}

function resolveProcedureType(int $labId): int
{
    $code = 'LOINC:' . A1C_LOINC;
    $row = sqlQuery(
        "SELECT procedure_type_id FROM procedure_type WHERE procedure_code = ? AND lab_id = ?",
        [$code, $labId]
    );
    if (!empty($row['procedure_type_id'])) {
        return (int) $row['procedure_type_id'];
    }
    $id = (int) sqlInsert(
        "INSERT INTO procedure_type SET name = ?, lab_id = ?, procedure_code = ?, procedure_type = 'ord', activity = 1",
        [A1C_NAME, $labId, $code]
    );
    sqlStatement("UPDATE procedure_type SET parent = ? WHERE procedure_type_id = ?", [$id, $id]);

    return $id;
}

/**
 * Remove previously generated A1c rows so the script can be re-run cleanly.
 *
 * Scoped to orders whose order code is the A1c LOINC, which is exactly what this
 * script and the FHIR injector create — CCDA-imported panels are untouched.
 */
function purgeExistingA1c(): int
{
    $orderIds = [];
    $res = sqlStatement(
        "SELECT DISTINCT poc.procedure_order_id FROM procedure_order_code poc WHERE poc.procedure_code = ?",
        ['LOINC:' . A1C_LOINC]
    );
    while ($row = sqlFetchArray($res)) {
        $orderIds[] = (int) $row['procedure_order_id'];
    }
    if ($orderIds === []) {
        return 0;
    }

    foreach (array_chunk($orderIds, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        sqlStatement(
            "DELETE pr FROM procedure_result pr
             JOIN procedure_report rp ON rp.procedure_report_id = pr.procedure_report_id
             WHERE rp.procedure_order_id IN ($in)",
            $chunk
        );
        sqlStatement("DELETE FROM procedure_report WHERE procedure_order_id IN ($in)", $chunk);
        sqlStatement("DELETE FROM procedure_order_code WHERE procedure_order_id IN ($in)", $chunk);
        sqlStatement("DELETE FROM procedure_order WHERE procedure_order_id IN ($in)", $chunk);
    }

    return count($orderIds);
}

/**
 * Gather the clinical timeline the A1c curve is derived from.
 *
 * @return array{prediabetes:?int,t2dm:?int,complications:bool,metformin:?int,insulin:?int,first:?int,last:?int}
 */
function patientTimeline(int $pid): array
{
    $dateOf = function (string $snomed) use ($pid): ?int {
        $row = sqlQuery(
            "SELECT MIN(begdate) d FROM lists
             WHERE pid = ? AND type = 'medical_problem' AND diagnosis LIKE ? AND begdate IS NOT NULL",
            [$pid, '%' . $snomed . '%']
        );

        return empty($row['d']) ? null : (int) strtotime((string) $row['d']);
    };

    $complications = false;
    $res = sqlStatement(
        "SELECT title FROM lists WHERE pid = ? AND type = 'medical_problem'",
        [$pid]
    );
    while ($row = sqlFetchArray($res)) {
        foreach (COMPLICATION_PATTERNS as $pattern) {
            if (stripos((string) $row['title'], $pattern) !== false) {
                $complications = true;
                break 2;
            }
        }
    }

    $medStart = function (string $regex) use ($pid): ?int {
        $row = sqlQuery(
            "SELECT MIN(start_date) d FROM prescriptions
             WHERE patient_id = ? AND drug REGEXP ? AND start_date IS NOT NULL",
            [$pid, $regex]
        );

        return empty($row['d']) ? null : (int) strtotime((string) $row['d']);
    };

    $span = sqlQuery("SELECT MIN(date) a, MAX(date) b FROM form_encounter WHERE pid = ?", [$pid]);

    return [
        'prediabetes' => $dateOf(SNOMED_PREDIABETES),
        't2dm' => $dateOf(SNOMED_T2DM),
        'complications' => $complications,
        'metformin' => $medStart('metformin'),
        'insulin' => $medStart('insulin'),
        'first' => empty($span['a']) ? null : (int) strtotime((string) $span['a']),
        'last' => empty($span['b']) ? null : (int) strtotime((string) $span['b']),
    ];
}

/**
 * Compute the A1c value for one draw, given where it falls on the timeline.
 */
function a1cAt(int $t, array $tl, array $spec, float $baseline, float $peak, float $target): float
{
    $year = 365.25 * 86400;
    $dx = $tl['t2dm'];

    if ($dx === null) {
        return $baseline + randFloat(-0.2, 0.3);
    }

    // Not every patient carries a recorded prediabetes date. Without a fallback
    // those patients skip the pre-diagnosis branches entirely and every draw —
    // including ones years before diagnosis — is evaluated as post-diagnosis.
    $pre = $tl['prediabetes'] ?? ($dx - (int) (3 * $year));

    // Before any dysglycaemia: normal, with assay noise.
    if ($t < $pre) {
        return $baseline + randFloat(-0.2, 0.2);
    }

    // Prediabetic phase: climbs from baseline toward the diagnostic peak.
    if ($t < $dx) {
        $progress = ($t - $pre) / max(1, $dx - $pre);

        return $baseline + ($peak - $baseline) * (0.35 * $progress) + randFloat(-0.2, 0.25);
    }

    // Diagnosis onward. A1c peaks at diagnosis, falls as therapy takes effect
    // over roughly a year, then trends by the archetype's drift.
    $yearsSinceDx = ($t - $dx) / $year;
    $onTherapy = ($tl['metformin'] !== null && $t >= $tl['metformin'])
        || ($tl['insulin'] !== null && $t >= $tl['insulin']);

    // Patients with no recorded pharmacotherapy are treated as diet-and-lifestyle
    // managed rather than untreated-at-peak-forever: they still improve after
    // diagnosis, just to a higher plateau. Leaving them pinned at peak put ~20 of
    // 46 patients permanently above 9% and skewed the whole cohort.
    $effectiveTarget = $onTherapy ? $target : $target + randFloat(0.2, 0.6);
    $responseYears = $onTherapy ? 1.0 : 2.0;

    $response = min(1.0, max(0.0, $yearsSinceDx / $responseYears));
    $settled = $peak + ($effectiveTarget - $peak) * $response;

    // Drift is bounded on both the horizon it accumulates over and its total
    // magnitude. Without this, a 20-year series compounds a small annual trend
    // into values past the physiological ceiling, and everyone ends up looking
    // poorly controlled — real cohorts sit nearer 15-25%.
    $driftYears = min(max(0.0, $yearsSinceDx - 1.0), 8.0);
    $drift = min(randFloat($spec['drift'][0], $spec['drift'][1]) * $driftYears, 1.0);

    // Occasional excursion — missed refills, illness, holidays.
    $excursion = (mt_rand(1, 100) <= 5) ? randFloat(0.5, 1.3) : 0.0;

    return $settled + $drift + $excursion + randFloat(-0.25, 0.3);
}

// ---------------------------------------------------------------------------

$patients = [];
$res = sqlStatement(
    "SELECT DISTINCT pid FROM lists
     WHERE type = 'medical_problem' AND diagnosis LIKE ? ORDER BY pid",
    ['%' . SNOMED_T2DM . '%']
);
while ($row = sqlFetchArray($res)) {
    $patients[] = (int) $row['pid'];
}

echo "A1c series generator (clinically-derived, synthetic)\n";
echo "  Patients with T2DM dx: " . count($patients) . "\n";
echo $dryRun ? "  MODE: DRY RUN (no writes)\n\n" : "\n";

if (!$dryRun) {
    $purged = purgeExistingA1c();
    echo "  Purged {$purged} pre-existing A1c orders\n\n";
}

$labId = $dryRun ? 0 : resolveLabId();
if (!$dryRun) {
    resolveProcedureType($labId);
}

$totalRows = 0;
$archCount = [];
$allValues = [];
/** @var array<int,float> latest A1c per patient — the clinically meaningful summary */
$latestPerPatient = [];

foreach ($patients as $pid) {
    mt_srand($pid * 7919);
    $tl = patientTimeline($pid);

    if ($tl['first'] === null || $tl['last'] === null) {
        echo "  pid {$pid}: no encounters, skipped\n";
        continue;
    }

    $onInsulin = $tl['insulin'] !== null;
    $arch = pickArchetype($pid, $tl['complications'], $onInsulin);
    $spec = ARCHETYPES[$arch];
    $archCount[$arch] = ($archCount[$arch] ?? 0) + 1;

    $baseline = randFloat(5.0, 5.5);
    // Insulin-requiring and complicated disease presents higher.
    $peak = randFloat(7.2, 8.8) + ($onInsulin ? randFloat(0.4, 1.2) : 0.0)
        + ($tl['complications'] ? randFloat(0.1, 0.5) : 0.0);
    $target = randFloat($spec['target'][0], $spec['target'][1]);

    // Draw cadence: sparse before dysglycaemia, 6-monthly in prediabetes,
    // 3-monthly once diabetic (ADA guidance), easing to 6-monthly when stable.
    // Bound the window. Synthea's first encounter is at birth, so an unbounded
    // series spans 50+ years and yields implausible draw counts. Two years of
    // pre-dysglycaemia context is enough to show the baseline, and the window
    // never reaches back more than 18 years before the last encounter.
    $yearSecs = (int) (365.25 * 86400);
    $start = $tl['prediabetes'] ?? $tl['t2dm'] ?? $tl['first'];
    $start = max(
        $tl['first'],
        $start - 2 * $yearSecs,
        $tl['last'] - 18 * $yearSecs
    );
    $t = $start;
    $rows = [];

    while ($t <= $tl['last']) {
        $value = clampA1c(a1cAt($t, $tl, $spec, $baseline, $peak, $target));
        $rows[] = ['t' => $t, 'v' => $value];
        $allValues[] = $value;

        $dx = $tl['t2dm'];
        if ($dx !== null && $t >= $dx) {
            $interval = ($value <= 7.0) ? 182 : 98;   // stable vs active management
        } elseif ($tl['prediabetes'] !== null && $t >= $tl['prediabetes']) {
            $interval = 365;
        } else {
            $interval = 730;
        }
        $t += (int) (($interval + mt_rand(-12, 12)) * 86400);
    }

    if (!$dryRun) {
        foreach ($rows as $r) {
            $date = date('Y-m-d H:i:s', $r['t']);
            $enc = sqlQuery(
                "SELECT encounter FROM form_encounter WHERE pid = ?
                 ORDER BY ABS(TIMESTAMPDIFF(SECOND, date, ?)) ASC LIMIT 1",
                [$pid, $date]
            );
            $encounter = empty($enc['encounter']) ? 0 : (int) $enc['encounter'];

            $orderId = (int) sqlInsert(
                "INSERT INTO procedure_order SET
                    patient_id = ?, encounter_id = ?, provider_id = 0, date_ordered = ?,
                    date_collected = ?, lab_id = ?, order_status = 'completed',
                    procedure_order_type = 'laboratory_test', activity = 1",
                [$pid, $encounter, $date, $date, $labId]
            );
            sqlStatement(
                "INSERT INTO procedure_order_code SET
                    procedure_order_id = ?, procedure_order_seq = 1,
                    procedure_code = ?, procedure_name = ?, procedure_order_title = 'laboratory_test'",
                [$orderId, 'LOINC:' . A1C_LOINC, A1C_NAME]
            );
            $reportId = (int) sqlInsert(
                "INSERT INTO procedure_report SET
                    procedure_order_id = ?, procedure_order_seq = 1,
                    date_collected = ?, date_report = ?, report_status = 'final'",
                [$orderId, $date, $date]
            );
            sqlStatement(
                "INSERT INTO procedure_result SET
                    procedure_report_id = ?, result_code = ?, result_text = ?, date = ?,
                    units = '%', result = ?, `range` = ?, abnormal = ?,
                    result_status = 'final', result_data_type = 'N'",
                [$reportId, A1C_LOINC, A1C_NAME, $date, (string) $r['v'], A1C_RANGE, classify($r['v'])]
            );
        }
    }

    if ($rows !== []) {
        $latestPerPatient[$pid] = (float) end($rows)['v'];
    }

    $totalRows += count($rows);
    printf(
        "  pid %-4d %-18s %3d draws  peak %.1f  target %.1f%s\n",
        $pid,
        $arch,
        count($rows),
        $peak,
        $target,
        $onInsulin ? '  [insulin]' : ($tl['complications'] ? '  [complications]' : '')
    );
}

sort($allValues);
$n = count($allValues);
echo "\n  Archetypes: ";
foreach ($archCount as $k => $v) {
    echo "{$k}={$v} ";
}

if ($n > 0) {
    printf(
        "\n  Values: n=%d  min=%.1f  median=%.1f  max=%.1f\n",
        $n,
        $allValues[0],
        $allValues[(int) ($n / 2)],
        $allValues[$n - 1]
    );

    // Compare against the real-world US diabetic distribution so the cohort can
    // be judged against something, rather than eyeballed.
    $bands = [
        '<7.0 (at goal)' => [0.0, 7.0, 50],
        '7.0-7.9'        => [7.0, 8.0, 25],
        '8.0-8.9'        => [8.0, 9.0, 13],
        '>=9.0 (poor)'   => [9.0, 99.0, 12],
    ];
    echo "  Distribution (latest value per patient vs. real-world target):\n";
    $latest = array_values($latestPerPatient);
    $lp = count($latest);
    foreach ($bands as $label => [$lo, $hi, $expected]) {
        $c = count(array_filter($latest, fn($v) => $v >= $lo && $v < $hi));
        printf(
            "    %-16s %2d pts  %5.1f%%   (real-world ~%d%%)\n",
            $label,
            $c,
            $lp > 0 ? 100 * $c / $lp : 0,
            $expected
        );
    }
}

echo "\nGenerated {$totalRows} A1c results" . ($dryRun ? " [DRY RUN — nothing written]\n" : "\n");

if (!$dryRun && $totalRows > 0) {
    echo "Populating UUIDs...\n";
    UuidRegistry::populateAllMissingUuids(false);
    echo "Done.\n";
}
