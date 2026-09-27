<?php

/**
 * Thin client for the two Voyage AI endpoints the guideline-evidence
 * retriever needs: embeddings (dense retrieval) and rerank (final
 * relevance ordering). Talks through an injected PSR-18 ClientInterface
 * rather than a bespoke SDK, matching this project's own small surface area
 * -- two endpoints, plain JSON in and out, nothing a full SDK would earn
 * its weight for.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Evidence;

use GuzzleHttp\Psr7\Request;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

final readonly class VoyageClient
{
    private const BASE_URI = 'https://api.voyageai.com/v1/';

    /**
     * Model names as of this writing -- Voyage's catalog changes over time,
     * so verify these are still current/recommended before relying on them
     * long-term rather than trusting this comment indefinitely.
     */
    private const EMBED_MODEL = 'voyage-3-large';
    private const RERANK_MODEL = 'rerank-2';

    public function __construct(
        private ClientInterface $http,
        private string $apiKey,
        private string $correlationId,
    ) {
    }

    /**
     * @param list<string> $texts
     * @param string $inputType 'query' or 'document', per Voyage's API --
     *                          asymmetric embedding models produce different
     *                          vectors depending on which side of a search a
     *                          text is on.
     * @return list<list<float>> One embedding vector per input text, in the
     *                           same order the texts were given.
     */
    public function embed(array $texts, string $inputType): array
    {
        $response = $this->post('embeddings', [
            'input' => $texts,
            'model' => self::EMBED_MODEL,
            'input_type' => $inputType,
        ]);

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Voyage embeddings response missing "data".');
        }

        usort($data, static fn (mixed $a, mixed $b): int => self::intField($a, 'index') <=> self::intField($b, 'index'));

        return array_map(
            static function (mixed $item): array {
                if (!is_array($item)) {
                    throw new RuntimeException('Voyage embeddings response item was not an object.');
                }

                $embedding = $item['embedding'] ?? null;
                if (!is_array($embedding)) {
                    throw new RuntimeException('Voyage embeddings response item missing "embedding".');
                }

                return array_values(array_map(self::floatFromMixed(...), $embedding));
            },
            $data,
        );
    }

    /**
     * @param list<string> $documents
     * @return list<array{index: int, relevanceScore: float}> Ranked
     *                                                         best-first,
     *                                                         truncated to
     *                                                         $topK.
     */
    public function rerank(string $query, array $documents, int $topK): array
    {
        if ($documents === []) {
            return [];
        }

        $response = $this->post('rerank', [
            'query' => $query,
            'documents' => $documents,
            'model' => self::RERANK_MODEL,
            'top_k' => $topK,
        ]);

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Voyage rerank response missing "data".');
        }

        return array_values(array_map(
            static function (mixed $item): array {
                if (!is_array($item)) {
                    throw new RuntimeException('Voyage rerank response item was not an object.');
                }

                return [
                    'index' => self::intField($item, 'index'),
                    'relevanceScore' => self::floatFromMixed($item['relevance_score'] ?? 0.0),
                ];
            },
            $data,
        ));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode Voyage API request body.', previous: $e);
        }

        $request = new Request(
            'POST',
            self::BASE_URI . $path,
            [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                VoyageClientFactory::CORRELATION_HEADER => $this->correlationId,
            ],
            $encoded,
        );

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(sprintf('Voyage API request to %s failed.', $path), previous: $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Voyage API response from %s was not valid JSON.', $path), previous: $e);
        }

        if ($status >= 400 || !is_array($decoded)) {
            throw new RuntimeException(sprintf('Voyage API request to %s failed with status %d.', $path, $status));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function floatFromMixed(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function intField(mixed $item, string $key): int
    {
        if (!is_array($item) || !is_int($item[$key] ?? null)) {
            return 0;
        }

        return $item[$key];
    }
}
