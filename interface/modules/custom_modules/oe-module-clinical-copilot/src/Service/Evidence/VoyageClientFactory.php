<?php

/**
 * Seam for building the PSR-18 HTTP client VoyageClient talks through.
 *
 * Mirrors AnthropicClientFactory's role for the Anthropic SDK: production
 * code depends on this interface instead of constructing a Guzzle client
 * directly, so tests can supply a factory backed by a fake PSR-18
 * transporter -- no network access, no real API key.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Evidence;

interface VoyageClientFactory
{
    /**
     * Outbound header carrying the request's correlation id on every call to
     * Voyage, matching AnthropicClientFactory::CORRELATION_HEADER's purpose
     * (PUNCH_LIST.md's cross-service-boundary correlation-id requirement).
     */
    public const CORRELATION_HEADER = 'X-Correlation-Id';

    public function create(string $apiKey, string $correlationId): VoyageClient;
}
