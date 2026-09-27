<?php

/**
 * Seam for ChartContextTools' search_guideline_evidence branch to retrieve
 * hybrid (sparse+dense, reranked) guideline evidence without depending on
 * the concrete Voyage-backed implementation directly -- lets tests supply a
 * fake with no network access and no real API key.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Evidence;

use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceResult;

interface GuidelineEvidenceRetriever
{
    /**
     * @throws \RuntimeException On any retrieval failure (missing API key,
     *                           DB failure, Voyage API failure) -- the
     *                           caller (ChartContextTools) is responsible
     *                           for catching this and degrading to
     *                           GuidelineEvidenceResult::failed(), matching
     *                           every other tool branch's failure handling.
     */
    public function retrieve(string $query, string $correlationId): GuidelineEvidenceResult;
}
