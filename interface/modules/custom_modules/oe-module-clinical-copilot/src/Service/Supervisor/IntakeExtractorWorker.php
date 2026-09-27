<?php

/**
 * The document-data specialist Supervisor's `consult_document_worker` tool
 * delegates to. Named to match AgentForge2's "intake-extractor worker"
 * terminology, even though its current capability is document *reading*
 * (get_extracted_documents) rather than live extraction -- a chat turn is
 * text-only and cannot carry file bytes, so extraction itself stays
 * upload-triggered (CopilotDocumentUploadController ->
 * DocumentIngestionPipeline). This is the natural home for a future
 * chat-triggered extraction capability if one is ever added; a documented
 * scoping choice, not a silent gap.
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

final readonly class IntakeExtractorWorker
{
    /**
     * @var list<string>
     */
    public const OWNED_TOOLS = ['get_extracted_documents'];

    public function __construct(private ChartContextTools $tools)
    {
    }

    /**
     * See ChartQaWorker::consult()'s docblock for why the return type is
     * ChartContextTools::call()'s full union rather than narrowed with an
     * inline @var cast.
     *
     * @return array<string, A1cSeriesResult|ActiveProblemsResult|MedicationsResult|RecentEncountersResult|ExtractedDocumentsResult|GuidelineEvidenceResult>
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
