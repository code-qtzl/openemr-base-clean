<?php

/**
 * Isolated LangfuseTracer Test
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Observability;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Service\AskResult;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\ToolCallSpan;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/AskResult.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/Span.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/ToolCallSpan.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/LangfuseOtlpPayloadBuilder.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/LangfuseTracer.php';

class LangfuseTracerTest extends TestCase
{
    public function testNotConfiguredSendsNothing(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: '', secretKey: '', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        self::assertNull($transporter->captured);
    }

    public function testConfiguredSendsToTheOtelEndpointWithBasicAuth(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        $request = $transporter->captured;
        self::assertNotNull($request);
        self::assertStringEndsWith('/api/public/otel/v1/traces', (string) $request->getUri());
        self::assertSame(
            'Basic ' . base64_encode('pk-lf-test:sk-lf-test'),
            $request->getHeaderLine('Authorization'),
        );
    }

    public function testTraceIdIsDerivedFromTheCorrelationIdSoLogsAndTracesShareOneIdentifier(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk(
            '11112222-3333-4444-5555-666677778888',
            27,
            'admin',
            'claude-opus-5',
            'q',
            self::stubResult(),
            0.0,
            1.0,
        );

        $spans = self::extractSpans($transporter->captured);

        self::assertSame('11112222333344445555666677778888', self::spanAt($spans, 0)['traceId']);
    }

    public function testEmitsOneSpanPerToolCallPlusOneGenerationSpanWhenTokensAreKnown(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $result = new AskResult(
            reply: 'answer',
            toolsUsed: ['get_medications'],
            verificationPassed: true,
            verificationReason: null,
            toolCalls: [new ToolCallSpan('get_medications', 0.1, 0.2, true)],
            inputTokens: 100,
            outputTokens: 50,
            retryCount: 0,
        );

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', $result, 0.0, 1.0);

        $spans = self::extractSpans($transporter->captured);

        // root + one tool span + one generation span
        self::assertCount(3, $spans);
        self::assertSame(
            ['clinical-copilot.ask', 'get_medications', 'anthropic.messages'],
            array_column($spans, 'name'),
        );
        $rootSpanId = self::spanAt($spans, 0)['spanId'];
        self::assertSame($rootSpanId, self::spanAt($spans, 1)['parentSpanId']);
        self::assertSame($rootSpanId, self::spanAt($spans, 2)['parentSpanId']);
    }

    /**
     * ALERTS.md's tool-failure-rate alert queries on this level, not on
     * parsing observation.output -- a failed tool call must be directly
     * filterable without that.
     */
    public function testFailedToolCallSpanIsLeveledErrorSoItsAlertableWithoutParsingOutput(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $result = new AskResult(
            reply: 'answer',
            toolsUsed: ['get_medications'],
            verificationPassed: false,
            verificationReason: 'tool call failed',
            toolCalls: [new ToolCallSpan('get_medications', 0.1, 0.2, false)],
            inputTokens: 0,
            outputTokens: 0,
            retryCount: 0,
        );

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', $result, 0.0, 1.0);

        $toolSpan = self::spanAt(self::extractSpans($transporter->captured), 1);
        $attributes = self::attributesOf($toolSpan);

        self::assertSame('failed', $attributes['langfuse.observation.output']);
        self::assertSame('ERROR', $attributes['langfuse.observation.level']);
    }

    public function testOmitsGenerationSpanWhenNoTokenUsageIsKnown(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        $spans = self::extractSpans($transporter->captured);

        self::assertSame(['clinical-copilot.ask'], array_column($spans, 'name'));
    }

    /**
     * PUNCH_LIST.md 3.3: retry count and verification pass/fail must reach
     * Langfuse's dashboard as a filterable trace tag, not only as metadata
     * (which the dashboard can't chart/aggregate by) -- see
     * LangfuseTracer::traceAsk()'s langfuse.trace.tags attribute.
     */
    public function testFailedVerificationAndRetriesAreTaggedAndCountedOnTheRootSpan(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $result = self::stubResult(verificationPassed: false, retryCount: 2);
        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', $result, 0.0, 1.0);

        $rootAttributes = self::attributesOf(self::spanAt(self::extractSpans($transporter->captured), 0));

        self::assertSame(['verification-failed', 'retried'], $rootAttributes['langfuse.trace.tags']);
        self::assertSame(2, $rootAttributes['langfuse.trace.metadata.retry_count']);
    }

    public function testPassedVerificationWithNoRetriesTagsOnlyVerificationPassed(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        $rootAttributes = self::attributesOf(self::spanAt(self::extractSpans($transporter->captured), 0));

        self::assertSame(['verification-passed'], $rootAttributes['langfuse.trace.tags']);
        self::assertSame(0, $rootAttributes['langfuse.trace.metadata.retry_count']);
    }

    /**
     * phpunit.xml sets `<env name="ENV" value="test" />` for every PHPUnit
     * run -- without LangfuseTracer reading it, this very test (and every
     * DB-backed CopilotChatControllerTest run, which exercises the real
     * LangfuseTracer against fixture token counts) would land in the same
     * Langfuse "default" environment as genuine clinician traffic.
     */
    public function testEnvironmentDefaultsToTheAmbientEnvVariablePhpunitSets(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        self::assertSame(
            ['stringValue' => 'test'],
            self::resourceAttributesOf($transporter->captured)['deployment.environment.name'],
        );
    }

    public function testExplicitEnvironmentOverridesTheAmbientDefault(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(
            publicKey: 'pk-lf-test',
            secretKey: 'sk-lf-test',
            environment: 'production',
            transporter: $transporter,
        );

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        self::assertSame(
            ['stringValue' => 'production'],
            self::resourceAttributesOf($transporter->captured)['deployment.environment.name'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceAttributesOf(?RequestInterface $request): array
    {
        self::assertNotNull($request);

        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        $resourceSpans = $body['resourceSpans'] ?? null;
        self::assertIsArray($resourceSpans);
        $resourceSpan0 = $resourceSpans[0] ?? null;
        self::assertIsArray($resourceSpan0);
        $resource = $resourceSpan0['resource'] ?? null;
        self::assertIsArray($resource);
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        $byKey = [];
        foreach ($attributes as $attribute) {
            self::assertIsArray($attribute);
            $key = $attribute['key'];
            self::assertIsString($key);
            $byKey[$key] = $attribute['value'];
        }

        return $byKey;
    }

    /**
     * Decodes one span's OTLP attribute list back into a plain
     * key => value map (arrayValue -> list<string>, the rest scalar),
     * mirroring LangfuseOtlpPayloadBuilder::anyValue()'s encoding.
     *
     * @param array<mixed, mixed> $span
     * @return array<string, mixed>
     */
    private static function attributesOf(array $span): array
    {
        $attributes = $span['attributes'] ?? null;
        self::assertIsArray($attributes);

        $map = [];
        foreach ($attributes as $attribute) {
            self::assertIsArray($attribute);
            $key = $attribute['key'] ?? null;
            self::assertIsString($key);
            $value = $attribute['value'] ?? null;
            self::assertIsArray($value);

            $intValue = $value['intValue'] ?? null;
            $map[$key] = match (true) {
                array_key_exists('arrayValue', $value) => self::decodeArrayValue($value),
                array_key_exists('boolValue', $value) => $value['boolValue'],
                array_key_exists('intValue', $value) && is_string($intValue) => (int) $intValue,
                array_key_exists('doubleValue', $value) => $value['doubleValue'],
                default => $value['stringValue'],
            };
        }

        return $map;
    }

    /**
     * @param array<mixed, mixed> $value
     * @return list<string>
     */
    private static function decodeArrayValue(array $value): array
    {
        $arrayValue = $value['arrayValue'] ?? null;
        self::assertIsArray($arrayValue);
        $values = $arrayValue['values'] ?? null;
        self::assertIsArray($values);

        return array_map(static function (mixed $item): string {
            self::assertIsArray($item);
            $stringValue = $item['stringValue'] ?? null;
            self::assertIsString($stringValue);

            return $stringValue;
        }, array_values($values));
    }

    private static function stubResult(bool $verificationPassed = true, int $retryCount = 0): AskResult
    {
        return new AskResult(
            reply: 'answer',
            toolsUsed: [],
            verificationPassed: $verificationPassed,
            verificationReason: null,
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
            retryCount: $retryCount,
        );
    }

    /**
     * Decodes the captured OTLP request body down to its span list,
     * asserting the shape is what LangfuseOtlpPayloadBuilder produces at
     * every level -- the untyped decode is exactly what a test double for
     * an external HTTP body should distrust before indexing into it.
     *
     * @return array<mixed, mixed>
     */
    private static function extractSpans(?RequestInterface $request): array
    {
        self::assertNotNull($request);

        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);

        $resourceSpans = $body['resourceSpans'] ?? null;
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

    /** @return ClientInterface&object{captured: ?RequestInterface} */
    private static function capturingTransporter(): object
    {
        return new class implements ClientInterface {
            public ?RequestInterface $captured = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };
    }
}
