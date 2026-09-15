<?php

declare(strict_types=1);

/**
 * Import endocrine lab results from Synthea FHIR bundles into OpenEMR.
 *
 * Synthea generates HbA1c, TSH, LDL and microalbumin as standalone FHIR
 * Observations, but its C-CDA exporter drops them — only panel members that
 * belong to a DiagnosticReport survive the CCDA round trip. OpenEMR imports
 * CCDA, so a straight `import_ccda.php` run yields diabetic patients with a
 * complete chemistry panel and *no* A1c history, which is the single most
 * important series for a diabetes co-pilot.
 *
 * This script closes that gap. Run it AFTER import_ccda.php has created the
 * patients: it matches each FHIR bundle to an existing patient, then writes the
 * procedure_order -> procedure_order_code -> procedure_report -> procedure_result
 * chain that OpenEMR uses for laboratory data. There is no REST endpoint or
 * service method for lab results (ProcedureService has no insert(), and
 * /api/procedure is GET-only), so direct inserts are the only available path.
 *
 * Usage:
 *   php import_synthea_labs.php --fhirPath=<dir> [--site=default] [--dryRun]
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

/**
 * LOINC codes Synthea emits as standalone observations that the CCDA export drops.
 *
 * `range` and `units` are recorded on the result row so the UI and any consuming
 * agent can reason about abnormality without hardcoding clinical knowledge.
 */
const TARGET_LOINC = [
    '4548-4'  => ['name' => 'Hemoglobin A1c/Hemoglobin.total in Blood', 'units' => '%',      'range' => '4.0-5.6'],
    '3016-3'  => ['name' => 'Thyrotropin [Units/volume] in Serum or Plasma', 'units' => 'mIU/L', 'range' => '0.4-4.0'],
    '3024-7'  => ['name' => 'Thyroxine (T4) free [Mass/volume] in Serum or Plasma', 'units' => 'ng/dL', 'range' => '0.8-1.8'],
    '13457-7' => ['name' => 'Cholesterol in LDL [Mass/volume] in Serum or Plasma', 'units' => 'mg/dL', 'range' => '0-99'],
    '14957-5' => ['name' => 'Microalbumin [Mass/volume] in Urine', 'units' => 'mg/L', 'range' => '0-30'],
];

$options = [];
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--')) {
        [$key, $value] = explode('=', substr((string) $arg, 2), 2) + [1 => true];
        $options[$key] = $value;
    }
}

$fhirPath = $options['fhirPath'] ?? null;
$dryRun = isset($options['dryRun']);
$site = $options['site'] ?? 'default';

if (!is_string($fhirPath) || !is_dir($fhirPath)) {
    die("Usage: php import_synthea_labs.php --fhirPath=<dir> [--site=default] [--dryRun]\n");
}

$_GET['site'] = $site;
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once __DIR__ . "/../../../interface/globals.php";

use OpenEMR\Common\Uuid\UuidRegistry;

/**
 * Resolve the lab vendor row used for imported results, creating it if absent.
 *
 * sql/database.sql ships procedure_providers empty; the CCDA importer creates
 * rows on demand and this mirrors that behaviour so the two paths agree.
 */
function resolveLabId(): int
{
    $existing = sqlQuery("SELECT ppid FROM procedure_providers WHERE name = ?", ['External Lab']);
    if (!empty($existing['ppid'])) {
        return (int) $existing['ppid'];
    }

    return (int) sqlInsert("INSERT INTO procedure_providers SET name = ?", ['External Lab']);
}

/**
 * Resolve (or create) the procedure_type catalogue entry for a LOINC code.
 *
 * @return int procedure_type_id
 */
function resolveProcedureType(string $loinc, string $name, int $labId): int
{
    $code = 'LOINC:' . $loinc;
    $existing = sqlQuery(
        "SELECT procedure_type_id FROM procedure_type WHERE procedure_code = ? AND lab_id = ?",
        [$code, $labId]
    );
    if (!empty($existing['procedure_type_id'])) {
        return (int) $existing['procedure_type_id'];
    }

    $id = (int) sqlInsert(
        "INSERT INTO procedure_type SET name = ?, lab_id = ?, procedure_code = ?, procedure_type = ?, activity = 1",
        [$name, $labId, $code, 'ord']
    );
    // parent points at the row itself for top-level orderables, matching the
    // shape the CCDA importer produces.
    sqlStatement("UPDATE procedure_type SET parent = ? WHERE procedure_type_id = ?", [$id, $id]);

    return $id;
}

/**
 * Find the patient this bundle belongs to.
 *
 * Synthea appends numeric suffixes to names (e.g. "Fay398"), so surname plus
 * date of birth is unique within a generated cohort. Returns null when the
 * bundle has no corresponding patient — i.e. the CCDA import skipped it.
 */
function findPid(string $family, string $birthDate): ?int
{
    $row = sqlQuery(
        "SELECT pid FROM patient_data WHERE lname = ? AND DOB = ? LIMIT 1",
        [$family, $birthDate]
    );

    return empty($row['pid']) ? null : (int) $row['pid'];
}

/**
 * Pick the encounter closest in time to a result, so labs attach to a real visit.
 *
 * Returns 0 when the patient has no encounters, which OpenEMR treats as an
 * unattached order rather than an error.
 */
function findNearestEncounter(int $pid, string $date): int
{
    $row = sqlQuery(
        "SELECT encounter FROM form_encounter
         WHERE pid = ?
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, date, ?)) ASC
         LIMIT 1",
        [$pid, $date]
    );

    return empty($row['encounter']) ? 0 : (int) $row['encounter'];
}

/**
 * Classify a result against its reference range for the `abnormal` column.
 */
function classify(float $value, string $range): string
{
    if (!preg_match('/^([\d.]+)-([\d.]+)$/', $range, $m)) {
        return '';
    }
    if ($value < (float) $m[1]) {
        return 'low';
    }
    if ($value > (float) $m[2]) {
        return 'high';
    }

    return 'no';
}

$files = glob(rtrim($fhirPath, '/') . '/*.json') ?: [];
if ($files === []) {
    die("No FHIR bundles found in {$fhirPath}\n");
}

echo "Synthea lab import\n";
echo "  FHIR path: {$fhirPath}\n";
echo "  Bundles:   " . count($files) . "\n";
echo "  Codes:     " . implode(', ', array_keys(TARGET_LOINC)) . "\n";
echo $dryRun ? "  MODE:      DRY RUN (no writes)\n\n" : "\n";

$labId = $dryRun ? 0 : resolveLabId();
$typeCache = [];
$totalInserted = 0;
$totalSkipped = 0;
$unmatched = [];

foreach ($files as $file) {
    $bundle = json_decode((string) file_get_contents($file), true);
    if (!is_array($bundle) || !isset($bundle['entry'])) {
        echo "  ! unreadable bundle: " . basename($file) . "\n";
        continue;
    }

    $family = null;
    $birthDate = null;
    $observations = [];

    foreach ($bundle['entry'] as $entry) {
        $resource = $entry['resource'] ?? [];
        $type = $resource['resourceType'] ?? '';

        if ($type === 'Patient' && $family === null) {
            $family = $resource['name'][0]['family'] ?? null;
            $birthDate = $resource['birthDate'] ?? null;
            continue;
        }

        if ($type !== 'Observation') {
            continue;
        }

        foreach ($resource['code']['coding'] ?? [] as $coding) {
            $loinc = $coding['code'] ?? '';
            if (!isset(TARGET_LOINC[$loinc])) {
                continue;
            }
            // Only quantitative results are useful as a trend series.
            if (!isset($resource['valueQuantity']['value'])) {
                continue;
            }
            $observations[] = [
                'loinc' => $loinc,
                'value' => (float) $resource['valueQuantity']['value'],
                'units' => $resource['valueQuantity']['unit'] ?? TARGET_LOINC[$loinc]['units'],
                'date' => substr((string) ($resource['effectiveDateTime'] ?? ''), 0, 19),
            ];
        }
    }

    if ($family === null || $birthDate === null) {
        echo "  ! no patient resource: " . basename($file) . "\n";
        continue;
    }

    $pid = findPid($family, $birthDate);
    if ($pid === null) {
        $unmatched[] = "{$family} ({$birthDate})";
        continue;
    }

    $inserted = 0;
    foreach ($observations as $obs) {
        if ($obs['date'] === '') {
            $totalSkipped++;
            continue;
        }

        $date = str_replace('T', ' ', $obs['date']);
        $meta = TARGET_LOINC[$obs['loinc']];

        if ($dryRun) {
            $inserted++;
            continue;
        }

        $typeCache[$obs['loinc']] ??= resolveProcedureType($obs['loinc'], $meta['name'], $labId);
        $encounter = findNearestEncounter($pid, $date);

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
            [$orderId, 'LOINC:' . $obs['loinc'], $meta['name']]
        );

        $reportId = (int) sqlInsert(
            "INSERT INTO procedure_report SET
                procedure_order_id = ?, procedure_order_seq = 1,
                date_collected = ?, date_report = ?, report_status = 'final'",
            [$orderId, $date, $date]
        );

        sqlStatement(
            "INSERT INTO procedure_result SET
                procedure_report_id = ?, result_code = ?, result_text = ?,
                date = ?, units = ?, result = ?, `range` = ?, abnormal = ?,
                result_status = 'final', result_data_type = 'N'",
            [
                $reportId,
                $obs['loinc'],
                $meta['name'],
                $date,
                $obs['units'],
                (string) round($obs['value'], 2),
                $meta['range'],
                classify($obs['value'], $meta['range']),
            ]
        );

        $inserted++;
    }

    $totalInserted += $inserted;
    echo sprintf("  pid %-4d %-28s %d results\n", $pid, substr($family, 0, 27), $inserted);
}

if ($unmatched !== []) {
    echo "\n  Unmatched bundles (no patient in OpenEMR): " . count($unmatched) . "\n";
    foreach (array_slice($unmatched, 0, 10) as $u) {
        echo "    - {$u}\n";
    }
}

echo "\nInserted {$totalInserted} lab results";
echo $totalSkipped > 0 ? " ({$totalSkipped} skipped: no date)" : "";
echo $dryRun ? " [DRY RUN — nothing written]\n" : "\n";

if (!$dryRun && $totalInserted > 0) {
    echo "Populating UUIDs...\n";
    UuidRegistry::populateAllMissingUuids(false);
    echo "Done.\n";
}
