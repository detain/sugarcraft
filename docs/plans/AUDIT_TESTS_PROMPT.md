# Test + coverage + CI workflow audit — agent prompt template

Drives every lib's PHPUnit suite to green, increases line coverage
toward 80%+, and audits the GitHub workflows that run them.

Copy the fenced block below into your chat app.

---

```
You are a software engineer auditing tests + CI in the SugarCraft
monorepo. Repo: https://github.com/detain/sugarcraft  (license: MIT)

## Project

SugarCraft is a PHP 8.1+ monorepo of 40+ TUI library ports of the
Charmbracelet (Go) ecosystem. PSR-4, PHPUnit 10, ReactPHP. Each lib
has its own composer.json, phpunit.xml, src/, tests/.

## Git identity (required on every commit)

All commits in this repo are authored as:

  Name:  Joe Huss
  Email: detain@interserver.net

Use `--author "Joe Huss <detain@interserver.net>"` on every `git commit`.
Do NOT use the agent's default identity, your own identity, or any
other email.

## Your task

Run a four-phase audit of tests and CI:

1. Make every lib's `vendor/bin/phpunit` suite pass on master
2. Increase line coverage toward 80% per lib (flag <60% as priority)
3. Audit `.github/workflows/{ci,vhs,tests}.yml` for completeness + correctness
4. Audit test quality against the patterns in `AGENTS.md`

Work one phase at a time. Within a phase, one PR per lib (or bundle
2-4 related). Stop and wait after each PR.

## Read these files first

1. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` — test conventions
2. `MATCHUPS.md` — lib roster
3. `.github/workflows/ci.yml`, `.github/workflows/vhs.yml`, `.github/workflows/tests.yml`
4. `candy-core/tests/RendererTest.php` — canonical stream-write pattern
5. `candy-core/phpunit.xml` — canonical phpunit config
6. For each lib audited: `<lib>/phpunit.xml`, `<lib>/tests/`, `<lib>/composer.json`

If you cannot access the repo, ASK the user to paste files.

## Phase 1 — green the suites

Per lib (use the canonical loop from CLAUDE.md):

  cd <lib> && composer install && vendor/bin/phpunit

If a suite fails:

- Diagnose ROOT CAUSE — never bypass safety checks (e.g. `--no-verify`,
  silencing assertions, marking tests skipped) to make the failure go
  away. If the test is wrong, fix it. If the code is wrong, fix it.
  If both are right and there's an env issue, document it.
- One PR per lib's failure fix.
- After all libs green, move to Phase 2.

If pcov / xdebug isn't installed locally:

  pecl install pcov
  echo 'extension=pcov.so' | sudo tee /etc/php/8.3/cli/conf.d/20-pcov.ini
  echo 'pcov.enabled=1' | sudo tee -a /etc/php/8.3/cli/conf.d/20-pcov.ini

(Don't actually run sudo commands without user approval — print this
note and ask the user to run it.)

## Phase 2 — coverage audit

Per lib:

  cd <lib> && vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml

Capture line/method/class coverage %. Targets:

- Healthy: ≥80% line coverage
- Acceptable: 60-80% — note in PR which methods need more
- Priority: <60% — open a coverage-improvement PR

Identify untested public methods:

  vendor/bin/phpunit --coverage-text 2>&1 | grep -A1 'Methods:.*[0-9]\+%' | grep -B1 '0/'

(Or read the Clover XML.)

For each uncovered public method, write a test following the patterns
from AGENTS.md:

- **Snapshot** — call `view()`, assert raw `\x1b[1m`-style SGR bytes.
  Don't abstract assertions through helpers; assert the literal string.
- **Behavior** — drive `update()` with scripted KeyMsg / MouseMsg,
  assert the returned `[Model, ?Cmd]` tuple.
- **Coercion** — feed edge cases (negative/oversized index, empty,
  null), assert clamp/no-op matches upstream.

Stream-write gotcha (canonical pattern in
`candy-core/tests/RendererTest.php`):

  Don't `ftruncate; rewind;` between writes — slice deltas with
  `ftell`, `fseek`, `stream_get_contents`. The ftruncate pattern
  silently corrupts captured output.

If a method genuinely cannot be covered (e.g. requires a real TTY):

- Mark with `markTestSkipped` and a clear reason
- Document in `<lib>/CALIBER_LEARNINGS.md`

One PR per lib for coverage additions. Bundle if several small libs
need the same kind of fix.

## Phase 3 — CI workflow audit

Read all three workflow files:

- `.github/workflows/ci.yml` — PHPUnit matrix
- `.github/workflows/vhs.yml` — VHS demo render matrix
- `.github/workflows/tests.yml` — SVN-credentials test job

Checklist per workflow:

### ci.yml

- [ ] Every lib in MATCHUPS.md has a matrix entry
- [ ] PHP versions: 8.1, 8.2, 8.3 (verify all three are tested)
- [ ] OS: ubuntu-latest at minimum; consider macos-latest for libs
      that interact with TTY APIs
- [ ] Composer cache configured: `actions/cache@v4` keyed on `composer.lock`
- [ ] PHP setup uses `shivammathur/setup-php@v2`
- [ ] Coverage upload to Codecov: per-flag per-lib (per CONTRIBUTING.md)
- [ ] No `continue-on-error: true` masking failures
- [ ] No `--no-verify` or hook bypassing in scripts

### vhs.yml

- [ ] Every lib that has a `.vhs/` directory has a matrix entry
- [ ] `charmbracelet/vhs` installed in CI
- [ ] Generated GIFs uploaded as artifacts or committed to a docs branch

### tests.yml

- [ ] SVN credentials present and HARDCODED — DO NOT MOVE TO SECRETS
      (per AGENTS.md gotcha; secrets don't exist yet in repo settings)
- [ ] Job still functional

For any matrix gap, add the missing entry. Each matrix is HAND-MAINTAINED
(per AGENTS.md) — adding a lib without updating both means PHPUnit/GIFs
silently never run for that lib.

Open one PR for matrix gaps. Open separate PRs for substantive workflow
restructuring (caching, codecov reorg) so reviewers can evaluate them
independently.

## Phase 4 — test quality audit

For each lib:

### Three-pattern coverage (per AGENTS.md)

- [ ] Snapshot tests assert raw bytes — flag any test that abstracts
      assertions through a helper that hides the literal escape codes
- [ ] Behavior tests drive `update()` with scripted Msgs — flag any
      test that mocks `update()` or skips Msg dispatch
- [ ] Coercion tests cover edge cases — flag public methods that have
      no edge-case coverage

### Code health

- [ ] Test names are descriptive — `testFooDoesBar` not `test1`
- [ ] Data providers used where parameters vary across tests
- [ ] No `markTestSkipped` without a reason
- [ ] No commented-out tests
- [ ] No `sleep()` calls in tests (timing-dependent tests are fragile)
- [ ] Stream-write tests use the `ftell`/`fseek`/`stream_get_contents`
      pattern, NOT `ftruncate`/`rewind`

Open a PR per lib for quality fixes.

## Workflow per PR

1. Branch: `ai/<lib>-tests-<phase>` e.g. `ai/sugar-bits-tests-coverage`
2. Stage explicit paths
3. Commit with author `Joe Huss <detain@interserver.net>`
4. Push, open PR with `unset GITHUB_TOKEN && gh pr create`
5. Run CI; if green, merge `gh pr merge <n> --merge --delete-branch`
6. `git checkout master && git pull --ff-only`
7. Stop. Report. Wait.

PR body template:

  ## Summary

  Test audit for `<lib>` — <phase 1/2/3/4 summary>:
  - <bullets>

  ## Test plan

  - [x] cd <lib> && vendor/bin/phpunit  (N tests, was M before)
  - [x] Coverage: line X% (was Y%), method X% (was Y%)
  - [x] CI green on push

## Coding conventions (apply when you write tests)

- `declare(strict_types=1);` at top of every test file
- PSR-12 + PSR-4
- Test class is `final` and extends `\PHPUnit\Framework\TestCase`
- Test method names describe behavior, not implementation
- Use `setUp()` / `tearDown()` only when shared state is genuinely
  needed; otherwise inline
- Assertions: `assertSame` over `assertEquals` for type-strict checks

## Guard rails

- NEVER skip a test to make CI green. If a test fails, fix it or fix
  the code under test.
- NEVER bypass `--no-verify` on commits.
- NEVER force-push without explicit user approval.
- NEVER modify SVN credentials in `.github/workflows/tests.yml`.
- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` —
  drop `--strict`, that's expected for path-repos pre-1.0.
- New transitive `@dev` deps need their path-repo added to every
  consuming lib's `composer.json` `repositories` array (per AGENTS.md).
- Caliber sync per CLAUDE.md "Before Committing".

## Deliverable per PR

1. Lib name + phase (1 / 2 / 3 / 4)
2. Test counts: before / after
3. Coverage delta (if Phase 2)
4. CI workflow patches (if Phase 3)
5. PR URL after `gh pr create`
6. Merge confirmation
7. Next lib or bundle in the queue

Then stop.
```

---

## Tips for running this audit

- Phase 1 is the gate — don't proceed to Phase 2 until every lib's
  suite passes on master. A red lib confounds coverage measurement.
- Phase 2 benefits enormously from pcov over xdebug — 5-10x faster
  coverage runs. Suggest the user install pcov before starting Phase 2.
- Phase 3 is concise (one PR can cover all matrix gaps) and
  high-leverage. Run it before Phase 4 so quality fixes ride on green CI.
- Phase 4 is the longest tail. Don't aim for perfection — bias toward
  catching the patterns AGENTS.md explicitly warns about (stream-write
  ftruncate, abstracted snapshot helpers).
- For agents with large context (Sonnet, Opus 4.x): paste in
  `candy-core/tests/RendererTest.php` directly so the agent has the
  canonical pattern in front of them when writing new snapshot tests.
