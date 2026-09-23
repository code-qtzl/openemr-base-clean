<?php

/**
 * Renders the Clinical Co-Pilot sidebar shell.
 *
 * A fixed-position, page-level sidebar (see Bootstrap::renderChartPanel()'s
 * docblock for why it renders at EVENT_RENDER_POST_PAGELOAD rather than
 * inside the dashboard's column grid), open by default with a close control
 * and a floating reopen tab for when it's been closed. All element ids used
 * by the existing chat flow (#copilot-form, #copilot-csrf, #copilot-input,
 * #copilot-send, #copilot-log) are unchanged, so copilot.js's message-send
 * logic needs no changes -- only the surrounding shell and the new
 * sidebar-toggle/attach-document controls are new.
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
            return '<div class="copilot-sidebar copilot-sidebar-unconfigured" id="copilot-sidebar">'
                . '<div class="copilot-sidebar-header"><span class="font-weight-bold">'
                . text(xl('Clinical Co-Pilot'))
                . '</span></div><div class="p-3 text-muted">'
                . text(xl('Clinical Co-Pilot is not configured on this server.'))
                . '</div></div>';
        }

        $title = text(xl('Clinical Co-Pilot'));
        $placeholder = attr(xl('Ask about this patient\'s chart...'));
        $send = text(xl('Ask'));
        $disclaimer = text(xl('Decision support only. Verify against the chart before acting.'));
        $close = attr(xl('Close Co-Pilot'));
        $reopen = text(xl('Co-Pilot'));
        $attach = attr(xl('Attach a document'));
        $modelLabel = text(xl('Model'));
        $modelNote = text(xl('More models coming soon.'));
        $docTypeLabel = text(xl('Document type'));
        $labOption = text(xl('Lab report (PDF)'));
        $intakeOption = text(xl('Intake form'));
        $chooseFile = text(xl('Choose file...'));
        $upload = text(xl('Upload'));
        $cancel = attr(xl('Cancel'));

        return <<<HTML
            <link rel="stylesheet" href="{$base}/public/assets/css/copilot.css">
            <button type="button" id="copilot-reopen" class="copilot-reopen-tab" hidden>
                <i class="fa fa-robot" aria-hidden="true"></i> {$reopen}
            </button>
            <div class="copilot-sidebar" id="copilot-sidebar">
                <div class="copilot-sidebar-header">
                    <span class="font-weight-bold"><i class="fa fa-robot" aria-hidden="true"></i> {$title}</span>
                    <button type="button" id="copilot-close" class="copilot-icon-btn" aria-label="{$close}" title="{$close}">
                        <i class="fa fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="copilot-sidebar-subheader text-muted">{$disclaimer}</div>
                <div class="copilot-model-row">
                    <label for="copilot-model-select" class="mb-0 mr-2">{$modelLabel}</label>
                    <select id="copilot-model-select" class="form-control form-control-sm" disabled>
                        <option selected>Claude Opus 5 (current)</option>
                        <option>Claude Sonnet 5</option>
                        <option>Claude Haiku 4.5</option>
                    </select>
                    <small class="text-muted d-block mt-1">{$modelNote}</small>
                </div>
                <div id="copilot-log" class="copilot-log" aria-live="polite"></div>
                <div id="copilot-upload-panel" class="copilot-upload-panel" hidden>
                    <label for="copilot-doctype" class="mb-1">{$docTypeLabel}</label>
                    <select id="copilot-doctype" class="form-control form-control-sm mb-2">
                        <option value="lab_pdf">{$labOption}</option>
                        <option value="intake_form">{$intakeOption}</option>
                    </select>
                    <input type="file" id="copilot-file-input" class="d-none" accept="application/pdf,image/png,image/jpeg">
                    <div class="d-flex align-items-center">
                        <button type="button" id="copilot-choose-file" class="btn btn-outline-secondary btn-sm mr-2">{$chooseFile}</button>
                        <span id="copilot-file-name" class="text-muted small flex-grow-1"></span>
                    </div>
                    <div class="mt-2 d-flex justify-content-end">
                        <button type="button" id="copilot-upload-cancel" class="btn btn-link btn-sm" aria-label="{$cancel}">{$cancel}</button>
                        <button type="button" id="copilot-upload-submit" class="btn btn-primary btn-sm" disabled>{$upload}</button>
                    </div>
                </div>
                <form id="copilot-form" class="form-inline mt-2" autocomplete="off"
                      data-endpoint="{$base}/public/ajax.php"
                      data-upload-endpoint="{$base}/public/upload-ajax.php">
                    <input type="hidden" id="copilot-csrf" value="{$token}">
                    <button type="button" id="copilot-attach" class="btn btn-outline-secondary mr-2" aria-label="{$attach}" title="{$attach}">
                        <i class="fa fa-paperclip" aria-hidden="true"></i>
                    </button>
                    <input type="text" id="copilot-input" class="form-control flex-grow-1 mr-2"
                           placeholder="{$placeholder}" aria-label="{$placeholder}">
                    <button type="submit" class="btn btn-primary" id="copilot-send">{$send}</button>
                </form>
            </div>
            <script src="{$base}/public/assets/js/copilot.js"></script>
            HTML;
    }
}
