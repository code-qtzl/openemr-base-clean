<?php

/**
 * Isolated ChartContextTools::definitions() Test
 *
 * Only exercises the static, DB-free definitions() method -- call()'s
 * DB-backed tool dispatch is covered by CopilotServiceTest/
 * CopilotChatControllerTest (tests/Tests/Services/Modules/ClinicalCopilot/).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service;

use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/ToolSchemaRegistry.php';
require_once __DIR__ . '/../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/ChartContextTools.php';

class ChartContextToolsDefinitionsTest extends TestCase
{
    public function testDefinitionsReturnsExactlyTheFiveAllowlistedChartTools(): void
    {
        $names = array_column(ChartContextTools::definitions(), 'name');

        self::assertSame(
            [
                'get_a1c_series',
                'get_active_problems',
                'get_medications',
                'get_recent_encounters',
                'get_extracted_documents',
            ],
            $names,
        );
    }

    /**
     * Failure mode guarded against: submit_answer is CopilotService's
     * forced final-answer tool, not a chart-reading tool -- it must never
     * be offered here, where the model deciding to "call" it would bypass
     * ChartContextTools::call()'s audit logging entirely.
     */
    public function testDefinitionsNeverIncludesSubmitAnswer(): void
    {
        $names = array_column(ChartContextTools::definitions(), 'name');

        self::assertNotContains('submit_answer', $names);
    }

    /**
     * Failure mode guarded against: confirmed against the real Anthropic
     * API -- an empty `properties` must round-trip through json_encode()
     * as a JSON object ("{}"), not an array ("[]"), for every chart tool,
     * not just one. See ToolSchemaRegistryTest for why
     * (json_decode(..., associative: true) collapses the distinction).
     */
    public function testEveryDefinitionEncodesEmptyPropertiesAsAJsonObject(): void
    {
        foreach (ChartContextTools::definitions() as $definition) {
            $encoded = json_encode($definition['input_schema'], JSON_THROW_ON_ERROR);
            self::assertStringContainsString('"properties":{}', $encoded);
        }
    }
}
