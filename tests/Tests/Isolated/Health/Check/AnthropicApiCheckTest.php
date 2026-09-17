<?php

/**
 * Isolated AnthropicApiCheck Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Health\Check;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Health\Check\AnthropicApiCheck;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AnthropicApiCheckTest extends TestCase
{
    public function testNotConfiguredIsReportedHealthy(): void
    {
        // An instance that never enabled the co-pilot has no Anthropic
        // dependency to be ready for -- this must not fail /readyz for
        // every OpenEMR install that doesn't use the module.
        $check = new AnthropicApiCheck(apiKey: '');

        $result = $check->check();

        self::assertTrue($result->healthy);
        self::assertSame('Not configured', $result->message);
    }

    public function test2xxResponseIsHealthy(): void
    {
        $transporter = self::fakeTransporter(new Response(200, [], '{"data":[]}'));

        $check = new AnthropicApiCheck(apiKey: 'sk-ant-test', transporter: $transporter);

        self::assertTrue($check->check()->healthy);
    }

    public function testErrorStatusIsUnhealthy(): void
    {
        // A revoked/invalid key still gets a response from Anthropic (401),
        // it just isn't a 2xx -- this must flip readiness.
        $transporter = self::fakeTransporter(new Response(401, [], '{"error":"invalid api key"}'));

        $check = new AnthropicApiCheck(apiKey: 'sk-ant-revoked', transporter: $transporter);

        $result = $check->check();

        self::assertFalse($result->healthy);
        self::assertNotNull($result->message);
    }

    public function testUnreachableTransportIsUnhealthy(): void
    {
        // PUNCH_LIST.md 1.2's "blocking egress to Anthropic" scenario never
        // gets an HTTP response at all -- the transporter throws instead.
        $transporter = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('Could not resolve host') extends \RuntimeException implements
                    ClientExceptionInterface {
                };
            }
        };

        $check = new AnthropicApiCheck(apiKey: 'sk-ant-test', transporter: $transporter);

        self::assertFalse($check->check()->healthy);
    }

    private static function fakeTransporter(ResponseInterface $response): ClientInterface
    {
        return new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
