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
 * long the visitor has had the form open and a small proof of work. None of it
 * passes through the field validator. That is what an anti-abuse provider — a honeypot,
 * a challenge token — travels in, because a value that is not part of the
 * Jotform form must not be sent as if it were.
 *
 * The theme stays in charge of the UX. This script imposes no popup and no
 * animation; it only toggles state attributes and dispatches events the theme
 * can listen to:
 *
 *   jotformbridge:before-submit  { integration, fields }
 *   jotformbridge:success        { integration, fields, message, redirect }
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

    /** Where a computed proof of work is parked, and its in-progress flag. */
    var SOLUTION = '__jotformBridgeSolution';
    var SOLVING = '__jotformBridgeSolving';

    /**
     * Leading zero bits a proof of work must have.
     *
     * Sixteen is about 65,000 hashes on average: a fraction of a second on a
     * phone, and a wall for anything trying to send thousands of submissions.
     * The server decides what it accepts; this only has to agree with it.
     */
    var POW_BITS = 16;

    /** How long a computed solution stays usable, in seconds. */
    var POW_STALE = 240;

    /** Hashes per slice, so a slow device never freezes while solving. */
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

    /**
     * SHA-256 of a byte array.
     *
     * Written out rather than taken from crypto.subtle, which is asynchronous:
     * one promise per hash would cost more than the hash does, and the whole
     * point here is to run tens of thousands of them.
     */
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

    /**
     * UTF-8 bytes of a string. The server hashes the same bytes.
     */
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

    /**
     * Finds a number that makes sha256("slug|timestamp|nonce") start with
     * POW_BITS zero bits.
     *
     * Sliced across timers rather than run in one go: the work is short, but on
     * a slow phone a single loop would still be a visible freeze, and freezing
     * the page of somebody filling in a contact form is not an acceptable way
     * to make life harder for a bot.
     */
    function solve(integration, done) {
        var timestamp = Math.floor(Date.now() / 1000);
        var prefix = integration + '|' + timestamp + '|';
        var nonce = 0;

        function slice() {
            var limit = nonce + POW_BATCH;

            for (; nonce < limit; nonce++) {
                if (hasLeadingZeroBits(sha256(utf8(prefix + nonce)), POW_BITS)) {
                    done({ timestamp: timestamp, value: timestamp + ':' + nonce });

                    return;
                }
            }

            window.setTimeout(slice, 0);
        }

        slice();
    }

    /**
     * Hands over a fresh proof of work, computing one if there is not one ready.
     *
     * A solution is normally waiting by the time anyone presses submit, because
     * solving starts the moment the form is first touched. The submit path only
     * has to wait when a form was filled in very quickly, or so slowly that the
     * one computed at the start has aged out.
     */
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

    /**
     * Starts solving in the background, once per form.
     */
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

            // The visitor has started filling the form in, so there is time to
            // do the work before they finish. Done here, it costs them nothing.
            prepareSolution(form);
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

    /**
     * Sends the visitor to the thing that went wrong.
     *
     * The live region announces the message, but announcing is not the same as
     * arriving: without this the keyboard focus stays on the submit button, and
     * finding which of twelve fields was rejected means tabbing back through all
     * of them. On a long form a sighted visitor may not even see the message,
     * because it is above the fold.
     *
     * The first invalid control in document order, not the first key in the
     * response — the server answers with a map, and a map has no order worth
     * relying on.
     */
    function focusFirstError(form) {
        var target = form.querySelector('[aria-invalid="true"]');

        if (!target) {
            var container = form.querySelector(ERROR_CONTAINER);

            if (!container || !container.textContent) {
                return;
            }

            // Containers are not focusable by default, and making one reachable
            // by tab would put an empty stop in the middle of every form.
            if (!container.hasAttribute('tabindex')) {
                container.setAttribute('tabindex', '-1');
            }

            target = container;
        }

        focusQuietly(target);
    }

    /**
     * Moves focus and brings the element into view, without letting an old
     * browser throw its way out of the submit handler.
     */
    function focusQuietly(element) {
        try {
            element.focus();

            if (element.scrollIntoView) {
                element.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        } catch (error) {
            // Focusing is a courtesy, never a requirement.
        }
    }

    /**
     * Puts the visitor next to the confirmation once a form is accepted.
     *
     * Only when the page is not about to be replaced: moving focus and then
     * navigating away is noise. The polite live region already announces the
     * message; this is about where the visitor ends up afterwards, which would
     * otherwise be the submit button of a form that just emptied itself.
     */
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

        // Without fetch there is no way to send this form, and letting the
        // browser submit it natively would post an empty body to the REST
        // endpoint and replace the page with a JSON error. Say so instead.
        if (typeof window.fetch !== 'function') {
            showErrors(form, message('unsupported'), {});
            focusFirstError(form);

            return;
        }

        if (!dispatch(form, 'jotformbridge:before-submit', { integration: integration, fields: fields })) {
            return;
        }

        setBusy(form, true);

        // The proof of work is normally already done; when it is not, the form
        // stays busy for the fraction of a second it takes.
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

                    // The event goes out before anything is cleared, and carries
                    // what was sent. A theme wiring up analytics needs the
                    // values, and until now they were gone by the time it could
                    // ask — out of the event, and out of the DOM.
                    var proceed = dispatch(form, 'jotformbridge:success', {
                        integration: integration,
                        fields: fields,
                        message: body.message || '',
                        redirect: redirect
                    });

                    // Only now: the form is emptied, the clock restarts and the
                    // spent proof of work is dropped, all in one place.
                    form.reset();
                    form[STARTED_AT] = 0;
                    form[SOLUTION] = null;

                    if (redirect && proceed) {
                        // The form stays busy until the page is replaced, so the
                        // visitor cannot submit again while the delay runs.
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

                // After the event, and only if the theme did not take over: a
                // theme that scrolls somewhere of its own, or opens a wizard
                // step, must not have to fight us for the focus.
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

    // One delegated listener, so forms added to the page later work too.
    document.addEventListener('submit', handle, false);

    // Anything that counts as the visitor starting to fill the form in.
    document.addEventListener('focusin', noteInteraction, true);
    document.addEventListener('keydown', noteInteraction, true);
    document.addEventListener('pointerdown', noteInteraction, true);
    document.addEventListener('change', noteInteraction, true);
})();
