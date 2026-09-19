<?php

/**
 * Minimal PSR-18 test double that captures the last outgoing HTTP request
 * instead of sending it -- used to inspect LangfuseTracer's raw OTLP
 * request body from an integration test (CopilotChatControllerTest)
 * without hitting the real Langfuse endpoint.
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

final class CapturingHttpTransporter implements ClientInterface
{
    public ?RequestInterface $captured = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return new Response(200);
    }
}
