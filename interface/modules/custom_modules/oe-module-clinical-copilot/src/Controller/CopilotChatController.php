<?php

/**
 * Clinical Co-Pilot chat request handler.
 *
 * This is the security boundary for the co-pilot. Everything that matters is
 * decided here, and nothing downstream re-derives it:
 *
 *   - The CSRF token is verified before any work happens.
 *   - ACL is checked against the same permission the chart itself uses
 *     ('patients', 'demo'), so the co-pilot can never widen what the signed-in
 *     user may already read.
 *   - The patient id comes from the SESSION -- the chart the user actually has
 *     open -- and is parsed to an int here before being handed to the tool
 *     layer. No request parameter and no model output can redirect it at
 *     another patient.
 *
 * It is also where the request's correlation id is minted. Every response --
 * success or error -- carries it, every log line this request produces
 * (EventAuditLogger, the PHP error log, clinical_copilot_log) is tagged with
 * it, and it rides along as an outbound header on the Anthropic call. A full
 * trace of one request can be reconstructed from logs alone by grepping for
 * that one id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use Anthropic\Core\Exceptions\AnthropicException;
use JsonException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\AnthropicClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\Conversation;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\ConversationRole;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\ConversationStore;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\ConversationTurn;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\SqlConversationStore;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotInteractionLogger;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use OpenEMR\Modules\ClinicalCopilot\Service\DefaultAnthropicClientFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\LangfuseTracer;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class CopilotChatController
{
    private const MAX_QUESTION_LENGTH = 2000;

    public function __construct(
        private CopilotInteractionLogger $interactionLogger = new CopilotInteractionLogger(),
        private LangfuseTracer $tracer = new LangfuseTracer(),
        private AnthropicClientFactory $clientFactory = new DefaultAnthropicClientFactory(),
        private ConversationStore $conversationStore = new SqlConversationStore(),
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

        $sessionPid = $session->get('pid');
        $patientId = is_numeric($sessionPid) ? (int) $sessionPid : 0;
        if ($patientId <= 0) {
            return $this->error(xl('No patient is currently selected.'), 400, $correlationId);
        }

        $question = trim($request->request->getString('question'));
        if ($question === '') {
            return $this->error(xl('Please enter a question.'), 400, $correlationId);
        }
        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            return $this->error(xl('Question is too long.'), 400, $correlationId);
        }

        $authUser = $this->sessionString($session->get('authUser'));
        // The DB-tracked session identity (OpenEMR\Common\Session\SessionTracker),
        // not the raw PHP session id -- it already has a durable row keyed to
        // this browser session, with its own TTL housekeeping, that
        // SqlConversationStore's eviction mirrors. Empty until
        // SessionTracker::setupSessionDatabaseTracker() has run for this
        // session (main_screen.php, on every login) -- in that case,
        // conversation history is skipped rather than failing the request.
        $sessionUuid = $this->sessionString($session->get('session_database_uuid'));
        $startedAt = microtime(true);

        try {
            $tools = new ChartContextTools(
                $patientId,
                $authUser,
                $this->sessionString($session->get('authProvider')),
                $correlationId,
            );

            $history = $sessionUuid !== ''
                ? $this->conversationStore->load($sessionUuid, $patientId)
                : new Conversation();

            $result = (new CopilotService($tools, $this->clientFactory))->ask($question, $correlationId, $history);
            $endedAt = microtime(true);

            $this->tracer->traceAsk(
                $correlationId,
                $patientId,
                $authUser,
                CopilotService::model(),
                $question,
                $result,
                $startedAt,
                $endedAt,
            );

            $this->interactionLogger->logSuccess(
                $correlationId,
                $patientId,
                $authUser,
                $question,
                $result->reply,
                $result->toolsUsed,
                CopilotService::model(),
                self::elapsedMs($startedAt),
                $result->verificationPassed,
            );

            if (!$result->verificationPassed) {
                ServiceContainer::getLogger()->warning('Clinical Co-Pilot answer failed verification', [
                    'correlationId' => $correlationId,
                    'pid' => $patientId,
                    'reason' => $result->verificationReason,
                ]);
            }

            if ($sessionUuid !== '') {
                $this->conversationStore->save(
                    $sessionUuid,
                    $patientId,
                    $history->append(
                        new ConversationTurn(ConversationRole::User, $question),
                        new ConversationTurn(ConversationRole::Assistant, $result->reply),
                    ),
                );
            }

            return new JsonResponse([
                'reply' => $result->reply,
                'toolsUsed' => $result->toolsUsed,
                'correlationId' => $correlationId,
                'verificationPassed' => $result->verificationPassed,
            ]);
        } catch (AnthropicException | SqlQueryException | RuntimeException | JsonException $e) {
            $endedAt = microtime(true);

            // Exception messages can carry API detail, prompt content or SQL.
            // Log with PSR-3 context; return something generic to the browser.
            ServiceContainer::getLogger()->error('Clinical Co-Pilot request failed', [
                'correlationId' => $correlationId,
                'pid' => $patientId,
                'exception' => $e,
            ]);

            $this->interactionLogger->logFailure(
                $correlationId,
                $patientId,
                $authUser,
                $question,
                CopilotService::model(),
                self::elapsedMs($startedAt),
            );

            $this->tracer->traceFailure($correlationId, $patientId, $authUser, $question, $e, $startedAt, $endedAt);

            return $this->error(xl('The co-pilot could not answer that right now.'), 500, $correlationId);
        }
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Session values are mixed; narrow instead of blind-casting.
     */
    private function sessionString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function error(string $message, int $status, string $correlationId): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'correlationId' => $correlationId], $status);
    }
}
