/**
 * Jotform Bridge admin screens.
 *
 * Three behaviours, all declared in the markup rather than wired up per screen,
 * so a new row, a new copy button or a new lookup needs no JavaScript of its own:
 *
 *   [data-jfb-toggle]   a select that shows or hides rows while it holds one value
 *   [data-jfb-copy]     a button that puts text on the clipboard
 *   [data-jfb-connect]  a button that resolves a Jotform form ID into its title
 *
 * Vanilla, no build step, no jQuery — the same rule the frontend script follows.
 * It replaces three inline <script> blocks that used to be printed straight
 * into the admin markup, where they could not be cached and would be refused by
 * any site with a content security policy.
 */
(function () {
    'use strict';

    /**
     * Shows the rows a select is responsible for only while it holds the value
     * they belong to.
     *
     * With JavaScript off every row stays visible, which is a usable form
     * rather than a broken one: the server ignores whatever the chosen mode
     * does not ask for.
     */
    function bindToggles() {
        var controls = document.querySelectorAll('[data-jfb-toggle]');

        for (var i = 0; i < controls.length; i++) {
            bindToggle(controls[i]);
        }
    }

    function bindToggle(control) {
        var rows = document.querySelectorAll(control.getAttribute('data-jfb-toggle'));
        var wanted = control.getAttribute('data-jfb-toggle-value') || '';

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

    /**
     * The text a copy button is meant to put on the clipboard: either spelled
     * out in the attribute, or read from the control it points at.
     */
    function copyText(button) {
        var literal = button.getAttribute('data-jfb-copy');

        if (literal) {
            return literal;
        }

        var source = document.querySelector(button.getAttribute('data-jfb-copy-from') || '');

        return source && typeof source.value === 'string' ? source.value : '';
    }

    /**
     * Puts text on the clipboard the modern way, falling back to the old one.
     *
     * The clipboard API needs a secure context, which a local admin over plain
     * HTTP is not, so the fallback is not vestigial.
     */
    function copy(text, source) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(
                function () {
                    return true;
                },
                function () {
                    return legacyCopy(text, source);
                }
            );
        }

        return Promise.resolve(legacyCopy(text, source));
    }

    function legacyCopy(text, source) {
        // Selecting the real control is better than a throwaway one: where the
        // copy is refused outright, the text is at least ready to be copied by
        // hand.
        if (source && typeof source.select === 'function') {
            source.focus();
            source.select();

            return exec();
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

    /**
     * Shows the "Copied" marker the button carries, and takes it away again.
     */
    function confirmCopy(button) {
        button.classList.add('is-copied');

        window.clearTimeout(button.__jfbCopyTimer);

        button.__jfbCopyTimer = window.setTimeout(function () {
            button.classList.remove('is-copied');
        }, 1500);
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

            var source = document.querySelector(button.getAttribute('data-jfb-copy-from') || '');

            copy(text, source).then(function (done) {
                if (done) {
                    confirmCopy(button);
                }
            });
        });
    }

    /**
     * "Connect form": resolves the Jotform form ID beside the button.
     *
     * Asynchronous rather than a form post because it belongs to one field of a
     * form still being filled in — a redirect would either discard everything
     * typed so far or have to save it, and neither is what a button next to a
     * text field should mean.
     *
     * Every parameter is read from the markup: the endpoint, the action, the
     * nonce, the field it reads and the element it writes to. Nothing about the
     * integration editor is known here.
     *
     * The button is bound only when the browser can make the request at all.
     * Where it cannot, the field still saves and an unconnected ID is reported
     * as a warning by the server, so the screen stays usable rather than
     * offering a button that quietly does nothing.
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
        var field = document.querySelector(button.getAttribute('data-jfb-connect'));
        var target = document.querySelector(button.getAttribute('data-jfb-connect-target') || '');
        var url = button.getAttribute('data-jfb-connect-url') || '';
        var action = button.getAttribute('data-jfb-connect-action') || '';
        var nonce = button.getAttribute('data-jfb-connect-nonce') || '';

        if (!field || !target || !url || !action) {
            return;
        }

        var idle = button.textContent;
        var busy = button.getAttribute('data-jfb-connect-busy') || idle;

        button.addEventListener('click', function () {
            var value = String(field.value || '').replace(/\s+/g, '');

            field.value = value;

            button.disabled = true;
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
                    report(target, 'ok', data.message || '', data.hint || '');

                    return;
                }

                report(target, 'error', data.message || '', data.hint || '');
            })['catch'](function () {
                report(
                    target,
                    'error',
                    // Not a Jotform failure: the request never left the site.
                    'Could not reach WordPress to check the form ID.'
                );
            }).then(function () {
                button.disabled = false;
                button.textContent = idle;
            });
        });
    }

    /**
     * Writes one result into the status element, replacing the previous one.
     *
     * The element carries role="status" and aria-live="polite" in the markup,
     * so replacing its text is what announces the outcome to a screen reader.
     */
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
})();
