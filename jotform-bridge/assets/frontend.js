/**
 * Jotform Bridge frontend.
 *
 * Vanilla JavaScript, no dependencies, no build step. It progressively enhances
 * any form marked with `data-jotform-bridge`: it serializes the semantic fields
 * declared through `data-jotform-field`, posts them as JSON to the plugin REST
 * endpoint and reports the server's answer back into the markup.
 *
 * Beside the semantic fields it carries a second, separate channel: any element
 * marked with `data-jotform-spam` is collected into `spam`, together with how
 * long the visitor has had the form open. None of it passes through the field
 * validator. That is what an anti-abuse provider — a honeypot,
 * a challenge token — travels in, because a value that is not part of the
 * Jotform form must not be sent as if it were.
 *
 * The theme stays in charge of the UX. This script imposes no popup and no
 * animation; it only toggles state attributes and dispatches events the theme
 * can listen to:
 *
 *   jotformbridge:before-submit  { integration, fields }
 *   jotformbridge:success        { integration, message, redirect }
 *   jotformbridge:error          { integration, message, errors, status }
 *
 * The one thing it does impose is the redirect the integration is configured
 * with — and only that one: the URL comes from the server's answer, is never
 * read from the markup, and calling preventDefault() on the success event
 * cancels it so the theme can build its own flow.
 *
 * The form never knows the Jotform form ID, the question IDs or the API key.
 */
(function () {
    'use strict';

    var SETTINGS = window.jotformBridgeSettings || {};
    var FORM_SELECTOR = 'form[data-jotform-bridge]';
    var FIELD_SELECTOR = '[data-jotform-field]';
    var SPAM_SELECTOR = '[data-jotform-spam]';
    var ERROR_CONTAINER = '[data-jotform-errors]';
    var BUSY_ATTRIBUTE = 'data-jotform-busy';

    /**
     * Property the first-interaction timestamp is parked on.
     *
     * A property rather than an attribute: it must not be serialized into the
     * markup, cached with the page, or visible to anything reading the DOM as
     * text.
     */
    var STARTED_AT = '__jotformBridgeStartedAt';

    function message(key) {
        var messages = SETTINGS.messages || {};

        return messages[key] || 'The form could not be submitted.';
    }

    function endpointFor(form, integration) {
        var explicit = form.getAttribute('data-jotform-endpoint');

        if (explicit) {
            return explicit;
        }

        return (SETTINGS.endpoint || '') + encodeURIComponent(integration);
    }

    /**
     * Collects the semantic values of one form.
     *
     * Only elements carrying data-jotform-field take part, so ordinary theme
     * inputs (honeypots, layout helpers) are ignored. Multi-value fields keep
     * their array shape; everything else is a single string.
     */
    function serialize(form) {
        var fields = {};
        var elements = form.querySelectorAll(FIELD_SELECTOR);

        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];
            var path = element.getAttribute('data-jotform-field');

            if (!path) {
                continue;
            }

            var type = (element.getAttribute('type') || '').toLowerCase();
            var tag = element.tagName.toLowerCase();

            if (element.disabled) {
                continue;
            }

            if (type === 'radio') {
                if (element.checked) {
                    fields[path] = element.value;
                }

                continue;
            }

            if (type === 'checkbox') {
                // A group of checkboxes shares one semantic path and produces a
                // list; a lone checkbox is a list of zero or one value too, so
                // the server always sees the same shape.
                if (!Array.isArray(fields[path])) {
                    fields[path] = [];
                }

                if (element.checked) {
                    fields[path].push(element.value);
                }

                continue;
            }

            if (tag === 'select' && element.multiple) {
                var selected = [];

                for (var o = 0; o < element.options.length; o++) {
                    if (element.options[o].selected) {
                        selected.push(element.options[o].value);
                    }
                }

                fields[path] = selected;

                continue;
            }

            fields[path] = element.value;
        }

        return fields;
    }

    /**
     * Collects the anti-abuse values of one form.
     *
     * These are deliberately kept out of `fields`: the server validates every
     * semantic path against the Jotform schema and rejects the ones it does not
     * know, so a honeypot or a challenge token sent as a field would fail every
     * submission. They travel in their own container instead, which only the
     * spam extension point ever reads.
     *
     * A checkbox contributes whether it is checked, a control contributes its
     * value, and an element that is not a control at all contributes the value
     * of the control inside it. That last case is what a challenge widget needs:
     * it writes its token into an input it creates itself, so the attribute has
     * to go on the container it is told to fill.
     */
    function collectSpam(form) {
        var spam = {};
        var elements = form.querySelectorAll(SPAM_SELECTOR);

        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];
            var key = element.getAttribute('data-jotform-spam');

            if (!key) {
                continue;
            }

            var type = (element.getAttribute('type') || '').toLowerCase();

            if (type === 'checkbox' || type === 'radio') {
                spam[key] = element.checked ? element.value : '';

                continue;
            }

            if (typeof element.value === 'string') {
                spam[key] = element.value;

                continue;
            }

            var inner = element.querySelector('input[name], textarea[name]');

            spam[key] = inner && typeof inner.value === 'string' ? inner.value : '';
        }

        var elapsed = elapsedSeconds(form);

        if (elapsed !== null) {
            spam.t = elapsed;
        }

        return spam;
    }

    /**
     * How long the visitor has had this form open, counted from the moment they
     * first touched it.
     *
     * Measured on the client on purpose. Stamping a server-side timestamp into
     * the markup would be defeated by full-page caching — every visitor would
     * receive the same, already-old stamp — and issuing one per page view would
     * mean an extra request before the form is even used. A client-side number
     * can be forged, but forging it takes a script that runs the page, and a
     * script that runs the page is not what this check is aimed at.
     *
     * Null when the form was never touched, which the server reads as "not
     * measured" rather than as "instant".
     */
    function elapsedSeconds(form) {
        var startedAt = form[STARTED_AT];

        if (!startedAt) {
            return null;
        }

        return Math.max(0, Math.round((Date.now() - startedAt) / 1000));
    }

    /**
     * Marks the moment a form is first interacted with.
     *
     * Delegated like the submit handler, so forms added to the page later are
     * covered too, and only the first interaction counts.
     */
    function noteInteraction(event) {
        var target = event.target;

        if (!target || !target.closest) {
            return;
        }

        var form = target.closest(FORM_SELECTOR);

        if (form && !form[STARTED_AT]) {
            form[STARTED_AT] = Date.now();
        }
    }

    /**
     * Escapes one attribute value for use inside a querySelector.
     *
     * Error keys come back from the server, which echoes the identifiers the
     * form sent. A quote or a backslash in one of them would otherwise make the
     * selector invalid and throw instead of showing the message.
     */
    function quote(value) {
        return String(value).replace(/(["\\])/g, '\\$1');
    }

    function clearErrors(form) {
        var container = form.querySelector(ERROR_CONTAINER);

        if (container) {
            container.textContent = '';
        }

        var marked = form.querySelectorAll('[data-jotform-field-error]');

        for (var i = 0; i < marked.length; i++) {
            marked[i].textContent = '';
            marked[i].removeAttribute('data-jotform-field-error-active');
        }

        var invalid = form.querySelectorAll('[aria-invalid="true"]');

        for (var j = 0; j < invalid.length; j++) {
            invalid[j].removeAttribute('aria-invalid');
        }
    }

    /**
     * Writes the server errors into the markup the template provides: a
     * per-field slot when there is one, the shared container otherwise.
     */
    function showErrors(form, text, errors) {
        var unplaced = [];

        for (var path in errors) {
            if (!Object.prototype.hasOwnProperty.call(errors, path)) {
                continue;
            }

            var selector = quote(path);
            var slot = form.querySelector('[data-jotform-field-error="' + selector + '"]');
            var input = form.querySelector('[data-jotform-field="' + selector + '"]');

            if (input) {
                input.setAttribute('aria-invalid', 'true');
            }

            if (slot) {
                slot.textContent = errors[path];
                slot.setAttribute('data-jotform-field-error-active', 'true');

                continue;
            }

            unplaced.push(errors[path]);
        }

        var container = form.querySelector(ERROR_CONTAINER);

        if (!container) {
            return;
        }

        container.textContent = unplaced.length ? unplaced.join(' ') : text;
    }

    function showSuccess(form, text) {
        var container = form.querySelector('[data-jotform-success]');

        if (container) {
            container.textContent = text;
        }
    }

    function submitButtons(form) {
        return form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
    }

    function setBusy(form, busy) {
        var buttons = submitButtons(form);

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = busy;
        }

        if (busy) {
            form.setAttribute(BUSY_ATTRIBUTE, 'true');
        } else {
            form.removeAttribute(BUSY_ATTRIBUTE);
        }
    }

    /**
     * Reads the redirect instruction out of a success body.
     *
     * The server only ever sends a same-origin URL it resolved itself, but the
     * check is repeated here so that nothing but a same-origin navigation can
     * come out of this script, whatever answered the request.
     */
    function redirectFrom(body) {
        var redirect = body && body.redirect;

        if (!redirect || typeof redirect.url !== 'string' || !redirect.url) {
            return null;
        }

        var resolved = document.createElement('a');
        resolved.href = redirect.url;

        if (resolved.origin !== window.location.origin) {
            return null;
        }

        var delay = parseInt(redirect.delay, 10);

        return {
            url: resolved.href,
            delay: isNaN(delay) || delay < 0 ? 0 : delay
        };
    }

    /**
     * Leaves for the target, keeping the form disabled until the page changes so
     * a second submit is impossible during the delay.
     */
    function go(redirect) {
        if (redirect.delay > 0) {
            window.setTimeout(function () {
                window.location.assign(redirect.url);
            }, redirect.delay * 1000);

            return;
        }

        window.location.assign(redirect.url);
    }

    function dispatch(form, name, detail) {
        var event;

        try {
            event = new CustomEvent(name, { bubbles: true, cancelable: true, detail: detail });
        } catch (error) {
            // Older browsers without the CustomEvent constructor.
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, true, detail);
        }

        return form.dispatchEvent(event);
    }

    function handle(event) {
        var form = event.target;

        if (!form || !form.matches || !form.matches(FORM_SELECTOR)) {
            return;
        }

        event.preventDefault();

        if (form.getAttribute(BUSY_ATTRIBUTE) === 'true') {
            return;
        }

        var integration = form.getAttribute('data-jotform-integration') || '';

        if (!integration) {
            return;
        }

        var fields = serialize(form);
        var spam = collectSpam(form);

        clearErrors(form);
        showSuccess(form, '');

        if (!dispatch(form, 'jotformbridge:before-submit', { integration: integration, fields: fields })) {
            return;
        }

        setBusy(form, true);

        var status = 0;

        window.fetch(endpointFor(form, integration), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json'
            },
            body: JSON.stringify({ fields: fields, spam: spam })
        })
            .then(function (response) {
                status = response.status;

                return response.json().catch(function () {
                    return {};
                });
            })
            .then(function (body) {
                if (body && body.success) {
                    var redirect = redirectFrom(body);

                    // Order matters: state, then message, then the event, then
                    // the navigation the event was given a chance to cancel.
                    form.reset();
                    form[STARTED_AT] = 0;
                    showSuccess(form, body.message || '');

                    var proceed = dispatch(form, 'jotformbridge:success', {
                        integration: integration,
                        message: body.message || '',
                        redirect: redirect
                    });

                    if (redirect && proceed) {
                        // The form stays busy until the page is replaced, so the
                        // visitor cannot submit again while the delay runs.
                        go(redirect);

                        return;
                    }

                    setBusy(form, false);

                    return;
                }

                var errors = (body && body.errors) || {};
                var text = (body && body.message) || message('error');

                showErrors(form, text, errors);
                dispatch(form, 'jotformbridge:error', {
                    integration: integration,
                    message: text,
                    errors: errors,
                    status: status
                });

                setBusy(form, false);
            })
            .catch(function () {
                showErrors(form, message('network'), {});
                dispatch(form, 'jotformbridge:error', {
                    integration: integration,
                    message: message('network'),
                    errors: {},
                    status: status
                });

                setBusy(form, false);
            });
    }

    // One delegated listener, so forms added to the page later work too.
    document.addEventListener('submit', handle, false);

    // Anything that counts as the visitor starting to fill the form in.
    document.addEventListener('focusin', noteInteraction, true);
    document.addEventListener('keydown', noteInteraction, true);
    document.addEventListener('pointerdown', noteInteraction, true);
    document.addEventListener('change', noteInteraction, true);
})();
