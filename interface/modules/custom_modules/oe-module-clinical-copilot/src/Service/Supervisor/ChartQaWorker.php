<?php

/**
 * The chart-data specialist Supervisor's `consult_chart_worker` tool
 * delegates to. Owns the four original structured-chart tools -- wraps
 * ChartContextTools' already-tested query logic rather than reimplementing
 * it; this class is a dispatch grouping, not a new data-access layer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Supervisor;

use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\A1cSeriesResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ActiveProblemsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\ExtractedDocumentsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncountersResult;

final readonly class ChartQaWorker
{
    /**
     * @var list<string>
     */
    public const OWNED_TOOLS = [
        'get_a1c_series',
        'get_active_problems',
        'get_medications',
        'get_recent_encounters',
    ];

    public function __construct(private ChartContextTools $tools)
    {
    }

    /**
     * Return type matches ChartContextTools::call()'s full union rather
     * than narrowing it with an inline @var cast -- OWNED_TOOLS never
     * includes 'get_extracted_documents' so an ExtractedDocumentsResult
     * never actually appears here, but the type system has no way to know
     * that from a fixed string list without a cast, and this project
     * avoids those in favor of an honest, slightly wider declared type.
     *
     * @return array<string, A1cSeriesResult|ActiveProblemsResult|MedicationsResult|RecentEncountersResult|ExtractedDocumentsResult>
     */
    public function consult(): array
    {
        $results = [];
        foreach (self::OWNED_TOOLS as $name) {
            $results[$name] = $this->tools->call($name);
        }

        return $results;
    }
}
