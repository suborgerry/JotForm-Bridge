/**
 * Jotform Bridge frontend.
 *
 * Vanilla JavaScript, no build step. Enhances any `form[data-jotform-bridge]`:
 * serializes `data-jotform-field` elements, posts them as JSON to the REST
 * endpoint and writes the answer back into the markup. Anti-spam values
 * (`data-jotform-spam`, elapsed time, proof of work) travel in a separate
 * `spam` container.
 *
 * No popup, no animation. State is exposed through attributes and events:
 *
 *   jotformbridge:before-submit  { integration, fields }
 *   jotformbridge:success        { integration, fields, message, redirect }
 *   jotformbridge:error          { integration, message, errors, status }
 *
 * The configured redirect comes from the server response only and is
 * cancelled by preventDefault() on the success event.
 */
(function () {
    'use strict';

    var SETTINGS = window.jotformBridgeSettings || {};
    var FORM_SELECTOR = 'form[data-jotform-bridge]';
    var FIELD_SELECTOR = '[data-jotform-field]';
    var SPAM_SELECTOR = '[data-jotform-spam]';
    var ERROR_CONTAINER = '[data-jotform-errors]';
    var BUSY_ATTRIBUTE = 'data-jotform-busy';

    // Form properties (not attributes): first-interaction timestamp, computed
    // proof of work and its in-progress flag.
    var STARTED_AT = '__jotformBridgeStartedAt';
    var SOLUTION = '__jotformBridgeSolution';
    var SOLVING = '__jotformBridgeSolving';

    /** Proof-of-work difficulty when SETTINGS.powBits has no entry for the slug. */
    var POW_BITS = 16;

    /** Seconds a computed solution stays reusable; must stay under ProofOfWork::WINDOW. */
    var POW_STALE = 240;

    /** Hashes per timer slice, so solving never freezes the page. */
    var POW_BATCH = 4096;

    var SHA256_K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];

    /** Synchronous SHA-256 of a byte array. */
    function sha256(bytes) {
        var h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
        var length = bytes.length;
        var block = new Uint8Array((((length + 8) >> 6) + 1) << 6);

        block.set(bytes);
        block[length] = 0x80;

        var bits = length * 8;
        block[block.length - 4] = (bits >>> 24) & 0xff;
        block[block.length - 3] = (bits >>> 16) & 0xff;
        block[block.length - 2] = (bits >>> 8) & 0xff;
        block[block.length - 1] = bits & 0xff;

        var w = new Int32Array(64);
        var i;

        for (var offset = 0; offset < block.length; offset += 64) {
            for (i = 0; i < 16; i++) {
                var j = offset + i * 4;
                w[i] = (block[j] << 24) | (block[j + 1] << 16) | (block[j + 2] << 8) | block[j + 3];
            }

            for (i = 16; i < 64; i++) {
                var x = w[i - 15];
                var y = w[i - 2];
                var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
                var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);

                w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
            }

            var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];

            for (i = 0; i < 64; i++) {
                var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                var ch = (e & f) ^ (~e & g);
                var t1 = (hh + S1 + ch + SHA256_K[i] + w[i]) | 0;
                var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                var maj = (a & b) ^ (a & c) ^ (b & c);
                var t2 = (S0 + maj) | 0;

                hh = g; g = f; f = e; e = (d + t1) | 0;
                d = c; c = b; b = a; a = (t1 + t2) | 0;
            }

            h[0] = (h[0] + a) | 0; h[1] = (h[1] + b) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0;
            h[4] = (h[4] + e) | 0; h[5] = (h[5] + f) | 0; h[6] = (h[6] + g) | 0; h[7] = (h[7] + hh) | 0;
        }

        var out = new Uint8Array(32);

        for (var k = 0; k < 8; k++) {
            out[k * 4] = (h[k] >>> 24) & 0xff;
            out[k * 4 + 1] = (h[k] >>> 16) & 0xff;
            out[k * 4 + 2] = (h[k] >>> 8) & 0xff;
            out[k * 4 + 3] = h[k] & 0xff;
        }

        return out;
    }

    /** UTF-8 bytes of a string. */
    function utf8(value) {
        var out = [];

        for (var i = 0; i < value.length; i++) {
            var c = value.charCodeAt(i);

            if (c < 0x80) {
                out.push(c);
            } else if (c < 0x800) {
                out.push(0xc0 | (c >> 6), 0x80 | (c & 63));
            } else if (c < 0xd800 || c >= 0xe000) {
                out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
            } else {
                i++;

                var cp = 0x10000 + (((c & 0x3ff) << 10) | (value.charCodeAt(i) & 0x3ff));

                out.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
            }
        }

        return new Uint8Array(out);
    }

    function hasLeadingZeroBits(hash, bits) {
        var whole = bits >> 3;

        for (var i = 0; i < whole; i++) {
            if (hash[i] !== 0) {
                return false;
            }
        }

        var rest = bits & 7;

        return rest === 0 || (hash[whole] >> (8 - rest)) === 0;
    }

    /** Proof-of-work difficulty for one integration, as localized by PHP. */
    function powBitsFor(integration) {
        var map = SETTINGS.powBits || {};
        var bits = map[integration];

        return typeof bits === 'number' && bits > 0 ? bits : POW_BITS;
    }

    /**
     * Finds a nonce making sha256("slug|timestamp|nonce") start with the
     * required zero bits. Sliced across timers to keep the page responsive.
     */
    function solve(integration, done) {
        var timestamp = Math.floor(Date.now() / 1000);
        var prefix = integration + '|' + timestamp + '|';
        var bits = powBitsFor(integration);
        var nonce = 0;

        function slice() {
            var limit = nonce + POW_BATCH;

            for (; nonce < limit; nonce++) {
                if (hasLeadingZeroBits(sha256(utf8(prefix + nonce)), bits)) {
                    done({ timestamp: timestamp, value: timestamp + ':' + nonce });

                    return;
                }
            }

            window.setTimeout(slice, 0);
        }

        slice();
    }

    /** Hands over a fresh proof of work, computing one if none is ready. */
    function withSolution(form, integration, done) {
        var ready = form[SOLUTION];
        var now = Math.floor(Date.now() / 1000);

        if (ready && now - ready.timestamp < POW_STALE) {
            done(ready.value);

            return;
        }

        solve(integration, function (solution) {
            form[SOLUTION] = solution;
            done(solution.value);
        });
    }

    /** Starts solving in the background, once per form. */
    function prepareSolution(form) {
        var integration = form.getAttribute('data-jotform-integration') || '';

        if (!integration || form[SOLVING]) {
            return;
        }

        form[SOLVING] = true;

        solve(integration, function (solution) {
            form[SOLUTION] = solution;
            form[SOLVING] = false;
        });
    }

    /** A localized message from PHP; empty when none was localized. */
    function message(key) {
        var messages = SETTINGS.messages || {};

        return messages[key] || messages.error || '';
    }

    function endpointFor(form, integration) {
        var explicit = form.getAttribute('data-jotform-endpoint');

        if (explicit) {
            return explicit;
        }

        return (SETTINGS.endpoint || '') + encodeURIComponent(integration);
    }

    /**
     * Collects the semantic values of one form. Multi-value fields are arrays,
     * everything else a string.
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
                // Checkboxes always produce a list, even a lone one.
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

    /** Same independent rule evaluation as ConditionalLogic::state(). */
    function applyConditions(form) {
        var slug = form.getAttribute('data-jotform-integration');
        var config = SETTINGS.conditions && SETTINGS.conditions[slug];
        if (!config || !config.rules.length) { return; }
        var fields = serialize(form);
        var state = Object.create(null);
        config.rules.forEach(function (rule) {
            var value = fields[rule.source] || '';
            var values = (Array.isArray(value) ? value : [value]).map(function (item) {
                return String(item).trim();
            }).filter(function (item) { return item !== ''; });
            var equal = values.indexOf(rule.value) !== -1;
            var matches = rule.operator === 'equals' ? equal
                : rule.operator === 'not_equals' ? !equal
                : rule.operator === 'empty' ? values.length === 0 : values.length > 0;
            if (!state[rule.target]) { state[rule.target] = {}; }
            state[rule.target][rule.action] = matches;
        });
        Object.keys(state).forEach(function (path) {
            var actions = state[path];
            var visible = actions.show !== false;
            var required = typeof actions.require === 'boolean' ? actions.require : config.required.indexOf(path) !== -1;
            var elements = form.querySelectorAll(FIELD_SELECTOR);
            var wrappers = [];
            for (var i = 0; i < elements.length; i++) {
                var input = elements[i];
                if (input.getAttribute('data-jotform-field') !== path) { continue; }
                if (typeof input.__jfbOriginalDisabled === 'undefined') {
                    var original = input.getAttribute('data-jotform-condition-original-disabled');
                    input.__jfbOriginalDisabled = original !== null ? original === 'true' : input.disabled;
                    input.setAttribute('data-jotform-condition-original-disabled', String(input.__jfbOriginalDisabled));
                }
                input.disabled = input.__jfbOriginalDisabled || !visible;
                // A checkbox group requires any choice, never every checkbox.
                input.required = visible && required && input.type !== 'checkbox';
                if (visible && required) { input.setAttribute('aria-required', 'true'); }
                else { input.removeAttribute('aria-required'); }
                if (!visible) { input.removeAttribute('aria-invalid'); }
                var wrapper = input.closest('[data-jotform-field-wrapper], .jfb-field');
                if (wrapper && form.contains(wrapper) &&
                    (!wrapper.hasAttribute('data-jotform-field-wrapper') || wrapper.getAttribute('data-jotform-field-wrapper') === path)) {
                    if (wrappers.indexOf(wrapper) === -1) { wrappers.push(wrapper); }
                } else {
                    if (typeof actions.show === 'boolean') { input.hidden = !visible; }
                    if (input.id) {
                        var labels = form.querySelectorAll('label[for]');
                        for (var l = 0; l < labels.length; l++) {
                            if (labels[l].htmlFor === input.id && typeof actions.show === 'boolean') { labels[l].hidden = !visible; }
                        }
                    }
                }
            }
            wrappers.forEach(function (wrapper) {
                if (typeof actions.show === 'boolean') { wrapper.hidden = !visible; }
                var label = wrapper.querySelector('legend, label');
                var marker = label && label.querySelector('.jfb-required');
                if (!marker && label && required) {
                    marker = document.createElement('span');
                    marker.className = 'jfb-required';
                    marker.textContent = ' ' + message('required');
                    label.appendChild(marker);
                }
                if (marker) { marker.hidden = !required; }
            });
            if (!visible) {
                var slots = form.querySelectorAll('[data-jotform-field-error]');
                for (var e = 0; e < slots.length; e++) {
                    if (slots[e].getAttribute('data-jotform-field-error') === path) { slots[e].textContent = ''; }
                }
            }
        });
    }

    function initConditions(root) {
        if (root.matches && root.matches(FORM_SELECTOR)) { applyConditions(root); }
        if (!root.querySelectorAll) { return; }
        var forms = root.querySelectorAll(FORM_SELECTOR);
        for (var i = 0; i < forms.length; i++) { applyConditions(forms[i]); }
    }

    function updateConditions(event) {
        var form = event.target.closest && event.target.closest(FORM_SELECTOR);
        if (form && form.getAttribute(BUSY_ATTRIBUTE) !== 'true') { applyConditions(form); }
    }

    /**
     * Collects the anti-spam values of one form into their own container.
     * A non-control element contributes the value of the input inside it
     * (challenge widgets write their token into an input they create).
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

    /** Seconds since the form was first touched; null when it never was. */
    function elapsedSeconds(form) {
        var startedAt = form[STARTED_AT];

        if (!startedAt) {
            return null;
        }

        return Math.max(0, Math.round((Date.now() - startedAt) / 1000));
    }

    /** Records the first interaction with a form and starts the proof of work. */
    function noteInteraction(event) {
        var target = event.target;

        if (!target || !target.closest) {
            return;
        }

        var form = target.closest(FORM_SELECTOR);

        if (form && !form[STARTED_AT]) {
            form[STARTED_AT] = Date.now();
            prepareSolution(form);
        }
    }

    /** Escapes an attribute value for use inside a querySelector. */
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

    /** Writes server errors into per-field slots, or the shared container. */
    function showErrors(form, text, errors) {
        var unplaced = [];

        for (var path in errors) {
            if (!Object.prototype.hasOwnProperty.call(errors, path)) {
                continue;
            }

            var selector = quote(path);
            var slot = form.querySelector('[data-jotform-field-error="' + selector + '"]');

            // Every input of the path: a choice group shares one path.
            var inputs = form.querySelectorAll('[data-jotform-field="' + selector + '"]');

            for (var k = 0; k < inputs.length; k++) {
                inputs[k].setAttribute('aria-invalid', 'true');
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

    /** Focuses the first invalid control in document order, else the error container. */
    function focusFirstError(form) {
        var target = form.querySelector('[aria-invalid="true"]');

        if (!target) {
            var container = form.querySelector(ERROR_CONTAINER);

            if (!container || !container.textContent) {
                return;
            }

            if (!container.hasAttribute('tabindex')) {
                container.setAttribute('tabindex', '-1');
            }

            target = container;
        }

        focusQuietly(target);
    }

    /** Moves focus and scrolls into view; a failure is ignored. */
    function focusQuietly(element) {
        try {
            element.focus();

            if (element.scrollIntoView) {
                element.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        } catch (error) {
            // Focusing is optional.
        }
    }

    /** Focuses the success message after an accepted submission. */
    function focusSuccess(form) {
        var container = form.querySelector('[data-jotform-success]');

        if (!container || !container.textContent) {
            return;
        }

        if (!container.hasAttribute('tabindex')) {
            container.setAttribute('tabindex', '-1');
        }

        focusQuietly(container);
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

    /**
     * Toggles the busy state: `aria-busy` on the form, `aria-disabled` on the
     * submit buttons (not `disabled`, which would drop keyboard focus).
     */
    function setBusy(form, busy) {
        var buttons = submitButtons(form);

        for (var i = 0; i < buttons.length; i++) {
            if (busy) {
                buttons[i].setAttribute('aria-disabled', 'true');
            } else {
                buttons[i].removeAttribute('aria-disabled');
            }
        }

        if (busy) {
            form.setAttribute(BUSY_ATTRIBUTE, 'true');
            form.setAttribute('aria-busy', 'true');
        } else {
            form.removeAttribute(BUSY_ATTRIBUTE);
            form.removeAttribute('aria-busy');
        }
    }

    /** Reads the redirect out of a success body; same-origin URLs only. */
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

    /** Navigates to the redirect target after its delay. */
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

        applyConditions(form);
        var fields = serialize(form);
        var spam = collectSpam(form);

        clearErrors(form);
        showSuccess(form, '');

        if (typeof window.fetch !== 'function') {
            showErrors(form, message('unsupported'), {});
            focusFirstError(form);

            return;
        }

        if (!dispatch(form, 'jotformbridge:before-submit', { integration: integration, fields: fields })) {
            return;
        }

        setBusy(form, true);

        withSolution(form, integration, function (solution) {
            spam.pow = solution;

            send(form, integration, fields, spam);
        });
    }

    function send(form, integration, fields, spam) {
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

                    showSuccess(form, body.message || '');

                    // Dispatched before the form is cleared, so handlers can read the values.
                    var proceed = dispatch(form, 'jotformbridge:success', {
                        integration: integration,
                        fields: fields,
                        message: body.message || '',
                        redirect: redirect
                    });

                    form.reset();
                    applyConditions(form);
                    form[STARTED_AT] = 0;
                    form[SOLUTION] = null;

                    if (redirect && proceed) {
                        // Stays busy until the page is replaced.
                        go(redirect);

                        return;
                    }

                    focusSuccess(form);
                    setBusy(form, false);

                    return;
                }

                var errors = (body && body.errors) || {};
                var text = (body && body.message) || message('error');

                showErrors(form, text, errors);

                // Focus moves only if the theme did not cancel the event.
                if (dispatch(form, 'jotformbridge:error', {
                    integration: integration,
                    message: text,
                    errors: errors,
                    status: status
                })) {
                    focusFirstError(form);
                }

                setBusy(form, false);
            })
            .catch(function () {
                showErrors(form, message('network'), {});

                if (dispatch(form, 'jotformbridge:error', {
                    integration: integration,
                    message: message('network'),
                    errors: {},
                    status: status
                })) {
                    focusFirstError(form);
                }

                setBusy(form, false);
            });
    }

    initConditions(document);
    document.addEventListener('input', updateConditions, false);
    document.addEventListener('change', updateConditions, false);
    document.addEventListener('reset', function (event) {
        var form = event.target;
        if (form.matches && form.matches(FORM_SELECTOR)) {
            window.setTimeout(function () { applyConditions(form); }, 0);
        }
    }, false);
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                for (var i = 0; i < record.addedNodes.length; i++) { initConditions(record.addedNodes[i]); }
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    // Delegated, so forms added later are covered too.
    document.addEventListener('submit', handle, false);

    document.addEventListener('focusin', noteInteraction, true);
    document.addEventListener('keydown', noteInteraction, true);
    document.addEventListener('pointerdown', noteInteraction, true);
    document.addEventListener('change', noteInteraction, true);
})();
