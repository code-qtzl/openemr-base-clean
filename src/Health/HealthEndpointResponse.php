<?php

/**
 * Builds the JSON responses meta/health/index.php's /readyz route sends,
 * separated out from that script so both branches -- a completed check run,
 * and the endpoint's own bootstrap throwing -- are unit-testable without
 * requiring interface/globals.php's DB-touching bootstrap.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Health;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HealthEndpointResponse
{
    /**
     * @param array{status: string, healthy: bool, checks: array<string, bool>} $results
     *                                                                          HealthChecker::getResultsArray()'s
     *                                                                          output.
     */
    public static function forCheckerResults(array $results): JsonResponse
    {
        return new JsonResponse($results, $results['healthy'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * Fail closed: an orchestrator (k8s, Railway, ...) only ever looks at
     * the HTTP status code, not the JSON body, so a 200 here would report
     * this instance as ready/alive while it is actually broken enough to
     * have thrown. Exception messages can carry internal detail (SQL, file
     * paths); log with PSR-3 context, return a generic message.
     */
    public static function forException(Throwable $e, LoggerInterface $logger): JsonResponse
    {
        $logger->error('Health check endpoint threw', ['exception' => $e]);

        return new JsonResponse(
            ['status' => 'error', 'message' => 'Health check failed'],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
