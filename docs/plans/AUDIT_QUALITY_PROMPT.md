# Code quality + bug audit — agent prompt template

Sweeps every lib for anti-patterns, dead code, security issues, and
performance bottlenecks. Surfaces findings; fixes the unambiguous
ones; leaves judgment-calls flagged for the user.

Copy the fenced block below into your chat app.

---

```
You are a software engineer auditing code quality in the SugarCraft
monorepo. Repo: https://github.com/detain/sugarcraft  (license: MIT)

## Project

SugarCraft is a PHP 8.1+ monorepo of 40+ TUI library ports of the
Charmbracelet (Go) ecosystem. PSR-4, PHPUnit 10, ReactPHP. Each lib
has its own composer.json + tests.

## Git identity (required on every commit)

All commits in this repo are authored as:

  Name:  Joe Huss
  Email: detain@interserver.net

Use `--author "Joe Huss <detain@interserver.net>"` on every `git commit`.
Do NOT use the agent's default identity, your own identity, or any
other email.

## Your task

Run an eight-category audit per lib. For each category, surface
findings; fix the unambiguous ones; FLAG judgment calls for the user.

A. Strict-mode hygiene (declare, types, comparisons)
B. Immutability + final
C. Naming + PSR conventions
D. Dead code
E. Security
F. Performance
G. Error handling
H. Codebase-specific gotchas (from CALIBER_LEARNINGS.md)

Open one PR per category per lib (or bundle related fixes if small).
Stop and wait after each PR.

## Read these files first

1. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`
2. `MATCHUPS.md` — lib roster
3. `CALIBER_LEARNINGS.md` (root + every per-lib one)
4. `UPSTREAM_OPPORTUNITIES.md` — known issues already tracked
5. For each lib audited: every file under `<lib>/src/`

If you cannot access the repo, ASK the user to paste files for one lib
at a time. Do not start coding without them.

## Static analysis tools

Try running these per lib (some may not be installed; install if user approves):

  cd <lib>
  composer require --dev phpstan/phpstan
  vendor/bin/phpstan analyse src --level=max

  composer require --dev vimeo/psalm
  vendor/bin/psalm src

If neither is installed and the user doesn't want to add them, fall
back to grep-based audits per category below.

## Category A — strict-mode hygiene

Per file:

- [ ] `declare(strict_types=1);` at the very top
- [ ] No `mixed` parameter or return types where a more specific
      type is available
- [ ] No loose comparisons (`==`) where strict (`===`) is correct —
      grep: `grep -RnE "[^=!]==[^=]" src/`
- [ ] No suppressed errors (`@`) — grep: `grep -Rn "@\$" src/`
- [ ] Nullable types (`?T`) used when null is valid; not when null is invalid
- [ ] Union types preferred over `mixed` when the set is finite

**Fix unambiguous**: missing `declare`, `==` on int/string comparisons.
**Flag for user**: type widening, nullable changes that touch public surface.

## Category B — immutability + final

Per public class:

- [ ] Class is `final` unless extension is part of the contract
- [ ] All `with*()` methods return a NEW instance via private `mutate()`,
      not `$this`
- [ ] State is `public readonly` (not `private` with getter)
- [ ] No `set*()` methods on otherwise-immutable classes
- [ ] No `clone $this` patterns where `mutate()` would be cleaner

**Fix unambiguous**: missing `final`, missing `readonly` on never-changed
properties.
**Flag for user**: changing a `set*()` method to `with*()` (breaks API).

## Category C — naming + PSR

- [ ] PSR-4 namespace matches directory layout
- [ ] PSR-12 indentation (4 spaces, no tabs)
- [ ] Bare-named accessors: `->name()` not `->getName()`
- [ ] Factory methods mirror upstream: `Theme::ansi()`, `Spinner::line()`
- [ ] Class names PascalCase, methods camelCase, constants UPPER_SNAKE
- [ ] No "I" prefix on interfaces, no "Abstract" prefix on abstract
      classes (PSR convention)
- [ ] No "_" prefix on private members (use `private` keyword)

**Fix unambiguous**: indentation, casing.
**Flag for user**: renaming `getX()` → `x()` (breaks API; bundle into
a "naming alignment" PR per lib that lists every breaking rename).

## Category D — dead code

- [ ] Unused private methods — `phpstan` flags these at level 4+
- [ ] Unused method parameters — `phpstan` flags at level 5+
- [ ] Unreachable branches (`if (false)`, dead `else`)
- [ ] Unused imports — phpstan strict-rules / php-cs-fixer
- [ ] Files in `src/` with no references from anywhere

**Fix unambiguous**: delete unused private methods, unused imports.
**Flag for user**: unused public methods (may be public API, may be dead).

## Category E — security

- [ ] `shell_exec` / `proc_open` / backtick — every external call uses
      `escapeshellarg((string)($field ?? ''))` per AGENTS.md gotcha
- [ ] `proc_open` array form preferred over string form (no shell parsing)
- [ ] `file_get_contents` / `fopen` / `include` on user-controlled paths —
      validate or restrict to known-safe roots
- [ ] Regex DoS — no nested quantifiers (`(a+)+`, `(a*)*`) on user input
- [ ] Unsafe deserialization — no `unserialize($userInput)`
- [ ] No hardcoded credentials in source — except the SVN credentials
      in `.github/workflows/tests.yml` which are HARDCODED ON PURPOSE
      per AGENTS.md (do NOT move to secrets — they don't exist yet)

**Fix unambiguous**: missing `escapeshellarg`, `proc_open` string form
when array would do.
**Flag for user**: anything that reads user-controlled paths or
deserializes untrusted data.

## Category F — performance

- [ ] No O(n²) loop where O(n) suffices
- [ ] No regex compilation in tight loops — use `preg_match_all` once,
      not `preg_match` per iteration
- [ ] No file I/O in render loops — read once, cache
- [ ] Large array copies in fluent setters: intentional cost of
      immutability; only flag if a setter is hot AND the array is
      huge (>1000 elements typical)
- [ ] String concat in loops: `array + implode` over repeated `.=`
- [ ] No unnecessary `array_merge` in loops (use spread or `[]+=`)

**Fix unambiguous**: regex compilation hoist, file I/O hoist, concat→implode.
**Flag for user**: structural perf changes that touch hot paths.

## Category G — error handling

- [ ] Throw `\InvalidArgumentException` for invalid input, not `return null`
- [ ] Throw `\RuntimeException` for runtime failures
- [ ] No `try { ... } catch (\Exception $e) {}` swallowing exceptions
- [ ] No `try { ... } catch (\Throwable $e) { /* nothing */ }` either
- [ ] Use specific exception classes; create them under `<Lib>\Exception\`
      if a domain-specific class would clarify
- [ ] Don't log AND throw the same exception (caller decides)

**Fix unambiguous**: `return null` on invalid input → throw.
**Flag for user**: changing return types from nullable to non-null
(breaks API).

## Category H — codebase-specific gotchas

From `CALIBER_LEARNINGS.md` (root + per-lib):

- [ ] External CLI calls — every flag passed every invocation, even
      when fields are empty. Pattern:
      `escapeshellarg((string)($field ?? ''))` rendering as `''`
      rather than dropping the flag.
- [ ] Bash CWD bugs — anchor multi-step shell with absolute paths
      (Bash tool's CWD persists across calls in the harness)
- [ ] `composer validate --strict` flagging `"sugarcraft/*": "@dev"` —
      drop `--strict` (expected for path-repos pre-1.0)
- [ ] Transitive `@dev` deps — every consuming lib's `composer.json`
      `repositories` array must include the path-repo for any new dep
- [ ] Sub-agents run in parallel — never; collide on shared files
- [ ] `tests.yml` SVN credentials — HARDCODED, do not move to secrets
- [ ] Stream-write `ftruncate; rewind` antipattern — use
      `ftell`/`fseek`/`stream_get_contents` (canonical pattern in
      `candy-core/tests/RendererTest.php`)

For any violation: fix and document in the appropriate
`CALIBER_LEARNINGS.md` if the pattern is new.

## Workflow per PR

1. Pick a lib + category (e.g. `sugar-bits` + Category A)
2. Generate a finding list for that lib + category
3. Triage: unambiguous fixes vs flag-for-user
4. Open the PR with the unambiguous fixes:

   git checkout -b ai/<lib>-quality-<category>
   <stage explicit paths>
   git commit -m "<lib>: quality audit <category> — <summary>" \
     --author "Joe Huss <detain@interserver.net>"
   git push -u origin ai/<lib>-quality-<category>
   unset GITHUB_TOKEN
   gh pr create --title "<lib>: quality audit — <category>" --body "<body>"
   gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only

5. Report the flagged-for-user items separately so the user can decide
6. Stop. Wait for direction on the flagged items before next category.

PR body template:

  ## Summary

  Quality audit for `<lib>` — <Category X>:

  Fixed (unambiguous):
  - <bullets>

  Flagged for user decision:
  - <bullets — these would touch the public API>

  ## Test plan

  - [x] cd <lib> && vendor/bin/phpunit  (N tests, all passing)
  - [x] vendor/bin/phpstan analyse src --level=max — N errors (was M)

## Coding conventions

- `declare(strict_types=1);` on every PHP file
- PSR-12 + PSR-4
- Public classes `final` + immutable + fluent (`with*()` returns new)
- Bare-named accessors
- Factory methods mirror upstream

## Guard rails

- Never push to master directly. PRs only.
- Never break the public API without explicit user approval — ALWAYS
  flag breaking changes for user decision, never just ship them.
- Never bypass pre-commit hooks (`--no-verify` forbidden).
- Never modify SVN credentials in `.github/workflows/tests.yml`.
- Never refactor architecture; this audit is bug-fix-and-cleanup, NOT
  redesign.
- Don't add features. Audit findings only.
- Don't bundle fixes from multiple categories in one PR — reviewers
  need to evaluate each category independently.
- Caliber sync per CLAUDE.md "Before Committing".

## Deliverable per PR

1. Lib + category
2. Findings count: total / fixed / flagged
3. Test results: phpunit + phpstan/psalm before/after
4. PR URL + merge confirmation
5. List of flagged-for-user items, each with: location, finding, why
   it can't be fixed unambiguously
6. Next lib + category in the queue

Then stop.
```

---

## Tips for running this audit

- Run categories E (security) and H (codebase gotchas) FIRST across
  every lib — these are the highest-impact and lowest-judgment fixes.
  Then A, B, C, D, F, G in any order.
- PHPStan at level=max is aggressive; if a lib has 200+ errors, drop to
  level 8 first, fix those, then ratchet up. Document the level reached.
- For Category B (immutability), a quick grep finds most issues:
  `grep -RnE 'public function (with[A-Z][a-zA-Z]+)' <lib>/src/ | xargs -I{} grep -l 'return \$this;' {}`
- Don't use this audit to ship architectural rewrites. If you discover
  a lib needs structural redesign, surface that as a separate planning
  doc under `plans/`, not as part of the audit.
- For agents that can run static analysis themselves (Cursor, Aider with
  shell tools), let them. For agents without shell access (Claude.ai,
  ChatGPT without Code Interpreter), they'll do grep-based audits — less
  thorough but workable.
