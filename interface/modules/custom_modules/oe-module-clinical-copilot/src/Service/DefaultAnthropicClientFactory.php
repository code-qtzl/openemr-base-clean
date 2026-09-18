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
use Http\Discovery\Psr18ClientDiscovery;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\RetryCountingTransporter;

final class DefaultAnthropicClientFactory implements AnthropicClientFactory
{
    private ?RetryCountingTransporter $transporter = null;

    public function create(string $apiKey, string $correlationId): Client
    {
        $this->transporter = new RetryCountingTransporter(Psr18ClientDiscovery::find());

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
