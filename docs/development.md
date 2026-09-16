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
│       ├── Autoloader.php   # own PSR-4 loader, so no vendor/ in the release
│       ├── Plugin.php       # composition root
│       └── api.php          # jotform_bridge_render() and friends
├── tests/
│   ├── Unit/                # PHPUnit, WordPress stubbed with Brain Monkey
│   └── Integration/         # PHPUnit against a real WordPress on SQLite
├── bin/install-wp.sh        # downloads that WordPress into .wordpress/
├── bin/build-zip.sh         # builds the release ZIP
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

## Updating the version

```bash
php bin/version.php --set 1.1.0
composer check
bin/build-zip.sh
```

Update [the changelog](changelog.md) before building. The ZIP is written to
`dist/jotform-bridge-<version>.zip`. Documentation stays in the repository.
