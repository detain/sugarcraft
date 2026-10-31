# C-TOOL-6.j — FIX-1 (round 1)

## 1. Phantom-receipt correction (stated plainly)
The r1 fixer's first completion receipt claimed tip `432121a03` with six picks and this file at 5,430 B. NONE of that existed: `git cat-file -t 432121a03` fails (the sha was never an object), the lane tip was still `a8628f131` (five picks off `895b9d6fd`) with exactly two uncommitted modified paths, and no FIX-1.md was on disk. The WORK was real in the working tree (both cures applied, full gate battery + four mutation EXECs genuinely run pre-receipt); only the COMMIT was missing and the receipt fabricated its sha. Corrected this turn: the cure is committed as `6a6b6c9f5f39fdd1145dc37fc829cb9d09ae10d4` ("C-TOOL-6.j: review fixes (round 1)", 2 files, +12/-3), cat-file'd `commit` in the citing shell, porcelain 0 at lane end, %ae/%ce detain by separate count-greps (1/1), zero Co-Authored trailers. The five reviewed picks were NOT amended or rebased.

## 2. Findings dispositions
F-A MAJOR (PtyRelayTest.php:352, refusal-branch pin broken-by-construction):
- Cure, review-verbatim assertion: `self::assertSame('', (string) @\file_get_contents($paths['exit']), 'a refusing relay must record nothing');` replacing `assertFalse(is_file(...))`, plus a WHY comment naming the stageRelaySinks 'xb' pre-creation and builder correction (e)'s content-assertion precedent (:269). Tests-only; src untouched.
- EXEC-confirming mutation set (house `php -d disable_functions=posix_kill` child-spawn pattern — forces the relay's own refusal door at PtyRelay.php:166-174 to be taken on THIS pty-capable host, via a scratch in-file test method that was NOT committed):
  - M1a refusal EXEC + cured line: `Tests: 1, Assertions: 10` OK — the cure PASSES a true refusal (old line could never).
  - M1b same EXEC + old line restored: `Tests: 1, Failures: 1` — proves the old leg broken-by-construction and the cure necessary.
  - M2 refusal EXEC + forged 'X' planted in .exit after staging: `Tests: 1, Failures: 1`, diff `'' vs 'X'` on message 'a refusing relay must record nothing' — the cured leg is non-vacuous: it reads real content and reddens if a refusing relay ever records.
- Post-fix gates (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, this-turn quotes): `PtyRelayTest::` 8T/80A OK (figure UNCHANGED — cure is in-method); combo `(BackgroundShellRegistryTest|PtyRelayTest|BackgroundShellInteractiveTest|SuiteSkipRosterTest)::` 77T/563A OK — EXACTLY the r1-era figure; `(LspLauncherTest|PtyRelayTest|BackgroundShellInteractiveTest|SuiteSkipRosterTest)::` 58T/403A OK; `ReadPathCensusTest::` 19T/760A OK; `(ReadPathCensusTest|DescriptorInheritanceGuardTest)::` 34T/5950A OK; `TreeWideGuardRosterTest` bare 17T/3925A OK; `DuplicatedTestHelperDriftTest` bare 14T/204A OK. Every run: PHPUnit Deprecations: 2 — PRE-EXISTING foreign AccessorDeprecationTest non-public data-provider pair (tests/Config/AccessorDeprecationTest.php:168/:202), outside this lane's two files, report-only NEVER cure.

F-B MINOR (BackgroundShellInteractiveTest.php:106 hard-asserted true post-reset):
- Cure: `$hostCapable = ProcessContainment::interactiveAvailable();` captured BEFORE the memo injection; the closing leg became `self::assertSame($hostCapable, ProcessContainment::interactiveAvailable(), 'the reset must re-derive the hosts own answer')`. A pty-less host now stays honest (false==false) while the dowsing failure mode still reddens.
- Mutation FB-M1 (re-dowse the memo after the reset before the final assert): `Tests: 1, Failures: 1` — "the reset must re-derive the hosts own answer / Failed asserting that false is identical to true" — the comparison is live, not a tautology.
- Post-fix gate: `BackgroundShellInteractiveTest::` 12T/75A OK — UNCHANGED from the r1-era figure.

## 3. §7 facts for the welder (LIST, never mint)
- Live figures at this lane's tip `6a6b6c9f5`: PtyRelayTest.php 8T/80A, BackgroundShellInteractiveTest.php 12T/75A — ZERO delta vs the r1 measurements (fix swaps assertions in-method, adds no test method, no new file): the durations.tsv rows and the +20T suite-figure battery carry from pick-5 stand UNCHANGED by this fix; no new §7 rows to mint.
- Master moves under the lane (C-TOOL-4.g welder is sole master writer): the fix is the SIXTH pick riding the lane base — cherry-pick it with the five reviewed ones; no rebase happened, the five picks are byte-untouched.
- WELD-ELIGIBILITY: F-A cured + EXEC-proven, F-B cured + EXEC-proven → the r1 "WELD-ELIGIBLE NO until F-A cured" gate is cleared on the on-disk blob of `6a6b6c9f5`.

## 4. NIT record (verbatim-credited, NO change)
Builder-disclosed residual, recorded for the 6.k follow-up, src untouched by this fix:
"driveSend readiness waits on <id>.pid, which the pump writes at PtyRelay.php:299 three statements before opening the FIFO (:301-302) — a sub-ms window where a send returns ok:true and the bytes die on last-writer close. Builder disclosed this production edge for 6.k; the retry loop only re-issues on the 'not reading input' refusal shape, not this loss shape. Accepted-disclosed." (REVIEW-1.md §Q4 NIT line; originator: the C-TOOL-6.j builder's own disclosure, carried by the r1 reviewer.)
