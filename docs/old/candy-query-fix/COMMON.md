# COMMON — shared subagent protocol (read this FIRST, every time)

You are a subagent working on `candy-query` (`/home/sites/sugarcraft/candy-query`,
namespace `SugarCraft\Query\`). You were spawned by the supervisor for ONE task.
Read this file, then read your assigned instruction file and execute it.

The full findings behind this work are in `/home/sites/sugarcraft/candy_query_audit.md`
(PART 1 = findings, PART 2 = plan). Read the sections your step cites if you need context.

---

## 1. The shared scratchpad — `plans/candy-query-fix/updates.md`

This is the ONLY channel to other agents and the supervisor.
- **Append** an item when you have something to pass on: deferred work, a gotcha the
  next agent must know, a decision (e.g. "wired X rather than deleted it, because…"),
  or a flag.
- **Remove** an item once it is resolved/consumed (don't let it grow stale).
- Item prefixes the supervisor watches for:
  - `BLOCKER: <what + why it blocks the next step>` — you could not proceed; STOP and report.
  - `RESEARCH NEEDED: <topic + why>` — you lack information; STOP if it blocks you, else continue and note it.
  - `DEFERRED: <what + where>` — non-blocking unfinished work to pick up later.
  - `NOTE: <decision/gotcha>` — informational for later agents.
- **Never `git add` updates.md or anything under `plans/`** — it is orchestration scratch, not part of any PR.

## 2. If you lack information

You CANNOT spawn agents. If you hit a topic you don't know enough about
(an upstream API, exact SQL semantics, a library's surface), write
`RESEARCH NEEDED: <topic>` to `updates.md`. If it blocks you, STOP and let the
supervisor get a researcher; if you can keep making progress elsewhere, do so and
leave the note for the supervisor to resolve before the dependent step.

## 3. Conventions (non-negotiable — see CLAUDE.md / AGENTS.md / .claude/rules/model-pattern.md)

- `declare(strict_types=1);` first line. PSR-12 + PSR-4. Public classes `final` unless extension is a contract.
- **Immutable + fluent**: every `with*()` returns a NEW instance via `mutate()`; never write `$this->x =`. `readonly` state; paired `bool $XSet` sentinels for nullable fields.
- TEA `Model`: `update(Msg): [Model, ?Cmd]` returns a NEW model; side effects are `Cmd`s, NEVER in `view()`.
- Bare accessors (no `get`). `::new()` is the default factory — never `::create()`/`::make()`/`::default()`.
- Doc-comments cite upstream (`Mirrors mysql-workbench <module>` / `Mirrors jorgerojas26/lazysql.<X>`). Comment WHY, not WHAT.
- **SQL safety:** prepared statements for ALL value interpolation; identifiers come from static catalogs and are backtick/double-quote escaped, never user input. Int-cast where placeholders are unsupported (e.g. `KILL`). Never `eval()` server data (calc expressions are precompiled closures). Never log/echo passwords.
- **TUI render invariants:** no line wider than the terminal (diff renderer is 1 line/row); constant frame line count; no mid-frame `\x1b[2J`; sanitize binary/DB data; no synchronous DB query on the per-keystroke render path (admin data flows through `AdminQueryCache`/`Cmd::promise`). Keep raw `\x1b[…m` out of `src/` (use `Sprinkles\Style`/`Color`); the only sanctioned exception is `CellValue.php`.

## 4. Verify before you claim done

- `cd /home/sites/sugarcraft/candy-query && composer update --quiet && vendor/bin/phpunit` — local `vendor/`/`composer.lock` go stale; **`composer update` before trusting any local red.** Baseline at plan start: **1112 tests green, 1 skipped.** Your step must leave the full suite green (test authoring happens in the TESTS_CI between-step, but do not leave the suite broken).
- `php -l` any file you change.
- If you touched another lib (e.g. added a class in sugar-dash/candy-async), run that lib's suite too. New transitive `@dev` deps need a path-repo in every consuming `repositories[]` — `php /home/sites/sugarcraft/tools/check-path-repos.php --fix` from repo root, then eyeball the diff (`composer validate --strict` flags `@dev` — expected, drop `--strict`).

## 5. Ship cadence (every subagent that changes files)

```sh
cd /home/sites/sugarcraft/candy-query
git checkout -b ai/candy-query-<step-slug>
git add <ONLY this step's source/test/doc files>      # NOT plans/, NOT updates.md, NOT Caliber files
git commit -m "candy-query: <concise step title>"      # author Joe Huss <detain@interserver.net>
git push -u origin ai/candy-query-<step-slug>
unset GITHUB_TOKEN && gh pr create --fill --title "candy-query: <step title>" --body "<summary>

## Test plan
<what you ran / N tests>"
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
git checkout master && git pull --ff-only
```

- **`unset GITHUB_TOKEN` immediately before EVERY `gh` command.** No exceptions.
- **End on `master`, clean working tree**, so the next subagent starts fresh.
- **Caliber:** do NOT run `caliber refresh`. If a pre-commit hook auto-stages Caliber files (`CLAUDE.md .claude/ AGENTS.md CALIBER_LEARNINGS.md .agents/ …`), unstage them before committing.
- Review steps are **read-only**: no branch, no PR — findings go to `updates.md`.
- PR author + commit author: `Joe Huss <detain@interserver.net>`.

## 6. Scope discipline

Do only your step. If you spot adjacent problems, write `NOTE:`/`DEFERRED:` to `updates.md` rather than expanding scope — a later step or between-step will catch it. Keep diffs minimal and reviewable.
