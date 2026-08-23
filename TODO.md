# TODO

Work that is understood and agreed, but not yet done. Each entry says what the
problem is and why it was left, so picking it up later does not mean
rediscovering the reasoning.

Not a backlog of everything imaginable — things that were considered and
rejected are not here, and should not be added back without a new decision.

**Next up** is the toolchain: four small items that are cheap on their own and
worth doing together, because each of them is currently a claim nobody can
check. **Later** is the rest, in no particular order.

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
`Support\Stats` and in readme.txt. That has to be a conscious decision with
retention limits and an uninstall story, not a side effect.

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
