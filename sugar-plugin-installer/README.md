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
- `<project>` is the directory of the root `composer.json` that Composer loaded:
  the file named by `COMPOSER`, or `./composer.json`. Composer does not always
  change into that directory, so the tree follows the file, not the working directory.
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
- a package whose staging directory another installed `sugar-plugin` already uses.
  Names that differ only by vendor (`acme/candy-x` and `sugarcraft/candy-x`) share
  one directory. This check sees the packages installed in this project. It does
  not see a tree another project staged under user scope.
- a home directory that is not an absolute `HOME` or `USERPROFILE`, under `user` scope.
  Project scope never reads the home directory.

The section is enforced when a `sugar-plugin` package is downloaded, installed or
updated, not when Composer starts. A malformed section therefore refuses only the
install or update of a plugin. It does not break other Composer commands.

Composer also asks where an installed plugin lives on `dump-autoload`, `show`,
`validate` and `remove`. Those commands do not need a valid section, or a home
directory under user scope. They use the
staged tree that exists: the `root` tree when the section names one, then the
project tier, then the user tier. `composer remove` therefore still deletes a
plugin staged under a section that has since been broken.

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
  `remove` and `update` find the tree where it sits, in either tier, and delete
  it. A tree counts only when its `composer.json` names the package, so another
  package's tree is never touched. A plain `composer install` leaves the tree
  where it is. To move it, `composer remove` and `composer require` the package
  again, or `composer update` it to a new version.
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
| Root `composer.json` | `require`, `repositories[]` (a path entry, as the root does for every sibling) and `allow-plugins` entries. The checked-in rule in AGENTS.md and plan section 7 asks for both. |
| Root `composer.lock` | Not refreshed in this change. A root `composer update` is needed to record the new require. |
| `docs/MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md` table | Done. Original-work rows; the root README row carries the package icon. |
| `codecov.yml` | Done. |
| `docs/index.html` tile, `docs/_data/<slug>.json` and body, `php tools/gen-docs.php` | Skipped. The site catalogues libraries and apps, and this package is install tooling with no page of its own yet. The site's library count does not move: the count is derived from `docs/MATCHUPS.md`, and this package sits under `## Composer tooling (outside the catalog counts)`, so the site stays at "forty-three" and `php tools/gen-docs.php --check` passes at 43. |
| `support.docs` in `composer.json` | Dropped. It would point at a `docs/lib/sugar-plugin-installer.html` page that is not generated, so the entry is left out until that page exists. |
| `media/icons/sugar-plugin-installer.png` | Done. The icon is in the root `media/icons/`, and the root README row references it. |
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
