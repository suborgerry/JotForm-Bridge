# TODO

Work that is understood and agreed, but not yet done. Each entry says what the
problem is and why it was left, so picking it up later does not mean
rediscovering the reasoning.

Not a backlog of everything imaginable — things that were considered and
rejected are not here, and should not be added back without a new decision.
Finished work is not here either: it lives in the code, in the `Amendment:`
sections of `AGENTS.md` and in the git history.

In no particular order.

---

## 1. An outbox for submissions that never reached Jotform

**Problem.** When Jotform is down, or the quota guard has tripped, the
submission is answered with a 502/503 and the values are gone. Nothing retries,
nothing is stored, and the visitor is unlikely to type it all again. For a
plugin whose entire job is delivering form submissions, losing one is the worst
failure it has.

The guards mean the plugin refuses submissions on purpose — rate limit, quota
guard, upstream allowance failures. Every one of those is a person who tried to
reach the site and did not.

**Shape.** Persist the mapped submission locally (custom post type, or a small
table), retry on cron or Action Scheduler with backoff, delete on success. The
quota guard already has the natural hook: instead of answering 503 when the
breaker is open, queue and answer success.

**Caveat.** The moment submissions are stored locally, the plugin holds
personal data, which today it deliberately does not — see the promise in
`readme.txt` and invariant 21 in `AGENTS.md`. That has to be a conscious
decision with retention limits and an uninstall story, not a side effect.

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

**Why it is sharper than it looks.** Version discipline is a correctness
requirement, not hygiene. `frontend.js` is enqueued with the plugin version in
its URL, and a browser holding the previous file will keep using it if the URL
has not changed — a stale script computes no proof of work, and the guard
refuses the submission. Shipping a release without bumping the version turns
into refused submissions for a slice of real visitors. `assets/admin.js` has
the same property: it binds the **Connect form** button, so a stale copy gives
an administrator a button that does nothing.

**State.** The version-consistency half exists: `bin/version.php --check` fails
when the three version strings disagree and `--set X.Y.Z` writes all three,
wired into `composer check` and the CI lint job.

**What is left.** The delivery channel: `Update URI` plus a small update
server, or `plugin-update-checker` against GitHub Releases.

---

## 4. Second review pass with a different model

**Problem.** The plugin, the removals recorded as `Amendment:` sections and the
fixes in this file were produced by one model. That is a single point of view,
and the failure it is prone to is not missing an obvious bug — it is being
consistent with its own earlier reasoning. Every one of these decisions looked
right to the model that made them, which is exactly what a wrong decision also
looks like.

**Shape.** A fresh review of the plugin by the Fable model, started cold —
without that conversation's context, so it is reading the code rather than the
argument for the code. Worth pointing at specifically:

* the `Amendment:` sections in `AGENTS.md`, which are the decisions with the
  most reasoning and the least outside scrutiny;
* the submission pipeline's ordering and its single-answer refusal policy;
* the proof-of-work guard, which is home-grown crypto in the security path and
  was reviewed by nobody;
* `Submission\RateLimiter`, whose storage and counting were examined and left
  as they are.

Treat disagreement as information rather than as a verdict: the useful output is
a place where two independent readings differ, which is a place worth looking at
by hand.

---

## 5. Sweep for hardcoding and over-engineering

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
* `assets/admin.css` beyond the core-palette block, which has been read already.
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
