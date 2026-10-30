# C-CTX-4.e — FIX-2 (post-weld follow-up) — VERDICT: FINDING WRONG (PHANTOM), zero code landed

Date: 2026-10-29. Base at start: master `07e49e872` (loose-ref, re-read individually; brief expected 574e69f1d but weld-18s landed batch-18s mid-handoff — 574e69f1d is its parent). Worktree /home/sites/cc-cmp-C-CTX-4.e-fix, branch cc/C-CTX-4.e-fix @ 07e49e872 — tree-identical to master, ZERO commits; worktree + branch pruned after this report (pristine parked-clean = scheduler double-offer hazard).

## The dispatched finding

run-state.json:206: "label-bug fixer (SessionHost.php:1566-1568 $msg->value vs ->text) — NO wake ever; branch still @98470d6de (welded); dispatch FRESH follow-up fixer off 574e69f1d". Claimed bug: a serve-path `outputStyleLabel()` reading `$msg->value` where SessionUserMessage carries `->text` => dead style label.

## Evidence the defect does not exist (each reproducible)

1. `git grep -n "outputStyleLabel" master` => empty. Also empty on lane tip 98470d6de, weld-18s tree, and all three weld picks (f7d312948/b515d2e2c/b2943d1cb full diffs grepped).
2. `git log --oneline --all -S 'SessionUserMessage'` => empty; `--all -S 'outputStyleLabel'` => empty. Neither symbol ever existed in any commit of this repo.
3. `git grep -n '\$msg->value' master -- candy-code/src` => only `SettingsEditor.php` `$msg->values` (SettingsMsg plural property, unrelated). No user-message property read for a style tag exists anywhere; all other `->value` sites are enum cases.
4. master SessionHost.php:1566-1568 = `admitPrompt()` docblock prose (`@param ?string $commandModelNotice C-EXT-2.e ...`). Lane-tip SessionHost:1550-1590 = compaction code. No label code at the cited lines on any revision.
5. The real serve-path style label lives in `src/Host/Commands/OutputStyleHostCommand.php:121` — `$active = $context->rulesState->activeStyle();` (+ :169 for the off path) — and it is LIVE, not dead code:
   - Pin: `tests/Context/OutputStyleTest.php::testTheListingShowsEveryStyleWithItsStateAndSource` (:326) + `testTheCommandIsRegisteredWithBothSurfaces` (:297).
   - Mutation-reason proof: neuter the read to `$active = null; // MUTATION PROBE` => exactly 1 red at OutputStyleTest.php:340 (78A, Tests:16, Failures:1). Restore md5 `53275e66f0f15cce2d28aa63dc3c63c2` (cp-backup, matches; `git status` clean) => `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter 'OutputStyle'` => **OK 16T/83A** (2 PHPUnit deprecations = pre-existing infra noise). A genuinely dead label would have stayed green under the neuter.
6. Provenance check of the "r3 disclosed" claim: the C-CTX-4.e loop was r1 FIXES (b2943d1cb) + r2 CLEAN (REVIEW-2.md) per weld-18q's commit log — no r3 exists for this step; W21/WAVE-REVIEW-1.md and WAVE-REVIEW-2.md never mention CTX-4.e. The note is unsourced — consistent with the run-state itself admitting the originating fixer had "NO wake ever" (phantom-session class per the w15-GEN10 precedent).

## What the note most plausibly garbled (NOT fixed here — ruling-gated)

The only C-CTX-4.e-defect-on-master actually disclosed is in the lane's own REPORT.md RISK + REVIEW-2 "WELD CARRIES": `Runtime::standingSlot` (src/Runtime.php, memo key) does not hash `activeStyle()`, so a mid-session `/output-style X` can serve the pre-switch memo slab until /clear. That is a memo-keying defect (one-line cure: include `$rulesState?->activeStyle()` in the slot key), not a property-name defect; the lane report and reviewer routed it to a [runtime] owner deliberately. It needs its own dispatched finding, brief, and owner ruling — I did not silently fix a different bug than the one dispatched.

## Gates run

- Targeted: `vendor/bin/phpunit -c phpunit.xml --filter 'OutputStyle'` (cwd=candy-code, linked vendor cp -a from /home/sites/cc-cmp-C-SES-8.d, plain pipe): 16T/83A OK before and after mutation round.
- Full suites/staleness pair: not run (zero code changed — nothing to re-pin). Nothing staged; no commits; no push; suite-figure.json/durations.tsv/dist untouched.
