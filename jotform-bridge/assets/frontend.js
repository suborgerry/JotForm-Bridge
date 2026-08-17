/**
 * Jotform Bridge frontend.
 *
 * Vanilla JavaScript, no dependencies, no build step. It progressively enhances
 * any form marked with `data-jotform-bridge`: it serializes the semantic fields
 * declared through `data-jotform-field`, posts them as JSON to the plugin REST
 * endpoint and reports the server's answer back into the markup.
 *
 * The theme stays in charge of the UX. This script imposes no popup, no
 * redirect and no animation; it only toggles state attributes and dispatches
 * events the theme can listen to:
 *
 *   jotformbridge:before-submit  { integration, fields }
 *   jotformbridge:success        { integration, message }
 *   jotformbridge:error          { integration, message, errors, status }
 *
 * The form never knows the Jotform form ID, the question IDs or the API key.
 */
(function () {
    'use strict';

    var SETTINGS = window.jotformBridgeSettings || {};
    var FORM_SELECTOR = 'form[data-jotform-bridge]';
    var FIELD_SELECTOR = '[data-jotform-field]';
    var ERROR_CONTAINER = '[data-jotform-errors]';
    var BUSY_ATTRIBUTE = 'data-jotform-busy';

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
            body: JSON.stringify({ fields: fields })
        })
            .then(function (response) {
                status = response.status;

                return response.json().catch(function () {
                    return {};
                });
            })
            .then(function (body) {
                if (body && body.success) {
                    form.reset();
                    showSuccess(form, body.message || '');
                    dispatch(form, 'jotformbridge:success', {
                        integration: integration,
                        message: body.message || ''
                    });

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
})();
