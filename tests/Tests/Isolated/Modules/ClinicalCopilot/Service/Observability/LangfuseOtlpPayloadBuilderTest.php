<?php

/**
 * Isolated LangfuseOtlpPayloadBuilder Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Observability;

use OpenEMR\Modules\ClinicalCopilot\Service\Observability\LangfuseOtlpPayloadBuilder;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\Span;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/Span.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/LangfuseOtlpPayloadBuilder.php';

class LangfuseOtlpPayloadBuilderTest extends TestCase
{
    public function testRootSpanHasNoParentSpanIdKey(): void
    {
        $span = new Span('a1a1a1a1a1a1a1a1', null, 'root', 1_700_000_000.0, 1_700_000_001.5, []);

        $spans = self::spans(LangfuseOtlpPayloadBuilder::build('t', [$span]));
        $encoded = self::spanAt($spans, 0);

        self::assertArrayNotHasKey('parentSpanId', $encoded);
        self::assertSame('a1a1a1a1a1a1a1a1', $encoded['spanId']);
        self::assertSame('root', $encoded['name']);
    }

    public function testChildSpanCarriesParentSpanId(): void
    {
        $span = new Span('b2b2b2b2b2b2b2b2', 'a1a1a1a1a1a1a1a1', 'child', 1_700_000_000.0, 1_700_000_000.5, []);

        $spans = self::spans(LangfuseOtlpPayloadBuilder::build('t', [$span]));
        $encoded = self::spanAt($spans, 0);

        self::assertSame('a1a1a1a1a1a1a1a1', $encoded['parentSpanId']);
    }

    public function testTimestampsConvertSecondsToNanosecondStrings(): void
    {
        $span = new Span('a1a1a1a1a1a1a1a1', null, 'root', 1_700_000_000.0, 1_700_000_001.5, []);

        $spans = self::spans(LangfuseOtlpPayloadBuilder::build('t', [$span]));
        $encoded = self::spanAt($spans, 0);

        self::assertSame('1700000000000000000', $encoded['startTimeUnixNano']);
        self::assertSame('1700000001500000000', $encoded['endTimeUnixNano']);
    }

    public function testAttributeTypesEncodeToTheMatchingOtlpAnyValueVariant(): void
    {
        $span = new Span('a1a1a1a1a1a1a1a1', null, 'root', 0.0, 0.0, [
            'a.string' => 'hello',
            'a.bool' => true,
            'a.int' => 42,
            'a.float' => 3.5,
        ]);

        $spans = self::spans(LangfuseOtlpPayloadBuilder::build('t', [$span]));
        $attributes = self::spanAt($spans, 0)['attributes'];
        self::assertIsArray($attributes);

        $byKey = [];
        foreach ($attributes as $attribute) {
            self::assertIsArray($attribute);
            $key = $attribute['key'];
            self::assertIsString($key);
            $byKey[$key] = $attribute['value'];
        }

        self::assertSame(['stringValue' => 'hello'], $byKey['a.string']);
        self::assertSame(['boolValue' => true], $byKey['a.bool']);
        self::assertSame(['intValue' => '42'], $byKey['a.int']);
        self::assertSame(['doubleValue' => 3.5], $byKey['a.float']);
    }

    public function testMultipleSpansAllAppearUnderOneScope(): void
    {
        $spans = [
            new Span('a1a1a1a1a1a1a1a1', null, 'root', 0.0, 1.0, []),
            new Span('b2b2b2b2b2b2b2b2', 'a1a1a1a1a1a1a1a1', 'child-a', 0.1, 0.5, []),
            new Span('c3c3c3c3c3c3c3c3', 'a1a1a1a1a1a1a1a1', 'child-b', 0.2, 0.6, []),
        ];

        $encoded = self::spans(LangfuseOtlpPayloadBuilder::build('t', $spans));

        self::assertCount(3, $encoded);
        self::assertSame(
            ['root', 'child-a', 'child-b'],
            [self::spanAt($encoded, 0)['name'], self::spanAt($encoded, 1)['name'], self::spanAt($encoded, 2)['name']],
        );
    }

    public function testEveryEncodedSpanCarriesTheSameTraceId(): void
    {
        $spans = [
            new Span('a1a1a1a1a1a1a1a1', null, 'root', 0.0, 1.0, []),
            new Span('b2b2b2b2b2b2b2b2', 'a1a1a1a1a1a1a1a1', 'child', 0.1, 0.5, []),
        ];

        $encoded = self::spans(LangfuseOtlpPayloadBuilder::build('deadbeef', $spans));

        self::assertSame('deadbeef', self::spanAt($encoded, 0)['traceId']);
        self::assertSame('deadbeef', self::spanAt($encoded, 1)['traceId']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed, mixed>
     */
    private static function spans(array $payload): array
    {
        $resourceSpans = $payload['resourceSpans'] ?? null;
        self::assertIsArray($resourceSpans);
        $resourceSpan0 = $resourceSpans[0] ?? null;
        self::assertIsArray($resourceSpan0);

        $scopeSpans = $resourceSpan0['scopeSpans'] ?? null;
        self::assertIsArray($scopeSpans);
        $scopeSpan0 = $scopeSpans[0] ?? null;
        self::assertIsArray($scopeSpan0);

        $spans = $scopeSpan0['spans'] ?? null;
        self::assertIsArray($spans);

        return $spans;
    }

    /**
     * @param array<mixed, mixed> $spans
     *
     * @return array<mixed, mixed>
     */
    private static function spanAt(array $spans, int $index): array
    {
        $span = $spans[$index] ?? null;
        self::assertIsArray($span);

        return $span;
    }
}
