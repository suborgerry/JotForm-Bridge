# Local conditional logic

[Documentation](README.md)

Rules belong to an integration and use semantic field paths. Two integrations
of the same Jotform form can have different rules. These rules are local to
WordPress; Jotform conditions are not imported or executed automatically.

## View saved rules

The integration editor displays saved rules in a read-only table. Targets,
actions, sources, comparisons and values cannot be added, edited or removed
through the admin. Saving other integration settings or renaming its slug
preserves the rules. New integrations created in the admin start without rules.

The admin save handler ignores any submitted `conditions`, including a forged
request or an editor opened before editing was disabled. The saved integration
is the only source of rules for this action.

For example, using semantic paths from your own synced schema:

| Target | Action | Source | Comparison | Value |
| --- | --- | --- | --- | --- |
| company | Show only when | client_type | Equals / includes | Company |
| company | Require only when | client_type | Equals / includes | Company |
| details | Show only when | reason | Equals / includes | Other |
| details | Require only when | reason | Equals / includes | Other |

**Show only when** makes the target visible when the condition matches and
hidden otherwise. Hidden fields are not required and their values are excluded
from the upstream payload, even if a forged REST request supplies them. Values
remain in the browser when toggling visibility, so changing an answer back does
not erase what the visitor typed. They are cleared by the ordinary form reset.

**Require only when** replaces the schema's ordinary requirement for that path:
the target is required exactly when the condition matches and it is visible.
Without this action, a visible field keeps its schema requirement. A required
checkbox group requires at least one choice, not every checkbox.

Comparisons are case-sensitive exact string comparisons. For radio, select and
checkbox questions, use the **option value**, not an independently translated
label. Equals / includes matches any selected value in a multi-value field;
Does not equal / include is its inverse. Is empty and Is not empty ignore the
value input and test whether the source has any nonempty values. `0` is nonempty.

## Custom templates

Automatic forms and newly generated starter templates include field containers.
For an existing custom template, put the label, all controls of the same path
and its error slot inside a wrapper:

```html
<div data-jotform-field-wrapper="company">
    <label for="company">Company</label>
    <input id="company" data-jotform-field="company">
    <span data-jotform-field-error="company"></span>
</div>
```

Use one wrapper per semantic path. A choice group's inputs share its wrapper.
Composite children use their own paths, such as `address.city`. The script also
recognizes `.jfb-field` containers. Without a container it can hide the control
and its explicitly associated label, but cannot identify surrounding layout or
descriptions. A container lets the whole field disappear together.

The rules are delivered by the normal rendering service; templates do not need
to print JSON or know a Jotform ID. Forms inserted into the DOM later are
initialized automatically. Input changes and form resets recompute the state.

## Validation and limits

The server evaluates conditions from validated, sanitized source values. Unknown
fields, invalid source values and oversized requests are still refused. The
browser's visibility and required flags are never authority for the backend.

Version one supports up to 50 independent rules, one action of each kind per
target. Self-references and sources that are conditionally hidden are refused.
There are no dependency chains, nested AND/OR groups, calculations, page jumps,
or Jotform rule imports. A template must include fields that can become required.

Saving preserves rules and validates them against the stored schema without contacting Jotform.
If a later schema sync removes a referenced path, the editor reports the broken
rule and rendering/submission stop until the saved configuration is repaired through code or controlled maintenance. The admin does not provide a rule repair action. Stored schemas are
never rewritten by conditional evaluation.

Keep Jotform's own required-field settings compatible with branches that omit
fields. Local rules do not change the upstream form definition; an upstream
refusal remains an ordinary submission error.

## Development verification

Run the unit and WordPress integration suites as described in
[Development](development.md). For browser smoke tests, generate isolated HTML
fixtures with `php tests/Browser/render-conditional.php`. Open the files in
`tests/_output/` with Playwright and run the corresponding functions in
`tests/Browser/conditional-frontend.js` and `tests/Browser/conditional-admin.js`.
The generator uses the test WordPress database and mocks upstream requests;
it does not read the local `.env` or perform live writes.
