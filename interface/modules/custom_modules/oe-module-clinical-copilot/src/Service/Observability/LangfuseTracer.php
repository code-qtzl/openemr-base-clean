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
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\ExtractionTelemetry;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Throwable;

final readonly class LangfuseTracer
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
        private ?string $publicKey = null,
        private ?string $secretKey = null,
        private ?string $host = null,
        private ?string $environment = null,
        private ?ClientInterface $transporter = null,
    ) {
    }

    /**
     * Builds and sends the trace for one completed co-pilot request. A
     * no-op when Langfuse credentials are not configured, same as
     * CopilotService itself being a no-op integration when unconfigured.
     *
     * @param list<TelemetryStep> $steps Sub-step timings (Voyage embed/
     *                                   rerank, keyword search, ...), emitted
     *                                   as child spans of the root. Attributes
     *                                   are PHI-free counts/scores by contract.
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
        array $steps = [],
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
                    'langfuse.trace.metadata.retry_count' => $result->retryCount,
                    // Tags (rather than only metadata) so Langfuse's dashboard can
                    // filter/break down by these -- PUNCH_LIST.md 3.3's verification
                    // pass/fail rate and retry-count metrics.
                    'langfuse.trace.tags' => array_filter([
                        $result->verificationPassed ? 'verification-passed' : 'verification-failed',
                        $result->retryCount > 0 ? 'retried' : null,
                    ]),
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
                    // ERROR level makes a failed tool call queryable/alertable in Langfuse
                    // without parsing observation.output -- ALERTS.md's tool-failure-rate alert.
                    'langfuse.observation.level' => $toolCall->success ? 'DEFAULT' : 'ERROR',
                ],
            );
        }

        foreach ($steps as $step) {
            $stepAttributes = [
                'langfuse.observation.type' => 'span',
                'langfuse.observation.level' => ($step->attributes['failed'] ?? false) === true ? 'ERROR' : 'DEFAULT',
            ];
            foreach ($step->attributes as $key => $value) {
                $stepAttributes['langfuse.observation.metadata.' . $key] = $value;
            }

            $spans[] = new Span(
                spanId: Span::newSpanId(),
                parentSpanId: $rootSpanId,
                name: $step->name,
                startedAt: $step->startedAt,
                endedAt: $step->endedAt,
                attributes: $stepAttributes,
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

    /**
     * Traces one document extraction (the upload path, which is a separate
     * request from chat and was previously invisible in Langfuse): a root
     * span for the whole ingest plus a generation span carrying the VLM
     * call's model, tokens and latency. Only counts and schema field names
     * are attached -- never extracted values, filenames or document text.
     *
     * @param ?string $failureReason ExtractionResult::failureReason -- one of
     *                               a fixed set of generic parse-failure
     *                               strings, never model output.
     */
    public function traceExtraction(
        string $correlationId,
        int $patientId,
        string $authUser,
        SchemaDocType $docType,
        bool $success,
        bool $schemaValid,
        ?string $failureReason,
        ExtractionTelemetry $telemetry,
        float $requestStartedAt,
        float $requestEndedAt,
    ): void {
        $rootSpanId = Span::newSpanId();

        $this->send($correlationId, [
            new Span(
                spanId: $rootSpanId,
                parentSpanId: null,
                name: 'clinical-copilot.extract',
                startedAt: $requestStartedAt,
                endedAt: $requestEndedAt,
                attributes: [
                    'langfuse.trace.name' => 'clinical-copilot-extraction',
                    'langfuse.observation.type' => 'span',
                    'langfuse.observation.level' => $success ? 'DEFAULT' : 'WARNING',
                    'langfuse.user.id' => $authUser,
                    'langfuse.trace.metadata.pid' => $patientId,
                    'langfuse.trace.metadata.correlation_id' => $correlationId,
                    'langfuse.trace.metadata.doc_type' => $docType->value,
                    'langfuse.trace.metadata.success' => $success,
                    'langfuse.trace.metadata.schema_valid' => $schemaValid,
                    'langfuse.trace.metadata.fields_expected' => $telemetry->fieldsExpected,
                    'langfuse.trace.metadata.fields_present' => $telemetry->fieldsPresent(),
                    'langfuse.trace.metadata.completeness' => $telemetry->completeness(),
                    'langfuse.trace.metadata.missing_fields' => implode(',', $telemetry->missingFields),
                    'langfuse.trace.metadata.failure_reason' => $failureReason ?? '',
                    'langfuse.trace.tags' => array_filter([
                        'extraction',
                        $success ? 'extraction-succeeded' : 'extraction-failed',
                        $telemetry->missingFields !== [] ? 'incomplete-extraction' : null,
                    ]),
                ],
            ),
            new Span(
                spanId: Span::newSpanId(),
                parentSpanId: $rootSpanId,
                name: 'anthropic.messages',
                startedAt: $telemetry->startedAt,
                endedAt: $telemetry->endedAt,
                attributes: [
                    'langfuse.observation.type' => 'generation',
                    'gen_ai.request.model' => $telemetry->model,
                    'gen_ai.usage.input_tokens' => $telemetry->inputTokens,
                    'gen_ai.usage.output_tokens' => $telemetry->outputTokens,
                ],
            ),
        ]);
    }

    /**
     * Traces a request that never produced an AskResult at all --
     * CopilotChatController's catch block, for a thrown missing-API-key,
     * Anthropic API, SQL, or JSON error. Without this, a failed request
     * was previously invisible in Langfuse entirely (only traceAsk()'s
     * success path ever sent anything), so ALERTS.md's error-rate alert had
     * no trace data to watch regardless of Langfuse plan tier -- see
     * PUNCH_LIST_2.md Item 2.
     *
     * `level: ERROR` on the root span is what makes this queryable via the
     * same `Is Root Observation` + `Status` filters PUNCH_LIST.md 3.4's
     * alerts already use for tool calls -- not the exception's own message,
     * which can carry API/SQL detail and never leaves this process
     * (`$exception::class` only, matching CopilotChatController's own
     * "never expose $e->getMessage()" rule for the browser response).
     */
    public function traceFailure(
        string $correlationId,
        int $patientId,
        string $authUser,
        string $question,
        Throwable $exception,
        float $requestStartedAt,
        float $requestEndedAt,
    ): void {
        $this->send($correlationId, [
            new Span(
                spanId: Span::newSpanId(),
                parentSpanId: null,
                name: 'clinical-copilot.ask',
                startedAt: $requestStartedAt,
                endedAt: $requestEndedAt,
                attributes: [
                    'langfuse.trace.name' => 'clinical-copilot-chat',
                    'langfuse.observation.type' => 'span',
                    'langfuse.observation.level' => 'ERROR',
                    'langfuse.user.id' => $authUser,
                    'langfuse.trace.metadata.pid' => $patientId,
                    'langfuse.trace.metadata.correlation_id' => $correlationId,
                    'langfuse.trace.tags' => ['request-failed'],
                    'langfuse.observation.input' => $question,
                    'langfuse.observation.output' => $exception::class,
                ],
            ),
        ]);
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
                LangfuseOtlpPayloadBuilder::build($traceId, $spans, $this->environment()),
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

    /**
     * `ENV`/`ENVIRONMENT` reuses this project's existing environment-name
     * convention (phpunit.xml sets `ENV=test` for every PHPUnit run;
     * GitHub Actions sets `ENVIRONMENT=ci`) rather than inventing a new
     * signal -- without this, every test run (CopilotChatControllerTest
     * included, which exercises the real LangfuseTracer against
     * FakeAnthropicTransporter's fixture token counts) lands in the same
     * "default" Langfuse environment as genuine clinician traffic, making
     * cost/latency/quality dashboards unusable for real usage.
     */
    private function environment(): string
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $envBag = OEEnvBag::getInstance();
        $env = $envBag->getString('ENV', $envBag->getString('ENVIRONMENT', ''));

        return $env !== '' ? $env : 'production';
    }

    private static function traceIdFromCorrelationId(string $correlationId): string
    {
        $hex = strtolower(str_replace('-', '', $correlationId));

        return substr(str_pad($hex, 32, '0'), 0, 32);
    }
}
