<?php

/**
 * Applies the Clinical Co-Pilot module's schema (table.sql) against the
 * active database.
 *
 * `./cli install` (used by CI to provision the test database) has no concept
 * of custom modules under interface/modules/custom_modules/ -- their
 * table.sql files are normally applied only through the OpenEMR admin UI's
 * module Register/Install action, which a headless CI run never performs.
 * Without this, every test in tests/Tests/Services/Modules/ClinicalCopilot/
 * fails against a freshly installed database with "table doesn't exist".
 *
 * Reuses SQLUpgradeService -- the same conditional-DDL preprocessor
 * (#IfNotTable/#IfMissingColumn/#IfNotIndex) the admin UI's module installer
 * uses -- so it is idempotent: safe to run against a fresh database (creates
 * the tables) or an already-provisioned one (a no-op).
 *
 * Run as the web user, not root (see OpenEMR\Common\Command\RootCliGuard):
 *   php tests/Tests/Fixtures/ClinicalCopilot/apply-schema.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Services\Utils\SQLUpgradeService;

chdir(__DIR__ . '/../../../..');
$_GET['site'] = 'default';
$ignoreAuth = true;
require_once 'interface/globals.php';

$service = new SQLUpgradeService();
$service->setRenderOutputToScreen(true);
$service->setThrowExceptionOnError(true);
$service->upgradeFromSqlFile(
    'table.sql',
    getcwd() . '/interface/modules/custom_modules/oe-module-clinical-copilot',
);

echo "\nClinical Co-Pilot schema applied.\n";
