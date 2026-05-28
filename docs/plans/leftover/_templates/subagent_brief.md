# Subagent brief — every subagent reads this first

You are a focused contributor to the SugarCraft monorepo at
`/home/sites/sugarcraft`. The supervisor handed you one step file. Do
exactly what the step file asks. Do not expand scope.

## Read order each time you start work

1. **This brief** — conventions and process rules.
2. `plans/leftover/updates.md` — current blockers, carry-forward
   items, prior review findings relevant to your step.
3. **Your step file** — the actual deliverable.
4. The relevant lib's `CLAUDE.md` / `AGENTS.md` / `CONTRIBUTING.md`
   if you have not seen them recently. Root `CLAUDE.md` is canonical
   for the monorepo as a whole.
5. The touched lib's `CALIBER_LEARNINGS.md` — accumulated patterns
   and gotchas from prior sessions.

## Process rules (non-negotiable)

- **Commit author:** `Joe Huss <detain@interserver.net>`. Use
  `--author=` if your environment defaults to something else.
- **`unset GITHUB_TOKEN`** before every single `gh` invocation. Yes,
  every single one. The ambient `GITHUB_TOKEN` in this environment is
  invalid for the SugarCraft remotes; the local keychain auth works
  but only when the ambient token is gone from the env. There is no
  "I just ran gh a second ago" exception — always `unset` first.
- **End every task on `master` with a clean working tree.** The full
  cycle is: branch → commit → push → `unset GITHUB_TOKEN && gh pr create`
  → `unset GITHUB_TOKEN && gh pr merge <N> --merge --delete-branch`
  → `git checkout master && git pull --ff-only`. The next subagent
  starts in exactly this state — never leave a feature branch checked
  out, never leave uncommitted edits, never skip the pull. If you
  cannot merge (CI red, blocker, etc.), stop and surface the blocker
  via `updates.md` — but still `git checkout master` first so the tree
  is in a known state for whoever picks up next.
- **Branch naming:** `ai/<slug>` where slug is in the step file's
  "Branch" field.
- **Bundle 2–4 related items per PR** (CLAUDE.md rule). If the step
  contains multiple bullets that share files, ship as one PR. If
  the step file says `[multi-PR]`, split it logically and stop after
  shipping one cohesive PR per call — the supervisor will spawn
  successive subagents for the remaining sub-parts.
- **Never** `--no-verify`, `--no-gpg-sign`, `git add -A`, `git add .`,
  or force-push to master. Stage explicit file paths only.
- **Caliber pre-commit hook** is active in this repo. Commit normally
  and the hook will sync. If you ever see
  `caliber: command not found`, stop and tell the supervisor — do
  **not** bypass the hook.

## Ship-as-you-go cadence (per step)

```bash
git checkout master && git pull --ff-only
git checkout -b ai/<slug-from-step-file>
# ... do the work ...
cd <touched-lib> && composer install --quiet && vendor/bin/phpunit
cd /home/sites/sugarcraft
git add <specific files>
git commit -m "$(cat <<'EOF'
<lib>: <short summary> (leftover-rollout <step ID>)

<2-4 sentence body — what changed and why.>

EOF
)"
git push -u origin HEAD
unset GITHUB_TOKEN && gh pr create --title "<lib>: <short summary> (leftover-rollout <step ID>)" --body "$(cat <<'EOF'
## Summary
- <bullet 1>
- <bullet 2>

## Test plan
- [x] `cd <lib> && vendor/bin/phpunit` — N tests passing
- [x] Consumer regression suites green
- [x] Touched files listed below

Per the leftover-updates rollout: see `plans/leftover/<phase>/<step file>`.

EOF
)"
PR=$(gh pr view --json number -q .number)
unset GITHUB_TOKEN && gh pr merge "$PR" --merge --delete-branch
git checkout master && git pull --ff-only
```

## Coding conventions (from root CLAUDE.md)

- `declare(strict_types=1);` first line of every PHP file.
- PSR-12 + PSR-4. `final` classes unless extension is part of the
  contract. Public `readonly` properties for state.
- **Immutable + fluent**: every `with*()` returns a new instance via
  a private `mutate()` helper. Never mutate `$this`.
- Bare-named accessors (no `get` prefix). Factory methods mirror
  upstream: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`.
- Doc-comment cites upstream when porting: `Mirrors charmbracelet/<repo>.<Method>`.
- Don't comment WHAT — only WHY (constraints, invariants, links to
  upstream issues).
- i18n: user-facing strings go through `Lang::t($key, $params)` per
  `sugar-wishlist/src/Lang.php` canonical pattern. Never throw raw
  English strings.

## Path-repo closure

If you add a new `sugarcraft/*` dependency to any lib, **also** add
its path-repo entry under `repositories`. Run
`php tools/check-path-repos.php` before committing. The script is
green on master; keep it green.

## Stream-write tests gotcha (AGENTS.md)

Do not `ftruncate; rewind;` between writes to a `php://memory` stream.
Slice deltas with `ftell` / `fseek` / `stream_get_contents`. Canonical
example: `candy-core/tests/RendererTest.php`.

## PHPUnit kill pattern (saved memory)

`timeout` does **not** kill PHP processes that hang inside FFI or PTY
syscalls. When running PHPUnit on candy-pty / candy-wish / candy-vcr
suites, wrap with a backgrounded pkill watchdog:

```bash
( sleep 90 && pkill -9 -f 'vendor/bin/phpunit'; pkill -9 -f 'pty-shim'; pkill -9 -f 'runchild' ) > /tmp/wd.log 2>&1 &
cd <lib> && vendor/bin/phpunit > /tmp/out.out 2>&1
```

## When you finish

Either:

1. **PR merged cleanly:** append one line to `plans/leftover/updates.md`
   under "Done log" — `step <ID> · <PR#> · <one-line summary>`. Stop
   and return to supervisor.

2. **Discovered carry-forward work:** append under "Carry-forward" with
   step ID + one-line note. Continue with your assigned work; do **not**
   expand scope.

3. **Hit a blocker:** append under "Blockers" with:
   - Step ID
   - One-line description of the blocker
   - What you tried
   - What the supervisor / user needs to decide

   Stop with a brief message to the supervisor naming the blocker.

## Things that are NEVER your job in a single step

- Editing other libs' CALIBER_LEARNINGS.md beyond the touched lib's own file.
- Rebasing or rewriting history on master.
- Triggering external services (Packagist, sister GitHub orgs).
- Editing the supervisor's `README.md` in this directory.
- Editing other step files in this directory.
- Adding `composer.lock` to consumer libs (the "drop consumer locks"
  step explicitly removed them — keep them out).
