# C-CTX-6.c — Context nit bundle — CLOSED
Branch cc/C-CTX-6.c, base c36cc1982. One commit `C-CTX-6.c: context nit bundle`.

## Shipped (4 bullets)
1. **VIS-6-P1** `Runtime::announceMemoryRecall()` (src/Runtime.php, now :6054-6060): the
   `'notes' => $count === 1 ? 'note' : 'notes'` English-plural-as-parameter is gone.
   `lang/en.php`: `chat.memory.recalled_notice` split into `.one` ('Recalled {count}
   note for this turn') / `.many` ('Recalled {count} notes for this turn'); call site
   picks by `$count === 1`, passing only `count`. English row byte-identical (existing
   'Recalled 1 note'/'Recalled 2 notes' pins survive green). `.one`/`.many` is the
   design-block spelling (house has both `.one/.many` and `.one/.other`; StartupSummary
   uses the former).
2. **k1-dorm#7** builtin-commands/3400-goal.php header comment truth-flipped: the served
   judge loop IS wired (W7a-3: SessionHost::scheduleGoalJudge()/landGoalVerdict()/endGoal(),
   SessionHost.php:2084-2215 live, shared GoalLoop table; GoalHostCommand docblock :27-32
   agrees). The 'follow-up' sentence is gone. Comment-only edit.
3. **INT-1-P1** `settings.instructionsExclude.help` (en.php) discloses the matcher truth
   from InstructionFileLoader::excluded() (:590-629): checkout files match relative,
   ancestor/forced files outside the checkout match by ABSOLUTE path, so
   `**/CLAUDE.local.md` reaches ancestors. Regenerated via `php tools/gen-settings-doc.php
   --write` → "settings docs: up to date", ZERO diff: the generator does not emit help
   text into SETTINGS.md, so there is no regenerated region to commit (guard
   SettingsSchemaDocDriftTest green).
4. **CTX-5-P2** docs/CONTEXT.md "Background summaries" paragraph now names the real reuse
   points: TUI `Chat::buildSummarizationRequest()` + served
   `SessionHost::requestAheadOfNeed()` (verified live: SessionHost.php:667, Chat.php:11087);
   `settings.compaction.idleAuto.help` (en.php) discloses that no short cache-warm idle
   tier ships yet (the only idle fold waits the full window; nothing summarizes inside
   the provider cache-warm window). C-CTX-5.c later replaces that sentence per design.

## Tests
- MemoryRecallBlockTest: +1 method `testTheRecallRowChoosesOneOrManyKeysAndInjectsNoEnglishPlural`
  (catalog shape: bare key absent, .one/.many present with {count}, no {notes}; plus both
  English render legs). RED-before-proof run at base (files swapped from `git show HEAD:`):
  fails exactly on 'the plural-injected spelling must not come back'; cp-restored md5-verified.
- Gates (plain pipe, cwd worktree candy-code, env -u LINES -u COLUMNS):
  - `--filter 'Lang|Runtime|SettingsSchemaDocDrift|Memory'` → Tests: 1616, Assertions: 18397,
    Failures: 2, Skipped: 1 — BOTH BASE REDS, proven on pristine stashed tree at base
    (`ChatHostLangCatalogueTest` roster gap on `chat.effort.launch_unshaped`;
    `DreamPassTest::testChatSchedulesThePassAtTurnClose…` :288). Foreign to this lane;
    not touched. Gate/weld owes triage.
  - five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`
    → 133T/15713A, Failures: 2 = EXACTLY the allowed staleness pair (pinned 25,537 vs live
    enum; closeout re-pin duty; this lane adds +1 method).
  - `tests/Host/ServedRuntimeNoticeDrainTest.php` → OK 6T/29A (mentions the Recalled row in prose).
  - "PHPUnit Deprecations: 2" rides the MemoryRecallBlockTest file at base too (pre-existing).
- LangParityTest untouched/green (en.php is the only locale; key-set parity holds).
- No new *Test.php files → no durations.tsv row owed. suite-figure.json/durations untouched.

## Files changed
candy-code/src/Runtime.php · candy-code/lang/en.php · candy-code/builtin-commands/3400-goal.php
(comment) · candy-code/docs/CONTEXT.md · candy-code/tests/Context/MemoryRecallBlockTest.php.
docs/SETTINGS.md: regenerated, zero diff (see 3).

## Notes for weld/reviewer
- `chat.memory.flushed_notice` (en.php:1946) carries the same `{notes}` plural-injection
  defect; it belongs to the flush row (different method, outside this lane's design block).
  Worth minting as a future micro — not touched here.
- 3400-goal.php comment: `[commands]` surface, comment-only; no registry/roster change.
