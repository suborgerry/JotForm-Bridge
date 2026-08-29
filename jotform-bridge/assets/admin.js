/**
 * Jotform Bridge admin screens.
 *
 * Two behaviours, both declared in the markup rather than wired up per screen,
 * so a new row or a new copy button needs no JavaScript of its own:
 *
 *   [data-jfb-toggle]  a select that shows or hides rows while it holds one value
 *   [data-jfb-copy]    a button that puts text on the clipboard
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

    bindToggles();
    bindCopyButtons();
})();
