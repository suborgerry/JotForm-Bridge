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

