# TODO

Work that is understood and agreed, but not yet done. Each entry says what the
problem is and why it was left, so picking it up later does not mean
rediscovering the reasoning.

Not a backlog of everything imaginable — things that were considered and
rejected are not here, and should not be added back without a new decision.

**Next up** is the toolchain: five small items that are cheap on their own and
worth doing together, because each of them is currently a claim nobody can
check. **Later** is the rest, in no particular order.

An entry marked *Reconsider* is not work. It is a decision already taken and
recorded, kept here so the trade-off behind it is not forgotten and so that
revisiting it starts from evidence rather than from memory.

---

# Next up

## 1. A PHPCS ruleset

**Problem.** The source is annotated as though a checker exists — `phpcs:ignore
WordPress.Security.NonceVerification`, `WordPress.Security.ValidatedSanitizedInput`,
`WordPress.Security.EscapeOutput`, `WordPress.PHP.DevelopmentFunctions` — and no
ruleset is in the repository. So every one of those lines is an unverifiable
claim: nothing proves the sniff would have fired, and nothing notices when the
suppression stops being needed or, worse, starts hiding something real.

**Shape.** `phpcs.xml.dist` scoped to `jotform-bridge/`, plus
`wp-coding-standards/wpcs` in require-dev, with the minimum PHP version and the
`jotform-bridge` text domain configured so the i18n sniffs are useful.

**Why now.** The annotations were written first. Either they mean something, in
which case the ruleset has to exist, or they do not, in which case they should
come out.

---

## 2. Commit composer.lock

**Problem.** It is in `.gitignore`. This repository is an application, not a
library: there is nothing downstream that needs to resolve its own versions. A
PHPUnit or Brain Monkey update can therefore change what the suite does with no
commit anywhere explaining it, and CI cannot be reproducible without it.

**Shape.** Remove the line from `.gitignore`, commit the lock. The release ZIP
is unaffected — `bin/build-zip.sh` already excludes Composer files.

**Why now.** It is a prerequisite for CI, and it is one line.

---

## 3. Continuous integration

**Problem.** 470 tests, and nothing runs them. A pull request can break the
suite and nobody finds out until somebody remembers to run `composer test`.

**Shape.** GitHub Actions: PHPUnit across PHP 8.0–8.4, plus whatever static
analysis is agreed (PHPStan with `szepeviktor/phpstan-wordpress` is the obvious
candidate), plus `bin/build-zip.sh` as a smoke test that the package still
assembles.

**Depends on.** Item 2 above, and it should land with item 1 so the ruleset is
enforced from the first run rather than added later and immediately red.

---

## 4. A hook reference

**Problem.** Eighteen filters and actions, every one documented in a docblock
next to its `apply_filters()` call and nowhere else. A theme developer can only
discover them by reading `src/`, which means in practice they do not get
discovered. More than half were added in a single day, so the gap is recent and
growing.

**Shape.** `HOOKS.md`: name, signature, where it fires, what returning what
does. Generated from the docblocks by a small script rather than written by
hand, so it cannot drift away from the code the way hand-kept documentation
always does.

---

## 5. Check the request size before the body is parsed

**Problem.** `SubmissionController::handle()` compares
`strlen($request->get_body())` against `MAX_BODY_BYTES` — after WordPress has
already read and JSON-decoded the body. The memory the cap exists to bound has
been spent by the time the cap is consulted.

**Shape.** Refuse on `Content-Length` before dispatch, on `rest_pre_dispatch` or
equivalent.

**Size.** Small, and honestly the least important item here: PHP's own
`post_max_size` is the real bound, and this cap is a second, tighter one. Worth
fixing because a limit that runs after the thing it limits is misleading to
anybody reading it.

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

## 3. Admin notices survive only one tab

**Problem.** `Admin\IntegrationsPage` stores its flash notice in a transient
keyed by user ID alone. Two admin tabs, or two actions in quick succession, and
one message overwrites the other — the second screen shows a notice about
something that happened elsewhere, or nothing at all.

**Shape.** Key the transient by user plus a short random token carried in the
redirect URL, so a notice belongs to the redirect that produced it.

**Size.** Small. Left out only because nobody has been bitten by it yet.

---

## 4. Interfaces, so the storage can be replaced

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

## 5. No way to update the plugin

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

## 6. Running many sites off one Jotform account

**Problem.** The monthly submission allowance and the daily API call limit
belong to the Jotform **account**, not to a site. Ten sites pointing at one
account share one budget, while each site's QuotaGuard sees only its own
traffic and knows nothing about its neighbours. One site under a spam wave can
burn the allowance for all ten, and every one of them switches off.

**Shape.** No code fixes this on its own — it is mostly an operational
decision, one account per client or ceilings set against the shared budget. What
the plugin could add is honesty about it: read the account-wide spend, compare
it with what this site believes it sent, and say plainly when the two do not
match because somebody else is spending from the same pot.

**Depends on.** Account allowance tracking, which the plugin no longer has:
reading `GET /user/usage` and keeping a snapshot of the spend was removed in
favour of reacting to Jotform's own refusal. Reviving it means bringing back a
second, always-stale source of truth, and that trade has to be made
deliberately.

---

## 7. No audit trail, no configuration backup

**Problem.** Integrations and settings can be changed or deleted by any
administrator, and nothing records who did it or what the previous value was.
There is also no export or import: rebuilding a site's configuration means
retyping it from memory.

**Why it is parked.** On a single site with one administrator this is noise. It
starts to matter at the point where several people share an admin, or where the
same configuration has to exist on staging and production — and at that point
the file-backed configuration in item 4 above solves most of it, because git
becomes the audit trail and the backup. Worth revisiting together with that
rather than building a second mechanism.

---

## 8. The rate limiter writes to `wp_options` and counts non-atomically

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

## 9. No integration tests

**Problem.** 450 tests, and every one of them is a unit test. Nothing exercises
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

`wp-env` or `wp-phpunit` is the usual way to get there, and it needs the CI in
Next up item 3 to be worth having — a suite nobody runs is a suite that rots.

**Why it is parked.** It is the largest piece of work in this file, and it is
worth strictly more once CI exists. Doing it before then means writing tests
that only run when somebody remembers to run them.

---

## 10. Reconsider: template discovery reads the theme on every render

**Flagged for thought, not for action.** The decision is recorded and justified
in "Amendment: template discovery reads the theme on demand" in `AGENTS.md`, and
the reasoning behind it still stands. This entry exists so the trade-off is not
forgotten, and so that if it is ever revisited it is revisited with numbers
rather than from memory.

**The state of things.** Rendering an integration that uses a custom template
calls `TemplateRegistry::file()`, which scans the theme: `scandir()` of up to two
directories, plus an `fopen`/`fread` of the first 8 KB of every PHP file in them.
Memoized within the request, nothing cached between requests. A page with no
form of ours on it scans nothing.

**Why it was made that way.** A cached registry meant a developer could add a
file to the theme and not see it, and — worse — an edited template left the
compatibility report describing yesterday's version of the file. The `Rescan`
button was a symptom cure that required a person to remember a cache they never
asked for.

**What would have to be true to change it.** Actual numbers from a real site:
how many template files, how long the scan takes, and what share of the page's
total time that is. On a theme with a handful of templates this is a few stat
calls and a few short reads, which is noise next to a single database query.
It becomes worth revisiting if a site has a large template directory, or if
`opcache.validate_timestamps=0` in production makes filesystem access more
expensive than it looks.

**And what the answer would be.** An object-cache layer keyed on the directory
mtime — not the return of the `Rescan` button. Whatever happens, the admin has
to keep reading the state of the files directly, because that is the property
the cache cost us last time.

---

## 11. No accessibility audit against WCAG

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

## 12. No check against current web standards

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

## 13. Reconsider: Tailwind and Alpine for the admin screens

**The question.** Would the admin be better built on Tailwind and Alpine, or is
that the wrong tool here?

**What is known before anybody starts.** The evidence currently points at "wrong
tool", and it is worth writing down so the investigation starts from it rather
than from taste:

* The plugin's stated promise is that the release ZIP works with no
  `composer install`, no `npm install` and no `npm run build`. Tailwind needs a
  build step; the play CDN is not an option for a shipped plugin, and it would
  also be blocked by the CSP rules that made us move the inline JS out in the
  first place.
* WordPress admin already ships a design system — `common.css`, `forms.css`,
  `.widefat`, `.form-table`, `submit_button()`. Tailwind's preflight resets
  exactly what those depend on. Using Tailwind without preflight, inside markup
  that has to keep looking like WordPress, gets most of the cost for little of
  the benefit.
* The surface is small. The whole admin stylesheet is under 200 lines, and most
  of it exists to outweigh a core rule or to tint a table row — the kind of thing
  a utility framework does not help with.
* Alpine would replace about 170 lines of dependency-free vanilla JS with a
  runtime dependency, to do two things: toggle rows and copy to the clipboard.

**What would change the answer.** A much larger admin surface — several screens
with real interactivity, an integration builder, live previews — where hand-
written CSS and delegated event handlers genuinely stop scaling. That is not
where this plugin is.

**If it is investigated anyway**, the honest comparison is against the third
option nobody names: keeping vanilla CSS and JS but organising them better. Most
of what Tailwind is wanted for at this size is usually consistency, and
consistency is a naming convention.

---

## 14. Second review pass with a different model

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
* the rate limiter trade-off recorded in item 8, including the option that was
  rejected there.

Treat disagreement as information rather than as a verdict: the useful output is
a place where two independent readings differ, which is a place worth looking at
by hand.

---

## 15. Work out what Template diagnostics is for, and whether it earns its place

**Problem.** The bottom of the integrations list carries a "Template
diagnostics" block: a flat `<ul>` of `Error: …` / `Warning: …` / `Notice: …`
lines from `TemplateScanner`. Nobody has decided who reads it or what they do
next, and the code shows it.

The evidence that it was never finished:

* `CODE_DYNAMIC_FIELD` and `CODE_NO_FIELDS` are declared as constants and never
  emitted by anything. Two of the nine codes are decoration.
* Every diagnostic carries `code`, `file` and `slug`. The view prints `level`
  and `message` and throws the other three away — so the block can tell you a
  template is overridden but not offer the path, and cannot be filtered,
  grouped, or linked to the integration it affects.
* It renders whenever the scan produces anything, whether or not any integration
  uses the template concerned. A parent-theme template nobody has bound is a
  `notice` on a screen about integrations.
* The three levels are printed with `ucfirst()` and no styling. An `error` that
  stops a form rendering looks exactly like a `notice` that a child theme is
  doing the normal thing.

**The question to answer first.** Who is this for? There are two plausible
readers and they want different things:

* the developer who just added a template file and is asking "why is it not in
  the select" — wants the file path, the specific reason, and to be looking at
  it near the template list;
* the site owner whose form stopped rendering — wants to know which integration
  is affected, and everything else is noise. That reader is already served
  better elsewhere: the integration row goes red and says
  "No theme file named x.php. This form does not render."

**Shape, once that is answered.** Probably: keep the errors and warnings, attach
them to the template row they concern rather than to a separate list, drop the
notices or fold them into the Templates table (an overridden template is a fact
about that row, not an incident), and either emit the two unused codes or delete
them. If the honest answer turns out to be that the integration rows and the
Templates table already cover every case a person can act on, then the right
outcome is to delete the block — which is a good outcome, not a failure.

**Why it is parked.** It is a design question before it is a code change, and
answering it wrongly means building a second notification surface next to one
that already works.

---

## 16. Sweep for hardcoding and over-engineering

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

## 17. Consider storing only the forms actually used, fetched by ID

**The idea.** Instead of pulling the whole account form list and keeping it,
keep a record only for the forms integrations actually reference, resolved one
at a time by ID.

**Why it is worth considering.** The account list is the largest thing the
plugin stores and the least of it is used. A site with three integrations keeps
every form on the account — a shared agency account can be hundreds — and reads
that option on every admin screen that shows a form title. It also brings its own
problems along:

* the pagination defect in item 16: `limit=1000` with no paging, so a large
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
   paging that item 16 says is missing anyway.

**Also to settle.** Sync with Jotform currently does double duty — it checks the
API key with `GET /user` and records the account name. If the account list stops
being the reason to press it, that check needs a home.

**Implementation to be agreed separately.** This entry records the idea and the
constraints, not a decision.

