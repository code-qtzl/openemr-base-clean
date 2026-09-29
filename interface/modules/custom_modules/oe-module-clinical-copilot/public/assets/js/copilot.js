/**
 * Clinical Co-Pilot sidebar.
 *
 * Deliberately dependency-free and defensive about escaping: every value that
 * reaches the DOM goes through textContent, never innerHTML, so a model reply
 * (or an uploaded document's extracted content) cannot inject markup into the
 * chart.
 *
 * The chat send flow below is unchanged from the prior inline-panel version;
 * only the sidebar open/close toggle and the attach-document flow are new.
 */
(function () {
    'use strict';

    var form = document.getElementById('copilot-form');
    if (!form) {
        return;
    }

    var sidebar = document.getElementById('copilot-sidebar');
    var input = document.getElementById('copilot-input');
    var sendBtn = document.getElementById('copilot-send');
    var log = document.getElementById('copilot-log');
    var csrf = document.getElementById('copilot-csrf');
    var endpoint = form.dataset.endpoint;
    var uploadEndpoint = form.dataset.uploadEndpoint;
    var patientId = form.dataset.pid;

    // A citation is only openable in the document viewer when it points at
    // a real stored document (lab_pdf/intake_form with a document_id) --
    // chart_tool/guideline citations have no PDF page to show.
    function isDocumentCitation(citation) {
        return !!citation
            && (citation.source_type === 'lab_pdf' || citation.source_type === 'intake_form')
            && !!citation.document_id;
    }

    function openSourceViewer(citation, title) {
        if (!window.CopilotDocumentViewer || !patientId) {
            return;
        }

        window.CopilotDocumentViewer.open({
            patientId: patientId,
            documentId: citation.document_id,
            title: title,
            bbox: citation.bbox || null
        });
    }

    function appendSourceChips(container, claims) {
        if (!claims || !claims.length) {
            return;
        }

        var chipIndex = 0;
        claims.forEach(function (claim) {
            if (!isDocumentCitation(claim.citation)) {
                return;
            }

            chipIndex += 1;
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'copilot-source-chip';
            chip.textContent = 'Source ' + chipIndex;
            chip.addEventListener('click', function () {
                openSourceViewer(claim.citation, 'Source ' + chipIndex);
            });
            container.appendChild(chip);
        });
    }

    function append(text, variant, toolsUsed, claims) {
        var div = document.createElement('div');
        div.className = 'copilot-msg copilot-msg-' + variant;
        div.textContent = text;

        if (toolsUsed && toolsUsed.length) {
            var tools = document.createElement('div');
            tools.className = 'copilot-tools';
            tools.textContent = 'Chart data read: ' + toolsUsed.join(', ');
            div.appendChild(tools);
        }

        appendSourceChips(div, claims);

        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var question = input.value.trim();
        if (!question) {
            return;
        }

        append(question, 'user');
        input.value = '';
        input.disabled = true;
        sendBtn.disabled = true;

        var pending = append('Thinking...', 'assistant');

        var body = new URLSearchParams();
        body.append('question', question);
        body.append('csrf_token', csrf.value);

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Unexpected response from the server.');
                });
            })
            .then(function (data) {
                log.removeChild(pending);
                if (data.error) {
                    var message = data.error;
                    if (data.correlationId) {
                        message += ' (reference: ' + data.correlationId + ')';
                    }
                    append(message, 'error');
                } else {
                    append(data.reply || '(no answer returned)', 'assistant', data.toolsUsed, data.claims);
                }
            })
            .catch(function () {
                log.removeChild(pending);
                append('Could not reach the co-pilot.', 'error');
            })
            .finally(function () {
                input.disabled = false;
                sendBtn.disabled = false;
                input.focus();
            });
    });

    // --- Sidebar open/close -------------------------------------------------

    var closeBtn = document.getElementById('copilot-close');
    var reopenBtn = document.getElementById('copilot-reopen');

    if (sidebar && closeBtn && reopenBtn) {
        closeBtn.addEventListener('click', function () {
            sidebar.classList.add('copilot-sidebar-closed');
            reopenBtn.hidden = false;
        });

        reopenBtn.addEventListener('click', function () {
            sidebar.classList.remove('copilot-sidebar-closed');
            reopenBtn.hidden = true;
        });
    }

    // --- Attach document ------------------------------------------------------

    var attachBtn = document.getElementById('copilot-attach');
    var uploadPanel = document.getElementById('copilot-upload-panel');
    var docTypeSelect = document.getElementById('copilot-doctype');
    var fileInput = document.getElementById('copilot-file-input');
    var chooseFileBtn = document.getElementById('copilot-choose-file');
    var fileNameLabel = document.getElementById('copilot-file-name');
    var uploadCancelBtn = document.getElementById('copilot-upload-cancel');
    var uploadSubmitBtn = document.getElementById('copilot-upload-submit');

    if (!attachBtn || !uploadPanel || !docTypeSelect || !fileInput || !chooseFileBtn
        || !fileNameLabel || !uploadCancelBtn || !uploadSubmitBtn || !uploadEndpoint) {
        return;
    }

    function resetUploadPanel() {
        uploadPanel.hidden = true;
        fileInput.value = '';
        fileNameLabel.textContent = '';
        uploadSubmitBtn.disabled = true;
    }

    attachBtn.addEventListener('click', function () {
        uploadPanel.hidden = !uploadPanel.hidden;
    });

    uploadCancelBtn.addEventListener('click', function () {
        resetUploadPanel();
    });

    chooseFileBtn.addEventListener('click', function () {
        fileInput.click();
    });

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        fileNameLabel.textContent = file ? file.name : '';
        uploadSubmitBtn.disabled = !file;
    });

    function formatExtractedFields(fields) {
        if (!fields || typeof fields !== 'object') {
            return '';
        }

        var parts = [];
        Object.keys(fields).forEach(function (key) {
            if (key === 'source_citation') {
                return;
            }
            var value = fields[key];
            if (Array.isArray(value)) {
                value = value.length ? value.join(', ') : '(none)';
            }
            parts.push(key + ': ' + value);
        });

        return parts.join(' | ');
    }

    uploadSubmitBtn.addEventListener('click', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) {
            return;
        }

        var fileName = file.name;
        uploadSubmitBtn.disabled = true;
        chooseFileBtn.disabled = true;

        var pending = append('Uploading ' + fileName + '...', 'upload');

        var body = new FormData();
        body.append('document', file);
        body.append('doc_type', docTypeSelect.value);
        body.append('csrf_token', csrf.value);

        fetch(uploadEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Unexpected response from the server.');
                });
            })
            .then(function (data) {
                log.removeChild(pending);

                if (data.success) {
                    var summary = formatExtractedFields(data.fields);
                    var msg = append(
                        fileName + ' processed as ' + data.docType + '.' + (summary ? ' ' + summary : ''),
                        'upload'
                    );

                    var sourceCitation = data.fields && data.fields.source_citation;
                    if (data.documentId && sourceCitation) {
                        appendSourceChips(msg, [{
                            citation: {
                                source_type: data.docType,
                                document_id: String(data.documentId),
                                bbox: sourceCitation.bbox || null
                            }
                        }]);
                    }

                    resetUploadPanel();
                    return;
                }

                var reason = data.message
                    || (data.failures && data.failures.length
                        ? 'Missing or invalid: ' + data.failures.map(function (f) { return f.field; }).join(', ')
                        : null)
                    || data.error
                    || 'Could not process that document.';
                if (data.correlationId) {
                    reason += ' (reference: ' + data.correlationId + ')';
                }
                append(reason, 'upload-error');
            })
            .catch(function () {
                log.removeChild(pending);
                append('Could not reach the co-pilot to upload that document.', 'upload-error');
            })
            .finally(function () {
                uploadSubmitBtn.disabled = false;
                chooseFileBtn.disabled = false;
            });
    });
}());
