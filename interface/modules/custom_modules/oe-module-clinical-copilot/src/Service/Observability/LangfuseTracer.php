<?php

/**
 * Sends one Langfuse trace per co-pilot request via Langfuse's OpenTelemetry
 * ingestion endpoint -- see LangfuseOtlpPayloadBuilder for why OTLP rather
 * than Langfuse's older event-ingestion API.
 *
 * The trace id is derived from the request's correlation id (not a random
 * id), so the same identifier that already ties together the audit log,
 * clinical_copilot_log, and the PHP error log also finds the trace in
 * Langfuse -- see PUNCH_LIST.md 0.1.
 *
 * A tracing failure must never be confused with, or mask, a real co-pilot
 * failure: every failure mode here is caught and logged, never propagated,
 * same philosophy as CopilotInteractionLogger.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Observability;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use JsonException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Modules\ClinicalCopilot\Service\AskResult;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

final class LangfuseTracer
{
    /**
     * Standard (non-HIPAA-BAA) US Langfuse Cloud region -- see LangfuseCheck
     * and the project's "LangFuse region decision" memory: this fork only
     * ever sends synthetic Synthea data through the co-pilot.
     */
    private const DEFAULT_HOST = 'https://us.cloud.langfuse.com';

    private const TIMEOUT_SECONDS = 3.0;

    /** See AnthropicApiCheck's constructor docblock for why these are nullable. */
    public function __construct(
        private readonly ?string $publicKey = null,
        private readonly ?string $secretKey = null,
        private readonly ?string $host = null,
        private readonly ?ClientInterface $transporter = null,
    ) {
    }

    /**
     * Builds and sends the trace for one completed co-pilot request. A
     * no-op when Langfuse credentials are not configured, same as
     * CopilotService itself being a no-op integration when unconfigured.
     */
    public function traceAsk(
        string $correlationId,
        int $patientId,
        string $authUser,
        string $model,
        string $question,
        AskResult $result,
        float $requestStartedAt,
        float $requestEndedAt,
    ): void {
        $rootSpanId = Span::newSpanId();

        $spans = [
            new Span(
                spanId: $rootSpanId,
                parentSpanId: null,
                name: 'clinical-copilot.ask',
                startedAt: $requestStartedAt,
                endedAt: $requestEndedAt,
                attributes: [
                    'langfuse.trace.name' => 'clinical-copilot-chat',
                    'langfuse.observation.type' => 'span',
                    'langfuse.user.id' => $authUser,
                    'langfuse.trace.metadata.pid' => $patientId,
                    'langfuse.trace.metadata.correlation_id' => $correlationId,
                    'langfuse.trace.metadata.verification_passed' => $result->verificationPassed,
                    'langfuse.observation.input' => $question,
                    'langfuse.observation.output' => $result->reply,
                ],
            ),
        ];

        foreach ($result->toolCalls as $toolCall) {
            $spans[] = new Span(
                spanId: Span::newSpanId(),
                parentSpanId: $rootSpanId,
                name: $toolCall->name,
                startedAt: $toolCall->startedAt,
                endedAt: $toolCall->endedAt,
                attributes: [
                    'langfuse.observation.type' => 'tool',
                    'langfuse.observation.output' => $toolCall->success ? 'ok' : 'failed',
                ],
            );
        }

        if ($result->inputTokens > 0 || $result->outputTokens > 0) {
            $spans[] = new Span(
                spanId: Span::newSpanId(),
                parentSpanId: $rootSpanId,
                name: 'anthropic.messages',
                startedAt: $requestStartedAt,
                endedAt: $requestEndedAt,
                attributes: [
                    'langfuse.observation.type' => 'generation',
                    'gen_ai.request.model' => $model,
                    'gen_ai.usage.input_tokens' => $result->inputTokens,
                    'gen_ai.usage.output_tokens' => $result->outputTokens,
                ],
            );
        }

        $this->send($correlationId, $spans);
    }

    /** @param list<Span> $spans */
    private function send(string $correlationId, array $spans): void
    {
        $publicKey = $this->publicKey ?? OEEnvBag::getInstance()->getString('OPENEMR__LANGFUSE_PUBLIC_KEY');
        $secretKey = $this->secretKey ?? OEEnvBag::getInstance()->getString('OPENEMR__LANGFUSE_SECRET_KEY');
        if ($publicKey === '' || $secretKey === '') {
            return;
        }

        $host = $this->host ?? OEEnvBag::getInstance()->getString('OPENEMR__LANGFUSE_HOST', self::DEFAULT_HOST);
        $traceId = self::traceIdFromCorrelationId($correlationId);

        try {
            $body = json_encode(
                LangfuseOtlpPayloadBuilder::build($traceId, $spans),
                JSON_THROW_ON_ERROR,
            );

            $transporter = $this->transporter ?? new GuzzleClient([
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
            ]);

            $transporter->sendRequest(new Request(
                'POST',
                rtrim($host, '/') . '/api/public/otel/v1/traces',
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($publicKey . ':' . $secretKey),
                ],
                $body,
            ));
        } catch (JsonException | ClientExceptionInterface $e) {
            ServiceContainer::getLogger()->warning('Clinical Co-Pilot trace export failed', [
                'correlationId' => $correlationId,
                'exception' => $e,
            ]);
        }
    }

    private static function traceIdFromCorrelationId(string $correlationId): string
    {
        $hex = strtolower(str_replace('-', '', $correlationId));

        return substr(str_pad($hex, 32, '0'), 0, 32);
    }
}
