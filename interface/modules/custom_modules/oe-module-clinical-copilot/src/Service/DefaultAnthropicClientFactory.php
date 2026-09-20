<?php

/**
 * Production AnthropicClientFactory: builds a real Anthropic\Client.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use Anthropic\Client;
use GuzzleHttp\Client as GuzzleClient;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\RetryCountingTransporter;

final class DefaultAnthropicClientFactory implements AnthropicClientFactory
{
    /**
     * Seconds to wait for a TCP connection to Anthropic before giving up.
     * Short on purpose -- a slow DNS/network path should fail fast, not
     * consume most of the request timeout just connecting.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    /**
     * Seconds to wait for one HTTP round trip to Anthropic before giving up.
     * `RequestOptions::$timeout`'s own docblock says its 600s default is
     * "advisory only: the timeout is enforced by the caller-supplied
     * transport, not by this SDK" -- and the transport this factory used to
     * hand it (Psr18ClientDiscovery::find(), an unconfigured Guzzle client)
     * has no timeout of its own either, so a stalled connection to Anthropic
     * would previously hang for as long as the surrounding PHP process
     * allows, not 600s. 60s is well above PERFORMANCE_BASELINE.md's observed
     * p99 for a full multi-tool-call conversation (18.9s across up to eight
     * sequential Anthropic requests), so a normal slow call still completes;
     * this only bounds a genuinely hung one.
     */
    private const REQUEST_TIMEOUT_SECONDS = 60.0;

    private ?RetryCountingTransporter $transporter = null;

    public function create(string $apiKey, string $correlationId): Client
    {
        $this->transporter = new RetryCountingTransporter(new GuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ]));

        return new Client(
            apiKey: $apiKey,
            requestOptions: [
                'extraHeaders' => [self::CORRELATION_HEADER => $correlationId],
                'transporter' => $this->transporter,
            ],
        );
    }

    public function retryCount(): int
    {
        return $this->transporter?->retryCount() ?? 0;
    }
}
