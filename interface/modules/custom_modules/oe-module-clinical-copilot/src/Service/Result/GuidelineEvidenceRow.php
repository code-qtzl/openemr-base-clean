<?php

/**
 * One reranked guideline-evidence chunk returned by search_guideline_evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Result;

final readonly class GuidelineEvidenceRow implements ToolResultRow
{
    public function __construct(
        public ?string $sourceId,
        public ?string $sourceLabel,
        public ?string $section,
        public ?string $chunkId,
        public ?string $chunkText,
        public ?string $rerankScore,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_label' => $this->sourceLabel,
            'section' => $this->section,
            'chunk_id' => $this->chunkId,
            'chunk_text' => $this->chunkText,
            'rerank_score' => $this->rerankScore,
        ];
    }
}
