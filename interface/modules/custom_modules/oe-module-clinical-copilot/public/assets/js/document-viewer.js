/**
 * Clinical Co-Pilot click-to-source document viewer.
 *
 * Renders one page of a previously-uploaded lab PDF / intake form (via
 * pdf.js, vendored under public/assets/vendor/pdfjs/) and draws a
 * highlighted box over the cited region, so a clinician can jump from a
 * citation straight to the source region on the page (AgentForge2 Core
 * Requirement #5's "visual PDF bounding-box overlay").
 *
 * Fetches the raw PDF bytes from OpenEMR core's own existing,
 * session-authenticated, IDOR-checked document route
 * (controller.php?document&retrieve&...) -- no new backend endpoint exists
 * or is needed just to stream the file. `withCredentials: true` carries the
 * same session cookie copilot.js's own fetch calls already rely on.
 *
 * Deliberately dependency-free beyond pdf.js and, like copilot.js, never
 * uses innerHTML for anything derived from a citation.
 */
/* global pdfjsLib */
(function () {
    'use strict';

    var backdrop = document.getElementById('copilot-docviewer-backdrop');
    var panel = document.getElementById('copilot-docviewer-panel');
    var closeBtn = document.getElementById('copilot-docviewer-close');
    var body = document.getElementById('copilot-docviewer-body');
    var titleEl = document.getElementById('copilot-docviewer-title');

    if (!backdrop || !panel || !closeBtn || !body || !titleEl || typeof pdfjsLib === 'undefined') {
        return;
    }

    pdfjsLib.GlobalWorkerOptions.workerSrc = backdrop.dataset.workerSrc;

    var retrieveBase = backdrop.dataset.retrieveUrl;
    var currentRequestToken = 0;

    function clearBody() {
        while (body.firstChild) {
            body.removeChild(body.firstChild);
        }
    }

    function showStatus(message) {
        clearBody();
        var status = document.createElement('div');
        status.className = 'copilot-docviewer-status';
        status.textContent = message;
        body.appendChild(status);
    }

    function close() {
        backdrop.hidden = true;
        clearBody();
        currentRequestToken += 1;
    }

    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) {
            close();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !backdrop.hidden) {
            close();
        }
    });

    /**
     * @param {{patientId: (string|number), documentId: (string|number), title: string, bbox: ?{page: number, x0: number, y0: number, x1: number, y1: number}}} opts
     */
    function open(opts) {
        if (!opts || !opts.documentId || !opts.patientId) {
            return;
        }

        titleEl.textContent = opts.title || 'Source document';
        backdrop.hidden = false;
        showStatus('Loading document…');

        var requestToken = ++currentRequestToken;
        // Order matters: OpenEMR's legacy Controller::act() routing passes
        // query params positionally to C_Document::retrieve_action(patient_id,
        // document_id, as_file, original_file, ...), not matched by name. This
        // must match that parameter order exactly, and retrieveBase (server-
        // rendered) must carry none of these four -- see
        // CopilotPanelController::render()'s docblock.
        var url = retrieveBase
            + '&patient_id=' + encodeURIComponent(opts.patientId)
            + '&document_id=' + encodeURIComponent(opts.documentId)
            + '&as_file=false'
            + '&original_file=true';

        pdfjsLib.getDocument({ url: url, withCredentials: true }).promise
            .then(function (pdf) {
                if (requestToken !== currentRequestToken) {
                    return null;
                }

                var pageNumber = opts.bbox ? opts.bbox.page + 1 : 1;
                pageNumber = Math.min(Math.max(pageNumber, 1), pdf.numPages);

                return pdf.getPage(pageNumber);
            })
            .then(function (page) {
                if (!page || requestToken !== currentRequestToken) {
                    return;
                }

                renderPage(page, opts.bbox);
            })
            .catch(function () {
                if (requestToken === currentRequestToken) {
                    showStatus('Could not load that document.');
                }
            });
    }

    function renderPage(page, bbox) {
        var viewport = page.getViewport({ scale: 1.5 });

        var canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;

        var wrap = document.createElement('div');
        wrap.className = 'copilot-docviewer-canvas-wrap';
        wrap.appendChild(canvas);

        clearBody();
        body.appendChild(wrap);

        var context = canvas.getContext('2d');
        page.render({ canvasContext: context, viewport: viewport }).promise.then(function () {
            if (!bbox) {
                return;
            }

            var highlight = document.createElement('div');
            highlight.className = 'copilot-docviewer-highlight';
            highlight.style.left = (bbox.x0 * 100) + '%';
            highlight.style.top = (bbox.y0 * 100) + '%';
            highlight.style.width = ((bbox.x1 - bbox.x0) * 100) + '%';
            highlight.style.height = ((bbox.y1 - bbox.y0) * 100) + '%';
            wrap.appendChild(highlight);
        });
    }

    window.CopilotDocumentViewer = { open: open, close: close };
}());
