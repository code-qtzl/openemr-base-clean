<?php

/**
 * Renders the Clinical Co-Pilot chart panel shell.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Service\CopilotService;

final class CopilotPanelController
{
    public function __construct(private readonly string $installPath)
    {
    }

    public function render(): string
    {
        $base = attr($this->installPath);
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token = attr(CsrfUtils::collectCsrfToken(session: $session));

        if (!CopilotService::isConfigured()) {
            return '<div class="card mb-2"><div class="card-body py-2 text-muted">'
                . text(xl('Clinical Co-Pilot is not configured on this server.'))
                . '</div></div>';
        }

        $title = text(xl('Clinical Co-Pilot'));
        $placeholder = attr(xl('Ask about this patient\'s chart...'));
        $send = text(xl('Ask'));
        $disclaimer = text(xl('Decision support only. Verify against the chart before acting.'));

        return <<<HTML
            <link rel="stylesheet" href="{$base}/public/assets/css/copilot.css">
            <div class="card mb-2" id="copilot-card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold">{$title}</span>
                    <small class="text-muted">{$disclaimer}</small>
                </div>
                <div class="card-body py-2">
                    <div id="copilot-log" class="copilot-log" aria-live="polite"></div>
                    <form id="copilot-form" class="form-inline mt-2" autocomplete="off" data-endpoint="{$base}/public/ajax.php">
                        <input type="hidden" id="copilot-csrf" value="{$token}">
                        <input type="text" id="copilot-input" class="form-control flex-grow-1 mr-2"
                               placeholder="{$placeholder}" aria-label="{$placeholder}">
                        <button type="submit" class="btn btn-primary" id="copilot-send">{$send}</button>
                    </form>
                </div>
            </div>
            <script src="{$base}/public/assets/js/copilot.js"></script>
            HTML;
    }
}
