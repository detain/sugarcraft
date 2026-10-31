# REVIEW-2 — C-TOOL-4.b (round-2 closure review)

reviewer: fresh round-2 (read-only; no edits/staging/commits, no mutations on disk).
lane: /home/sites/cc-cmp-C-TOOL-4.b  branch: cc/C-TOOL-4.b  base: 288b81f11
picks: 068a7d204 46ef97001 1c9f29e32 c70b20db5  tip: c70b20db5  porcelain: 0 (self-read)
author law at tip: %ae=%ce=detain@interserver.net, zero Co-Authored (self-read).
cure e78d5dc14 IS on master, NOT in the lane base (self-read) — red-set re-measured accordingly.

VERDICT: FINDINGS C=0 M=1 MINOR=0 NIT=2

## Q1 MAJOR-1 (vacuous door pin) — CLOSED

`tests/Tools/BashSandboxTest.php::testBothCatalogDoorsAskTheFactoryNotAConcreteBackend` exists at tip. It reads the SOURCE SLICE (file_get_contents of `src/Tools/BuiltIn/{Bash,PtyRun}.php` via `dirname(__DIR__,2)`, slice = door body from `public static function fromCatalog` to first `\n    }`), asserts findability (`assertGreaterThan(0,strpos)`), one-door uniqueness (`substr_count(...)===1`), contains `SandboxFactory::fromSetting(`, and zero `Bubblewrap::fromSetting(` inside the slice. OS-independent; reads wiring, not behavior. Red-before DERIVED STATICALLY (mutations not run — read-only): reverting either door to `Bubblewrap::fromSetting(` kills both slice legs (contains- and zero-count-); the legacy `testTheWiredJailsAreTheFactorysChoiceForThisOs` (kept untouched at :397) stays GREEN under that mutation on Linux because there the factory IS a Bubblewrap — the vacuity the pin cures. The fixer's M1 report is consistent with this derivation; I did not run it.

## Q2 MINOR-1 (refusal remedy) — CLOSED

(a) Linux text byte-identical: old literal `'...fix %s (on Ubuntu, unprivileged user namespaces are restricted by AppArmor) or set bashSandbox to "off".'` vs new `'...fix %s (%s) or set bashSandbox to "off".'` + `Bubblewrap::refusalRemedy()` returning exactly `on Ubuntu, unprivileged user namespaces are restricted by AppArmor` (Bubblewrap.php ~:261). Parenthetical + spacing reconstruct identically with name()='bubblewrap'; and the BASE-era full-text pin (one 'AppArmor' occurrence in the base BashSandboxTest) still exists and is GREEN at tip (my BashSandboxTest run 23T/109A 0F). Behavior change dressed as a fix: NO.
(b) Both doors route through the method: Bash.php:624 and PtyRun.php:434 interpolate `$this->sandbox->refusalRemedy()`; `testNeitherRefusalSentenceHardCodesALinuxRemedy` pins per-file counts (1 sentence / 1 refusalRemedy( / 0 AppArmor) and is green.
(c) Seatbelt-regression discrimination: `testEachBackendRemedyRidesTheRefusalItBelongsTo` drives the real `Bash::execute` refusal arm with a `Seatbelt::new()` backend (probe answers the Darwin fact before touching any binary — no spawn) and asserts NotContains 'Ubuntu'/'AppArmor' on the rendered refusal; regression to the Ubuntu sentence = RED. Also asserts isError + assertFileDoesNotExist. Bubblewrap arm uses the planted broken-bwrap shim through the existing spawn-capture idiom; no new proc_open/fork class (nothing owed to the roster).
No new lang keys: fix pick stat contains no lang file (verified `git show --stat c70b20db5`). Raw tool-result prose matches the pre-existing sentence's register.

## Q3 MINOR-2 (doctor vocabulary arm) — CLOSED

`DoctorChecks::sandboxProbe` arm restored to `!in_array($osFamily, ['Linux','Darwin'], true)`. Windows stays inside the arm (not in the allow-list) — `testAWindowsHostReportsTheLinuxOnlyLine` (renamed pre-existing, Windows detail pins intact) green. BSD/Solaris now never reach the probe: new `testFamiliesWithoutEitherJailReportTheVocabularyLineToo` pins the exact off-OK and configured-WARN detail strings AND `assertStringNotContainsString('jail starts')`. A wrong remedy is not constructible: the two `cli.doctor.sandbox_nonlinux*` keys literally state "bubblewrap is Linux-only and this host is {os}". Red-before derived statically: `=== 'Windows'` reintroduced sends BSD to the probe → detail ≠ pinned vocabulary string → RED (matches fixer M3).

## Q4 Regression inventory + jail re-check

Assertion/method inventory base→tip: BashSandboxTest 19→23 methods, 59→78 asserts; DoctorSandboxProbeTest 10→12, 42→57; ReadPathCensusTest 7→7, 13→13. Diff-wide removed assertion lines: exactly 3 — the rename `testANonLinuxHost...`→`testAWindowsHost...` (its Windows pins untouched) plus the two Darwin assertSame lines that asserted the now-FALSE "Darwin gets the Linux-only line" polarity — replaced by `testADarwinHostRoutesTheRowThroughTheSeatbeltBackend`. No loosened legs. +4T/+32A = the fix's four new methods exactly.
Reachability chain re-traced at tip: `src/Cli/LaunchContext.php:1763` → `ToolCatalog::build` → `ToolCatalog.php:206 $class::fromCatalog($context)` → Bash.php:233 / PtyRun.php:182 → `SandboxFactory::fromSetting`; CONSUMED at Bash.php:613 (refusal) + :630 (wrap) and PtyRun.php:469 (spawnArgvPrefix). Fail-closed live proof intact (SeatbeltTest 14T/104A green here incl. isError + both markers absent). Jail files: fix pick adds only the pure-text `refusalRemedy()` to Bubblewrap/Seatbelt/SandboxBackend; JailPaths untouched; r1's gitDirs/realpath/symlink/`..` PASS stands unbroken.
NEWLY VACUOUS: none. NEW MAJOR GUARD RED: see below.

### MAJOR-2 (NEW, fix-pick-attributable, in-step guard law §1.8)

The structural test's `\dirname(__DIR__, 2) . '/src/...'` anchors (BashSandboxTest.php:436, :461) make that file "names the package root", which pulls its PRE-EXISTING `new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS)` (:60, cleanup walker — present at base, unchanged) into TreeWideGuardRosterTest's scan. `TreeWideGuardRosterTest` now reports TWO offender lines: the foreign `Cli/LoginSubcommandTest.php => glob(...)` AND `Tools/BashSandboxTest.php => RecursiveDirectoryIterator(...)`. The aggregate figure the fixer reported (59T/4216A/4F) is coincidentally identical to r1's, but the failure MESSAGES are not — "byte-identical to reviewer" is false at message level. At weld onto post-e78d5dc14 master the LoginSubcommandTest leg cures; the BashSandboxTest leg does NOT — both TreeWideGuardRosterTest methods stay red, lane-attributable, weld-blocker class.
FIX (exact): add `'Tools/BashSandboxTest.php' => 'RecursiveDirectoryIterator($this->tmpDir,\FilesystemIterator::SKIP_DOTS)'` (guard-normalized spelling per its own message; the walk is over a directory the test made) to `WALKS_A_DIRECTORY_THE_TEST_MADE` in `candy-code/tests/TreeWideGuardRosterTest.php`, same commit, reason comment included — the precise cure shape e78d5dc14 just used for LoginSubcommandTest on master. (e78d5dc14 does not collide with any of the lane's 18 files.)

## Q5 Scope + gates (my numbers)

Fix pick `git show --stat c70b20db5`: exactly the 8 declared files (+152/−13). Lane-wide `git diff --name-only 288b81f11..HEAD`: 18 files, identical to r1's adjudicated declared set. Forbidden grep (suite-figure|durations.tsv|dist/|generated.ts|SERVER.md|protocol/|plan_comparison) over the file list: ZERO hits.

Gates, cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, plain pipe, SIGHUP method excluded:
- Sandbox family, EXACT-CLASS sum: SeatbeltTest 14T/104A + SandboxFactoryTest 7T/27A + BashSandboxTest 23T/109A + DoctorSandboxProbeTest 12T/72A + SettingsSchema pair 65T/2810A = **121T/3122A 0F** — reproduces the fixer's aggregate EXACTLY (and = r1's 117T/3090A + 4T/+32A). Note: the filter string as printed in both reports is not byte-reproducible in bash (`\|` = literal pipe in PCRE); the claim verifies as the per-class sum, which I anchored individually.
- ReadPathCensusTest 19T/727A ✓ · PtyRunToolTest minus `testExpectMissIsLoudAndTheScriptStillContinues` 12T/93A ✓ (all my figures == fixer's).
- Guard filter (8 families): **256T/59106A/3F** — staleness pair (pinned 25,537 vs live 29,150) + GlobDialect 310,245-vs-311,360 (corpus UNMOVED, §7 rider figure). Figures match both r1 and fixer.
- Drift/roster/skip filter: **59T/4216A/4F** — but the red SET has moved under the cure: DuplicatedTestHelperDrift 2F (titleBackend trio + T0 pair, no lane file named) and the LoginSubcommandTest roster leg are the CURED-ON-MASTER foreign pair (absent from this lane base; they will be GREEN at weld). The SECOND roster offender is now the LANE's own BashSandboxTest line → MAJOR-2; that leg survives the cure.
- Additional family found by re-measurement (not in the accepted list, foreign, pre-existing): `tests/Support/OneSidedHomeSandboxTest.php:435` 1F — its pinned home-sandbox-user roster is stale against three files that are ALL AT-BASE at 288b81f11 (HelpHostCommandTest, ToolOutputRedactionTest, SendMessageCrossSessionTest; verified via cat-file), lane never touched the guard. §7-roster staleness family; not lane-attributable.

## NIT riders — facts for the lead's ruling

- NIT-1 (live, cheap): Seatbelt.php:301 (post-fix line shift) joins `$root . '/' . $relative` vs the bwrap twin's `rtrim($root,'/') . '/' . $relative` (Bubblewrap.php:191). Only reachable at root `/` (the probe's own case); yields `//.candy-code` which never matches a canonicalised vnode — no privilege delta. Fix = one `rtrim()`. No pin anywhere asserts the `//` spelling (grep clean) → step-local, zero test churn.
- NIT-2 (live, cheap): `Bubblewrap::probe()` (Bubblewrap.php:235-241) checks `resolvedBinary()` before the OS gate, contradicting `SandboxBackend::probe`'s family sentence ("answer from that fact alone, BEFORE touching any binary"); Seatbelt obeys. Reordering the two gates is a two-statement move, BUT the Windows-vocabulary pins in SandboxFactoryTest/doctor tests name specific messages — the fixer must check which text the Windows-without-bwrap case pins and flip in-step if it names "not found on PATH". Both arms refuse either way today; the defect is prose-overclaim, not behavior.
- Fact for the ruling: both are ≤3-line fixes in files MAJOR-2's cure pick already forces; marginal cost of including them in fix-2 is one extra pin check. Since MAJOR-2 makes a round-3 review mandatory anyway, neither needs to block anything beyond that pick.
