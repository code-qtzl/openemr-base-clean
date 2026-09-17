<?php

/**
 * HealthChecker - Runs all health checks and aggregates results
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Health;

use OpenEMR\Health\Check\AnthropicApiCheck;
use OpenEMR\Health\Check\CacheCheck;
use OpenEMR\Health\Check\DatabaseCheck;
use OpenEMR\Health\Check\FilesystemCheck;
use OpenEMR\Health\Check\InstallationCheck;
use OpenEMR\Health\Check\LangfuseCheck;
use OpenEMR\Health\Check\OAuthKeysCheck;
use OpenEMR\Health\Check\SessionCheck;

class HealthChecker
{
    /** @var HealthCheckInterface[] */
    private array $checks = [];

    /**
     * @param bool $registerDefaultChecks Set false to start with no checks
     *                                    registered -- a seam for unit
     *                                    testing getResultsArray()'s
     *                                    aggregation logic against
     *                                    controlled HealthCheckInterface
     *                                    stubs instead of this class's real,
     *                                    DB/filesystem/network-touching
     *                                    default checks.
     */
    public function __construct(bool $registerDefaultChecks = true)
    {
        if ($registerDefaultChecks) {
            $this->registerDefaultChecks();
        }
    }

    private function registerDefaultChecks(): void
    {
        $this->addCheck(new InstallationCheck());
        $this->addCheck(new DatabaseCheck());
        $this->addCheck(new FilesystemCheck());
        $this->addCheck(new SessionCheck());
        $this->addCheck(new OAuthKeysCheck());
        $this->addCheck(new CacheCheck());
        $this->addCheck(new AnthropicApiCheck());
        $this->addCheck(new LangfuseCheck());
    }

    public function addCheck(HealthCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Run all health checks
     *
     * @return HealthCheckResult[]
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $check->check();
        }
        return $results;
    }

    /**
     * Get results as an associative array suitable for JSON response
     *
     * `status` keeps its pre-existing install-only meaning (ready vs.
     * setup_required) so callers that only look at that field never see a
     * new value. `healthy` is the actual dependency-readiness signal added
     * for PUNCH_LIST.md 1.2 -- false whenever a real dependency (database,
     * Anthropic API, Langfuse, ...) is unhealthy -- so callers that need
     * real readiness (meta/health/index.php's HTTP status code) have
     * something to key off.
     *
     * `healthy` deliberately excludes InstallationCheck's result:
     * `library/sql.inc.php` reassigns the global `$config` variable
     * (originally sqlconf.php's 0/1 install flag) to a DatabaseConnectionOptions
     * instance as part of its own ADODB bootstrap, and that file loads as
     * part of interface/globals.php's normal bootstrap chain. By the time
     * this class's checks run, `$config !== 1` is always true, so
     * InstallationCheck reports false on every fully-installed, fully
     * running instance -- a pre-existing bug unrelated to any dependency
     * this class actually checks. Folding it into `healthy` would make
     * meta/health/index.php's readiness probe permanently fail once
     * installed, which is worse than the false signal it would be flagging.
     * `checks['installed']` still surfaces InstallationCheck's result
     * as-is for transparency; only the `healthy` aggregate excludes it.
     *
     * @return array{status: string, healthy: bool, checks: array<string, bool>}
     */
    public function getResultsArray(): array
    {
        $checks = [];
        $isInstalled = true;
        $allHealthy = true;

        foreach ($this->runAll() as $result) {
            $checks[$result->name] = $result->healthy;

            if ($result->name === InstallationCheck::NAME) {
                if (!$result->healthy) {
                    $isInstalled = false;
                }

                continue;
            }

            if (!$result->healthy) {
                $allHealthy = false;
            }
        }

        return [
            'status' => $isInstalled ? 'ready' : 'setup_required',
            'healthy' => $allHealthy,
            'checks' => $checks,
        ];
    }
}
