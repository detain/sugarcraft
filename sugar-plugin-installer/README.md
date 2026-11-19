# sugar-plugin-installer

A Composer plugin that installs SugarCraft plugin packages (`type: sugar-plugin`)
into the bundle tree that candy-code reads, instead of into `vendor/`.

Original work: there is no upstream port, so no `Mirrors charmbracelet/...`
citation applies.

```sh
composer require sugarcraft/sugar-plugin-installer
```

The root project must allow the plugin to run, and it must say where plugins go:

```json
{
    "config": {
        "allow-plugins": {
            "sugarcraft/sugar-plugin-installer": true
        }
    },
    "extra": {
        "sugar-plugin-installer": {
            "scope": "project"
        }
    }
}
```

## Where a plugin lands

The root project's `composer.json` selects the scope. Plugin packages never set it.

| `extra.sugar-plugin-installer` | Staged to |
|---|---|
| absent, or `{}`, or `"scope": "user"` | `~/.candy-code/bundles/<name>` |
| `"scope": "project"` | `<project>/.candy-code/bundles/<name>` |
| `"scope": "project", "root": "<path>"` | `<path>/.candy-code/bundles/<name>` |

- `<name>` is the part of the package name after the vendor slash.
  `sugarcraft/candy-discord` stages to `.../bundles/candy-discord`.
- `<project>` is the directory Composer runs in (`--working-dir` is honoured,
  because Composer changes into it first).
- A relative `root` is resolved against `<project>`. An absolute `root` is used
  as given. `root` applies only under `project` scope. Under `user` scope it is
  validated but unused.
- `<path>` must be a directory the user can write to. The installer creates
  the `.candy-code/bundles` tree on install.

Refused with an exception, before anything is written:

- a `scope` other than `user` or `project` (`"global"`, `"USER"`, `null`, a number)
- a section that is not an object, or a non-empty list
- an unknown key in the section (so a typo such as `"scoep"` cannot fall back to user scope)
- a `root` that is not a non-empty string
- a package name that cannot be a directory name
- a home directory that is not an absolute `HOME` or `USERPROFILE`

The section is read when a `sugar-plugin` package is installed, not when Composer
starts. A malformed section therefore breaks only the install of a plugin, not
every other Composer command.

## What the installer does, and does not do

- It stages files only. Composer's downloader fills the directory. `composer remove`
  deletes it, and `composer update` writes the new version to the same place.
- One exception outside the staged tree: a package's declared `bin` entries are
  linked into the project's `vendor/bin` on install and update, and the links are
  removed on remove, as Composer does for any package. The links point into the
  staged directory; no other file is written outside it.
- It never runs plugin code.
- It never edits `trustedBundleChannels`, and it never writes the channel ledger.
  Trust stays a user-tier decision in candy-code.
- It does not check the staged tree's contents. A package's `autoload.files`
  entries still run when the project's Composer autoloader loads. Treat a
  plugin package with the same trust you give any Composer dependency.
- Changing `scope` or `root` after an install does not move the staged tree.
  Remove the package before changing the scope, or the old tree is orphaned.
- The home directory is taken from `HOME`, or from `USERPROFILE` when `HOME` is
  empty. candy-code's own home resolution has extra hardening that this
  package does not copy, because a plugin library must not depend on candy-code.

## Checklist items not applied

The new-lib checklist in `plan_plugin.md` section 7 applies to this package as
follows.

| Item | Status and reason |
|---|---|
| `composer.json`, `phpunit.xml`, `README.md`, `CALIBER_LEARNINGS.md`, `src/`, `tests/` | Done. |
| No `repositories[]` in the lib manifest; no candy-code require | Done. `tools/check-plugin-deps.php` and `tools/check-path-repos.php --no-lib-path-repos` pass. |
| No per-lib `composer.lock` | Done. `/*/composer.lock` is gitignored. |
| Root `composer.json` | `require` entry and `allow-plugins` entry only. No `repositories[]` entry: the plan's acceptance asks for a require entry alone, and the package resolves from Packagist like any other published lib. |
| Root `composer.lock` | Not refreshed in this change. A root `composer update` is needed to record the new require. |
| `docs/MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md` table | Done. Original-work rows; no icon yet, so the README row has an empty image cell. |
| `codecov.yml` | Done. |
| `docs/index.html` tile, `docs/_data/<slug>.json` and body, `php tools/gen-docs.php` | Skipped. The site catalogues libraries and apps, and this package is install tooling with no page of its own yet. |
| `support.docs` in `composer.json` | Dropped. It would point at a `docs/lib/sugar-plugin-installer.html` page that is not generated, so the entry is left out until that page exists. |
| `media/icons/sugar-plugin-installer.png` | Skipped. No icon asset has been designed for this package. |
| `.github/workflows/vhs.yml` | Skipped. Non-visual, so exempt. |
| `php tools/check-one-type-per-file.php`, `php tools/check-lib-licenses.php`, `php tools/check-child-lifetimes.php` | Applied. The package holds one type per file and carries the root `LICENSE`. It starts no child processes, so the lifetime check expects no change. The integration test runs `composer` through `exec`, which is a test-only child and is not exposed by the library. |

## Tests

```sh
cd sugar-plugin-installer && composer install && vendor/bin/phpunit
```

`ComposerIntegrationTest` runs the real `composer` against a scratch project,
with a scratch `HOME`, `COMPOSER_HOME`, cache and network disabled. It requires
the installer and a sample `sugar-plugin` package by path repository, then checks
the staged tree and that the trust list and ledger bytes do not change, across
`require` and `remove`. It skips with a reason when `composer` is not on `PATH`.

## Plan

`plan_plugin.md` sections 5.1 (install behavior), 5.3 (Composer phase 4), 5.5
(who writes which `composer.json`), and 0.2 O5, O6 and O8.
