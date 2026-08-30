# Hooks

Every extension point Jotform Bridge provides. A theme or a companion plugin
can change what the plugin does through these and through nothing else — the
plugin's classes are `final` on purpose.

> **Generated file.** Built from the docblocks above the `apply_filters()`
> and `do_action()` calls in `jotform-bridge/src/` by
> `bin/generate-hooks.php`. Edit the docblock, then run `composer hooks`.
> Editing this file directly will be overwritten, and CI checks that it
> matches the source.

**15** filters, **2** actions.

---

# Filters

A filter receives a value and must return one. Returning nothing, or a
value of the wrong type, is treated as no opinion: the plugin falls back to
what it would have used anyway.

## `jotform_bridge_allowed_api_hosts`

Filters the hosts a custom Jotform API base URL may point at.

Each entry matches that host and its subdomains. Adding one means
accepting that the API key will be sent there.

| Parameter | Type | Description |
| --- | --- | --- |
| `$hosts` | `array<int, string>` | Allowed hosts. |

Fires in `jotform-bridge/src/Settings/Settings.php:315`.

## `jotform_bridge_auto_field_html`

Filters the markup of one automatically rendered field.

The intended use is adding a wrapper or a description without
taking over the whole form. The returned string is printed as is,
which makes this the one place where the plugin hands the output
over to somebody else's code.

`$html` is finished, escaped markup. `$field` is not: it is the
normalized schema entry, and its `label` and `options[*].label`
are text as Jotform reports it, which may legitimately contain a
quote or an angle bracket. Anything taken out of `$field` and put
into markup has to be escaped by the callback:

    add_filter(
        'jotform_bridge_auto_field_html',
        static function (string $html, array $field): string {
            return '<div class="col">' . $html
                . '<p>' . esc_html($field['label']) . '</p></div>';
        },
        10,
        2
    );

The values in `$field` are deliberately left raw, because every
other consumer escapes them at the moment it prints them — see
`Templates\TemplateScaffold::text()`, which does the same job for
the generated starter template. Escaping them here instead would
mean the same array key held escaped text in one context and raw
text in every other.

| Parameter | Type | Description |
| --- | --- | --- |
| `$html` | `string` | Escaped field markup. |
| `$field` | `array<string, mixed>` | Normalized field; its text is raw. |
| `$integration` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Rendering/AutoRenderer.php:143`.

## `jotform_bridge_daily_ceiling`

Filters the daily ceiling the quota guard trips at.

| Parameter | Type | Description |
| --- | --- | --- |
| `$ceiling` | `int` | Submissions allowed today. |
| `$median` | `int` | Median of the last seven days. |
| `$state` | `array<string, mixed>` | Raw guard state. |

Fires in `jotform-bridge/src/Submission/QuotaGuard.php:239`.

## `jotform_bridge_duplicate_window`

Filters how long an identical submission is refused after one was

accepted. Return 0 to turn the guard off.

| Parameter | Type | Description |
| --- | --- | --- |
| `$seconds` | `int` | Duplicate window. |

Fires in `jotform-bridge/src/Submission/SubmissionPipeline.php:362`.

## `jotform_bridge_global_rate_limits`

Filters how many submission requests one address may send to the

plugin as a whole, whichever integration they name.

Set either value to 0 to disable that window.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limits` | `array{per_minute:int, per_hour:int}` | Current limits. |

Fires in `jotform-bridge/src/Submission/RateLimiter.php:161`.

## `jotform_bridge_minimum_time`

Filters how long a form must be open before it may be submitted.

Return 0 to disable the check.

| Parameter | Type | Description |
| --- | --- | --- |
| `$seconds` | `int` | Minimum seconds. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/Guards/MinimumTime.php:116`.

## `jotform_bridge_normalized_schema`

Filters the normalized schema before it is stored.

| Parameter | Type | Description |
| --- | --- | --- |
| `$schema` | `FormSchema` | The normalized schema. |
| `$formId` | `string` | Jotform form ID. |

Fires in `jotform-bridge/src/Forms/SchemaRepository.php:152`.

## `jotform_bridge_pow_bits`

Filters how much work a submission must cost.

Every extra bit doubles it. The frontend script has to be taught the
same number, so this is only useful together with a filter on the
script itself.

| Parameter | Type | Description |
| --- | --- | --- |
| `$bits` | `int` | Leading zero bits required. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/Guards/ProofOfWork.php:153`.

## `jotform_bridge_pow_required`

Filters whether a submission without a proof of work is refused.

Turning this off removes the only barrier in front of a bot that
posts straight to the endpoint without running any of the page's
JavaScript. The one good reason to do it is a site still serving a
cached copy of an older version of the plugin's script.

| Parameter | Type | Description |
| --- | --- | --- |
| `$required` | `bool` | Whether the proof is mandatory. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/Guards/ProofOfWork.php:169`.

## `jotform_bridge_rate_limits`

Filters how many submissions one address may send.

Set either value to 0 to disable that window.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limits` | `array{per_minute:int, per_hour:int}` | Current limits. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/RateLimiter.php:182`.

## `jotform_bridge_spam_check`

Filters whether a submission is allowed to reach Jotform.

Return true to allow, false to reject with the default message, or a
string to reject with a custom, visitor-facing message.

| Parameter | Type | Description |
| --- | --- | --- |
| `$allowed` | `bool\|string` | Allow the submission. |
| `$slug` | `string` | Integration slug. |
| `$values` | `array<string, string\|array<int, string>>` | Sanitized values. |
| `$context` | `array<string, mixed>` | Request metadata. |

Fires in `jotform-bridge/src/Submission/SpamGuard.php:48`.

## `jotform_bridge_submission_fields`

Filters the sanitized values just before they are mapped.

| Parameter | Type | Description |
| --- | --- | --- |
| `$values` | `array<string, string\|array<int, string>>` | Sanitized values. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/SubmissionPipeline.php:191`.

## `jotform_bridge_template_paths`

Filters the directories scanned for custom form templates.

Paths must be absolute filesystem paths. They are resolved with
realpath() and every discovered file is verified to stay inside them.

| Parameter | Type | Description |
| --- | --- | --- |
| `$paths` | `array<int, string>` | Absolute directory paths, most specific first. |

Fires in `jotform-bridge/src/Templates/TemplateScanner.php:147`.

## `jotform_bridge_turnstile_fail_open`

Filters whether submissions proceed when the challenge cannot be

verified.

| Parameter | Type | Description |
| --- | --- | --- |
| `$failOpen` | `bool` | Allow the submission through. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/Guards/Turnstile.php:208`.

## `jotform_bridge_turnstile_required`

Filters whether a submission without a challenge token is refused.

Returning false makes the challenge optional, which is only
sensible while old markup is still being served from a cache.

| Parameter | Type | Description |
| --- | --- | --- |
| `$required` | `bool` | Whether the token is mandatory. |
| `$slug` | `string` | Integration slug. |

Fires in `jotform-bridge/src/Submission/Guards/Turnstile.php:114`.

# Actions

An action's return value is ignored. Anything slow belongs on a queue: these
fire inside the request the visitor is waiting on.

## `jotform_bridge_after_submit`

Fires after a submission was accepted by Jotform.

| Parameter | Type | Description |
| --- | --- | --- |
| `$slug` | `string` | Integration slug. |
| `$values` | `array<string, string\|array<int, string>>` | Sanitized values. |
| `$submissionId` | `string` | Jotform submission ID. |

Fires in `jotform-bridge/src/Submission/SubmissionPipeline.php:276`.

## `jotform_bridge_before_submit`

Fires before a validated submission is sent to Jotform.

| Parameter | Type | Description |
| --- | --- | --- |
| `$slug` | `string` | Integration slug. |
| `$values` | `array<string, string\|array<int, string>>` | Sanitized values. |

Fires in `jotform-bridge/src/Submission/SubmissionPipeline.php:228`.

