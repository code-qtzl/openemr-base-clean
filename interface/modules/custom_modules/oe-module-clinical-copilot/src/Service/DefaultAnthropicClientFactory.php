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

final class DefaultAnthropicClientFactory implements AnthropicClientFactory
{
    public function create(string $apiKey, string $correlationId): Client
    {
        return new Client(
            apiKey: $apiKey,
            requestOptions: ['extraHeaders' => [self::CORRELATION_HEADER => $correlationId]],
        );
    }
}
