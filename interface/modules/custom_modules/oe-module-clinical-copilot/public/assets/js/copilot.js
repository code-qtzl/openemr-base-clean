/**
 * Clinical Co-Pilot chart panel.
 *
 * Deliberately dependency-free and defensive about escaping: every value that
 * reaches the DOM goes through textContent, never innerHTML, so a model reply
 * cannot inject markup into the chart.
 */
(function () {
    'use strict';

    var form = document.getElementById('copilot-form');
    if (!form) {
        return;
    }

    var input = document.getElementById('copilot-input');
    var sendBtn = document.getElementById('copilot-send');
    var log = document.getElementById('copilot-log');
    var csrf = document.getElementById('copilot-csrf');
    var endpoint = form.dataset.endpoint;

    function append(text, variant, toolsUsed) {
        var div = document.createElement('div');
        div.className = 'copilot-msg copilot-msg-' + variant;
        div.textContent = text;

        if (toolsUsed && toolsUsed.length) {
            var tools = document.createElement('div');
            tools.className = 'copilot-tools';
            tools.textContent = 'Chart data read: ' + toolsUsed.join(', ');
            div.appendChild(tools);
        }

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
                    append(data.error, 'error');
                } else {
                    append(data.reply || '(no answer returned)', 'assistant', data.toolsUsed);
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
}());
