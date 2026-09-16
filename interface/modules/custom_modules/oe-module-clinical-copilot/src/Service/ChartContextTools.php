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
 * Every invocation is written to OpenEMR's audit log with log_from='copilot',
 * so each disclosure is attributable to a user, a patient, and a timestamp.
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

final class ChartContextTools
{
    /** LOINC code for Hemoglobin A1c / Hemoglobin.total in Blood. */
    private const LOINC_A1C = '4548-4';

    /** Upper bound on rows returned by any single tool call. */
    private const MAX_ROWS = 60;

    /** Audit event name; the handle for filtering co-pilot disclosures. */
    private const AUDIT_EVENT = 'clinical-copilot-tool';

    public function __construct(
        private readonly int $patientId,
        private readonly string $authUser,
        private readonly string $authProvider,
    ) {
    }

    /**
     * Tool definitions in Anthropic Messages API shape.
     *
     * Note there is no patient parameter anywhere by design -- see the class
     * docblock. The descriptions tell the model what each tool covers so it
     * requests only what a given question needs.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        $noArgs = ['type' => 'object', 'properties' => (object) [], 'required' => []];

        return [
            [
                'name' => 'get_a1c_series',
                'description' => 'Hemoglobin A1c results for this patient over time, oldest first, '
                    . 'with value, units, reference range and abnormal flag. Use for questions about '
                    . 'glycaemic control, diabetes trajectory, or whether the patient is improving.',
                'inputSchema' => $noArgs,
            ],
            [
                'name' => 'get_active_problems',
                'description' => 'The active problem list for this patient: title, coded diagnosis, '
                    . 'onset date and outcome. Use to establish what conditions the patient carries.',
                'inputSchema' => $noArgs,
            ],
            [
                'name' => 'get_medications',
                'description' => 'Currently active prescriptions: drug, dosage, form, interval, route '
                    . 'and start date. Use for questions about treatment, adherence or therapy changes.',
                'inputSchema' => $noArgs,
            ],
            [
                'name' => 'get_recent_encounters',
                'description' => 'The most recent encounters for this patient: date, reason and '
                    . 'encounter type. Use to establish recent clinical activity or visit history.',
                'inputSchema' => $noArgs,
            ],
        ];
    }

    /**
     * Dispatch one tool call and audit it.
     *
     * @return array{ok: bool, rows?: list<array<mixed>>, count?: int, error?: string}
     */
    public function call(string $toolName): array
    {
        try {
            $rows = match ($toolName) {
                'get_a1c_series'        => $this->a1cSeries(),
                'get_active_problems'   => $this->activeProblems(),
                'get_medications'       => $this->medications(),
                'get_recent_encounters' => $this->recentEncounters(),
                default                 => null,
            };
        } catch (SqlQueryException $e) {
            // The message can carry SQL detail, so it is logged with PSR-3
            // context and never surfaced. The model (and therefore the user)
            // sees only a generic string.
            ServiceContainer::getLogger()->error('Clinical Co-Pilot tool failed', [
                'tool' => $toolName,
                'exception' => $e,
            ]);
            $this->audit($toolName, false, 'tool execution failed');

            return ['ok' => false, 'error' => 'Could not retrieve that part of the chart.'];
        }

        if ($rows === null) {
            $this->audit($toolName, false, 'unknown tool requested');

            return ['ok' => false, 'error' => 'Unknown tool.'];
        }

        $this->audit($toolName, true, count($rows) . ' row(s) disclosed');

        return ['ok' => true, 'count' => count($rows), 'rows' => $rows];
    }

    /**
     * Record the disclosure against the patient, so it is filterable in
     * Reports > Audit Log alongside every other access to this chart.
     *
     * The event name is the filter handle. EventAuditLogger::newEvent() accepts
     * a $log_from argument, but only forwards it to recordLogItem() when it is
     * exactly 'patient-portal' -- every other value is dropped and the column
     * defaults to 'open-emr'. So do not pass one; identify co-pilot activity by
     * this event/category string instead.
     */
    private function audit(string $toolName, bool $success, string $detail): void
    {
        EventAuditLogger::getInstance()->newEvent(
            self::AUDIT_EVENT,
            $this->authUser,
            $this->authProvider,
            $success ? 1 : 0,
            $toolName . ': ' . $detail,
            $this->patientId,
        );
    }

    /** @return list<array<mixed>> */
    private function a1cSeries(): array
    {
        return QueryUtils::fetchRecords(
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
    }

    /** @return list<array<mixed>> */
    private function activeProblems(): array
    {
        return QueryUtils::fetchRecords(
            'SELECT `title`, `diagnosis`, `begdate` AS onset_date, `enddate` AS resolved_date, `outcome`
               FROM `lists`
              WHERE `pid` = ?
                AND `type` = ?
                AND `activity` = 1
              ORDER BY `begdate` DESC
              LIMIT ' . self::MAX_ROWS,
            [$this->patientId, 'medical_problem']
        );
    }

    /** @return list<array<mixed>> */
    private function medications(): array
    {
        return QueryUtils::fetchRecords(
            'SELECT `drug`, `dosage`, `form`, `interval`, `route`, `quantity`,
                    `start_date`, `end_date`
               FROM `prescriptions`
              WHERE `patient_id` = ?
                AND `active` = 1
              ORDER BY `start_date` DESC
              LIMIT ' . self::MAX_ROWS,
            [$this->patientId]
        );
    }

    /** @return list<array<mixed>> */
    private function recentEncounters(): array
    {
        return QueryUtils::fetchRecords(
            'SELECT `date` AS encounter_date, `reason`, `encounter_type_description`
               FROM `form_encounter`
              WHERE `pid` = ?
              ORDER BY `date` DESC
              LIMIT 20',
            [$this->patientId]
        );
    }
}
