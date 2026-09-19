<?php

/**
 * Isolated HealthEndpointResponse Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Health;

use OpenEMR\Health\HealthEndpointResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

class HealthEndpointResponseTest extends TestCase
{
    public function testHealthyResultsReturnHttp200(): void
    {
        $response = HealthEndpointResponse::forCheckerResults([
            'status' => 'ready',
            'healthy' => true,
            'checks' => ['database' => true],
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnhealthyResultsReturnHttp503(): void
    {
        $response = HealthEndpointResponse::forCheckerResults([
            'status' => 'ready',
            'healthy' => false,
            'checks' => ['database' => false],
        ]);

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * Failure mode guarded against: PUNCH_LIST_2.md Item 4's readyz gap --
     * an uncaught exception during the health-checker's own bootstrap must
     * fail closed (a non-2xx status an orchestrator actually acts on), not
     * fall through to a default HTTP 200 that reports a broken instance as
     * ready.
     */
    public function testExceptionReturnsHttp503(): void
    {
        $response = HealthEndpointResponse::forException(new RuntimeException('irrelevant'), new NullLogger());

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * Failure mode guarded against: exception messages can carry internal
     * detail (SQL, file paths, stack traces) -- CLAUDE.md's "Never expose
     * $e->getMessage() in user-facing output" applies to this
     * publicly-reachable endpoint just as much as any other.
     */
    public function testExceptionMessageNeverReachesTheResponseBody(): void
    {
        $response = HealthEndpointResponse::forException(
            new RuntimeException('SELECT * FROM users WHERE password_hash = \'leaked\''),
            new NullLogger(),
        );

        $body = (string) $response->getContent();
        self::assertStringNotContainsString('SELECT * FROM users', $body);
        self::assertStringNotContainsString('leaked', $body);
        self::assertStringContainsString('Health check failed', $body);
    }

    public function testExceptionIsLoggedWithPsr3Context(): void
    {
        $exception = new RuntimeException('boom');
        $logger = new class implements LoggerInterface {
            /** @var array<array-key, mixed>|null */
            public ?array $capturedContext = null;

            public function emergency(string|Stringable $message, array $context = []): void
            {
            }

            public function alert(string|Stringable $message, array $context = []): void
            {
            }

            public function critical(string|Stringable $message, array $context = []): void
            {
            }

            public function error(string|Stringable $message, array $context = []): void
            {
                $this->capturedContext = $context;
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
            }

            public function notice(string|Stringable $message, array $context = []): void
            {
            }

            public function info(string|Stringable $message, array $context = []): void
            {
            }

            public function debug(string|Stringable $message, array $context = []): void
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
            }
        };

        HealthEndpointResponse::forException($exception, $logger);

        self::assertSame(['exception' => $exception], $logger->capturedContext);
    }
}
