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

    /**
     * @param ?float $timeoutSeconds Per-request timeout; null uses the factory's
     *                               default (sized for a chat turn). Document
     *                               extraction passes a longer one because a
     *                               full lab panel is a much larger response.
     */
    public function create(string $apiKey, string $correlationId, ?float $timeoutSeconds = null): Client;

    /**
     * Number of SDK-level retries the most recent create()'d client's calls
     * made, for PUNCH_LIST.md 3.3's retry-count dashboard metric. 0 until
     * create() has been called, and after every call that made none.
     */
    public function retryCount(): int;

    /**
     * Register a callback to be invoked periodically while a create()'d
     * client's HTTP call to Anthropic is in flight (Appendix_CheckList.md
     * Phase 3 Item 15's connection-timeout gap: a fully-synchronous,
     * non-streaming chat request sends zero bytes to the browser until the
     * whole multi-tool-call conversation finishes, which a reverse proxy
     * with no view into that work can and does mistake for a dead
     * connection and close early -- confirmed against the real Railway
     * deployment, where the backend completed a request successfully in
     * ~17s but the browser had already errored out).
     *
     * The production implementation wires this into the transport's
     * progress reporting so it fires roughly once per second even while no
     * bytes have moved yet, letting the caller (CopilotChatController) push
     * keep-alive bytes to the client during the wait. Pass null to clear a
     * previously registered callback. A no-op in test doubles, whose fake
     * transporters resolve instantly and have nothing to report progress on.
     */
    public function setHeartbeat(?callable $onTick): void;
}
