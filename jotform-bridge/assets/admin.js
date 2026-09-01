/**
 * Jotform Bridge admin screens.
 *
 * Four behaviours, all declared in the markup rather than wired up per screen,
 * so a new row, a new copy button or a new lookup needs no JavaScript of its own:
 *
 *   [data-jfb-toggle]   a select that shows or hides rows while it holds one value
 *   [data-jfb-copy]     a button that puts text on the clipboard
 *   [data-jfb-connect]  a button that resolves a Jotform form ID into its title
 *   [data-jfb-confirm]  a form that asks before it submits
 *
 * Vanilla, no build step, no jQuery — the same rule the frontend script follows.
 * It replaces three inline <script> blocks that used to be printed straight
 * into the admin markup, where they could not be cached and would be refused by
 * any site with a content security policy.
 */
(function () {
    'use strict';

    var SETTINGS = window.jotformBridgeAdmin || {};

    /**
     * How long to wait after clearing the live region before writing to it.
     *
     * A screen reader announces a change to the region's text. Clearing and
     * rewriting in the same tick is not two changes but one, and the same
     * message twice running would be announced once — which is exactly the case
     * that matters, somebody pressing the same copy button again. One frame is
     * enough to make them separate; a delay long enough to notice would be a
     * delay before the visitor is told anything.
     */
    var ANNOUNCE_DELAY = 100;

    /**
     * How long the "Copied" marker stays up, in milliseconds.
     *
     * Long enough to be seen after the eye has moved back from the button,
     * short enough that it is gone before the next copy — otherwise a marker
     * left over from the previous button says the wrong thing about this one.
     */
    var COPIED_VISIBLE = 1500;

    /** Every sentence this script says comes from PHP, so it can be translated. */
    function message(key) {
        return (SETTINGS.messages && SETTINGS.messages[key]) || '';
    }

    /**
     * The element an attribute points at, or null when it points nowhere.
     *
     * `querySelector('')` does not return null, it throws — and an element
     * whose attribute is simply absent is the ordinary case here, not an error.
     * The shortcode copy buttons spell their text out in `data-jfb-copy` and
     * carry no `data-jfb-copy-from` at all, so every one of them threw out of
     * the click handler before the copy was ever attempted: the button did
     * nothing, silently, in every browser.
     */
    function find(element, attribute) {
        var selector = element.getAttribute(attribute);

        return selector ? document.querySelector(selector) : null;
    }

    /**
     * Says something to a screen reader.
     *
     * The views render one empty `role="status"` region per screen, before
     * anything can be written to it: a live region created at the moment it is
     * first needed is not reliably announced. Everything this script does that
     * has no other outcome — a copy that worked, a copy that did not — is
     * announced here, because until now the only sign was a coloured word
     * fading in beside a button.
     */
    function announce(text) {
        var region = document.querySelector('[data-jfb-status]');

        if (!region || !text) {
            return;
        }

        // Cleared first so that repeating the same message is still a change,
        // and is therefore still announced.
        region.textContent = '';

        window.setTimeout(function () {
            region.textContent = text;
        }, ANNOUNCE_DELAY);
    }

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

    /**
     * The text a copy button is meant to put on the clipboard: either spelled
     * out in the attribute, or read from the control it points at.
     */
    function copyText(button) {
        var literal = button.getAttribute('data-jfb-copy');

        if (literal) {
            return literal;
        }

        var source = find(button, 'data-jfb-copy-from');

        return source && typeof source.value === 'string' ? source.value : '';
    }

    /**
     * Puts text on the clipboard the modern way, falling back to the old one.
     *
     * The clipboard API needs a secure context, which a local admin over plain
     * HTTP is not, so the fallback is not vestigial.
     */
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
        // Selecting the real control is better than a throwaway one: where the
        // copy is refused outright, the text is at least ready to be copied by
        // hand.
        if (source && typeof source.select === 'function') {
            source.focus();
            source.select();

            var done = exec();

            // Back to the button. Selecting the source moves focus into it, and
            // leaving it there drops a keyboard user inside a twenty-row
            // read-only textarea with the whole document selected, with nothing
            // said about how they got there.
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

    /**
     * Shows the "Copied" marker the button carries, and takes it away again.
     *
     * The marker is `aria-hidden` in the markup, because it is faded in with
     * opacity rather than taken out of the flow, and an element at zero opacity
     * is still in the accessibility tree: without that attribute every copy
     * button would be named "… Copied" from the moment the page loaded,
     * claiming a state it was not in. The announcement is made separately, by
     * the caller, through the live region.
     */
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

    /**
     * A form that asks before it submits.
     *
     * Both of these used to be an inline `onsubmit="return confirm(…)"`, which
     * a content security policy refuses outright — and a refused attribute here
     * does not disable the button, it removes the question and lets the
     * destructive action through unasked. Declared in the markup, the question
     * travels through esc_attr() like any other value and the behaviour lives
     * in a file that can be cached.
     */
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
            // `aria-disabled` rather than the `disabled` property: a disabled
            // control cannot hold focus, so the browser used to move focus to
            // the document body the moment this was pressed and never brought
            // it back — a keyboard user pressed one button and was returned to
            // the top of the screen, well placed to miss the polite status
            // update that had just arrived. Keeping the focus is also what
            // makes the label below announce itself, since a screen reader
            // reports a name change on the element it is sitting on.
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
                    // The state comes from the server rather than from the fact
                    // that the request succeeded: a form that was found but sits
                    // in the Jotform trash, or whose definition would not load,
                    // is a successful lookup with a bad answer, and painting it
                    // the same green as a working form is how it gets missed.
                    report(target, data.state || 'ok', data.message || '', data.hint || '');

                    return;
                }

                report(target, 'error', data.message || '', data.hint || '');
            })['catch'](function () {
                // Not a Jotform failure: the request never left the site.
                report(target, 'error', message('unreachable'));
            }).then(function () {
                button.removeAttribute('aria-disabled');
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
    bindConfirms();
})();
