# Changelog

[Documentation](README.md)

## 2.0.13

* **Check again** on the Updates screen now reaches GitHub. `force-check` only
  forces Core's version check and clears no plugin transient, so the updater
  answered from its own twelve-hour cache and a release published after the
  last check was not offered until that expired. The updater now drops its
  cache on `load-update-core.php` when the forced check is requested, before
  Core asks. The integration test that was meant to cover this called
  `wp_clean_plugins_cache()` directly; it now goes through the page action.
* Integration tests run on PHP 8.0 again: three `0o777` literals, which are
  PHP 8.1 syntax, were `0777`.

## 2.0.11

* The plugin version is written down once, in the `Version:` line of the plugin
  header. `JOTFORM_BRIDGE_VERSION` reads it from there at boot, `readme.txt`
  carries no `Stable tag`, and `bin/version.php` is gone with nothing left to
  keep in step.
* A release is a version bump merged into `main`. The workflow reads the header,
  and when no tag exists for that version yet, runs the checks, builds the ZIP
  and creates the tag and the GitHub Release. Nobody pushes a tag by hand.
* `composer.json` pins the Composer platform to PHP 8.0, so the lock file always
  resolves for the floor the plugin header promises, whatever PHP the developer
  runs.

## 2.0.0

* Updates arrive from GitHub Releases through the ordinary Updates screen. The
  plugin header carries `Update URI`, and `Updates\GitHubUpdater` answers Core's
  check with the ZIP attached to the latest release. No more manual uploads.
* Added local conditional visibility and requirements per integration, with
  matching browser and server evaluation and a read-only table of saved rules.

## 1.1.0
* Connect form loads missing schemas; automatic rendering is now the default.
* Added optional email domain restrictions and clearer connection diagnostics.
* Improved debug logging and removed redundant notices.

## 1.0.0
* Initial release.

## 0.2.1
* Accessibility fixes across both renderers and the admin, from an audit against
  WCAG 2.2 AA.
* A radio or checkbox group's error message is now announced: every input in the
  group points at the slot the message lands in. It used to be written into the
  page and read by nobody.
* A Jotform field with an empty label no longer renders a control with no
  accessible name; it falls back to the field's semantic key.
* Removed `aria-required` from choice group fieldsets, where it was invalid ARIA
  and ignored.
* The submit button keeps the keyboard focus while a submission is in flight,
  and so does **Connect form**.
* Fixes the **copy shortcode** button, which threw and did nothing at all, in
  every browser.
* Copying now says so out loud, and the copy buttons no longer announce
  "Copied" before they are pressed.
* The admin tables scroll inside their own container instead of taking the whole
  page sideways on a narrow screen.
* The Integrations screen has an `<h1>` again; the connection status green and
  the shortcode button meet the contrast minimum.
* The two destructive actions ask before they submit through a data attribute
  rather than an inline handler, which a Content Security Policy refuses — and a
  refused confirmation removed the question, not the action.
* The required marker on an auto-rendered form hides itself without depending on
  a stylesheet the plugin does not ship.

## 0.2.0
* Forms are connected one at a time by ID instead of the whole account form list
  being stored. The integration editor has a **Jotform Form ID** field and a
  **Connect form** button in place of the form dropdown.
* **Sync with Jotform** on the settings screen became **Check Connection**: it
  checks the API key and nothing else. The Jotform Forms table and the
  **Remove from list** action are gone with the list they belonged to.
* Saving an integration whose form ID has not been connected warns instead of
  refusing.
* Fixes a defect where an account with more than a thousand forms was silently
  truncated and the missing forms could not be selected at all.
* Upgrading removes the stored account form list; integrations, settings and
  synced schemas are untouched.

## 0.1.0
* First release: integrations, custom templates, automatic rendering, server-side
  validation, submission mapping to Jotform, per-integration success redirect,
  admin diagnostics.
