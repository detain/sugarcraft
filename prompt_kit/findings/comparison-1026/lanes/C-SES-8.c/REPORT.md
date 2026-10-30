# C-SES-8.c — Insights report core (GAP-OBS-06) — LANDED

- **Branch/commit:** `cc/C-SES-8.c` @ `e2759d0817cfd429eb048fd265feec00b138f2ec` (base `cf0cfd0be`, one commit, 5 paths, +1216). Author+committer `Joe Huss <detain@interserver.net>` (split-var mint), `%ae`/`%ce` verified separately, zero Co-Authored trailer. Never pushed (lane law).

## Files
- `candy-code/src/Commands/InsightsCommand.php` (new) — pure text/HTML composer. `compose(facts)`/`composeHtml(facts)`/`lineBudget(facts)` over REQUIRED_FACTS `windowDays/sessions/spendDays/spendModels/spendReason` (missing key → `\LogicException` naming it, per UsageCommand law). Spend by model + by day grids (same `  %-11s %-22s %6s %9s %11s` shape; unpriced folds print `≥$` lower-bound — same law as C-SES-8.b), sessions/turns per day, busiest projects (cwd), fixed four kind rows main/branch/subagent/background (foreign kinds extra), unpriced + imported shares, completeness law: every section prints count/none/unavailable+reason. HTML: self-contained page, inline style, zero external refs, every dynamic string through `htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE)`.
- `candy-code/src/Host/Commands/InsightsHostCommand.php` (new) — gathers facts via existing public reads only: `UsageHostCommand::rollupFacts(store,null,W,true)` (C-SES-9.a) + `listSessionsWithMeta` roster window-folded to whole UTC days; EnhancedSessionStore NOT edited. Window arg = positive whole days, default 30, junk → usage reply. Writes `~/.candy-code/exports/insights-<date>.html` 0600 via `AtomicFileWriter` beside /export files (ctor `$exportsDir` test seam like ExportCommand); replies page + path; write failure → page + `Report not written:` line, never a silent loss; no store → unavailable reply, no file. No model call, no billed side call (risk note honored).
- `candy-code/tests/Commands/InsightsCommandTest.php` (new, 10 tests) — byte-exact full/empty/blind pages, `lineBudget` formula equality on 5 shapes, forgery pin (injected newline can't fake a heading), HTML escaping incl. malformed-UTF-8 substitution, LogicException both faces.
- `candy-code/tests/Host/Commands/InsightsHostCommandTest.php` (new, 4 tests) — real seeded store (forked branch session, `import:` session, backdated out-of-window session, unpriced usage) → file at 0600 with expected page lines; window arg + junk usage replies; no-store refusal writes nothing; unwritable-shelf failure path replies page + refusal.
- `candy-code/lang/en.php` — `cmd.insights.*` alphabetically inserted between `cmd.init.hint` and `cmd.keys.description` (~1314–1350), all literal keys used by src present in-step (LangParity law).

## Guards (in-step)
- `LangParityTest` green (key + placeholder parity, src-asked-keys census).
- `GlobDialectDifferentialTest` green — PathGlob corpus restored to pinned 304,700 (554×550): my initial command-word regex was glob-shaped; re-spelled `$`-anchored form is exempt; `glob('/*')` test literal replaced with scandir walk. No forbidden file touched.
- `DuplicatedTestHelperDriftTest` green (helpers uniquely named `insights*`).
- Adjacency `UsageCommandTest` green (shared rollup read unchanged; grid order inverse is my page's design).
- StderrEmitterCensus/TreeWideGuardRoster/DocFigureProseDrift 204T green; the 2 PHPUnit self-deprecations are pre-existing house noise (persist with my src files hidden — probe-verified).

## Owed downstream (not lane-commitable)
- NEW test files needing durations.tsv rows at gate: `candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php` (14 new tests; suite-figure.json/README re-pin = merge agent; staleness pair red-at-tip is accepted precedent).
- Wiring (registry row, README roster, /insights palette) = C-SES-8.d per brief docs/guards: none.

## FIX ROUND 1 (REVIEW-1) — landed `cc8ea765f29f1b0d96b76ac8bd00d020e1737fda` on cc/C-SES-8.c (follow-up, e2759d081 NOT amended)

1. **MINOR fixed** — `src/Host/Commands/InsightsHostCommand.php`: `$directory`/`$stamp`/`$target` resolution moved INSIDE the try (with a why-comment). The `exportsDirectory()` RuntimeException (no owned home) no longer escapes `run()`; the Throwable arm now answers with the `cmd.insights.write-failed` page-preserving reply, honoring the class docblock and the ExportCommand pattern. PIN: new `testAShelfThatCannotBeLocatedIsAWriteFailureNotAThrownPageLoss` — default-ctor command with `HOME=/` (both spellings moved/restored, OneSided-compliant). Mutation proof: HEAD src swapped under the new pin → 1E (`RuntimeException ... :84`, red pre-fix); fixed src → green; restore md5-verified.
2. **NIT fixed** — `lang/en.php`: `cmd.insights.spend-day` ↔ `spend-heading` swapped; block now fully alphabetical (live key-order probe: projects < scope < spend-day < spend-heading < spend-model < subtitle). §1.8: same commit as the src fix it belongs to.
3. **NIT stamped — original "62T/3736A" provenance retired.** Exact filters re-run post-commit, cwd=`candy-code`, plain pipe, `env -u LINES -u COLUMNS`, `vendor/bin/phpunit -c phpunit.xml`:
   - `--filter 'InsightsCommandTest|InsightsHostCommandTest'` = **15T/84A** (14 lane tests + the 1 new pin);
   - `--filter 'Insights|Usage'` = **405T/2047A** (was 404T at e2759d081, +1 pin);
   - `--filter 'StderrEmitterCensus|TreeWideGuardRoster|DocFigureProseDrift'` = **204T/29126A** (matches REVIEW-1's reproduction exactly);
   - `--filter 'LangParity'` = 7T/3025A; `--filter 'GlobDialectDifferential|DuplicatedTestHelperDrift|SuiteHomeSandboxContract'` = 32T/594A; phpstan L5 on the changed src = OK. (2 PHPUnit deprecations = the pre-existing `AccessorDeprecationTest` private-provider house noise REVIEW-1 provenanced.)
   - DISCLOSURE: `OneSidedHomeSandboxTest::testNoTestFileSandboxesOnlyHalfOfHome` is RED at base and tip — accused file is foreign `Context/InstructionWalkPastRootTest.php` (C-CTX-4.c, d361720d9, ancestor of cf0cfd0be); proven base-identical via HEAD-swap probe; this lane's test moves both HOME spellings and is not accused. NOT fixed here (outside step files) — welder: route to the C-CTX-4.c owner or roster per the guard's own message.
   - **Welder carries (updated for FINAL tip cc8ea765f):** durations.tsv rows for the SAME 2 new files (`candy-code/tests/Commands/InsightsCommandTest.php`, `candy-code/tests/Host/Commands/InsightsHostCommandTest.php` — no new files in round 1); suite-figure.json/README carry now **+15T** (14 + the round-1 pin); plan §4 mark: `✅ cc8ea765f C-SES-8.c` (cite this hash, not e2759d081).

Author+committer `Joe Huss <detain@interserver.net>` (`%ae`/`%ce` separate greps == 1, zero Co-Authored trailer). Never pushed.
