<?php

/**
 * The guideline-evidence specialist Supervisor's `consult_evidence_worker`
 * tool delegates to. Owns the hybrid-RAG search_guideline_evidence tool --
 * wraps ChartContextTools' dispatch (see its class docblock's third
 * invariant) rather than reimplementing retrieval here; this class is a
 * dispatch grouping, not a new retrieval layer.
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
use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\MedicationsResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\RecentEncountersResult;

final readonly class EvidenceRetrieverWorker
{
    /**
     * @var list<string>
     */
    public const OWNED_TOOLS = ['search_guideline_evidence'];

    public function __construct(private ChartContextTools $tools)
    {
    }

    /**
     * See ChartQaWorker::consult()'s docblock for why the return type is
     * ChartContextTools::call()'s full union rather than narrowed with an
     * inline @var cast. Unlike the other two workers, this one has a
     * model-supplied argument to pass through -- the search query.
     *
     * @return array<string, A1cSeriesResult|ActiveProblemsResult|MedicationsResult|RecentEncountersResult|ExtractedDocumentsResult|GuidelineEvidenceResult>
     */
    public function consult(string $query): array
    {
        $results = [];
        foreach (self::OWNED_TOOLS as $name) {
            $results[$name] = $this->tools->call($name, $query);
        }

        return $results;
    }
}
