<?php

/**
 * Counts SDK-level retries of the real Anthropic API call, for PUNCH_LIST.md
 * 3.3's "retry counts" dashboard metric.
 *
 * The Anthropic PHP SDK (Anthropic\Core\BaseClient::sendRequest()) retries a
 * request by recursing and calling the same PSR-18 transporter again, tagging
 * each attempt with an `X-Stainless-Retry-Count` header (0 on the first try,
 * incrementing on each retry) -- verified against the vendored SDK source
 * rather than assumed. Wrapping the real transporter and counting requests
 * whose header value is not "0" therefore counts retries directly, without
 * needing to separately track how many tool-loop iterations happened.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryCountingTransporter implements ClientInterface
{
    private int $retryCount = 0;

    public function __construct(private readonly ClientInterface $delegate)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getHeaderLine('X-Stainless-Retry-Count') !== '' && $request->getHeaderLine('X-Stainless-Retry-Count') !== '0') {
            ++$this->retryCount;
        }

        return $this->delegate->sendRequest($request);
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }
}
