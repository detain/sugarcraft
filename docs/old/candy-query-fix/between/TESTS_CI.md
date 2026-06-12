# BETWEEN-STEP — TESTS & CI

> First read `plans/candy-query-fix/COMMON.md`. **Agent:** `oac:test-engineer`.
> The supervisor named the real step just completed; ensure its behaviour is properly tested and CI is current.

## Do

1. **Tests.** For every public method / behaviour the real step added or changed, ensure a
   PHPUnit test exists and is meaningful (not just smoke). candy-query test styles:
   behavioural (`update()` → `[Model, ?Cmd]`), snapshot (renderer SGR substrings — assertions
   are substring-based, not byte-golden), coercion/clamp edges, immutability (`with*()` returns
   new). Non-SQLite drivers + admin queries use `tests/Admin/FakeDatabase.php` /
   `FakePostgresDatabase.php` / `DatabaseInterface` doubles — **no live MySQL/PG**. Cover the
   new wiring/call paths the step created (the recurring gap was untested wiring).
2. **Run green.** `cd /home/sites/sugarcraft/candy-query && composer update --quiet &&
   vendor/bin/phpunit` — all green (baseline 1112 + your additions; 1 pre-existing skip is OK).
   If you touched another lib, run its suite too.
3. **CI / workflows.** If the step added a new lib, a new VHS tape, or OS-specific code, update
   the hand-maintained bits: `.github/workflows/vhs.yml` `all=(...)` array (visual libs only;
   candy-query admin is non-visual), and `WINDOWS_LIBS`/`MACOS_LIBS` in `ci.yml` only if
   OS-specific. `ci.yml` otherwise auto-discovers via `scripts/affected-libs.php` — don't
   hand-edit the matrix. Keep SVN creds in `tests.yml` HARDCODED (repo secrets don't exist).
4. If you cannot reach a behaviour without a live server, write a focused fake-backed test for
   the SQL/string it generates and record the live-smoke gap as `DEFERRED:` (for Step 8.1).

## Ship

Branch `ai/candy-query-tests-<stepid>`, commit (author Joe Huss <detain@interserver.net>),
push, PR, merge, pull. End on master. Update `updates.md` (add `DEFERRED:` smoke gaps; remove
resolved test-related notes).

> Reminder: `unset GITHUB_TOKEN` immediately before EVERY `gh` command.
