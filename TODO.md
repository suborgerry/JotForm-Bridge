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

## 3. Interfaces, so the storage can be replaced

**Problem.** `IntegrationRepository` and `SchemaRepository` are `final` and read
their own options directly. Behaviour is adjustable through fifteen filters, but
neither can be swapped for a different implementation.

**Why it might matter.** One concrete scenario, not a general wish for
flexibility: keeping integrations and schemas **in code** rather than in the
database. A developer who wants staging and production to be provably identical,
wants the configuration to go through review, and does not want a deployment to
require clicking "Sync Schema" on every environment by hand, currently cannot —
the option in the database is the only source there is. It is the same argument
that already put the API key in `wp-config.php`, applied to the rest of the
configuration.

**Shape.** An interface for each of the two, a filter on the composition root in
`Plugin` so a site can substitute its own, and a file-backed implementation to
prove the interface is actually usable.

**Why it is parked.** The usual argument for interfaces — testability — does not
apply here: Brain Monkey stubs `get_option`, and the suite runs against the real
classes without complaint. That leaves only the scenario above, and until
somebody actually wants configuration in code, an interface with a single
implementation is an extra file and a false promise of flexibility. Worth doing
properly when the need is real; not worth doing speculatively.

---

## 4. No way to update the plugin

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

**Shape.** `Update URI` plus a small update server, or `plugin-update-checker`
against GitHub Releases. Whichever it is, a release checklist that fails the
build when the three version strings — plugin header,
`JOTFORM_BRIDGE_VERSION`, `Stable tag` — disagree.

---

## 5. The rate limiter writes to `wp_options` and counts non-atomically

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

## 6. No integration tests

**Problem.** 453 tests, and every one of them is a unit test. Nothing exercises
a REST request end to end, nothing exercises an `admin_post` action, and nothing
renders a template from an actual file through the actual registry.

That is not a coverage statistic — it is where the bugs were. Of the defects
found in the August 2026 review, the ones that mattered lived precisely in the
seams no unit test looks at: settings saving reset a stored value because a
hidden field was read unconditionally from `$_POST`; the admin screens carried
two stale instructions naming a directory and a button that no longer existed;
the auto-rendered form posted an empty body to the REST endpoint with no
JavaScript. Each of those is invisible to a test that constructs one class and
calls one method.

**Shape.** A second suite, separate from `tests/Unit/`, that boots enough of
WordPress to be honest:

* the REST route, exercised through `WP_REST_Request` against the registered
  route rather than by calling the pipeline directly — including the permission
  callback, the JSON body and the response headers;
* the `admin_post` handlers, exercised with and without a valid nonce and with
  and without the capability, asserting that the guard actually fires;
* rendering a real template file through `TemplateRegistry`, including a file
  that resolves outside its root;
* activation, upgrade and uninstall against a real options table.

`wp-env` or `wp-phpunit` is the usual way to get there. The precondition it had
— continuous integration, so that a suite nobody runs does not rot — is met:
`.github/workflows/ci.yml` runs the existing suite on every push. A second suite
needs a job of its own beside it.

**Why it is parked.** It is the largest piece of work in this file, and it is
the one that would have caught the defects that actually got through.

---

## 7. PHPStan, or a decision that PHPCS is enough

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

## 8. No accessibility audit against WCAG

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

## 9. No check against current web standards

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

## 10. Second review pass with a different model

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
* the rate limiter trade-off recorded in item 5, including the option that was
  rejected there.

Treat disagreement as information rather than as a verdict: the useful output is
a place where two independent readings differ, which is a place worth looking at
by hand.

---

## 11. Sweep for hardcoding and over-engineering

**Problem.** Nobody has read the plugin looking specifically for two opposite
faults: a value that should have been derived or configurable but was typed in,
and machinery that costs more than the problem it solves. Both accumulate
quietly, and both are easiest to see in one deliberate pass rather than while
working on something else.

This is a sweep, not a rewrite. Most of what it finds should be left alone with
a note; the point is to know which is which.

**Hardcoding — concrete candidates already visible:**

* `Api\JotformClient::FORMS_PAGE_LIMIT = 1000`, and `getForms()` does not
  paginate. An account with more than a thousand forms is silently truncated,
  and the missing ones simply never appear in the integration editor. This is
  the one item on the list that is a defect rather than a question.
* `Guards\ProofOfWork::BITS = 16` and `POW_BITS = 16` in `assets/frontend.js`
  are the same number written twice in two languages. The `jotform_bridge_pow_bits`
  filter changes only the PHP side, so using it silently breaks every submission
  — the docblock admits this. Either the script learns the number, or the filter
  goes.
* The menu position `58` in `IntegrationsPage::registerMenu()` is a bare
  literal, and it is the kind of number two plugins collide on.
* `assets/admin.css` carries the WordPress core palette as literal hex — the
  values are right, but nothing says where they came from.
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

## 12. Consider storing only the forms actually used, fetched by ID

**The idea.** Instead of pulling the whole account form list and keeping it,
keep a record only for the forms integrations actually reference, resolved one
at a time by ID.

**Why it is worth considering.** The account list is the largest thing the
plugin stores and the least of it is used. A site with three integrations keeps
every form on the account — a shared agency account can be hundreds — and reads
that option on every admin screen that shows a form title. It also brings its own
problems along:

* the pagination defect in item 11: `limit=1000` with no paging, so a large
  account is silently truncated and the missing forms never reach the select;
* `jotform_bridge_forms_hidden` and the whole **Remove from list** action exist
  only because the stored list carries forms nobody wants to see. Under a
  by-ID model that feature has nothing left to do;
* a form renamed in Jotform shows its old title until somebody presses Sync, and
  the list is refreshed as a whole or not at all.

Fetching one form by ID is already supported by the API the plugin uses —
`GET /form/{formID}` returns the title and status, alongside the
`/form/{formID}/questions` call the schema sync already makes.

**The tension to resolve first, before any implementation.** A list is what
makes it possible to *choose* a form without typing an ID, and not typing an ID
was a deliberate decision in stage 3. Fetching only what you need means knowing
what you need. Three shapes, and the choice between them is the actual design
question:

1. **Keep the list for discovery, store only what is used.** The editor fetches
   the list to populate the select and does not persist it; a chosen form gets
   its own stored record. Fixes the storage and the truncation for forms that
   matter, but puts an API call back on the editor screen — which is the thing
   the manual-sync amendment was written to avoid.
2. **Paste an ID or a Jotform form URL, resolve it once.** No account list at
   all. The smallest storage, no truncation, no hidden-forms feature — and the
   worst first-run experience, since the site owner has to go and find the ID.
3. **A searchable, paged picker.** Best at scale, most work, and it needs the
   paging that item 11 says is missing anyway.

**Also to settle.** Sync with Jotform currently does double duty — it checks the
API key with `GET /user` and records the account name. If the account list stops
being the reason to press it, that check needs a home.

**Implementation to be agreed separately.** This entry records the idea and the
constraints, not a decision.

