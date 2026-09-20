# Development

[Documentation](README.md)

## Development

The repository is the plugin plus its dev harness. Only `jotform-bridge/` ships.

```text
.
├── jotform-bridge/          # ← the plugin; this directory is the release
│   ├── jotform-bridge.php   # main file with the plugin header
│   ├── uninstall.php
│   ├── readme.txt           # WordPress.org style readme
│   ├── assets/frontend.js
│   ├── examples/contact.php # a complete custom template
│   ├── languages/           # jotform-bridge.pot
│   └── src/
│       ├── Admin/           # settings and integrations screens
│       ├── Api/             # JotformClient, the only HTTP layer
│       ├── Forms/           # normalization, schema, storage
│       ├── Integrations/    # the Integration entity and its storage
│       ├── Rendering/       # both renderers, template context, assets
│       ├── Rest/            # the submission endpoint
│       ├── Settings/
│       ├── Submission/      # validation, mapping, spam guard, pipeline
│       ├── Support/         # logger
│       ├── Templates/       # scanner, registry, validator
│       ├── Updates/         # the GitHub Releases update check
│       ├── Autoloader.php   # own PSR-4 loader, so no vendor/ in the release
│       ├── Plugin.php       # composition root
│       └── api.php          # jotform_bridge_render() and friends
├── tests/
│   ├── Unit/                # PHPUnit, WordPress stubbed with Brain Monkey
│   └── Integration/         # PHPUnit against a real WordPress on SQLite
├── bin/install-wp.sh        # downloads that WordPress into .wordpress/
├── bin/build-zip.sh         # builds the release ZIP
├── bin/release-notes.php    # the readme.txt changelog entry for one version
├── AGENTS.md                # architectural specification
└── prompts/                 # the staged prompts this was built from
```

```bash
composer install           # dev dependencies (PHPUnit, Brain Monkey, PHPStan)
composer test              # unit suite; no WordPress, no network
composer test:integration  # integration suite; downloads WordPress once
composer lint              # coding standards (phpcs.xml.dist)
composer analyse           # static analysis (phpstan.neon.dist)
composer check             # lint, analysis, hooks, versions, unit suite
bin/build-zip.sh           # dist/jotform-bridge-<version>.zip
```

The integration suite loads a real WordPress — a real options table, a real REST
server, a real nonce — running on the official SQLite drop-in, so it needs
neither a database server nor Docker. `bin/install-wp.sh` puts it in
`.wordpress/` (git-ignored) and symlinks the plugin into it, which
`composer test:integration` does for you the first time. It never contacts
Jotform: outbound HTTP is blocked, and any request a test did not answer from a
fixture fails that test.

Regenerating the translation template:

```bash
wp i18n make-pot jotform-bridge jotform-bridge/languages/jotform-bridge.pot --domain=jotform-bridge
```

Architectural invariants worth keeping — they are what the design is:

* A template never knows a Jotform form ID or a question ID.
* The integration is the only binding layer between the two worlds.
* The normalized schema is the single schema contract.
* Custom and Auto rendering share one submission pipeline.
* `JotformClient` is the only code that talks to Jotform.
* The API key is server-only and constant-only; it never reaches the database.
* `TemplateRegistry` is an allowlist; a path is renderable only because it is in
  there.
* Backend validation is authoritative.

## Releasing

Installed copies update themselves from GitHub Releases: the plugin header
carries `Update URI`, and `Updates\GitHubUpdater` answers WordPress's update
check with the ZIP attached to the latest release. A release is therefore a
version bump on `main`, and the workflow in `.github/workflows/release.yml`
does the rest.

```bash
# set "Version: 2.1.0" in the header of jotform-bridge/jotform-bridge.php
# add "= 2.1.0 =" to the changelog in jotform-bridge/readme.txt, and to docs/changelog.md
composer check
git commit -am "Release 2.1.0"
# merge into main
```

The version is written down once, in the plugin header. The
`JOTFORM_BRIDGE_VERSION` constant reads it from there at boot, so the asset
URLs, the upgrade routine and the schema's "synced by" stamp cannot disagree
with what WordPress compares on update.

On every push to `main` the workflow reads that header. If a tag `v2.1.0`
already exists it does nothing; otherwise it runs `composer check`, builds the
ZIP with `bin/build-zip.sh`, creates the tag and publishes the release with
`jotform-bridge-2.1.0.zip` attached. The release body is the `= 2.1.0 =` entry
from `readme.txt`, which the plugin shows in the "View details" window; when
there is none, GitHub generates the notes from the commits and the release goes
out all the same. Nobody pushes a tag by hand, so the tag cannot disagree with
the header, and a push that did not move the version is not a release. The only
thing that stops a release is a failing `composer check`, and that is fixed by
fixing `main`: nothing was tagged, and the next push tries again.

Sites notice within twelve hours, or at once after **Check again** on
**Dashboard → Updates**. The check reads only the latest non-draft, non-prerelease
release, and only the asset named `jotform-bridge-<version>.zip`: GitHub's own
source archive is never offered, since it unpacks into a directory named after
the commit and carries the whole repository.

To build the ZIP locally without releasing:

```bash
bin/build-zip.sh                    # dist/jotform-bridge-<version>.zip
php bin/release-notes.php 2.1.0     # what the release body would be
```
