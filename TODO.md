# TODO

Work that is understood and agreed, but deliberately not scheduled. Each entry
says what the problem is and why it was left, so picking it up later does not
mean rediscovering the reasoning.

Not a backlog of everything imaginable — things that were considered and
rejected are not here, and should not be added back without a new decision.

---

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

## 4. Continuous integration

**Problem.** 443 tests, and nothing runs them. A pull request can break the
suite and nobody finds out until somebody remembers to run `composer test`.

**Shape.** GitHub Actions: PHPUnit across PHP 8.0–8.4, plus whatever static
analysis is agreed (PHPStan with `szepeviktor/phpstan-wordpress` is the obvious
candidate), plus `bin/build-zip.sh` as a smoke test that the package still
assembles.

**Depends on.** Committing `composer.lock`, which is currently gitignored — CI
without a lock file is not reproducible.
