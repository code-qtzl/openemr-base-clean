<?php

/**
 * Clinical Co-Pilot document-upload request handler.
 *
 * Mirrors CopilotChatController's security boundary exactly -- CSRF
 * verification, the same 'patients'/'demo' ACL check, the same per-session
 * SessionRateLimiter (an upload triggers a real Anthropic call just like a
 * chat question, so both share one budget rather than each having its own
 * unbounded one), and the patient id read from the SESSION only, never a
 * request parameter. See that class's docblock for the full rationale;
 * it is not repeated here.
 *
 * DocumentIngestionPipeline does the actual work (extract, self-validate,
 * store); this controller's job is the same as CopilotChatController's --
 * decide whether the request is even allowed to reach that pipeline, and
 * translate its result into a response that never leaks internal exception
 * detail to the browser.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use Anthropic\Core\Exceptions\AnthropicException;
use InvalidArgumentException;
use JsonException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema\SchemaDocType;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentIngestionPipeline;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentPayload;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Service\SessionRateLimiter;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class CopilotDocumentUploadController
{
    /**
     * Above any real chart document (a scanned lab PDF or intake form) while
     * still bounding worst-case request size; matches this module's general
     * posture of validating at the boundary rather than trusting client input.
     */
    private const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private DocumentIngestionPipeline $pipeline = new DocumentIngestionPipeline(),
        private SessionRateLimiter $rateLimiter = new SessionRateLimiter(),
        private LangfuseTracer $tracer = new LangfuseTracer(),
    ) {
    }

    public function handleRequest(): void
    {
        $this->buildResponse(Request::createFromGlobals())->send();
    }

    private function buildResponse(Request $request): JsonResponse
    {
        $correlationId = Uuid::uuid4()->toString();
        $session = SessionWrapperFactory::getInstance()->getActiveSession();

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token'), $session)) {
            return $this->error(xl('Session expired. Reload the page and try again.'), 403, $correlationId);
        }

        if (!AclMain::aclCheckCore('patients', 'demo')) {
            return $this->error(xl('You do not have permission to view this chart.'), 403, $correlationId);
        }

        if (!$this->rateLimiter->withinLimit($session)) {
            return $this->error(xl('Too many requests. Please wait a moment before trying again.'), 429, $correlationId);
        }

        $sessionPid = $session->get('pid');
        $patientId = is_numeric($sessionPid) ? (int) $sessionPid : 0;
        if ($patientId <= 0) {
            return $this->error(xl('No patient is currently selected.'), 400, $correlationId);
        }

        $docType = SchemaDocType::tryFrom($request->request->getString('doc_type'));
        if ($docType === null) {
            return $this->error(xl('Unrecognized document type.'), 400, $correlationId);
        }

        $file = $request->files->get('document');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->error(xl('No document was uploaded.'), 400, $correlationId);
        }

        $fileSize = $file->getSize();
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE_BYTES) {
            return $this->error(xl('Document is too large.'), 400, $correlationId);
        }

        try {
            $bytes = file_get_contents($file->getPathname());
            if ($bytes === false || $bytes === '') {
                return $this->error(xl('Could not read the uploaded document.'), 400, $correlationId);
            }

            // UploadedFile::getMimeType() requires symfony/mime, which this
            // project does not install (confirmed: not in composer.lock's
            // resolved packages) -- it throws LogicException rather than
            // returning null. mime_content_type() on the real uploaded file
            // path is the same server-side-detection approach
            // DocumentService::insertAtPath() already uses elsewhere in this
            // codebase, and it's a core PHP function (fileinfo extension),
            // no extra dependency needed.
            $mimeType = mime_content_type($file->getPathname());

            $payload = DocumentPayload::fromBytes(
                $file->getClientOriginalName(),
                $mimeType !== false ? $mimeType : '',
                $bytes,
            );
        } catch (InvalidArgumentException) {
            return $this->error(xl('That file type is not supported. Attach a PDF, PNG, or JPEG.'), 400, $correlationId);
        }

        try {
            $ingestStartedAt = microtime(true);
            $result = $this->pipeline->ingest($patientId, $correlationId, $payload, $docType);
            $ingestEndedAt = microtime(true);

            $telemetry = $result->telemetry ?? $result->extractionFailure?->telemetry;
            if ($telemetry !== null) {
                $authUser = $session->get('authUser');
                $this->tracer->traceExtraction(
                    $correlationId,
                    $patientId,
                    is_string($authUser) ? $authUser : '',
                    $docType,
                    $result->success,
                    // Success means the extraction passed SchemaValidator; every
                    // failure is either schema-invalid or unparseable.
                    $result->success,
                    $result->extractionFailure?->failureReason,
                    $telemetry,
                    $ingestStartedAt,
                    $ingestEndedAt,
                );
            }

            if ($result->success && $result->document !== null) {
                return new JsonResponse([
                    'success' => true,
                    'docType' => $result->document->docType->value,
                    'fields' => $result->document->fields,
                    'documentId' => $result->documentId,
                    'extractionId' => $result->extractionId,
                    'correlationId' => $correlationId,
                ]);
            }

            $failure = $result->extractionFailure;
            if ($failure !== null && $failure->validation !== null) {
                return new JsonResponse([
                    'success' => false,
                    'reason' => 'schema_invalid',
                    'failures' => array_map(
                        static fn ($finding): array => $finding->toArray(),
                        $failure->validation->findings,
                    ),
                    'correlationId' => $correlationId,
                ], 422);
            }

            return new JsonResponse([
                'success' => false,
                'reason' => 'parse_failed',
                'message' => $failure->failureReason ?? xl('Could not process that document.'),
                'correlationId' => $correlationId,
            ], 422);
        } catch (AnthropicException | SqlQueryException | RuntimeException | JsonException $e) {
            // Exception messages can carry API detail, file content, or SQL.
            // Log with PSR-3 context; return something generic to the browser.
            ServiceContainer::getLogger()->error('Clinical Co-Pilot document upload failed', [
                'correlationId' => $correlationId,
                'pid' => $patientId,
                'docType' => $docType->value,
                'exception' => $e,
            ]);

            return $this->error(xl('The co-pilot could not process that document right now.'), 500, $correlationId);
        }
    }

    private function error(string $message, int $status, string $correlationId): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'correlationId' => $correlationId], $status);
    }
}
