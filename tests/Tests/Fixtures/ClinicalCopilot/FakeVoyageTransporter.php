<?php

/**
 * Fake PSR-18 HTTP client that plays back pre-scripted Voyage API response
 * bodies, routed by endpoint path (embeddings vs. rerank) rather than call
 * order, since a single retrieve() call makes one embed call and one rerank
 * call to different endpoints -- unlike FakeAnthropicTransporter's single
 * linear turn sequence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class FakeVoyageTransporter implements ClientInterface
{
    private int $embedIndex = 0;
    private int $rerankIndex = 0;

    /** @var list<string> Raw body of every request this transporter has sent, in order. */
    private array $sentBodies = [];

    /**
     * @param list<array<string, mixed>> $embedResponses One response body per expected embed() call.
     * @param list<array<string, mixed>> $rerankResponses One response body per expected rerank() call.
     */
    public function __construct(
        private readonly array $embedResponses,
        private readonly array $rerankResponses,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sentBodies[] = (string) $request->getBody();
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/embeddings')) {
            if (!isset($this->embedResponses[$this->embedIndex])) {
                throw new RuntimeException('FakeVoyageTransporter: no more scripted embed() responses.');
            }

            $body = $this->embedResponses[$this->embedIndex];
            ++$this->embedIndex;
        } elseif (str_ends_with($path, '/rerank')) {
            if (!isset($this->rerankResponses[$this->rerankIndex])) {
                throw new RuntimeException('FakeVoyageTransporter: no more scripted rerank() responses.');
            }

            $body = $this->rerankResponses[$this->rerankIndex];
            ++$this->rerankIndex;
        } else {
            throw new RuntimeException("FakeVoyageTransporter: unexpected Voyage endpoint '{$path}'.");
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function sentBodies(): array
    {
        return $this->sentBodies;
    }
}
