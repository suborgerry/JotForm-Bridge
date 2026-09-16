# Security and spam protection

[Documentation](README.md)


Going headless means the form no longer sits behind Jotform's own defences, so
the plugin brings its own. Four are on from the start and cost a visitor
nothing: a honeypot field, a minimum time between opening a form and sending it,
a rate limit per visitor address, and a small proof of work.

The proof of work is what covers the gap the others leave. Before a form is
sent, the browser has to find a number that makes a SHA-256 hash start with a
run of zero bits — roughly 65,000 hashes, a fraction of a second, and it runs in
the background while the visitor is still typing. Nobody is asked to identify
themselves, click anything, or talk to a third party. Sending one submission
costs nothing worth measuring; sending a hundred thousand costs real machine
time, which is the entire business model of spam.

Missing proof of work is refused; configured Turnstile also requires a token. A submission with no proof
did not run the plugin's script at all, which is exactly what posting straight
to the endpoint looks like. Above them sits a circuit breaker that
stops sending when a day's traffic is far above the site's normal — two hundred
submissions a day, or six times the recent median once there is one to compare
against. It also stops if Jotform itself reports the account is out of
allowance, because spending that allowance switches off every form on the
account, embedded ones included, until it resets.

Cloudflare Turnstile is optional and is the only layer that stops something
driving a real browser. Two constants in `wp-config.php` enable it:

`define( 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY', '...' );`
`define( 'JOTFORM_BRIDGE_TURNSTILE_SECRET', '...' );`

Flush your page cache after enabling it. A submission without a challenge token
is refused, and pages cached before the change do not carry the widget.

If the site sits behind a CDN or reverse proxy, tell the plugin which forwarded
header to believe, or every visitor will look like the proxy:

`define( 'JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );`

Custom templates print `$honeypot` and `$turnstile`; automatically rendered
forms include both already.


## Extension point

Providers register on `jotform_bridge_spam_check`: `true` allows, `false` refuses,
and a string refuses with that message. Preserve an earlier refusal:

```php
add_filter('jotform_bridge_spam_check', function ($allowed, $slug, $values, $context) {
    if ($allowed !== true) {
        return $allowed;
    }

    return my_spam_check($slug, $values, $context);
}, 40, 4);
```

Send anti-spam values in `spam`, separately from semantic `fields`. Honeypot
and minimum-time checks allow absent values for older templates; proof of work
and configured Turnstile refuse them. Turnstile registers only when both constants
are set. Accepted duplicate submissions are refused for 30 seconds; only hashes
are remembered, never submitted values.
