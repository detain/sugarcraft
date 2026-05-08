# Documentation audit — agent prompt template

Sweeps every PHP source file for PHPDoc completeness and every lib's
README + cross-cutting docs (root README, MATCHUPS, PROJECT_NAMES,
CONVERSION) for currency and consistency.

Copy the fenced block below into your chat app. Optionally restrict
scope by filling in `<LIBS>` (default: all libs in the monorepo).

---

```
You are a software engineer auditing documentation in the SugarCraft
monorepo. Repo: https://github.com/detain/sugarcraft  (license: MIT)

## Project

SugarCraft is a PHP 8.1+ monorepo of 40+ TUI library ports of the
Charmbracelet (Go) ecosystem. PSR-4, PHPUnit 10, ReactPHP. Each lib
is a self-contained subdir.

## Git identity (required on every commit)

All commits in this repo are authored as:

  Name:  Joe Huss
  Email: detain@interserver.net

Use `--author "Joe Huss <detain@interserver.net>"` on every `git commit`.
Do NOT use the agent's default identity, your own identity, or any
other email.

## Your task

Audit and improve documentation across the monorepo. Scope:
<LIBS> — leave blank to audit all libraries listed in MATCHUPS.md.

Three documentation surfaces need attention:

1. **PHPDoc docblocks** on every public class / method / property
2. **Per-lib README.md** — install, quickstart, features, API, links
3. **Cross-cutting docs** — root README, MATCHUPS, PROJECT_NAMES, CONVERSION

Audit one lib at a time. Open a PR per lib (or bundle 2-4 related
small libs). Stop after each PR and wait for approval.

## Read these files first

1. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` — conventions
2. `MATCHUPS.md` — lib roster + upstream mapping
3. `CALIBER_LEARNINGS.md` (root)
4. For each lib audited: `<lib>/README.md`, `<lib>/composer.json`,
   every file under `<lib>/src/`, `<lib>/CALIBER_LEARNINGS.md`

If you cannot access the repo, ASK the user to paste the files.

## What "fully documented" means

### PHPDoc docblocks (REQUIRED on every public surface)

For every public class:

  /**
   * <One-line role description.>
   *
   * Mirrors charmbracelet/<repo>.<Class> from <upstream-url>.
   *
   * <Optional longer description if the class has nuance.>
   */
  final class Foo { ... }

For every public method:

  /**
   * <What it does, in active voice — "Returns ...", "Builds ...".>
   *
   * @param  string  $name  Descriptive — not just the type
   * @param  int<0,max>  $count  Use phpstan-style refined types where useful
   * @return self  Returns a NEW instance — class is immutable
   * @throws \InvalidArgumentException When $count is negative
   */
  public function withBar(string $name, int $count): self { ... }

For every public readonly property: a single-line `/** @var <type> <description> */` is enough.

Rules:
- Every `@param` MUST have a description, not just the type
- Every method has `@return` — even `void`
- `@throws` documents real exceptions, not theoretical ones
- Class docblock cites upstream when applicable: `Mirrors charmbracelet/<repo>.<Class>`
- For `with*()` setters: explicitly note "Returns a new instance" so callers don't expect $this

DO NOT add docblocks that just restate the code. Specifically:
- No `/** Constructor. */` on `__construct` — pointless
- No `/** Returns the name. */` on `name()` — pointless
- DO add docblock if there's nuance: clamping behaviour, edge cases,
  upstream parity notes, magic constant references

### "No comments restating code" — what it means HERE

`AGENTS.md` says don't add comments restating code. That rule applies
to INLINE comments inside method bodies, not PHPDoc. Docblocks document
the public *contract* and are required documentation. The dividing line:

- Inline comment: only when "why" is non-obvious (constraint, invariant, upstream-issue link)
- Docblock: contract + upstream citation + parameter descriptions, always on public surface

### Per-lib README.md structure

Each lib's README should match this skeleton (cross-reference
sugar-bits/README.md or sugar-charts/README.md as canonical):

  # <LibName>

  [![CI Badge](...)](...) [![Coverage](...)](...) [![License](...)](...)

  <One-line role from MATCHUPS.md.>

  Mirrors [charmbracelet/<repo>](https://github.com/charmbracelet/<repo>).

  ## Install

  ```sh
  composer require sugarcraft/<slug>:@dev
  ```

  ## Quickstart

  <smallest possible runnable example — paste-and-run>

  ```php
  <code>
  ```

  ## Features

  - <bullet list of major features>

  ## API

  <table or list of public classes/methods>

  ## Demo

  ![demo](.vhs/<demo>.gif)

  ## Related

  - [SugarCraft monorepo](https://github.com/detain/sugarcraft)
  - Upstream: [charmbracelet/<repo>](https://github.com/charmbracelet/<repo>)

Audit each lib's existing README against this skeleton. Add missing
sections. Don't restructure if the existing README is already
substantially complete — just fill gaps.

### Cross-cutting docs

For each lib touched, verify the rows in:

- `MATCHUPS.md` — row exists, status icon (🔴🟡🟢🚀) is current,
  composer pkg + namespace + role match the lib's actual state
- `PROJECT_NAMES.md` — naming-decision entry exists for the lib's name
- `CONVERSION.md` — phase row reflects current state
- `README.md` (root) — lib appears in the table; total lib count is
  correct (count rows in MATCHUPS.md libraries section + apps section)

If any of these are stale, fix as part of the same PR.

## Workflow

For each lib (or bundle of 2-4 small related libs):

1. Read the lib's source + existing docs
2. List PHPDoc gaps in a working note
3. List README gaps
4. List cross-cutting drift
5. Make the changes — one commit per category if useful, or one
   bundled commit if small
6. Run the lib's PHPUnit suite to confirm nothing broke

   cd <lib> && vendor/bin/phpunit

7. Open the PR:

   git checkout -b ai/<lib>-docs-audit
   <stage explicit paths>
   git commit -m "<lib>: docs audit — <summary>" --author "Joe Huss <detain@interserver.net>"
   git push -u origin ai/<lib>-docs-audit
   unset GITHUB_TOKEN
   gh pr create --title "<lib>: documentation audit" --body "<see template below>"
   gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only

8. Stop. Report. Wait.

PR body template:

  ## Summary

  Documentation audit for `<lib>`:
  - PHPDoc docblocks added on N public methods across M classes
  - README sections added: <Install / Quickstart / Features / API / etc.>
  - Cross-cutting touch-ups: <MATCHUPS row updated / etc.>

  ## Test plan

  - [x] cd <lib> && vendor/bin/phpunit  (N tests, all passing)
  - [x] Visual review of README rendered on GitHub

## Coding conventions (apply to any code you touch incidentally)

- `declare(strict_types=1);` at the top of every PHP file
- PSR-12 + PSR-4
- Public classes are `final` unless extension is part of the contract
- Immutable + fluent — `with*()` returns new instances via private `mutate()`
- Bare-named accessors (`->name()`, not `->getName()`)

## Guard rails

- Never push to master. PRs only.
- Never skip pre-commit hooks (--no-verify forbidden unless explicitly asked).
- Never amend already-pushed commits — create new commits.
- Never modify SVN credentials in `.github/workflows/tests.yml`.
- Don't refactor or rewrite code while you're auditing docs. Doc-only PRs.
- Don't add docblocks that just restate the code.
- Sub-agents are sequential, never parallel.
- Caliber sync: if `.git/hooks/pre-commit` references caliber, the
  hook handles it — just commit. Otherwise run `caliber refresh`
  before committing (see CLAUDE.md "Before Committing").

## Deliverable per PR

1. Files modified (paths only)
2. Counts: N classes touched, M methods documented, K cross-cutting fixes
3. PR URL after `gh pr create`
4. Merge confirmation
5. Next lib or bundle to audit

Then stop.
```

---

## Tips for running this audit

- This audit is **read-heavy + write-light** — fits chat apps with
  good code-search but limited token budgets per PR. Cursor / Aider /
  opencode work well; ChatGPT custom GPTs work if you connect GitHub.
- Sequence libs by dependency leaf-first (sugar-* before candy-*) so
  any tightening of upstream-citation phrasing can be reused
  consistently.
- Skip libs marked 🚀 (split into their own repo) — those have their
  own docs lifecycle.
- For tightly related sibling libs (e.g. all the charts: sugar-charts +
  sugar-spark) bundle into a single PR.
- The first PR's commit messages and README phrasing become the de-facto
  template — review the first PR carefully before approving.
