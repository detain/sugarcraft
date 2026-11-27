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
- a `root` that starts with `~` under `project` scope. PHP does not expand the home
  shorthand, so `~/work` would stage under the project at a literal `~` directory and
  candy-code would never find it. Write the absolute path (for example `/home/you/work`).
  Under `user` scope the `root` is unused, so a `~` value there is accepted.
- a package name that cannot be a directory name
- a package whose staging directory another installed `sugar-plugin` already uses.
  Names that differ only by vendor (`acme/candy-x` and `sugarcraft/candy-x`) share
  one directory. This check sees the packages installed in this project. It does
  not see a tree another project staged under user scope.
- a `project` scope install or update whose package name matches a bundle already in
  the user tier (`~/.candy-code/bundles/<name>`). A project bundle never inherits a
  user-tier name or its trust, so the install is refused by name, whichever package the
  user tree came from. An update is exempt only when that user-tier tree is the
  package's own tree and no project tree holds it: the update then moves the tree into
  the project tier. Project scope with no home directory has no user tier to clash with.
- a home directory that is not an absolute `HOME` or `USERPROFILE`, under `user` scope.
  Project scope never reads the home directory.

The section is enforced when a `sugar-plugin` package is downloaded, installed or
updated, not when Composer starts. A malformed section therefore refuses only the
install or update of a plugin. It does not break other Composer commands.

Composer also asks where an installed plugin lives on `dump-autoload`, `show`,
`validate` and `remove`. Those commands do not need a valid section, or a home
directory under user scope. They use the
staged tree that exists: the `root` tree when the section names one, then the
project tier, then the user tier, then the directory Composer recorded for the
package. `composer remove` therefore still deletes a
plugin staged under a section that has since been broken.

## Install closure and extension requirements

The installer requires `sugarcraft/sugar-plugins`, and that is deliberate. The
provenance record below is written by `SugarCraft\Plugins\Provenance\SourceRecord`,
the same class candy-code's marketplace install writes its own record through.
A copy of the writer here would let the two record formats, and the atomic-write
and lock rules behind them, drift apart, so the installer writes through the
library's writer and keeps no copy of its own.

Requiring `sugarcraft/sugar-plugins` also pulls in a closure, and a project that
installs this package must be able to satisfy all of it:

| Package | Requirements that matter here | Reached through |
|---|---|---|
| `sugarcraft/sugar-plugins` | `sugarcraft/sugar-core`, `sugarcraft/sugar-mcp-client`, `ext-tokenizer`, `psr/http-message`, `react/event-loop`, `react/promise` | this package |
| `sugarcraft/sugar-mcp-client` | `sugar-core` | `sugar-plugins` |
| `sugarcraft/sugar-core` | `ext-intl`, `ext-mbstring`, `sugarcraft/sugar-pty`, `sugarcraft/sugar-ansi-parser`, `react/*` | `sugar-plugins` |
| `sugarcraft/sugar-pty` | `ext-ffi`, `sugarcraft/sugar-input`, `sugarcraft/sugar-ansi-parser`, `sugar-core`, `react/*` | `sugar-core` |
| `sugarcraft/sugar-input` | `ext-mbstring`, `evenement/evenement`, `react/*` | `sugar-pty` |

The extensions a host PHP needs are therefore `ext-ffi` (through `sugar-pty`),
`ext-intl` (through `sugar-core`), `ext-mbstring` and `ext-tokenizer`. Composer checks
platform requirements on `composer require` and `composer install`, so a host
without one of them refuses to resolve the closure. Neither `ext-ffi` nor `ext-intl`
is needed by this package's own code. They arrive because the record writer's
package requires them.

## Provenance record

Each successful install and update writes `.sugar-plugin-source.json` into the staged
directory, beside the package's files. It is read back through `SugarCraft\Plugins\Provenance\SourceRecord`
and carries:

| Field | Value |
|---|---|
| `marketplace` | `composer` |
| `name` | the staged directory name (`candy-discord` for `sugarcraft/candy-discord`) |
| `sourceType` | `composer` |
| `repo` | the Composer package name |
| `url`, `sha` | empty |
| `version` | the resolved package version; an update rewrites it |
| `installedAt` | the Unix time of the install or update |

A staged directory that is a symlink, which is what a Composer `path` repository makes
under its default symlink strategy, gets no record. The link points at the developer's
checkout, and a record written through it would be an untracked file in that checkout.

The file name is not `.candy-code-source.json`, so a marketplace record already in a
bundle directory is never overwritten. The record is a description of what the
installer staged, not a trust decision: a project-tier bundle still loads under no
user-tier name, and candy-code's trust list is untouched. A record that cannot be
written rejects the install.

## What the installer does, and does not do

- It stages files, and the provenance record above. Composer's downloader fills
  the directory. `composer remove` deletes it, and `composer update` writes the new
  version to the same place.
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
  `remove` and `update` find the tree where it sits and delete it. They check the
  configured tier, the project and user default tiers, and the directory Composer
  recorded for the package in `vendor/composer/installed.json` after its last
  save. The recorded directory is what keeps a tree findable after a `root`
  change or a dropped `root` key. A tree counts only when its `composer.json`
  names the package, so another package's tree is never touched. A plain `composer install` leaves the tree
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

The offline run cannot fetch the closure above, so the test puts two stand-ins in
the scratch project in place of `sugarcraft/sugar-core` and `sugarcraft/sugar-plugins`.
`StubClosureDriftTest::STUBBED` lists which requirements each stand-in keeps and
which it drops. The `sugar-plugins` stand-in keeps only `sugarcraft/sugar-core`; it
drops `sugarcraft/sugar-mcp-client` along with the packages the offline run cannot
fetch, since the record writer the installer calls does not load it. `StubClosureDriftTest::testStubsDropExactlyTheDocumentedRequirements`
checks that table against the real manifests, so a new requirement in the closure
fails the suite until the table says how it is handled. It lives in its own class
so it still runs where `composer` is not on `PATH`.

## Plan

`plan_plugin.md` sections 5.1 (install behavior), 5.3 (Composer phase 4), 5.5
(who writes which `composer.json`), and 0.2 O5, O6 and O8.
