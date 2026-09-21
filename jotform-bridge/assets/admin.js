/**
 * Jotform Bridge admin screens. Behaviours are declared in the markup:
 *
 *   [data-jfb-toggle]   a select that shows or hides rows while it holds one value
 *   [data-jfb-copy]     a button that puts text on the clipboard
 *   [data-jfb-connect]  a button that resolves a Jotform form ID into its title
 *   [data-jfb-confirm]  a form that asks before it submits
 */
(function () {
    'use strict';

    var SETTINGS = window.jotformBridgeAdmin || {};

    /** Delay between clearing the live region and writing to it, so a repeat is announced. */
    var ANNOUNCE_DELAY = 100;

    /** How long the "Copied" marker stays up, in milliseconds. */
    var COPIED_VISIBLE = 1500;

    /** A localized message from PHP. */
    function message(key) {
        return (SETTINGS.messages && SETTINGS.messages[key]) || '';
    }

    /** The element an attribute selector points at, or null. */
    function find(element, attribute) {
        var selector = element.getAttribute(attribute);

        return selector ? document.querySelector(selector) : null;
    }

    /** Writes to the screen's live region. */
    function announce(text) {
        var region = document.querySelector('[data-jfb-status]');

        if (!region || !text) {
            return;
        }

        region.textContent = '';

        window.setTimeout(function () {
            region.textContent = text;
        }, ANNOUNCE_DELAY);
    }

    /** Shows the rows a select points at only while it holds the wanted value. */
    function bindToggles() {
        var controls = document.querySelectorAll('[data-jfb-toggle]');

        for (var i = 0; i < controls.length; i++) {
            bindToggle(controls[i]);
        }
    }

    function bindToggle(control) {
        var selector = control.getAttribute('data-jfb-toggle');
        var wanted = control.getAttribute('data-jfb-toggle-value') || '';

        if (!selector) {
            return;
        }

        var rows = document.querySelectorAll(selector);

        if (!rows.length) {
            return;
        }

        function sync() {
            var show = control.value === wanted;

            for (var i = 0; i < rows.length; i++) {
                rows[i].hidden = !show;
            }
        }

        control.addEventListener('change', sync);
        sync();
    }

    /** Text from the attribute, or from the control it points at. */
    function copyText(button) {
        var literal = button.getAttribute('data-jfb-copy');

        if (literal) {
            return literal;
        }

        var source = find(button, 'data-jfb-copy-from');

        return source && typeof source.value === 'string' ? source.value : '';
    }

    /** Clipboard API in a secure context, execCommand otherwise. */
    function copy(text, source, button) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(
                function () {
                    return true;
                },
                function () {
                    return legacyCopy(text, source, button);
                }
            );
        }

        return Promise.resolve(legacyCopy(text, source, button));
    }

    function legacyCopy(text, source, button) {
        // Select the real control where there is one, so a refused copy leaves the text selected.
        if (source && typeof source.select === 'function') {
            source.focus();
            source.select();

            var done = exec();

            if (button && typeof button.focus === 'function') {
                button.focus();
            }

            return done;
        }

        var field = document.createElement('textarea');

        field.value = text;
        field.setAttribute('readonly', 'readonly');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();

        var done = exec();

        document.body.removeChild(field);

        return done;
    }

    function exec() {
        try {
            return document.execCommand('copy');
        } catch (error) {
            return false;
        }
    }

    /** Shows the button's "Copied" marker for a moment; the live region announces separately. */
    function confirmCopy(button) {
        button.classList.add('is-copied');

        window.clearTimeout(button.__jfbCopyTimer);

        button.__jfbCopyTimer = window.setTimeout(function () {
            button.classList.remove('is-copied');
        }, COPIED_VISIBLE);
    }

    function bindCopyButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest
                ? event.target.closest('[data-jfb-copy], [data-jfb-copy-from]')
                : null;

            if (!button) {
                return;
            }

            event.preventDefault();

            var text = copyText(button);

            if (!text) {
                return;
            }

            var source = find(button, 'data-jfb-copy-from');

            copy(text, source, button).then(function (done) {
                if (done) {
                    confirmCopy(button);
                }

                announce(message(done ? 'copied' : 'copyFailed'));
            });
        });
    }

    /** A form with [data-jfb-confirm] asks before it submits. */
    function bindConfirms() {
        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form || !form.getAttribute) {
                return;
            }

            var question = form.getAttribute('data-jfb-confirm');

            if (question && !window.confirm(question)) {
                event.preventDefault();
            }
        });
    }

    /**
     * "Connect form": resolves the form ID beside the button over admin-ajax.
     * Endpoint, action, nonce, source field and target all come from the markup.
     */
    function bindConnectButtons() {
        if (!window.fetch) {
            return;
        }

        var buttons = document.querySelectorAll('[data-jfb-connect]');

        for (var i = 0; i < buttons.length; i++) {
            bindConnect(buttons[i]);
        }
    }

    function bindConnect(button) {
        var field = find(button, 'data-jfb-connect');
        var target = find(button, 'data-jfb-connect-target');
        var url = button.getAttribute('data-jfb-connect-url') || '';
        var action = button.getAttribute('data-jfb-connect-action') || '';
        var nonce = button.getAttribute('data-jfb-connect-nonce') || '';

        if (!field || !target || !url || !action) {
            return;
        }

        var idle = button.textContent;
        var busy = button.getAttribute('data-jfb-connect-busy') || idle;

        button.addEventListener('click', function () {
            // `aria-disabled`, not `disabled`: the button has to keep focus.
            if (button.getAttribute('aria-disabled') === 'true') {
                return;
            }

            var value = String(field.value || '').replace(/\s+/g, '');

            field.value = value;

            button.setAttribute('aria-disabled', 'true');
            button.textContent = busy;
            report(target, '', busy);

            var body = 'action=' + encodeURIComponent(action) +
                '&_ajax_nonce=' + encodeURIComponent(nonce) +
                '&form_id=' + encodeURIComponent(value);

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                var data = (payload && payload.data) || {};

                if (payload && payload.success) {
                    // The state comes from the server: a found form may still be a bad answer.
                    report(target, data.state || 'ok', data.message || '', data.hint || '');

                    return;
                }

                report(target, 'error', data.message || '', data.hint || '');
            })['catch'](function () {
                report(target, 'error', message('unreachable'));
            }).then(function () {
                button.removeAttribute('aria-disabled');
                button.textContent = idle;
            });
        });
    }

    /** Writes one result into the status element (a live region). */
    function report(target, state, message, hint) {
        target.className = 'jfb-connect-status jfb-state-' + (state || 'none');
        target.textContent = message || '';

        if (hint) {
            var note = document.createElement('span');

            note.className = 'description';
            note.textContent = hint;
            target.appendChild(document.createTextNode(' '));
            target.appendChild(note);
        }
    }

    bindToggles();
    bindCopyButtons();
    bindConnectButtons();
    bindConfirms();
})();
