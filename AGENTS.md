# SugarCraft contributor playbook

End-to-end recipe for adding a new library or reference app to the
SugarCraft monorepo. Targeted at AI coding assistants and contributors
new to the project — every step says **what** to change and **where**.

If you're working through the audit (`AUDIT_2026_05_06.md`) instead of
adding a new lib, skip to the [Audit-driven PR section](#audit-driven-prs).

> **Source-of-truth files** to keep in sync when adding any new lib or
> app: [`MATCHUPS.md`](./MATCHUPS.md) (upstream → SugarCraft mapping),
> [`PROJECT_NAMES.md`](./PROJECT_NAMES.md) (naming-decision history),
> [`CONVERSION.md`](./CONVERSION.md) (architectural roadmap),
> [`docs/index.html`](./docs/index.html) (homepage tile),
> [`docs/lib/<slug>.html`](./docs/lib/) (per-lib detail page),
> and the audit file when an audit pass exists.

---

## 0 — Pick a name

1. Decide the **prefix** from [`MATCHUPS.md`'s cheat sheet](./MATCHUPS.md#naming-conventions-cheat-sheet):
   - `Candy-` — foundation / system / framework
   - `Sugar-` — components / data / forms / apps
   - `Honey-` — math / physics / motion
2. Pick a **short technical suffix** that describes the role (Core /
   Sprinkles / Bits / Charts / Prompt / Shell / Shine / …).
3. The PHP **subdir** + **composer name** follow the kebab-cased pair:
   `CandyShine` → `candy-shine/` → `candycore/candy-shine`.
4. The **PSR-4 namespace** drops the prefix: `CandyShine` →
   `CandyCore\Shine`. (`CandyCore\Core` is the one quirky exception
   for the runtime that shares the umbrella name.)
5. Record the choice in two places:
   - Add a new row to [`MATCHUPS.md`](./MATCHUPS.md) under the right
     table (Libraries or Reference apps).
   - Add a one-line entry + any naming-decision rationale to
     [`PROJECT_NAMES.md`](./PROJECT_NAMES.md).

---

## 1 — Scaffold the package

```
<slug>/
├── composer.json
├── phpunit.xml
├── README.md
├── examples/
│   └── <demo>.php       # at least one runnable example
├── src/
│   └── <Class>.php      # primary entry-point class(es)
├── tests/
│   └── <Class>Test.php  # PHPUnit 10
└── .vhs/
    └── <demo>.tape      # VHS recording driving the example
```

### `composer.json` skeleton

```json
{
    "name": "candycore/<slug>",
    "description": "PHP port of <upstream> — <one-line role>.",
    "type": "library",
    "license": "MIT",
    "keywords": ["tui", "terminal", "candycore", "<lib-specific>"],
    "homepage": "https://github.com/sugarcraft/<slug>",
    "authors": [
        { "name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer" }
    ],
    "support": {
        "issues": "https://github.com/sugarcraft/<slug>/issues",
        "source": "https://github.com/sugarcraft/<slug>"
    },
    "require": {
        "php": "^8.1",
        "candycore/candy-core": "@dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": { "<NS>\\<Sub>\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "<NS>\\<Sub>\\Tests\\": "tests/" }
    },
    "minimum-stability": "dev"
}
```

Wire the new package into the **root** `composer.json` so `composer
install` from the monorepo root pulls it: add a `repositories` entry
and require it from the umbrella metapackage. Existing packages
provide a working pattern.

### `phpunit.xml` skeleton

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true">
    <testsuites>
        <testsuite name="<slug>"><directory>tests</directory></testsuite>
    </testsuites>
    <source>
        <include><directory>src</directory></include>
    </source>
</phpunit>
```

---

## 2 — Code conventions

- `declare(strict_types=1);` at the top of every PHP file.
- PSR-12 + PSR-4. Every public class is final unless extension is part
  of the contract.
- **Immutable values, fluent builders.** Mirror upstream's `with*`
  pattern: each setter returns a new instance via a private `mutate()`
  / `copy()` helper.
- **Public readonly properties** for state; methods (without `get`
  prefix) for read-only accessors that need to compute.
- **Factory methods** named after upstream where possible: `Theme::ansi()`
  / `Theme::dracula()` / `Spinner::line()` etc.
- Keep PHP **8.1+ compatible** — fibers, readonly properties, enums
  are all fair game; named arguments encouraged on multi-param ctors.
- New surface that mirrors an upstream API gets a doc-comment
  `Mirrors upstream's <UpstreamName>.<Method>` so readers can pivot
  back to the Go reference.

---

## 3 — Tests

PHPUnit 10. **Every public method needs at least one test.** Tests
live alongside source under `tests/<Class>Test.php` with namespace
`<NS>\\<Sub>\\Tests\\…`.

Common patterns in the codebase (look at `sugar-bits/tests/` for
prior art):

- **Snapshot tests** for renderers — call `view()`, assert SGR bytes.
  We use `\x1b[1m`-style raw escape strings in assertions; don't
  abstract.
- **Behaviour tests** for state machines — drive `update()` with
  scripted `KeyMsg` / `MouseMsg` instances, assert resulting state.
- **Coercion tests** for value APIs — feed edge cases (negative
  index, oversized index, empty input, null) and assert clamped /
  default behaviour. Most fluent setters silently no-op on
  out-of-range inputs to match upstream.

CI runs `vendor/bin/phpunit` per package. Don't merge with red
tests; if a test fixture needs adjusting, fix the test alongside the
behaviour change.

---

## 4 — Examples

Each library ships at least one runnable demo under `examples/`:

```
<slug>/examples/<feature>.php
```

The demo is a self-contained `php examples/<feature>.php` invocation
that exercises the visible surface. For TUI libraries, the demo
should let the user interact (keys + quit) and not rely on external
input fixtures.

---

## 5 — Demos (VHS recordings)

Every non-trivial demo gets a corresponding `.tape` file under
`<slug>/.vhs/<demo>.tape`. The CI workflow re-renders these to
`.gif` on every push that touches the source.

```
# .vhs/<demo>.tape
Set Theme "TokyoNight"
Output <demo>.gif
Set FontSize 14
Set Width 800
Set Height 480
Type "php examples/<demo>.php"
Enter
Sleep 2s
…
Sleep 1s
```

The rendered GIF lives at:

```
https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif
```

The website pulls these URLs directly — no manual copy step.

---

## 6 — Wire the new lib into the website

Two edits, both in [`docs/`](./docs/):

### `docs/index.html`

- **Homepage tile.** Add a new `<a class="lib-card" href="lib/<slug>.html">`
  block to the `#libraries` grid (or `#apps` for reference apps).
  Match the structure of the existing tiles: a `.lib-card-preview`
  wrapper containing the demo `<img>` (or the `lib-card-preview--empty`
  placeholder if no demo exists yet), then `.lib-icon-row` + title +
  `.lib-source` + `<p class="summary">` + `.links`.
- **Demo gallery.** If the lib has a headline demo, add a tile to the
  `#demos` section too.

### `docs/lib/<slug>.html`

Copy any sibling lib's detail page (e.g. `docs/lib/candy-core.html`)
and customise:

- `<title>` / `<meta description>` / `og:*` tags.
- The hero header: icon path, title, sub-title, port-of-X chip, role
  chips.
- Install snippet: `composer require candycore/<slug>`.
- Quickstart: a self-contained snippet small enough to read at a
  glance.
- "What's in the box" feature grid — 4–6 short feature cards.
- Source & demos list, then a Demo grid pulling each `.vhs/*.gif`.

### `docs/img/icons/<slug>.png`

A 256-square candy-themed icon. PNG with transparent background.

---

## 7 — Update the central docs

When the new lib lands, update **all four** of these:

1. **[`MATCHUPS.md`](./MATCHUPS.md)** — add the row + bump the status
   icon as you progress (🔴 → 🟡 → 🟢 → 🚀).
2. **[`PROJECT_NAMES.md`](./PROJECT_NAMES.md)** — naming rationale, if
   non-obvious.
3. **[`CONVERSION.md`](./CONVERSION.md)** — append to the "Phase 9+"
   table (or the right phase) with the dependency chain.
4. **[`AUDIT_2026_05_06.md`](./AUDIT_2026_05_06.md)** — only if there's
   an active audit and the lib has an upstream we're tracking gaps
   against. Otherwise leave alone.

---

## 8 — Commit + PR conventions

- Commit author **must** be `Joe Huss <detain@interserver.net>` for
  any maintainer-driven flow. CI infrastructure relies on this.
- **Branch name**: `agent/<slug>-<short>` for AI-driven work,
  `feat/<slug>-<short>` for human contributors.
- **PR title**: `<lib>: <short summary> (audit #N)` when closing audit
  items, or `<lib>: <feature>` for feature work.
- **PR body** ends with a `## Test plan` checklist that cites the
  test count + suite name (e.g. `sugar-bits full suite green
  (260/260)`).
- **PR size**: bundle 2–4 related items per PR — one-feature-per-PR
  produces too much churn. Mix domains where it makes sense
  (e.g. one lib feature + a website polish pass).
- **Auto-merge** is **not** enabled on the repo. Merge with
  `gh pr merge <num> --squash --delete-branch` after the PR creates
  cleanly.

---

## Audit-driven PRs

The `AUDIT_2026_05_06.md` file lists per-library API / example / doc
gaps against the upstream Go counterpart. Audit work flows
identically to feature work, with two extra rules:

1. **Update the audit file inline.** When you ship something the audit
   listed, mark it ✅ with a one-line summary right where it lived in
   the file. Don't move it; readers should see history in place.
2. **Skip credit + upgrade-guide entries.** Doc gaps that exist
   solely to credit upstream authors (e.g. "no Acknowledgements
   section") or to ship `UPGRADE_GUIDE.md` files are **out of scope**
   for this pre-1.0 PHP port. Drop them from the audit when you
   touch a section that contains them.

---

## Release / split-out

When a library hits **v1.0** (every audit item closed or explicitly
deferred, examples + tests + docs in place):

1. Tag the monorepo: `<slug>-v1.0.0`.
2. Extract the subtree into its own repo under `github.com/sugarcraft/<slug>`
   with full git history (`git filter-repo`).
3. Publish to Packagist under `candycore/<slug>` (will move to
   `sugarcraft/<slug>` when the org migrates).
4. Bump the row in [`MATCHUPS.md`](./MATCHUPS.md) to 🚀.
5. Replace the `repositories: [{type: path, ...}]` entry in the root
   `composer.json` with a Packagist constraint.

---

## Quick reference: file checklist for a new lib

```
[ ] <slug>/composer.json
[ ] <slug>/phpunit.xml
[ ] <slug>/README.md
[ ] <slug>/src/<Class>.php
[ ] <slug>/tests/<Class>Test.php
[ ] <slug>/examples/<demo>.php
[ ] <slug>/.vhs/<demo>.tape
[ ] composer.json (root)        — add repositories + require entry
[ ] MATCHUPS.md                 — new row + status
[ ] PROJECT_NAMES.md            — naming entry
[ ] CONVERSION.md               — phase table entry
[ ] docs/index.html             — homepage tile
[ ] docs/lib/<slug>.html        — detail page
[ ] docs/img/icons/<slug>.png   — 256-square icon
```
