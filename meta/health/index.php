<?php

/**
 * Health Check Entry Point
 *
 * Provides liveness and readiness probe endpoints for Kubernetes and Docker.
 * Access restriction should be configured at the infrastructure level.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Load autoloader
require_once __DIR__ . "/../../vendor/autoload.php";

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Health\HealthChecker;
use OpenEMR\Health\HealthEndpointResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$request = Request::createFromGlobals();

try {
    $pathInfo = $request->getPathInfo();

    // Handle /livez - minimal check, just verify PHP is running
    if (str_ends_with($pathInfo, '/livez')) {
        $response = new JsonResponse(['status' => 'alive']);
        $response->send();
        return;
    }

    // Handle /readyz - full health check with component status
    if (str_ends_with($pathInfo, '/readyz')) {
        // Set up site context - default to "default" site
        $siteId = 'default';
        $_GET['site'] = $siteId;

        // Load sqlconf.php first to check installation status
        $sqlconfPath = __DIR__ . "/../../sites/{$siteId}/sqlconf.php";
        if (file_exists($sqlconfPath)) {
            require_once $sqlconfPath;
        }

        // Check if OpenEMR is installed ($config is set in sqlconf.php)
        global $config;
        $isInstalled = isset($config) && $config === 1;

        if ($isInstalled) {
            // Bootstrap OpenEMR globals (this sets up database connection)
            // NOTE: This loads the full OpenEMR framework on each probe request.
            // For high-frequency probes, a lighter bootstrap path that only
            // initializes the database connection could improve performance.
            //
            // Skip authentication - health checks must work without a session
            $ignoreAuth = true;
            // Skip audit logging - health checks should not pollute the audit log
            $skipAuditLog = true;
            // Allow session writes — each probe starts a fresh session (no cookie)
            // so globals.php always writes site_id, triggering a read_and_close
            // reopen. Marking writable avoids that reopen and its log warning.
            $sessionAllowWrite = true;
            require_once __DIR__ . "/../../interface/globals.php";

            // Run full health checks. A non-2xx status here is what actually
            // makes an orchestrator (k8s, Railway, ...) treat the instance as
            // not ready -- the JSON body's `status`/`healthy` fields alone
            // are not enough, since a probe checks the HTTP status code.
            $checker = new HealthChecker();
            $response = HealthEndpointResponse::forCheckerResults($checker->getResultsArray());
        } else {
            // Not installed - return minimal response
            $response = new JsonResponse([
                'status' => 'setup_required',
                'checks' => [
                    'installed' => false,
                ],
            ]);
        }

        $response->send();
        return;
    }

    // Unknown endpoint - return 404
    $response = new JsonResponse(
        ['error' => 'Not found', 'message' => 'Valid endpoints are /livez and /readyz'],
        Response::HTTP_NOT_FOUND
    );
    $response->send();
} catch (\Throwable $e) {
    // Fail closed: an orchestrator (k8s, Railway, ...) only ever looks at
    // the HTTP status code, not the JSON body, so a 200 here would report
    // this instance as ready/alive while it's actually broken enough to
    // have thrown. See HealthEndpointResponse::forException() for why this
    // never surfaces $e's own message.
    $response = HealthEndpointResponse::forException($e, ServiceContainer::getLogger());
    $response->send();
}
