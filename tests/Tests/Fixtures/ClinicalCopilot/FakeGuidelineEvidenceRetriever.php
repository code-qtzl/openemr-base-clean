<?php

/**
 * Test double for GuidelineEvidenceRetriever: returns a pre-scripted result
 * instead of calling Voyage/the DB. Lets Supervisor-level tests exercise
 * consult_evidence_worker/search_guideline_evidence deterministically, with
 * no network access and no real API key -- the same role
 * ScriptedAnthropicClientFactory plays for the LLM call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

require_once __DIR__ . '/../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Evidence/GuidelineEvidenceRetriever.php';

use OpenEMR\Modules\ClinicalCopilot\Service\Evidence\GuidelineEvidenceRetriever;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceResult;

final class FakeGuidelineEvidenceRetriever implements GuidelineEvidenceRetriever
{
    /** @var list<string> Every query this fake was asked to retrieve, in order. */
    private array $queries = [];

    public function __construct(private readonly GuidelineEvidenceResult $result)
    {
    }

    public function retrieve(string $query, string $correlationId): GuidelineEvidenceResult
    {
        $this->queries[] = $query;

        return $this->result;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->queries;
    }
}
