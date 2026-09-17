<?php

/**
 * LangfuseCheck - Verifies the Langfuse observability backend is reachable
 * (if the Clinical Co-Pilot module's tracing credentials are configured)
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

final class LangfuseCheck implements HealthCheckInterface
{
    public const NAME = 'langfuse';

    /**
     * Standard (non-HIPAA-BAA) US Langfuse Cloud region -- see the project's
     * "LangFuse region decision" memory: this fork only ever sends synthetic
     * Synthea data through the co-pilot, so the cheaper standard region
     * (rather than hipaa.cloud.langfuse.com) is sufficient.
     */
    private const DEFAULT_HOST = 'https://us.cloud.langfuse.com';

    private const TIMEOUT_SECONDS = 3.0;

    /** See AnthropicApiCheck's constructor docblock for why these are nullable. */
    public function __construct(
        private readonly ?string $publicKey = null,
        private readonly ?string $secretKey = null,
        private readonly ?string $host = null,
        private readonly ?ClientInterface $transporter = null,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function check(): HealthCheckResult
    {
        $env = OEEnvBag::getInstance();
        $publicKey = $this->publicKey ?? $env->getString('OPENEMR__LANGFUSE_PUBLIC_KEY');
        $secretKey = $this->secretKey ?? $env->getString('OPENEMR__LANGFUSE_SECRET_KEY');

        if ($publicKey === '' || $secretKey === '') {
            return new HealthCheckResult($this->getName(), true, 'Not configured');
        }

        $host = $this->host ?? $env->getString('OPENEMR__LANGFUSE_HOST', self::DEFAULT_HOST);
        $transporter = $this->transporter ?? new GuzzleClient([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
        ]);

        try {
            $response = $transporter->sendRequest(new Request(
                'GET',
                rtrim($host, '/') . '/api/public/health',
                ['Authorization' => 'Basic ' . base64_encode($publicKey . ':' . $secretKey)],
            ));
        } catch (ClientExceptionInterface $e) {
            return new HealthCheckResult($this->getName(), false, 'Langfuse unreachable');
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return new HealthCheckResult($this->getName(), true);
        }

        return new HealthCheckResult($this->getName(), false, sprintf('Langfuse returned HTTP %d', $status));
    }
}
