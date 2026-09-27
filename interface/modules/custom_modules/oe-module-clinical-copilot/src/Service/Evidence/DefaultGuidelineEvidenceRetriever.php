<?php

/**
 * Hybrid (sparse+dense, reranked) retrieval over the small guideline corpus
 * seeded by bin/seed-guideline-corpus.php into clinical_copilot_guideline_chunk.
 *
 * Dense side: brute-force cosine similarity computed in PHP over every row's
 * stored embedding -- no vector-store infra, deliberately, since the corpus
 * is explicitly small (a handful of drug labels' worth of chunks). Sparse
 * side: a MariaDB FULLTEXT search over the same chunk text. The two ranked
 * lists are fused via Reciprocal Rank Fusion (k=60, the standard constant)
 * rather than a weighted score blend, because cosine similarity and
 * MariaDB's MATCH...AGAINST relevance score live on incompatible scales with
 * no principled shared normalization -- RRF only needs ranks, not comparable
 * scores, so there is nothing to tune or get wrong here. The fused
 * candidates are then reranked by Voyage's rerank endpoint for the final
 * ordering, and only the top few are returned.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Evidence;

use JsonException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Result\GuidelineEvidenceRow;
use RuntimeException;

final readonly class DefaultGuidelineEvidenceRetriever implements GuidelineEvidenceRetriever
{
    private const TABLE = 'clinical_copilot_guideline_chunk';

    /** Standard Reciprocal Rank Fusion smoothing constant. */
    private const RRF_K = 60;

    /** How many fused candidates go into the (paid) rerank call. */
    private const FUSED_CANDIDATE_LIMIT = 20;

    /** Final evidence chunks returned to the model. */
    private const FINAL_TOP_K = 5;

    public function __construct(
        private VoyageClientFactory $voyageClientFactory = new DefaultVoyageClientFactory(),
    ) {
    }

    public function retrieve(string $query, string $correlationId): GuidelineEvidenceResult
    {
        $apiKey = self::apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Voyage API key is not configured (OPENEMR__VOYAGE_API_KEY).');
        }

        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `id`, `source_id`, `source_label`, `section`, `chunk_index`, `chunk_text`, `embedding_json`
                   FROM `' . self::TABLE . '`'
            );
        } catch (SqlQueryException $e) {
            throw new RuntimeException('Could not load the guideline corpus.', previous: $e);
        }

        if ($rows === []) {
            return GuidelineEvidenceResult::ok([]);
        }

        $voyage = $this->voyageClientFactory->create($apiKey, $correlationId);
        $queryVector = $voyage->embed([$query], inputType: 'query')[0] ?? [];

        $denseRanking = self::rankByCosineSimilarity($queryVector, $rows);
        $sparseRanking = self::rankByFullText($query);
        $fusedIds = self::fuseRankings($denseRanking, $sparseRanking, self::FUSED_CANDIDATE_LIMIT);

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[self::intFromMixed($row['id'] ?? null)] = $row;
        }

        $candidateRows = [];
        foreach ($fusedIds as $id) {
            if (isset($rowsById[$id])) {
                $candidateRows[] = $rowsById[$id];
            }
        }

        if ($candidateRows === []) {
            return GuidelineEvidenceResult::ok([]);
        }

        $chunkTexts = array_map(static fn (array $row): string => self::stringFromMixed($row['chunk_text'] ?? ''), $candidateRows);
        $reranked = $voyage->rerank($query, $chunkTexts, self::FINAL_TOP_K);

        $evidenceRows = [];
        foreach ($reranked as $result) {
            $row = $candidateRows[$result['index']] ?? null;
            if ($row === null) {
                continue;
            }

            $evidenceRows[] = new GuidelineEvidenceRow(
                sourceId: self::nullableString($row['source_id'] ?? null),
                sourceLabel: self::nullableString($row['source_label'] ?? null),
                section: self::nullableString($row['section'] ?? null),
                chunkId: 'chunk-' . self::stringFromMixed($row['chunk_index'] ?? ''),
                chunkText: self::nullableString($row['chunk_text'] ?? null),
                rerankScore: number_format($result['relevanceScore'], 4),
            );
        }

        return GuidelineEvidenceResult::ok($evidenceRows);
    }

    /**
     * @param list<float> $queryVector
     * @param list<array<array-key, mixed>> $rows
     * @return list<int> Row ids, best (highest cosine similarity) first.
     */
    private static function rankByCosineSimilarity(array $queryVector, array $rows): array
    {
        $scored = [];
        foreach ($rows as $row) {
            $embeddingJson = $row['embedding_json'] ?? null;
            if (!is_string($embeddingJson)) {
                continue;
            }

            try {
                $decoded = json_decode($embeddingJson, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $vector = array_values(array_map(self::floatFromMixed(...), $decoded));
            $scored[self::intFromMixed($row['id'] ?? null)] = self::cosineSimilarity($queryVector, $vector);
        }

        arsort($scored, SORT_NUMERIC);

        return array_keys($scored);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** @return list<int> Row ids, best (highest MATCH relevance) first. */
    private static function rankByFullText(string $query): array
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `id`
                   FROM `' . self::TABLE . '`
                  WHERE MATCH(`chunk_text`) AGAINST (? IN NATURAL LANGUAGE MODE)
                  ORDER BY MATCH(`chunk_text`) AGAINST (? IN NATURAL LANGUAGE MODE) DESC
                  LIMIT ' . self::FUSED_CANDIDATE_LIMIT,
                [$query, $query],
            );
        } catch (SqlQueryException) {
            // A FULLTEXT miss/failure degrades to dense-only ranking rather
            // than failing the whole retrieval -- the fusion step below
            // handles an empty sparse ranking list fine.
            return [];
        }

        return array_map(static fn (array $row): int => self::intFromMixed($row['id'] ?? null), $rows);
    }

    /**
     * @param list<int> $denseRanking
     * @param list<int> $sparseRanking
     * @return list<int> Fused row ids, best first, truncated to $limit.
     */
    private static function fuseRankings(array $denseRanking, array $sparseRanking, int $limit): array
    {
        $scores = [];
        foreach ($denseRanking as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
        }
        foreach ($sparseRanking as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
        }

        arsort($scores, SORT_NUMERIC);

        return array_slice(array_keys($scores), 0, $limit);
    }

    private static function apiKey(): ?string
    {
        $key = OEEnvBag::getInstance()->getString('OPENEMR__VOYAGE_API_KEY');

        return $key !== '' ? $key : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    private static function intFromMixed(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    private static function floatFromMixed(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
