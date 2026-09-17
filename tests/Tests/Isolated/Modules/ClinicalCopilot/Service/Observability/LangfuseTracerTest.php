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

    public function testOmitsGenerationSpanWhenNoTokenUsageIsKnown(): void
    {
        $transporter = self::capturingTransporter();
        $tracer = new LangfuseTracer(publicKey: 'pk-lf-test', secretKey: 'sk-lf-test', transporter: $transporter);

        $tracer->traceAsk('c1', 27, 'admin', 'claude-opus-5', 'q', self::stubResult(), 0.0, 1.0);

        $spans = self::extractSpans($transporter->captured);

        self::assertSame(['clinical-copilot.ask'], array_column($spans, 'name'));
    }

    private static function stubResult(): AskResult
    {
        return new AskResult(
            reply: 'answer',
            toolsUsed: [],
            verificationPassed: true,
            verificationReason: null,
            toolCalls: [],
            inputTokens: 0,
            outputTokens: 0,
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
