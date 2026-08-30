# TODO

Work that is understood and agreed, but not yet done. Each entry says what the
problem is and why it was left, so picking it up later does not mean
rediscovering the reasoning.

Not a backlog of everything imaginable — things that were considered and
rejected are not here, and should not be added back without a new decision.

The toolchain section that used to head this file is gone: the PHPCS ruleset,
`composer.lock`, continuous integration and the generated hook reference were
done together in August 2026, and the request-size check that remained was
examined and dropped — see `SubmissionController::MAX_BODY_BYTES`, where the
reasoning now lives beside the code it is about. What follows is in no
particular order.

---

# Later

## 1. An outbox for submissions that never reached Jotform

**Problem.** When Jotform is down, or the quota guard has tripped, the
submission is answered with a 502/503 and the values are gone. Nothing retries,
nothing is stored, and the visitor is unlikely to type it all again. For a
plugin whose entire job is delivering form submissions, losing one is the worst
failure it has.

**Why it matters more now than it used to.** The guards added in August 2026
mean the plugin refuses submissions on purpose more often than before —
rate limit, quota guard, upstream allowance failures. Every one of those is a
person who tried to reach the site and did not.

**Shape.** Persist the mapped submission locally (custom post type, or a small
table), retry on cron or Action Scheduler with backoff, delete on success. The
quota guard already has the natural hook: instead of answering 503 when the
breaker is open, queue and answer success.

**Caveat.** The moment submissions are stored locally, the plugin holds
personal data, which today it deliberately does not — see the promise in
readme.txt. That has to be a conscious decision with retention limits and an
uninstall story, not a side effect.

---

## 2. File upload fields

**Problem.** `Submission\SubmissionMapper` builds an
`application/x-www-form-urlencoded` body, so file, signature and payment fields
cannot be sent. `Forms\FieldNormalizer` reports them as unsupported and
`Rendering\AutoRenderer` leaves them out rather than rendering an input whose
value would be dropped — which is the right behaviour, but it means those forms
simply cannot be used through the plugin.

**Shape.** `multipart/form-data` in `Api\JotformClient::createSubmission()`, an
upload path through the REST endpoint, size and type limits, and a decision
about where the file lives between the browser and Jotform.

**Caveat.** This is the largest single piece of work on the list, and it drags
temporary local storage of visitor uploads with it — same privacy question as
the outbox.

---

## 3. No way to update the plugin

**Problem.** The plugin is not on wordpress.org, carries no `Update URI` header
and ships no updater. Every install is a manual ZIP upload. On one site that is
an annoyance; across a portfolio of client sites it means the fleet drifts and
security fixes do not land.

**Why it is sharper than it looks.** The proof of work made version discipline a
correctness requirement, not hygiene. `frontend.js` is enqueued with the plugin
version in its URL, and a browser holding the previous file will keep using it
if the URL has not changed — a stale script computes no proof, and the guard
refuses the submission. Shipping a release without bumping the version turns
into refused submissions for a slice of real visitors.

`assets/admin.js` acquired the same property in August 2026: the **Connect form**
button is bound by that file, so an administrator whose browser holds the
previous copy gets a button that does nothing at all. Less severe — it costs one
admin a confusing minute rather than a visitor a lost submission — but it is the
second file whose staleness is a functional bug rather than a cosmetic one.

**What is done.** The version-consistency half, in August 2026.
`bin/version.php` reads the three strings, `--check` fails when they disagree,
and `--set X.Y.Z` writes all three at once. It runs as `composer version:check`,
inside `composer check`, and as a step in the CI lint job. `tests/bootstrap.php`
no longer carries a fourth copy: it reads the version out of the plugin header.

A single source of truth was considered and is not available. WordPress parses
`Version:` out of the raw file with a regular expression and wordpress.org does
the same to `Stable tag:`, so neither can be an expression. The constant could be
derived from the header at runtime, but it builds the asset URLs on every
request, and a file read plus a regex per page load is a bad price for removing
one literal. One command that writes all three, plus a check that proves they
agree, is as close as this gets.

**What is left.** The delivery channel: `Update URI` plus a small update server,
or `plugin-update-checker` against GitHub Releases. Until that exists, every
install is still a manual ZIP upload, which is the actual problem this entry
opened with.

---

## 4. The rate limiter writes to `wp_options` and counts non-atomically

**Problem.** `Submission\RateLimiter` keeps its buckets in transients, and both
properties of that storage are wrong under load rather than merely imperfect.

*Non-atomic counting.* `get_transient()` then `set_transient()` is a
read-modify-write, so two requests arriving together read the same number and
write the same increment. A burst can overshoot a limit of five by a few. It
blurs the boundary; it does not open it.

*Row growth.* One submission to a real integration charges two scopes across
two windows — four transients, eight `wp_options` rows per address per hour. A
flood that rotates addresses grows the table quickly.

**Why it is parked, not urgent.** The second problem is smaller than it looks:
expiring transients are stored with `autoload = 'no'`, so they cost nothing per
page load, and WordPress collects the expired ones daily through
`wp_scheduled_delete`. What is left is table size, bounded by roughly a day of
traffic.

**The threshold, so it does not have to be worked out again.** Arithmetic from
the code, not a measurement.

A key is scope + address + window length + window start, so one address writes
a bucket per wall-clock minute it submits in and one per hour, in each of two
scopes. Each transient is two `wp_options` rows. One ordinary visitor
submitting once therefore costs four transients, **eight rows**.

The cost is per *unique address*, not per request: a refused attempt writes
nothing at all — `RateLimiter::consume()` returns before `set_transient()` when
any window is already full — so a flood from one address stops growing the
table the moment it hits the limit.

| Unique addresses per day | Rows per day |
| --- | --- |
| ~1,000 | ~8,000 — invisible |
| ~10,000 | ~80,000 — visible in table size, harmless |
| ~100,000 | ~800,000 — this is where it hurts |

Which means genuine traffic essentially never gets there. Reaching 100,000 rows
honestly needs on the order of 12,000 submissions a day from distinct people.
The scenario that does reach it is a botnet rotating addresses — 100 requests a
second from unique addresses is roughly three million rows an hour — and that
is precisely the distributed flood this class does not defend against anyway
(see its docblock; `QuotaGuard` is what that is for). In that scenario the
limiter is not protecting anything and is still filling the table.

Two second-order effects worth remembering: `delete_expired_transients()` is
itself a load spike when it has a million rows to remove, and it runs on
WP-Cron, which only fires when somebody visits — so on a quiet site the cleanup
lags.

Non-atomic counting scales differently: the overshoot is bounded by how many
requests from one address are in flight at once, never by volume. It needs a
deliberate parallel burst, and the next batch reads the updated count.

**How to tell the moment has arrived:**

```sql
SELECT COUNT(*) FROM wp_options
WHERE option_name LIKE '\_transient\_jotform\_bridge\_rate\_%';
```

**Shape, when it matters.** Two independent moves, in this order:

1. With a persistent object cache, count with `wp_cache_incr()` — atomic, and no
   database rows at all. Only when `wp_using_ext_object_cache()` is true:
   without a backing store `wp_cache_*` lives for one request, which would turn
   the limiter off rather than speed it up. Transients stay as the fallback.
2. Without an object cache, merge both windows of one scope into a single
   transient with the hour's TTL and keep the minute counter inside the value.
   Eight rows become four, and both limits survive.

**Explicitly rejected: dropping the hourly window.** It halves the rows the same
way, and it is the wrong trade. The minute window bounds a burst; the hour
window bounds a steady trickle, and those are different attacks. Without it one
address goes from 30 submissions an hour to 300 on one integration, and from 60
to 900 across all of them. Worse, the daily circuit breaker trips at 200
*accepted* submissions, so the time a single address needs to take the form
offline for everybody falls from about seven hours to about forty minutes. The
hourly window is availability protection, not only spam protection.

---

## 5. ~~No integration tests~~ — done

Built in August 2026. There is a second suite, `tests/Integration/`, with 70
tests beside the 463 unit tests, and a CI job of its own.

**What it runs on.** WordPress core on the official SQLite drop-in, driven by
plain PHPUnit: no database server, no Docker, no `wp-env`. `bin/install-wp.sh`
downloads both into `.wordpress/` (git-ignored) and symlinks the plugin into
`wp-content/plugins/`, so the suite tests the working tree rather than a copy.
`tests/Integration/install.php` installs the site once and keeps a pristine
database; every run is restored from it, so nothing an earlier run left behind
can make a test pass. `composer test:integration` is the whole command, and
`.github/workflows/ci.yml` runs it on PHP 8.0 and 8.4.

Two decisions in there are worth not rediscovering:

* the plugin is *active in the options table*, so `wp-settings.php` includes it
  the way a site does. A bootstrap that required the plugin file would not have
  noticed it failing to load at all;
* each test gets a fresh request (`Runtime::newRequest()`): storage emptied,
  the callbacks bound to the previous services removed, the composition root
  discarded and booted again. `IntegrationRepository`, `Settings`,
  `FormRepository` and `TemplateRegistry` all memoize within a request, which
  is right on a site and wrong across fifty tests in one process.

**What it covers**, which is what this entry asked for:

* the REST route through `WP_REST_Request` against the registered route — the
  permission callback, the JSON body, 200/403/413/422/429/502/503, the
  `Retry-After` and `Cache-Control` headers, the redirect in the success body,
  and the mapped payload that reaches the upstream boundary;
* the `admin_post_` handlers and `wp_ajax_jotform_bridge_connect_form`, each
  with and without a valid nonce and with and without the capability, asserting
  that a refused action changed nothing;
* a template rendered from a real theme directory — the shipped
  `examples/contact.php`, so the documented example is checked too — child theme
  priority, a symlink leaving its root, and a path that resolves outside;
* activation, upgrade, deactivation and uninstall against a real options table,
  including every legacy purge and the stored API key an old version left
  behind;
* the admin screens rendered, asserting they do not describe features that were
  removed. That is the defect class this entry was mostly about, and it is now
  a failing test rather than a thing somebody notices in a browser.

**What it found immediately.** `Settings::region()` validated a stored value
against `regions()`, which translates, so every admin request called `__()` on
`plugins_loaded` — a `_doing_it_wrong` notice about loading the text domain too
early, on WordPress 6.7 and later. The region slugs are now a list of their own
and the labels stay in the view. Nothing in three hundred unit tests could see
it, because nothing there loads WordPress.

**What is still not covered, and is not pretending to be:**

* `assets/frontend.js`. The suite computes the proof of work in PHP, so it
  proves the server's half of the contract and not that the browser computes the
  same thing — the two copies of `BITS = 16` in item 10 are still unguarded by
  anything but a person opening the page. Double submit, the events and the
  redirect are still Playwright by hand;
* multisite. `Plugin::eachSite()` and the network-activation path have no test:
  the installed site is single;
* the admin screens are rendered by constructing the page object, not through
  `admin.php` — there is no `current_screen`, and the submenu order is not
  asserted;
* the HTTP status beside a `wp_send_json` answer. WordPress only sets it when
  `headers_sent()` is false, and by the first PHPUnit dot it is true. The body
  is asserted; the status is not;
* WordPress 6.4, the floor in the plugin header. CI runs whatever is current;
  `JFB_WP_VERSION=6.4` pins it for a one-off check;
* a live Jotform call, deliberately. Outbound HTTP is blocked and an unmocked
  request fails the test, so the boundary is always a fixture.

---

## 6. PHPStan, or a decision that PHPCS is enough

**Where this came from.** The continuous integration entry named PHPStan with
`szepeviktor/phpstan-wordpress` as the obvious static analysis candidate. CI was
built without it, deliberately: PHPCS had landed in the same session and had its
own first-run triage to work through, and adding a second analyser in the same
breath would have meant neither of them was read.

**What the two would actually do.** They do not overlap much, which is the
argument for having both:

* PHPCS checks the shape of the source — escaping, nonces, prefixes, the text
  domain, the style. It does not know what a variable holds.
* PHPStan checks types across call boundaries: a method that can return `null`
  into a parameter that cannot take it, an array key that is not always set, a
  docblock that no longer describes the code under it. This codebase declares
  types everywhere and carries a lot of `array<string, mixed>` shapes in
  docblocks, which is exactly the material PHPStan reads and nothing else
  currently checks.

**Shape.** `phpstan.neon.dist` at level 5 or 6 to begin with, plus
`szepeviktor/phpstan-wordpress` so WordPress's own function signatures are
known, and a `composer analyse` script wired into the CI lint job. A baseline
file is acceptable to get started, but it has to shrink: a baseline nobody
empties is a list of accepted defects.

**Why it is parked rather than dropped.** The honest possibility is that the
answer is no. If a first run produces mostly complaints about WordPress's own
loose signatures and about docblock array shapes that are correct in practice,
then a second analyser is cost without a finding, and recording that is a
result. Run it once before deciding.

---

## 7. No accessibility audit against WCAG

**Problem.** This is a plugin whose entire output is forms, and forms are where
accessibility is most often got wrong and most keenly felt. The markup was
written with the right intentions — `<label for>`, `<fieldset>`/`<legend>` for
choice groups, `aria-describedby` on every control, `aria-invalid` set by the
script, `role="alert"` with `aria-live="assertive"` for form errors and
`role="status"` with `aria-live="polite"` for the success message, focus moved
to the first invalid control on failure — but nothing has been measured against
the standard. Good intentions and a conformance check are different things.

**Shape.** WCAG 2.2 AA as the target, on both renderers and on the admin:

* automated first — axe-core or Lighthouse through the browser MCP, on an
  auto-rendered form, a custom-template form and the two admin screens;
* then the parts a tool cannot see: keyboard-only completion of a form,
  the announcement order when validation fails, whether the live region actually
  reads the error or is beaten by the focus move, the visible focus ring on the
  copy buttons and the delete button, and the required-field marker being
  understandable without colour;
* the auto renderer's error slots are `<span>` elements written to by
  JavaScript — check they are announced when filled, since an `aria-live`
  region added after page load is not reliably announced in every screen reader.

Fix what the audit finds; record what is deliberately not fixed and why.

**Why it is parked.** It is a real piece of work, not a checkbox, and it wants
the browser MCP available. It should not be parked for long: of everything in
this file it is the item most likely to be affecting real people right now.

---

## 8. No check against current web standards

**Problem.** The output has never been validated. The plugin generates HTML from
a schema it does not control, and Jotform allows labels and option values that
are not obviously safe to interpolate into markup — so "it renders in Chrome" is
not evidence of much.

**Shape.**

* run the generated markup of both renderers through the W3C validator,
  including a form with a schema that exercises the awkward cases: an empty
  label, a label with quotes and angle brackets, duplicate option values, an
  option value that is an empty string, a very long label;
* check the same for the admin screens;
* confirm the generated IDs are unique with two of the same integration on one
  page, which the instance counter is supposed to handle;
* check `assets/frontend.js` against what the supported browser range actually
  provides. It is written as ES5 and now guards `window.fetch`, but nothing
  states what that range is — decide it and write it down, because the answer
  changes whether the ES5 style is still worth its cost.

---

## 9. Second review pass with a different model

**Problem.** The August 2026 review, the removals that followed it and the fixes
in this file were all produced in one long session by one model. That is a
single point of view, and the failure it is prone to is not missing an obvious
bug — it is being consistent with its own earlier reasoning. Every one of these
decisions looked right to the model that made them, which is exactly what a
wrong decision also looks like.

**Shape.** A fresh review of the plugin by the Fable model, started cold —
without this conversation's context, so it is reading the code rather than the
argument for the code. Worth pointing at specifically:

* the four `Amendment:` sections in `AGENTS.md`, which are the decisions with
  the most reasoning and the least outside scrutiny;
* the submission pipeline's ordering and its single-answer refusal policy;
* the proof-of-work guard, which is home-grown crypto in the security path and
  was reviewed by nobody;
* the rate limiter trade-off recorded in item 4, including the option that was
  rejected there.

Treat disagreement as information rather than as a verdict: the useful output is
a place where two independent readings differ, which is a place worth looking at
by hand.

---

## 10. Sweep for hardcoding and over-engineering

**Problem.** Nobody has read the plugin looking specifically for two opposite
faults: a value that should have been derived or configurable but was typed in,
and machinery that costs more than the problem it solves. Both accumulate
quietly, and both are easiest to see in one deliberate pass rather than while
working on something else.

This is a sweep, not a rewrite. Most of what it finds should be left alone with
a note; the point is to know which is which.

**Hardcoding — concrete candidates already visible:**

* `Guards\ProofOfWork::BITS = 16` and `POW_BITS = 16` in `assets/frontend.js`
  are the same number written twice in two languages. The `jotform_bridge_pow_bits`
  filter changes only the PHP side, so using it silently breaks every submission
  — the docblock admits this. Either the script learns the number, or the filter
  goes.
* The menu position `58` in `IntegrationsPage::registerMenu()` is a bare
  literal, and it is the kind of number two plugins collide on.
* ~~`assets/admin.css` carries the WordPress core palette as literal hex.~~ Done
  in August 2026 while the Connect form styles were added: the block now names
  the core variables the values come from and says why they are repeated rather
  than referenced. The rest of the file has not been read for this.
* Worth confirming as deliberate rather than accidental: `MIN_DAILY`,
  `BURST_FACTOR`, `MEDIAN_DAYS`, the four rate-limit defaults, `DUPLICATE_WINDOW`,
  `MIN_SECONDS`, `MAX_BODY_BYTES`, `HEADER_BYTES`, `SOURCE_BYTES`, and the 15 and
  5 second HTTP timeouts. Most already have a docblock arguing for the value,
  which is the standard the rest should meet.

**Over-engineering — concrete candidates:**

* `SubmissionPipeline::__construct()` takes eleven parameters, nine of them
  nullable with defaults constructed inside. That is a constructor doing
  container work, and it makes the dependency graph invisible from the outside.
  `IntegrationsPage` has eight, `FormRenderer` seven.
* `Rest\SubmissionController::__construct()` accepts either a pipeline or a
  factory returning one, and normalizes between them. Two ways to do one thing,
  where the laziness the factory buys is only needed on one path.
* `Settings::$generation`, a static counter that exists so an instance memo can
  notice a write made through a static method. Six references for a case the
  class's own docblock says does not currently occur.
* `TemplateScanner::lastChange()` takes `max(filemtime, filectime)` to survive
  tools that preserve mtime — clever, and worth confirming anyone depends on it.

**What the pass should produce.** For each finding, one of three outcomes: fix
it, or write down why it stays, or delete the machinery. A finding that ends in
none of the three has not been resolved, only visited.

---

## 11. ~~Consider storing only the forms actually used, fetched by ID~~ — done

Settled in August 2026 and implemented. Shape **2** was chosen: no account list
at all, a form ID typed into the integration editor and resolved one at a time
by a **Connect form** button.

The full decision, and what has to keep holding, is recorded in
"Amendment: no account form list" in `AGENTS.md`. In short:

* `GET /user/forms` is gone, and with it the `limit=1000` truncation defect this
  file used to carry as the one outright bug in item 10;
* `jotform_bridge_forms`, `_meta` and `_hidden` are deleted on upgrade; the store
  is now `jotform_bridge_connected_forms`, one record per form an integration
  names;
* **Sync with Jotform** became **Check Connection** — the `GET /user` half only.
  It kept a button because Jotform answers a wrong form ID, another account's
  form and a bad API key with an identical 401, so it is the only thing that
  tells the key apart from the ID;
* saving an integration with an unconnected ID warns rather than refusing.

The open question this entry raised — where the key check goes if the form list
stops being the reason to press the button — is answered by that last point.

**What was not done, and is not obviously needed.** Shape 3, a searchable paged
picker, stays unbuilt. It was the answer to "choosing a form without knowing its
ID", which the ID field makes moot at the cost of one copy-paste. Revisit only if
someone actually reports that cost, not on principle.
