# TODO-ACCESSIBILITY

The record of the accessibility audit `TODO.md` item 4 asked for, run on
2026-08-31 against plugin 0.2.0 and acted on in 0.2.1. Target: **WCAG 2.2 level
AA**, on both renderers and on the admin screens.

The eleven defects it found are fixed; they are listed in `readme.txt` under
0.2.1 and argued for in the commits and in the code they changed, which is where
finished work lives. What is kept here is the part that is expensive to
rediscover: how to run the audit again, what was measured and found sound, what
was deliberately left alone, and what this method cannot see at all.

When something below stops being true, fix it and say so here. A check recorded
as passing that nobody re-runs is worse than no record, because it is believed.

---

## How to run it again

Not in Playground and not against fixtures: a real WordPress serving real pages.
The questions worth asking — where does focus go, what is actually in the
accessibility tree, what colour is that text on that background — are all
questions about a rendered page.

The scaffolding lives inside the git-ignored `.wordpress/` tree, beside the
WordPress the integration suite uses, and does not disturb it: its own
`wp-config.php`, its own SQLite database (`jotform-bridge-audit.sqlite`), and no
`wp-config.php` on the suite's path, which defines its constants itself and
includes `wp-settings.php` directly. Both suites were re-run with all of it in
place.

```bash
bin/install-wp.sh                       # if .wordpress/ is not there yet
php -S 127.0.0.1:8765 -t .wordpress     # plain permalinks, no rewrite needed
```

What has to be on the site:

* **Three integrations**, with the schema written straight into
  `jotform_bridge_schema_{formId}` so that nothing contacts Jotform:
  * one auto-rendered from `tests/Fixtures/Jotform/form-questions.json` — full
    name, e-mail, phone, address, textbox, textarea, dropdown, radio, checkbox,
    number;
  * one of the same form through `examples/contact.php`, copied into the theme's
    `jotform-bridge-templates/`;
  * one auto-rendered from a schema built for the audit alone, carrying an empty
    label, a label with quotes and angle brackets, a very long label, a required
    checkbox group and a required radio group. Deliberately not a committed
    fixture: it is not a Jotform response anybody received, it is a set of
    awkward cases, and `AGENTS.md` is right that an invented fixture sets a
    wrong assumption in stone.
* **A page per integration**, plus one with the same shortcode twice, which is
  what proves the instance counter.
* **axe-core**, loaded on the front end and in wp-admin by an mu-plugin in
  `.wordpress/wp-content/mu-plugins/`.
* `WP_HTTP_BLOCK_EXTERNAL` in the audit `wp-config.php`, so no request can reach
  Jotform whatever the local `.env` holds.

Tools: axe-core 4.10.2 per screen, Lighthouse as an independent second opinion,
Playwright for keyboard traversal, focus tracking, live regions and computed
styles.

**Read the browser console.** The defect that had been there longest was not
found by either analyser: the shortcode copy button threw
`querySelector('')` — which does not return null, it throws — and did nothing at
all, in every browser, silently. axe saw a well-formed button. The console said
so on the first click.

**Check the asset version.** Two runs of this audit reported failures that had
already been fixed, because `admin.css?ver=` had not changed and the browser
served what it had. That is the same mechanism `TODO.md` item 3 describes for
real visitors, seen from the inside: bump with `bin/version.php --set` whenever
a shipped asset changes.

---

## Measured and sound

All of it on the running site, not read off the source. Re-verified after the
0.2.1 fixes.

* **axe: zero violations** on the auto-rendered form, the custom template, the
  awkward-label form, both admin tables, the integration editor, the "Add
  Integration" screen and the settings screen. On the two-forms page the three
  findings that remain are core's admin bar, Twenty Twenty-Five's navigation
  block and core's skip link, none of them inside our markup.
* **Lighthouse accessibility 97** on an auto-rendered form; its one remaining
  failure is the theme's navigation block.
* **Keyboard traversal.** Tab order follows the DOM, 19 stops from the first
  field to the submit button, no trap. The honeypot is never reached
  (`tabindex="-1"`), and the radio group is one stop. Every control shows a
  focus ring; so do the admin's copy buttons and the red Delete button, whose
  ring is drawn outside its own dark background and reads at 4.85:1 against the
  page.
* **Error handling, end to end, on a real 422.** Five errors, `aria-invalid` on
  every input carrying each rejected path — including both radios of a group —
  five messages in slots their controls describe themselves by, focus on the
  first invalid control in document order, `Validation failed.` in the assertive
  region.
* **Upstream failure.** Message in the `role="alert"` container, focus moved
  into it, everything the visitor typed still there, the form usable again.
* **The success sequence.** Success text written, `jotformbridge:success`
  dispatched with the form still filled in, then `reset()`, then focus moved to
  the success message — the order `AGENTS.md` mandates, in that order.
* **The in-flight state.** Focus stays on the submit button, which is
  `aria-disabled`, and the form is `aria-busy`; both are cleared when the answer
  arrives. The same holds for **Connect form** in the admin.
* **The auto renderer's error slots**, which `TODO.md` asked about specifically:
  they are not live regions and do not need to be. They are announced through
  `aria-describedby` when the script moves focus to the field, which is the
  mechanism that does not depend on a screen reader noticing a region that
  appeared after load.
* **Two forms of the same integration on one page.** No duplicate ids, distinct
  radio group `name`s.
* **Composite fields.** `fieldset`/`legend` per group, `label for` per
  sub-input, `autocomplete` tokens throughout.
* **1.4.12 Text Spacing.** The standard override on the frontend form and the
  admin editor: nothing clips, nothing overlaps, no horizontal scrollbar.
* **1.4.10 Reflow at 320 px.** The frontend form fits. Both admin screens now
  fit exactly, the tables scrolling inside their own containers.
* **2.5.8 Target Size (Minimum).** Under-24 px targets in the admin are all
  ≥ 38 px from their nearest neighbour, so the spacing exception applies. The
  frontend's checkboxes and radios are unstyled by us, so the user-agent
  exception applies.
* **The honeypot.** `aria-hidden` on the wrapper, `tabindex="-1"` on the input:
  not in the accessibility tree, not in the tab order, no `aria-hidden-focus`
  finding. A visitor using a screen reader cannot fill in the trap that would
  get their submission refused.

---

## Accepted, not fixed

**Nothing announces that a submission is being sent.** Focus stays on the submit
button and the form carries `aria-busy`, so a keyboard user keeps their place
and hears the outcome the moment it arrives — but no words are said in between.

Saying them would mean either writing progress into `[data-jotform-success]`,
which is a documented slot themes read and style and which would then hold a
message that is not a success, or adding a live region that custom templates
written against the current contract do not have. The requests are single-shot
and short: one `fetch`, with the proof of work normally computed while the
visitor was still typing.

Worth revisiting if the contract gains a status slot for other reasons, or if
the outbox in `TODO.md` item 1 makes submissions long-running. Not worth
breaking a template contract for on its own.

---

## What this method cannot see

* **No real screen reader was used.** Everything above is the accessibility
  tree, the DOM and focus as the browser reports them. What NVDA, JAWS and
  VoiceOver actually say — in particular whether the assertive region is
  announced or is cut off by the focus move that follows it — cannot be settled
  this way. The design no longer depends on the answer: when a field is at fault
  its message is in the focused control's description, and when the failure is
  form-level focus is moved into the alert itself. It is still the largest gap
  in this audit, and the one worth closing first if anybody has the software.
* **Windows High Contrast / forced-colours mode.** Not exercised.
* **The Turnstile widget.** Cloudflare's markup inside an iframe; the plugin's
  own contribution is one empty `<div>`.
* **Themes other than Twenty Twenty-Five.** The frontend markup is ours, but
  contrast and focus rings belong to whatever CSS the site loads. The one thing
  the plugin used to leave to the theme and no longer does is hiding the
  required marker.
* **The redirect success path**, including whether the form stays busy for the
  whole delay. The non-redirect path was exercised; the redirect one navigates
  away, and was left alone.
