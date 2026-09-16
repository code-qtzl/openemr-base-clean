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
use OpenEMR\Modules\ClinicalCopilot\Service\ChartContextTools;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class CopilotChatController
{
    private const MAX_QUESTION_LENGTH = 2000;

    public function handleRequest(): void
    {
        $this->buildResponse(Request::createFromGlobals())->send();
    }

    private function buildResponse(Request $request): JsonResponse
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token'), $session)) {
            return $this->error(xl('Session expired. Reload the page and try again.'), 403);
        }

        if (!AclMain::aclCheckCore('patients', 'demo')) {
            return $this->error(xl('You do not have permission to view this chart.'), 403);
        }

        $sessionPid = $session->get('pid');
        $patientId = is_numeric($sessionPid) ? (int) $sessionPid : 0;
        if ($patientId <= 0) {
            return $this->error(xl('No patient is currently selected.'), 400);
        }

        $question = trim($request->request->getString('question'));
        if ($question === '') {
            return $this->error(xl('Please enter a question.'), 400);
        }
        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            return $this->error(xl('Question is too long.'), 400);
        }

        try {
            $tools = new ChartContextTools(
                $patientId,
                $this->sessionString($session->get('authUser')),
                $this->sessionString($session->get('authProvider')),
            );

            $result = (new CopilotService($tools))->ask($question);

            return new JsonResponse([
                'reply' => $result['reply'],
                'toolsUsed' => $result['toolsUsed'],
            ]);
        } catch (AnthropicException | SqlQueryException | RuntimeException | JsonException $e) {
            // Exception messages can carry API detail, prompt content or SQL.
            // Log with PSR-3 context; return something generic to the browser.
            ServiceContainer::getLogger()->error('Clinical Co-Pilot request failed', [
                'pid' => $patientId,
                'exception' => $e,
            ]);

            return $this->error(xl('The co-pilot could not answer that right now.'), 500);
        }
    }

    /**
     * Session values are mixed; narrow instead of blind-casting.
     */
    private function sessionString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
