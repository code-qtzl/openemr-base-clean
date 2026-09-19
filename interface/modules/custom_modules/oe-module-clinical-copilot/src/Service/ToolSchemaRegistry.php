<?php

/**
 * Loads the Clinical Co-Pilot's Anthropic tool definitions from
 * schemas/tool-definitions.json -- the schema is the source of truth here,
 * not the PHP that consumes it (PUNCH_LIST_2.md Item 4). `ChartContextTools`
 * and `CopilotService` read from this class rather than hand-rewriting a
 * tool's `name`/`description`/`input_schema` inline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service;

use JsonException;
use RuntimeException;

final class ToolSchemaRegistry
{
    private const SCHEMA_FILE = __DIR__ . '/../../schemas/tool-definitions.json';

    /** @var array<string, array{name: string, description: string, input_schema: array<string, mixed>}>|null */
    private static ?array $cache = null;

    /**
     * One tool's definition, in Anthropic Messages API shape.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function get(string $toolName): array
    {
        $tool = self::all()[$toolName] ?? null;
        if ($tool === null) {
            throw new RuntimeException("No schema defined for tool '{$toolName}' in " . self::SCHEMA_FILE);
        }

        return $tool;
    }

    /**
     * @return array<string, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $contents = file_get_contents(self::SCHEMA_FILE);
        if ($contents === false) {
            throw new RuntimeException('Could not read tool schema file: ' . self::SCHEMA_FILE);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Malformed tool schema file: ' . self::SCHEMA_FILE, previous: $e);
        }

        if (!is_array($decoded) || !is_array($decoded['tools'] ?? null)) {
            throw new RuntimeException('Tool schema file missing a "tools" array: ' . self::SCHEMA_FILE);
        }

        $indexed = [];
        foreach ($decoded['tools'] as $tool) {
            $indexed[self::validatedName($tool)] = self::validatedTool($tool);
        }

        self::$cache = $indexed;

        return $indexed;
    }

    private static function validatedName(mixed $tool): string
    {
        $name = is_array($tool) ? ($tool['name'] ?? null) : null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('A tool entry in ' . self::SCHEMA_FILE . ' is missing a string "name".');
        }

        return $name;
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    private static function validatedTool(mixed $tool): array
    {
        $name = self::validatedName($tool);
        $description = is_array($tool) ? ($tool['description'] ?? null) : null;
        $inputSchema = is_array($tool) ? ($tool['input_schema'] ?? null) : null;

        if (!is_string($description) || $description === '') {
            throw new RuntimeException("Tool '{$name}' in " . self::SCHEMA_FILE . ' is missing a string "description".');
        }
        if (!is_array($inputSchema)) {
            throw new RuntimeException("Tool '{$name}' in " . self::SCHEMA_FILE . ' is missing an "input_schema" object.');
        }

        /** @var array<string, mixed> $inputSchema */
        return ['name' => $name, 'description' => $description, 'input_schema' => self::withObjectProperties($inputSchema)];
    }

    /**
     * json_decode(..., associative: true) collapses JSON's "{}" (object)
     * and "[]" (array) into the same empty PHP array, so an empty
     * `properties` here re-encodes as a JSON array, not the object
     * Anthropic's API requires -- confirmed against the real API: "tools.0.
     * custom.input_schema.properties: Input should be an object". Casting
     * an empty `properties` back to an object restores what the original
     * hand-written definitions did with `(object) []` before this class
     * existed.
     *
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    private static function withObjectProperties(array $inputSchema): array
    {
        if (($inputSchema['properties'] ?? null) === []) {
            $inputSchema['properties'] = (object) [];
        }

        return $inputSchema;
    }
}
