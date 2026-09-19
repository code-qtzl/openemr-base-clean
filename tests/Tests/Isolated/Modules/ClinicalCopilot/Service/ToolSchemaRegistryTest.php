<?php

/**
 * Isolated ToolSchemaRegistry Test
 *
 * Covers PUNCH_LIST_2.md Item 4: schemas/tool-definitions.json is the
 * source of truth for the co-pilot's Anthropic tool contracts, not the PHP
 * that consumes it -- these tests load the real committed file, not a
 * fixture, so a broken or malformed schema file fails here first.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service;

use OpenEMR\Modules\ClinicalCopilot\Service\ToolSchemaRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/ToolSchemaRegistry.php';

class ToolSchemaRegistryTest extends TestCase
{
    /**
     * Failure mode guarded against: the four chart tools' definitions
     * previously used camelCase "inputSchema", which Anthropic's API does
     * not recognize (its wire format is snake_case "input_schema", see
     * vendor/anthropic-ai/sdk/src/Beta/Messages/BetaTool.php's
     * #[Required('input_schema')] attribute) -- silently harmless only
     * because all four tools take no arguments. This pins the correct key.
     */
    public function testChartToolDefinitionUsesSnakeCaseInputSchemaKey(): void
    {
        $tool = ToolSchemaRegistry::get('get_a1c_series');

        self::assertSame('object', $tool['input_schema']['type']);
        self::assertSame([], $tool['input_schema']['required']);
    }

    /**
     * Failure mode guarded against: confirmed against the real Anthropic
     * API (not just a unit assumption) -- json_decode(..., associative:
     * true) collapses JSON's "{}" and "[]" into the same empty PHP array,
     * so a naive re-encode of an empty `properties` sends a JSON array
     * where Anthropic's API requires an object, and the real API rejects
     * it: "tools.0.custom.input_schema.properties: Input should be an
     * object". A chart tool's empty `properties` must be a PHP stdClass
     * (json_encode's only way to force "{}" for an empty value), not `[]`.
     */
    public function testChartToolWithNoArgumentsEncodesPropertiesAsAJsonObjectNotArray(): void
    {
        $tool = ToolSchemaRegistry::get('get_a1c_series');

        self::assertInstanceOf(\stdClass::class, $tool['input_schema']['properties']);
        self::assertJsonStringEqualsJsonString(
            '{"type":"object","properties":{},"required":[]}',
            json_encode($tool['input_schema'], JSON_THROW_ON_ERROR),
        );
    }

    public function testSubmitAnswerDefinitionHasItsStructuredClaimsSchema(): void
    {
        $tool = ToolSchemaRegistry::get('submit_answer');

        self::assertSame('submit_answer', $tool['name']);
        self::assertSame(
            ['insufficient_information', 'claims'],
            $tool['input_schema']['required'],
        );
        $properties = $tool['input_schema']['properties'];
        self::assertIsArray($properties);
        self::assertArrayHasKey('claims', $properties);
    }

    /**
     * Failure mode guarded against: a typo'd or removed tool name reaching
     * this registry must fail loudly (a schema-file/code mismatch is a
     * programming error, not a per-request condition to degrade
     * gracefully from) rather than silently returning nothing.
     */
    public function testUnknownToolNameThrows(): void
    {
        $this->expectException(RuntimeException::class);

        ToolSchemaRegistry::get('not_a_real_tool');
    }

    public function testEveryToolHasANonEmptyNameAndDescription(): void
    {
        foreach (['get_a1c_series', 'get_active_problems', 'get_medications', 'get_recent_encounters', 'submit_answer'] as $name) {
            $tool = ToolSchemaRegistry::get($name);

            self::assertSame($name, $tool['name']);
            self::assertNotSame('', $tool['description']);
        }
    }
}
