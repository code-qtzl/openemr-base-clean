<?php

/**
 * Fake PSR-18 HTTP client that plays back a pre-scripted sequence of
 * Anthropic Messages API response bodies.
 *
 * CopilotService::ask()'s tool loop (Anthropic\Lib\Tools\BetaToolRunner)
 * drives its request/execute/loop cycle through plain, non-streaming POSTs
 * via the SDK's PSR-18 transporter -- so a fake transporter that returns
 * canned JSON responses per call is enough to script a full multi-turn
 * conversation deterministically, with no network access and no real API key.
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
use RuntimeException;

final class FakeAnthropicTransporter implements ClientInterface
{
    private int $index = 0;

    /** @var list<string> Raw body of every request this transporter has sent, in order. */
    private array $sentBodies = [];

    /** @param list<array<string, mixed>> $responses One BetaMessage-shaped body per expected create() call. */
    public function __construct(private readonly array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sentBodies[] = (string) $request->getBody();

        if (!isset($this->responses[$this->index])) {
            throw new RuntimeException(sprintf(
                'FakeAnthropicTransporter: asked for response #%d but only %d were scripted -- '
                    . 'the fake conversation ran longer than the test scripted it.',
                $this->index + 1,
                count($this->responses),
            ));
        }

        $body = $this->responses[$this->index];
        ++$this->index;

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * Every request body this transporter has sent so far -- lets a test
     * inspect exactly what data flowed to the (fake) model across the whole
     * conversation, including tool_result content from earlier turns that
     * get_recent_encounters()-style tools embed in the next request.
     *
     * @return list<string>
     */
    public function sentBodies(): array
    {
        return $this->sentBodies;
    }
}
