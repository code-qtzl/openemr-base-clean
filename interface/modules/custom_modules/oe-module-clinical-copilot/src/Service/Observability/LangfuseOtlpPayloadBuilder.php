<?php

/**
 * Builds the OTLP/HTTP+JSON ExportTraceServiceRequest body Langfuse's
 * OpenTelemetry ingestion endpoint expects.
 *
 * Langfuse's older `/api/public/ingestion` JSON event API (trace-create,
 * span-create, ...) is deprecated on Langfuse Cloud and stops accepting
 * anything but score-create events on 2026-11-16; the OTLP endpoint
 * (`/api/public/otel/v1/traces`) is the supported replacement and has no
 * such sunset, so this integration targets OTLP directly rather than the
 * event API PUNCH_LIST.md 1.1 originally described. There is no official
 * PHP OpenTelemetry exporter wired into this project, and pulling in the
 * full opentelemetry-php SDK for one export call would be a large new
 * dependency for a single HTTP POST -- this builds the (intentionally
 * small) JSON body by hand instead.
 *
 * Langfuse reads specific `langfuse.*` and `gen_ai.*` span attributes to
 * group and render spans (trace name, user/session, observation type,
 * token usage, ...) -- see https://langfuse.com/docs/opentelemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

final class LangfuseOtlpPayloadBuilder
{
    /** OTLP SpanKind.SPAN_KIND_INTERNAL -- every span here is internal application work. */
    private const SPAN_KIND_INTERNAL = 1;

    /**
     * @param list<Span> $spans
     * @param string $environment Langfuse's environment field, read via the
     *                            OTel `deployment.environment.name` resource
     *                            attribute -- see
     *                            https://langfuse.com/docs/opentelemetry and
     *                            LangfuseTracer::environment() for how this
     *                            is resolved. Separates real usage from
     *                            test/CI-generated traces in Langfuse's
     *                            dashboard filters.
     *
     * @return array<string, mixed> JSON-encoding-ready OTLP export request.
     */
    public static function build(string $traceId, array $spans, string $environment): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            self::attribute('service.name', 'openemr-clinical-copilot'),
                            self::attribute('deployment.environment.name', $environment),
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => 'clinical-copilot'],
                            'spans' => array_map(
                                static fn (Span $span): array => self::encodeSpan($traceId, $span),
                                $spans,
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function encodeSpan(string $traceId, Span $span): array
    {
        $attributes = [];
        foreach ($span->attributes as $key => $value) {
            $attributes[] = self::attribute($key, $value);
        }

        $encoded = [
            'traceId' => $traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => self::SPAN_KIND_INTERNAL,
            'startTimeUnixNano' => self::toUnixNano($span->startedAt),
            'endTimeUnixNano' => self::toUnixNano($span->endedAt),
            'attributes' => $attributes,
        ];

        if ($span->parentSpanId !== null) {
            $encoded['parentSpanId'] = $span->parentSpanId;
        }

        return $encoded;
    }

    private static function toUnixNano(float $secondsSinceEpoch): string
    {
        return (string) (int) round($secondsSinceEpoch * 1_000_000_000);
    }

    /**
     * @param string|bool|int|float|list<string> $value
     * @return array{key: string, value: array<string, mixed>}
     */
    private static function attribute(string $key, string|bool|int|float|array $value): array
    {
        return ['key' => $key, 'value' => self::anyValue($value)];
    }

    /**
     * @param string|bool|int|float|list<string> $value
     * @return array<string, mixed>
     */
    private static function anyValue(string|bool|int|float|array $value): array
    {
        return match (true) {
            is_array($value) => ['arrayValue' => ['values' => array_map(
                static fn (string $item): array => ['stringValue' => $item],
                $value,
            )]],
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            default => ['stringValue' => $value],
        };
    }
}
