<?php

/**
 * AnthropicApiCheck - Verifies the Anthropic API is reachable (if the
 * Clinical Co-Pilot module's API key is configured)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Health\Check;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Health\HealthCheckInterface;
use OpenEMR\Health\HealthCheckResult;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

final class AnthropicApiCheck implements HealthCheckInterface
{
    public const NAME = 'anthropic_api';

    private const TIMEOUT_SECONDS = 3.0;

    /**
     * $apiKey/$transporter default to null so production code
     * (HealthChecker::registerDefaultChecks()) can construct this with no
     * arguments, same as every other check; passing them explicitly is the
     * seam tests use to avoid depending on this process's real environment
     * or making a real network call.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?ClientInterface $transporter = null,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function check(): HealthCheckResult
    {
        $apiKey = $this->apiKey ?? OEEnvBag::getInstance()->getString('OPENEMR__COPILOT_API_KEY');
        if ($apiKey === '') {
            return new HealthCheckResult($this->getName(), true, 'Not configured');
        }

        $transporter = $this->transporter ?? new GuzzleClient([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
        ]);

        try {
            // A models listing is the cheapest authenticated call the API
            // offers -- it proves the key and network path both work without
            // spending tokens on a chat completion.
            $response = $transporter->sendRequest(new Request(
                'GET',
                'https://api.anthropic.com/v1/models?limit=1',
                [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
            ));
        } catch (ClientExceptionInterface $e) {
            return new HealthCheckResult($this->getName(), false, 'Anthropic API unreachable');
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return new HealthCheckResult($this->getName(), true);
        }

        return new HealthCheckResult($this->getName(), false, sprintf('Anthropic API returned HTTP %d', $status));
    }
}
