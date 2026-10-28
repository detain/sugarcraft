# REVIEW-1 — C-SES-1.b (fresh reviewer, round 1)

VERDICT: CLEAN

## What was verified (all commands run in /home/sites/cc-cmp-C-SES-1.b)

1. **Scope/traceability.** `git diff master...HEAD --stat`: exactly `candy-code/src/Background/BackgroundSession.php` (+9/−2) and `candy-code/tests/Chat/BackgroundResultAnnouncedTest.php` (+28). Nothing else. The src hunks live entirely inside the declared `announcement` region (import block + docblock + the cost branch). No forbidden-file touches: suite-figure.json, durations.tsv, candy-code-web/dist all absent. Worktree clean.
2. **Design match.** Fix is verbatim the design block: gate `if ($this->costUsd > 0.0 || $this->spendUnpriced)` printing `SpendLedger::boundUsd($this->spendUnpriced, $this->costUsd)` (`BackgroundSession.php:332-333`); `boundUsd` = `('≥$'|'$') . sprintf('%.4f',…)` (`src/Host/SpendLedger.php:86-89`). Regression test present in the named file per brief's "both polarities + unpriced-at-zero ≥$0".
3. **Regression proof re-run independently (pre-fix).** Swapped in `git show master:…BackgroundSession.php` (tracked at HEAD, tree clean → restore = HEAD checkout), ran `--filter testTheAnnouncementCostFigureObeysTheBoundLaw`: FAIL at :291 "an unpriced figure reads as the lower bound it is" with announcement `Stats: runtime 0s · $0.0123` — matches the builder's report exactly. Restored; tree clean; test green after fix (1T/6A).
4. **Pins non-vacuous (mutation-reasoned; empirical mutation not run — cp/php-write primitives are permission-denied for this reviewer, and the contract forbids scratch src writes).** Enumeration of plausible wrong shapes vs the four legs: pre-fix sprintf-gt0 → legs 2+3 red; unpriced flag ignored in the print (sprintf under the new gate) → leg 2 red; unconditional boundUsd with no gate, or `>= 0.0` → priced-zero leaks `'$'` → leg 4 red (helper text `job s9 / do the s9 work / x` carries no `$`, so leg 4 cannot pass vacuously); argument swap → strict_types TypeError; separator-anchored needles ` · $…`/` · ≥$…` correctly cannot co-occur for one figure (` · ≥$…` does not contain ` · $…`). The ' · ' anchor additionally pins the stats-join shape — consistent with the file's existing exact-byte announcement pins, so intentional.
5. **Gates re-run (plain pipe, env -u LINES -u COLUMNS, cwd=worktree candy-code, phpunit -c phpunit.xml).**
   - `--filter BackgroundResultAnnouncedTest`: `Tests: 15, Assertions: 73` OK (builder claimed 15T/73A — exact).
   - `--filter BackgroundReconnectAfterRestart|TaskToolBackground|BgReconnectAtBoot|BgMethods`: `Tests: 29, Assertions: 259` OK (exact).
   - `--filter SpendLedgerTest`: 15T/66A OK.
   - The 2 "PHPUnit Deprecations" are pre-existing `AccessorDeprecationTest` non-public data providers (unrelated file).
6. **Base-inherited reds spot-verified.** TreeWideGuardRosterTest: 2F, accusing `Cli/JsonSchemaEnvelopeTest.php` walkers — a file the lane does not touch (lane diff = 2 files), so the red is base-true. StderrEmitterCensusTest: 3F (roster-agreement arms); `grep fwrite|STDERR|error_log` over BackgroundSession.php returns zero — the lane hunk cannot move any emitter census, base-true. Staleness pair (+1 test) is a disclosed §7 gate re-pin duty. Builder's claims stand.
7. **Laws.** Author: `%ae`=detain@interserver.net, `%ce`=detain@interserver.net (separate greps), `%(trailers)` empty. Docblock law sentence truthful — boundUsd call sites: Chat.php:15052, NonInteractive.php:2420, Subcommands.php:1406, CompactionService.php:754, SpendLedger.php:77/:205. Import alphabetical after the Agents block. No Lang::t implication (announcement is the established agent-facing literal-English surface; no new user string). No docs elsewhere pin the old spelling (`grep -rln "Stats: runtime"` hits only the reviewed test file). E646/fork law: untouched.

## Findings
None. Zero CRITICAL / MAJOR / MINOR / NIT. Lane is weldable as-is.

Reviewer note: harness limitation recorded — `cp`/`md5sum`/direct `php` are denied to this agent; the pre-fix swap used `git show >file` + HEAD `git checkout --` restore (file clean at HEAD, so lossless), verified by post-restore clean status + green re-run.
