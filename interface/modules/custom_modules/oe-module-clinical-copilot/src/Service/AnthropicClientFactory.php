<?php

/**
 * Seam for building the Anthropic SDK client CopilotService talks to.
 *
 * CopilotService depends on this interface instead of constructing
 * Anthropic\Client itself, so tests can supply a factory that returns a
 * client wired to a fake transporter -- the SDK's tool runner drives its
 * whole request/execute/loop cycle through plain PSR-18 HTTP calls, so a
 * fake transporter is enough to script a full conversation deterministically,
 * with no network access and no real API key.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use Anthropic\Client;

interface AnthropicClientFactory
{
    /**
     * Outbound header carrying the request's correlation id on every call to
     * the Anthropic API, so a trace can be tied back to a specific request
     * from either side (this application's logs, or Anthropic's own).
     */
    public const CORRELATION_HEADER = 'X-Correlation-Id';

    public function create(string $apiKey, string $correlationId): Client;

    /**
     * Number of SDK-level retries the most recent create()'d client's calls
     * made, for PUNCH_LIST.md 3.3's retry-count dashboard metric. 0 until
     * create() has been called, and after every call that made none.
     */
    public function retryCount(): int;
}
