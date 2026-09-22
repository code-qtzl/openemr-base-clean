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
 *   - A per-session request-rate limit is enforced before any Anthropic call
 *     is made, so a stuck client-side retry loop or a single misbehaving
 *     session cannot run up unbounded real API spend (AI_SPEND.md).
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
 * Once the fast validation above passes, beginHeartbeat() commits the HTTP
 * response and starts writing keep-alive bytes for the duration of the
 * Anthropic call -- see its docblock for why: a fully-synchronous,
 * non-streaming request that writes nothing until it's entirely done reads
 * as a dead connection to any reverse proxy in front of it, and one dropped
 * a real Railway request that had actually succeeded server-side.
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
use OpenEMR\Common\Session\SessionUtil;
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
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class CopilotChatController
{
    private const MAX_QUESTION_LENGTH = 2000;

    /**
     * Session key holding this session's rate-limit window state, an
     * array{windowStart: int, count: int}.
     */
    private const RATE_LIMIT_SESSION_KEY = 'clinical_copilot_rate_limit';

    /**
     * Requests allowed per session per RATE_LIMIT_WINDOW_SECONDS. Sized
     * generously above realistic use (USERS.md's Dr. Ruiz persona asks a
     * handful of questions per patient visit) while still bounding worst-case
     * real Anthropic spend from a stuck client-side retry loop or a
     * misbehaving session to roughly this count times AI_SPEND.md's
     * per-question rate, not an unbounded amount.
     */
    private const RATE_LIMIT_MAX_REQUESTS = 15;

    private const RATE_LIMIT_WINDOW_SECONDS = 300;

    private ClockInterface $clock;

    public function __construct(
        private CopilotInteractionLogger $interactionLogger = new CopilotInteractionLogger(),
        private LangfuseTracer $tracer = new LangfuseTracer(),
        private AnthropicClientFactory $clientFactory = new DefaultAnthropicClientFactory(),
        private ConversationStore $conversationStore = new SqlConversationStore(),
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? ServiceContainer::getClock();
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

        if (!$this->withinRateLimit($session)) {
            return $this->error(xl('Too many requests. Please wait a moment before asking again.'), 429, $correlationId);
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

        $this->beginHeartbeat();

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

    /**
     * Sliding-window request-rate limit, scoped to this browser session.
     * Checked before any Anthropic call is made, so a rejected request costs
     * nothing. Not a defense against a determined multi-session attacker --
     * every request here already required a valid, ACL-checked login -- this
     * bounds the ordinary failure mode of a stuck client-side retry loop or
     * one session asking far outside realistic use.
     */
    private function withinRateLimit(SessionInterface $session): bool
    {
        $now = $this->clock->now()->getTimestamp();

        /** @var mixed $state */
        $state = $session->get(self::RATE_LIMIT_SESSION_KEY);
        $windowStart = is_array($state) && is_int($state['windowStart'] ?? null) ? $state['windowStart'] : $now;
        $count = is_array($state) && is_int($state['count'] ?? null) ? $state['count'] : 0;

        if ($now - $windowStart >= self::RATE_LIMIT_WINDOW_SECONDS) {
            $windowStart = $now;
            $count = 0;
        }

        ++$count;
        SessionUtil::setSession(self::RATE_LIMIT_SESSION_KEY, ['windowStart' => $windowStart, 'count' => $count]);

        return $count <= self::RATE_LIMIT_MAX_REQUESTS;
    }

    /**
     * Commits this response to HTTP 200 with a keep-alive heartbeat running
     * for the duration of the (potentially long, fully-synchronous,
     * non-streaming) Anthropic call that follows, so a reverse proxy sees
     * steady byte traffic instead of an apparently-dead connection.
     * Appendix_CheckList.md Phase 3 Item 15: confirmed against the live
     * Railway deployment that a request can complete successfully
     * server-side in ~17s while the browser has already errored out well
     * before that, because nothing is written to the response until the
     * whole conversation finishes.
     *
     * Trade-off, made deliberately rather than by accident: once this runs,
     * the real HTTP status code this request ultimately sends is locked at
     * 200, because the status line must be sent before we can start writing
     * keep-alive bytes, before the Anthropic call's outcome is known. A
     * later failure (AnthropicException/SqlQueryException/etc. below) still
     * produces a JsonResponse with the "correct" status internally (500,
     * etc.) -- Symfony's Response::sendHeaders() silently no-ops when
     * headers were already sent, so that status is never actually
     * transmitted -- but the frontend (copilot.js) already ignores the HTTP
     * status entirely and only branches on the JSON body's `error` key, so
     * this is invisible to the user. Skipped entirely under the CLI SAPI
     * (i.e. the test suite): header()/echo/flush would pollute PHPUnit's
     * output and there is nothing real to keep alive against a synchronous
     * test double, so buildResponse()'s return value -- and its status
     * codes -- stay exactly as tests assert them.
     */
    private function beginHeartbeat(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        $this->clientFactory->setHeartbeat(static function (): void {
            echo ' ';
            flush();
        });
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
