<?php

/**
 * Test fixtures for the Clinical Co-Pilot's DB-backed integration suite.
 *
 * Installs patients plus rows in the tables ChartContextTools queries
 * (procedure_order/procedure_report/procedure_result for A1c, lists for
 * active problems, prescriptions for medications, form_encounter for recent
 * encounters, clinical_copilot_extracted_document for previously-uploaded
 * documents), so tests can exercise real SQL rather than a mocked
 * ChartContextTools (the class is final and DB-backed by design -- see its
 * own docblock).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Tests\Fixtures\FixtureManager;

final class ClinicalCopilotFixtureManager
{
    /** pubpid of the shared pool's first fixture -- see tests/Tests/Fixtures/patients.php. */
    private const PRIMARY_PUBPID = 'test-fixture-789456';

    /** pubpid of the shared pool's second fixture, used as a distinct second patient. */
    private const SECONDARY_PUBPID = 'test-fixture-8';

    private bool $patientsInstalled = false;

    public function __construct(private readonly FixtureManager $patientFixtureManager = new FixtureManager())
    {
    }

    /**
     * Installs the shared patient pool (idempotent within one instance) and
     * returns the primary test patient's pid. Its chart starts empty -- no
     * rows in any of the four tables ChartContextTools queries -- until one
     * of the seed*() methods below is called for it.
     */
    public function installPrimaryPatient(): int
    {
        $this->installPatients();

        return $this->pidForPubpid(self::PRIMARY_PUBPID);
    }

    /**
     * A second, distinct test patient -- for the adversarial cross-patient
     * disclosure test, so there is another patient's data to check is never
     * leaked into the primary patient's reply.
     */
    public function installSecondaryPatient(): int
    {
        $this->installPatients();

        return $this->pidForPubpid(self::SECONDARY_PUBPID);
    }

    private function installPatients(): void
    {
        if (!$this->patientsInstalled) {
            $this->patientFixtureManager->installPatientFixtures();
            $this->patientsInstalled = true;
        }
    }

    private function pidForPubpid(string $pubpid): int
    {
        $row = QueryUtils::querySingleRow('SELECT `pid` FROM `patient_data` WHERE `pubpid` = ?', [$pubpid]);
        if ($row === false || !isset($row['pid']) || !is_numeric($row['pid'])) {
            // @codeCoverageIgnoreStart Defensive check — only fires if test infrastructure is broken.
            throw new \RuntimeException("Failed to find test patient fixture for pubpid {$pubpid}");
            // @codeCoverageIgnoreEnd
        }

        return (int) $row['pid'];
    }

    /**
     * One Hemoglobin A1c result (LOINC 4548-4), via the
     * procedure_order -> procedure_report -> procedure_result join chain
     * ChartContextTools::a1cSeries() reads.
     */
    public function seedA1c(
        int $pid,
        string $date,
        string $value,
        string $units = '%',
        string $range = '4.0-5.6',
        string $abnormal = 'no',
    ): void {
        $orderId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_order` (`patient_id`) VALUES (?)',
            [$pid],
        );
        $reportId = QueryUtils::sqlInsert(
            'INSERT INTO `procedure_report` (`procedure_order_id`) VALUES (?)',
            [$orderId],
        );
        QueryUtils::sqlInsert(
            'INSERT INTO `procedure_result`
                (`procedure_report_id`, `result_code`, `date`, `units`, `result`, `range`, `abnormal`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$reportId, '4548-4', $date, $units, $value, $range, $abnormal],
        );
    }

    /** One active problem row ChartContextTools::activeProblems() reads. */
    public function seedActiveProblem(
        int $pid,
        string $title,
        string $diagnosis = '',
        string $onsetDate = '2020-01-01',
        string $outcome = '',
    ): void {
        QueryUtils::sqlInsert(
            'INSERT INTO `lists` (`pid`, `type`, `activity`, `title`, `diagnosis`, `begdate`, `outcome`)
             VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$pid, 'medical_problem', $title, $diagnosis, $onsetDate, $outcome],
        );
    }

    /**
     * One currently-active prescription (matches the SQL filter
     * ChartContextTools::medications() applies: active = 1 and no past
     * end_date).
     */
    public function seedMedication(
        int $pid,
        string $drug,
        string $startDate,
        ?string $endDate = null,
        string $dosage = '10mg',
        string $route = 'oral',
    ): void {
        QueryUtils::sqlInsert(
            'INSERT INTO `prescriptions` (`patient_id`, `active`, `drug`, `dosage`, `route`, `start_date`, `end_date`)
             VALUES (?, 1, ?, ?, ?, ?, ?)',
            [$pid, $drug, $dosage, $route, $startDate, $endDate],
        );
    }

    /**
     * An open-ended (no end_date) prescription whose start_date is old
     * enough to trip MedicationStalenessPolicy's warning -- the PUNCH_LIST
     * 1.4 regression case: "active" alone is not proof of current use.
     */
    public function seedStaleOpenEndedMedication(int $pid, string $drug, string $startDate): void
    {
        $this->seedMedication($pid, $drug, $startDate, endDate: null);
    }

    /**
     * One row ChartContextTools::extractedDocuments() reads -- mirrors the
     * `{"doc_type": ..., "fields": {...}}` envelope
     * DocumentIngestionPipeline actually persists, so tests exercise the
     * same shape production code writes rather than a simplified one.
     *
     * @param array<string, mixed> $fields
     */
    public function seedExtractedDocument(int $pid, string $docType, array $fields): void
    {
        $fieldsJson = json_encode(['doc_type' => $docType, 'fields' => $fields], JSON_THROW_ON_ERROR);

        QueryUtils::sqlInsert(
            'INSERT INTO `clinical_copilot_extracted_document`
                (`pid`, `doc_type`, `fields_json`, `created_at`, `last_updated`)
             VALUES (?, ?, ?, NOW(), NOW())',
            [$pid, $docType, $fieldsJson],
        );
    }

    /** One recent-encounter row ChartContextTools::recentEncounters() reads. */
    public function seedEncounter(
        int $pid,
        string $date,
        string $reason,
        string $encounterTypeDescription = 'Office Visit',
    ): void {
        QueryUtils::sqlInsert(
            'INSERT INTO `form_encounter` (`pid`, `date`, `reason`, `encounter_type_description`)
             VALUES (?, ?, ?, ?)',
            [$pid, $date, $reason, $encounterTypeDescription],
        );
    }

    /**
     * Removes every row this manager may have written for the given pids
     * (chart data plus any clinical_copilot_log rows a controller-level test
     * wrote), then the patient fixtures themselves.
     *
     * @param list<int> $pids
     */
    public function removeFixtures(array $pids): void
    {
        foreach ($pids as $pid) {
            $this->removeChartData($pid);
        }

        if ($this->patientsInstalled) {
            try {
                $this->patientFixtureManager->removePatientFixtures();
            // @codeCoverageIgnoreStart Defensive catch — only fires on unexpected DB errors during cleanup.
            } catch (SqlQueryException $e) {
                \OpenEMR\BC\ServiceContainer::getLogger()->error('Clinical Co-Pilot fixture cleanup failed', [
                    'exception' => $e,
                ]);
            }
            // @codeCoverageIgnoreEnd
            $this->patientsInstalled = false;
        }
    }

    private function removeChartData(int $pid): void
    {
        try {
            $orderIds = QueryUtils::fetchTableColumn(
                'SELECT `procedure_order_id` FROM `procedure_order` WHERE `patient_id` = ?',
                'procedure_order_id',
                [$pid],
            );
            if ($orderIds !== []) {
                $reportIds = QueryUtils::fetchTableColumn(
                    'SELECT `procedure_report_id` FROM `procedure_report`'
                        . ' WHERE `procedure_order_id` IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')',
                    'procedure_report_id',
                    $orderIds,
                );
                if ($reportIds !== []) {
                    QueryUtils::sqlStatementThrowException(
                        'DELETE FROM `procedure_result` WHERE `procedure_report_id` IN ('
                            . implode(',', array_fill(0, count($reportIds), '?')) . ')',
                        $reportIds,
                    );
                    QueryUtils::sqlStatementThrowException(
                        'DELETE FROM `procedure_report` WHERE `procedure_report_id` IN ('
                            . implode(',', array_fill(0, count($reportIds), '?')) . ')',
                        $reportIds,
                    );
                }
                QueryUtils::sqlStatementThrowException(
                    'DELETE FROM `procedure_order` WHERE `patient_id` = ?',
                    [$pid],
                );
            }

            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `lists` WHERE `pid` = ? AND `type` = ?',
                [$pid, 'medical_problem'],
            );
            QueryUtils::sqlStatementThrowException('DELETE FROM `prescriptions` WHERE `patient_id` = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM `form_encounter` WHERE `pid` = ?', [$pid]);
            QueryUtils::sqlStatementThrowException('DELETE FROM `clinical_copilot_log` WHERE `pid` = ?', [$pid]);
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
                [$pid],
            );
        // @codeCoverageIgnoreStart Defensive catch — only fires on unexpected DB errors during cleanup.
        } catch (SqlQueryException $e) {
            \OpenEMR\BC\ServiceContainer::getLogger()->error('Clinical Co-Pilot fixture cleanup failed', [
                'pid' => $pid,
                'exception' => $e,
            ]);
        }
        // @codeCoverageIgnoreEnd
    }
}
