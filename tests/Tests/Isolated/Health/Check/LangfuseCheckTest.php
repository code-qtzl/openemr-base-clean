<?php

/**
 * Isolated LangfuseCheck Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Health\Check;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Health\Check\LangfuseCheck;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class LangfuseCheckTest extends TestCase
{
    public function testNotConfiguredIsReportedHealthy(): void
    {
        $check = new LangfuseCheck(publicKey: '', secretKey: '');

        $result = $check->check();

        self::assertTrue($result->healthy);
        self::assertSame('Not configured', $result->message);
    }

    public function testPartiallyConfiguredIsReportedHealthy(): void
    {
        // Only one of the pair set is not a real "on" state; don't fail
        // readiness over a half-finished .env edit.
        $check = new LangfuseCheck(publicKey: 'pk-lf-test', secretKey: '');

        self::assertTrue($check->check()->healthy);
    }

    public function test2xxResponseIsHealthy(): void
    {
        $transporter = self::fakeTransporter(new Response(200, [], '{"status":"OK"}'));

        $check = new LangfuseCheck(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        self::assertTrue($check->check()->healthy);
    }

    public function testErrorStatusIsUnhealthy(): void
    {
        $transporter = self::fakeTransporter(new Response(503, [], '{"status":"error"}'));

        $check = new LangfuseCheck(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $result = $check->check();

        self::assertFalse($result->healthy);
        self::assertNotNull($result->message);
    }

    public function testUnreachableTransportIsUnhealthy(): void
    {
        $transporter = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('Could not resolve host') extends \RuntimeException implements
                    ClientExceptionInterface {
                };
            }
        };

        $check = new LangfuseCheck(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

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
