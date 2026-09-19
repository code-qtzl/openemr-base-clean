<?php

/**
 * Allowlisted chart-retrieval tools exposed to the Clinical Co-Pilot.
 *
 * This class is the minimum-necessary control. The patient chart is never
 * placed into the prompt; instead the model is given a small set of narrow
 * tools, each returning one bounded slice of one patient's record.
 *
 * Two invariants make that enforceable, and both must be preserved:
 *
 *  1. The patient id is supplied by the CONSTRUCTOR, from the server-side
 *     session -- never by the model. A tool schema that accepted a patient id
 *     would let the model request a chart the signed-in user cannot see.
 *  2. Every query names its columns explicitly. No SELECT *. Adding a column
 *     to one of these tables must not silently widen what leaves the building.
 *
 * Every invocation is written to OpenEMR's audit log under the
 * 'clinical-copilot-tool' event/category, tagged with a correlation id, so
 * each disclosure is attributable to a user, a patient, a timestamp, and the
 * request that caused it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\A1cResultRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\A1cSeriesResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncounterRow;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncountersResult;
use Psr\Clock\ClockInterface;
use RuntimeException;

final readonly class ChartContextTools
{
    /** LOINC code for Hemoglobin A1c / Hemoglobin.total in Blood. */
    private const LOINC_A1C = '4548-4';

    /** Upper bound on rows returned by any single tool call. */
    private const MAX_ROWS = 60;

    /** Audit event name; the handle for filtering co-pilot disclosures. */
    private const AUDIT_EVENT = 'clinical-copilot-tool';

    private ClockInterface $clock;

    public function __construct(
        private int $patientId,
        private string $authUser,
        private string $authProvider,
        private string $correlationId,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? ServiceContainer::getClock();
    }

    /**
     * The four allowlisted chart-reading tools' definitions, in Anthropic
     * Messages API shape -- loaded from schemas/tool-definitions.json (the
     * contract), not hand-written here (PUNCH_LIST_2.md Item 4).
     *
     * Note there is no patient parameter anywhere by design -- see the class
     * docblock. The descriptions tell the model what each tool covers so it
     * requests only what a given question needs.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        return array_map(
            ToolSchemaRegistry::get(...),
            ['get_a1c_series', 'get_active_problems', 'get_medications', 'get_recent_encounters'],
        );
    }

    /**
     * Dispatch one tool call and audit it.
     */
    public function call(
        string $toolName
    ): A1cSeriesResult|ActiveProblemsResult|MedicationsResult|RecentEncountersResult {
        $result = match ($toolName) {
            'get_a1c_series'        => $this->a1cSeries(),
            'get_active_problems'   => $this->activeProblems(),
            'get_medications'       => $this->medications(),
            'get_recent_encounters' => $this->recentEncounters(),
            default                 => $this->rejectUnknownTool($toolName),
        };

        $this->audit(
            $toolName,
            $result->ok,
            $result->ok
                ? ($result->count() . ' row(s) disclosed')
                : ('tool execution failed: ' . ($result->error ?? 'unknown error')),
        );

        return $result;
    }

    /**
     * There is no code path that reaches this today: the tool runner only
     * ever invokes names drawn from definitions() above. It exists so
     * call()'s match stays exhaustive without a silently-swallowed default,
     * and so a future new tool definition that forgets a matching branch
     * here fails loudly instead of returning a bogus result to the model.
     */
    private function rejectUnknownTool(string $toolName): never
    {
        $this->audit($toolName, false, 'unknown tool requested');

        throw new RuntimeException(sprintf('Unknown Clinical Co-Pilot tool requested: %s', $toolName));
    }

    /**
     * Record the disclosure against the patient, so it is filterable in
     * Reports > Audit Log alongside every other access to this chart.
     *
     * The event name is the filter handle. EventAuditLogger::newEvent() accepts
     * a $log_from argument, but only forwards it to recordLogItem() when it is
     * exactly 'patient-portal' -- every other value is dropped and the column
     * defaults to 'open-emr'. So do not pass one; identify co-pilot activity by
     * this event/category string instead. The correlation id is folded into
     * the free-text comment for the same reason: it is the only field
     * newEvent() does not drop, so it is what lets this entry be found by
     * grepping for the id alongside the PHP error log and clinical_copilot_log.
     */
    private function audit(string $toolName, bool $success, string $detail): void
    {
        EventAuditLogger::getInstance()->newEvent(
            self::AUDIT_EVENT,
            $this->authUser,
            $this->authProvider,
            $success ? 1 : 0,
            sprintf('[%s] %s: %s', $this->correlationId, $toolName, $detail),
            $this->patientId,
        );
    }

    private function a1cSeries(): A1cSeriesResult
    {
        try {
            $records = QueryUtils::fetchRecords(
                'SELECT pres.`date`      AS result_date,
                        pres.`result`    AS value,
                        pres.`units`     AS units,
                        pres.`range`     AS reference_range,
                        pres.`abnormal`  AS abnormal_flag
                   FROM `procedure_result` pres
                   JOIN `procedure_report` prep
                     ON prep.`procedure_report_id` = pres.`procedure_report_id`
                   JOIN `procedure_order` po
                     ON po.`procedure_order_id` = prep.`procedure_order_id`
                  WHERE po.`patient_id` = ?
                    AND pres.`result_code` = ?
                  ORDER BY pres.`date` ASC
                  LIMIT ' . self::MAX_ROWS,
                [$this->patientId, self::LOINC_A1C]
            );
        } catch (SqlQueryException $e) {
            $this->logToolFailure('get_a1c_series', $e);

            return A1cSeriesResult::failed('Could not retrieve that part of the chart.');
        }

        return A1cSeriesResult::ok(array_map(self::mapA1cRow(...), $records));
    }

    /**
     * QueryUtils::fetchRecords() only guarantees `array<mixed>` per row
     * (array-key, not string) -- this is what array_map's contravariance
     * check on the callback actually needs to see; the body still indexes
     * by the string column names the query selects.
     *
     * @param array<array-key, mixed> $row
     */
    private static function mapA1cRow(array $row): A1cResultRow
    {
        return new A1cResultRow(
            resultDate: self::nullableString($row['result_date'] ?? null),
            value: self::nullableString($row['value'] ?? null),
            units: self::nullableString($row['units'] ?? null),
            referenceRange: self::nullableString($row['reference_range'] ?? null),
            abnormalFlag: self::nullableString($row['abnormal_flag'] ?? null),
        );
    }

    private function activeProblems(): ActiveProblemsResult
    {
        try {
            $records = QueryUtils::fetchRecords(
                'SELECT `title`, `diagnosis`, `begdate` AS onset_date, `enddate` AS resolved_date, `outcome`
                   FROM `lists`
                  WHERE `pid` = ?
                    AND `type` = ?
                    AND `activity` = 1
                  ORDER BY `begdate` DESC
                  LIMIT ' . self::MAX_ROWS,
                [$this->patientId, 'medical_problem']
            );
        } catch (SqlQueryException $e) {
            $this->logToolFailure('get_active_problems', $e);

            return ActiveProblemsResult::failed('Could not retrieve that part of the chart.');
        }

        return ActiveProblemsResult::ok(array_map(self::mapActiveProblemRow(...), $records));
    }

    /**
     * QueryUtils::fetchRecords() only guarantees `array<mixed>` per row
     * (array-key, not string) -- this is what array_map's contravariance
     * check on the callback actually needs to see; the body still indexes
     * by the string column names the query selects.
     *
     * @param array<array-key, mixed> $row
     */
    private static function mapActiveProblemRow(array $row): ActiveProblemRow
    {
        return new ActiveProblemRow(
            title: self::nullableString($row['title'] ?? null),
            diagnosis: self::nullableString($row['diagnosis'] ?? null),
            onsetDate: self::nullableString($row['onset_date'] ?? null),
            resolvedDate: self::nullableString($row['resolved_date'] ?? null),
            outcome: self::nullableString($row['outcome'] ?? null),
        );
    }

    private function medications(): MedicationsResult
    {
        try {
            // `active = 1` alone means "not marked discontinued" -- nothing
            // clears it just because end_date has since passed (see
            // AUDIT_Extra.md's Data-Quality Finding 1 and PUNCH_LIST.md
            // 1.4(a)). Excluding rows with a documented past end_date fixes
            // the common case (Synthea-seeded rows carrying a real end_date
            // that active was never flipped off for); MedicationStalenessPolicy
            // separately flags the remaining case of an open-ended row (no
            // end_date at all) whose start_date is itself old enough to
            // doubt.
            $records = QueryUtils::fetchRecords(
                'SELECT `drug`, `dosage`, `form`, `interval`, `route`, `quantity`,
                        `start_date`, `end_date`
                   FROM `prescriptions`
                  WHERE `patient_id` = ?
                    AND `active` = 1
                    AND (`end_date` IS NULL OR `end_date` >= CURDATE())
                  ORDER BY `start_date` DESC
                  LIMIT ' . self::MAX_ROWS,
                [$this->patientId]
            );
        } catch (SqlQueryException $e) {
            $this->logToolFailure('get_medications', $e);

            return MedicationsResult::failed('Could not retrieve that part of the chart.');
        }

        return MedicationsResult::ok(array_map($this->mapMedicationRow(...), $records));
    }

    /**
     * QueryUtils::fetchRecords() only guarantees `array<mixed>` per row
     * (array-key, not string) -- this is what array_map's contravariance
     * check on the callback actually needs to see; the body still indexes
     * by the string column names the query selects.
     *
     * @param array<array-key, mixed> $row
     */
    private function mapMedicationRow(array $row): MedicationRow
    {
        $startDate = self::nullableString($row['start_date'] ?? null);
        $endDate = self::nullableString($row['end_date'] ?? null);

        return new MedicationRow(
            drug: self::nullableString($row['drug'] ?? null),
            dosage: self::nullableString($row['dosage'] ?? null),
            form: self::nullableString($row['form'] ?? null),
            interval: self::nullableString($row['interval'] ?? null),
            route: self::nullableString($row['route'] ?? null),
            quantity: self::nullableString($row['quantity'] ?? null),
            startDate: $startDate,
            endDate: $endDate,
            staleWarning: MedicationStalenessPolicy::warningFor($startDate, $endDate, $this->clock->now()),
        );
    }

    private function recentEncounters(): RecentEncountersResult
    {
        try {
            $records = QueryUtils::fetchRecords(
                'SELECT `date` AS encounter_date, `reason`, `encounter_type_description`
                   FROM `form_encounter`
                  WHERE `pid` = ?
                  ORDER BY `date` DESC
                  LIMIT 20',
                [$this->patientId]
            );
        } catch (SqlQueryException $e) {
            $this->logToolFailure('get_recent_encounters', $e);

            return RecentEncountersResult::failed('Could not retrieve that part of the chart.');
        }

        return RecentEncountersResult::ok(array_map(self::mapRecentEncounterRow(...), $records));
    }

    /**
     * QueryUtils::fetchRecords() only guarantees `array<mixed>` per row
     * (array-key, not string) -- this is what array_map's contravariance
     * check on the callback actually needs to see; the body still indexes
     * by the string column names the query selects.
     *
     * @param array<array-key, mixed> $row
     */
    private static function mapRecentEncounterRow(array $row): RecentEncounterRow
    {
        return new RecentEncounterRow(
            encounterDate: self::nullableString($row['encounter_date'] ?? null),
            reason: self::nullableString($row['reason'] ?? null),
            encounterTypeDescription: self::nullableString($row['encounter_type_description'] ?? null),
        );
    }

    /**
     * Log a query failure (the exception can carry SQL detail, so it goes to
     * PSR-3 context and never further) so callers can return their own
     * failed() DTO and let the model see only a generic message.
     */
    private function logToolFailure(string $toolName, SqlQueryException $e): void
    {
        ServiceContainer::getLogger()->error('Clinical Co-Pilot tool failed', [
            'correlationId' => $this->correlationId,
            'tool' => $toolName,
            'exception' => $e,
        ]);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
