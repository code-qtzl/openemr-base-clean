<?php

/**
 * Loads the Clinical Co-Pilot module's production classes for tests.
 *
 * The module lives under interface/modules/custom_modules/, which composer's
 * PSR-4 map does not cover (OpenEMR\ maps only to src/) -- the same
 * constraint tests/Tests/Isolated/Modules/ClinicalCopilot/... already works
 * around with explicit require_once chains. This file centralizes that list
 * once instead of repeating it in every DB-backed test file.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$moduleSrc = __DIR__ . '/../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src';

require_once $moduleSrc . '/Service/AnthropicClientFactory.php';
require_once $moduleSrc . '/Service/DefaultAnthropicClientFactory.php';
require_once $moduleSrc . '/Service/AskResult.php';
require_once $moduleSrc . '/Service/MedicationStalenessPolicy.php';
require_once $moduleSrc . '/Service/Observability/Span.php';
require_once $moduleSrc . '/Service/Observability/ToolCallSpan.php';
require_once $moduleSrc . '/Service/Observability/LangfuseOtlpPayloadBuilder.php';
require_once $moduleSrc . '/Service/Observability/LangfuseTracer.php';
require_once $moduleSrc . '/Service/Verification/VerificationOutcome.php';
require_once $moduleSrc . '/Service/Verification/VerificationClaim.php';
require_once $moduleSrc . '/Service/Verification/ResponseVerifier.php';
require_once $moduleSrc . '/Service/Result/ToolResultRow.php';
require_once $moduleSrc . '/Service/Result/AbstractToolResult.php';
require_once $moduleSrc . '/Service/Result/A1cResultRow.php';
require_once $moduleSrc . '/Service/Result/A1cSeriesResult.php';
require_once $moduleSrc . '/Service/Result/ActiveProblemRow.php';
require_once $moduleSrc . '/Service/Result/ActiveProblemsResult.php';
require_once $moduleSrc . '/Service/Result/MedicationRow.php';
require_once $moduleSrc . '/Service/Result/MedicationsResult.php';
require_once $moduleSrc . '/Service/Result/RecentEncounterRow.php';
require_once $moduleSrc . '/Service/Result/RecentEncountersResult.php';
require_once $moduleSrc . '/Service/ChartContextTools.php';
require_once $moduleSrc . '/Service/CopilotInteractionLogger.php';
require_once $moduleSrc . '/Service/CopilotService.php';
require_once $moduleSrc . '/Controller/CopilotChatController.php';
