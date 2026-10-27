# C-CTX-6.a — REVIEW-1

VERDICT: CLEAN (0 critical / 0 major / 1 minor out-of-scope / 1 nit)

Lane: /home/sites/cc-cmp-C-CTX-6.a @ 527f958cf (single commit, tree clean, base 86f43fae2).

## Gates (re-run, cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'SessionHost'`: **57T / 283A** — matches claim exactly (exit 0; "PHPUnit Deprecations: 2" is tree-wide pre-existing, identical count in every filter incl. five-guard, none lane-attributed).
- `--filter 'Idle|AutoCompact|Compaction'`: **461T / 22159A** — matches claim exactly.
- five-guard (`ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift`): **staleness pair ONLY** (pinned 25,537 vs live 26,181) — era-cut drift carried by the whole fleet; lane is +3T modify-only, closeout re-pin owns the pair (§1.5/§1.3).

## Scope / traceability
- Diff = 2 files, +183/−13, exactly the brief's declared set: `src/Host/SessionHost.php` (armIdleAutoCompaction / onIdleAutoCompaction / settleWith + one new private `lastSettledAt` stamp and its adjacent property-docblock truth-flip) and `tests/Host/SessionHostIdleAutoTest.php` (modify-only). Hot-file [hot:candy-code/src/Host/SessionHost.php] claim honored — no hunks outside declared regions, no unrelated reformat (git show verified).
- No new `*Test.php` → no durations.tsv row owed; no suite-figure/protocol/generated touches (none in diff).

## Semantics (traced)
- settleWith stamps `lastSettledAt = now` and re-arms AFTER `releaseQueue()` — a queued prompt's submit re-arm replaces it via cancel-first; final live alarm always carries the current prompt stamp. Cancelled-generation settles early-return BEFORE the stamp (:2104 `if ($generation !== $this->generation) return;`), so a cancel is not treated as a settle — correct.
- Idleness = max(lastPromptAt, lastSettledAt) with null-safe arms (:818-822); DateTimeImmutable `>` is valid. Anchor falls back to prompt when no settle happened → legacy behavior preserved.
- Busy-fire re-arm (:793-797): the timer closure nulls `idleAutoTimer` before the verdict, so armIdleAutoCompaction's cancel-first is a no-op there and the re-arm lands — single-slot invariant holds on ALL three arm sites (submit :658, busy-fire :795, settle :2130), every one routed through the same method. Stamp-mismatch fire returns WITHOUT re-arm (user returned ⇒ their submit already re-armed) — no orphan.
- Threshold/cadence unchanged: `idleOfferSeconds + 2` delay byte-identical vs base (86f43fae2:711-713); CompactorConfig untouched (zero hunks); E646 clean (no LLM-request timeout; fold still waits for the verdict, never aborts work).

## Red-before / pin non-vacuity (mutation-reasoned; on-disk base repro shell-denied in this read-only lane, mechanism code-verified)
- testAnAlarmThatFiresIntoARunningTurnReArmsItself: base merged `stamp || isBusy` early-return drops the alarm ⇒ `assertNotNull(self::timer(...))` RED. Removing the lane's re-arm ⇒ same red.
- testTheAlarmMeasuresIdlenessFromTheSettle: base anchors on prompt (-2h), and the identical fixture (88_000, 60k z's) is the file's proven PAYING positive at :137-145 ⇒ base verdict folds ⇒ `assertNull(held)` + `summaryCalls 0` RED. Reverting the lane's anchor block ⇒ same reds.
- testEverySettleReArmsTheWatch: base busy-fire already drops the alarm ⇒ first assertNotNull($refired) RED; on tip, deleting the settle stamp+arm reddens assertNotSame + the lastSettledAt reflection assert. Claims 3F-before consistent.

## TUI parity
- Brief's cite `App.php:4691-4697` is stale on this cut (those lines are settled-row cursor code); the live TUI tick is `Chat.php` `shouldWatchIdleCompaction()`/`handleIdleCompactTick()` (~:14245-14300): per-minute re-derive from the activity clock, "a false is free to re-check in a minute." The served busy-fire re-arm + later-of-two-stamps anchor genuinely mirrors that verdict semantics; the committed docblocks name "(App.php)" with no false line numbers. No new timer kinds — one slot, plan §6.1/§6.2 idle-fold default untouched.

## Author law
- `%ae`=detain (1), `%ce`=detain (1) separate greps; 0 Co-Authored-By; plain -m; single commit.

## Findings
- [MINOR · out-of-scope] SessionHost.php:1055 `release()` never cancels `idleAutoTimer` (the only cancel site is inside armIdleAutoCompaction — same at base). The lane does not add a timer, but its re-arms extend that one alarm's expected life past release+settle, so a fold verdict can still run against a released host. Pre-existing shape; record for the wave ledger, not this lane's debt.
- [NIT] BRIEF.md:5 stale App.php line cite (see parity section); fix at closeout doc sweep, nothing lane-committed to correct.
