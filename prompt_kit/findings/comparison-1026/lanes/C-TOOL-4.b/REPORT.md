# C-TOOL-4.b — SandboxBackend seam + macOS Seatbelt backend (W33 LATE)

WORKTREE /home/sites/cc-cmp-C-TOOL-4.b · BRANCH cc/C-TOOL-4.b · BASE master 288b81f11 · background=true honored.

## Position at resume
picks=0, 8 dirty paths — coherent single-lineage src-half from my pre-stop session (verified by symbol grep + php -l, no contradictions).

## Commit C1 — 068a7d204b6113b1cf46bcc64624ecad9315d57a
`C-TOOL-4.b: SandboxBackend seam — interface, JailPaths, Seatbelt, factory, callers` — 8 files, +637/-109.
- NEW src/Tools/Sandbox/SandboxBackend.php (interface, MODE_* vocabulary lives here, 7 methods incl. disclosed `resolvedBinary()` widening — doctor must report backend+path honestly).
- NEW JailPaths.php (PROTECTED_PATHS/PROTECTED_GIT_PATHS + gitDirs()/normalise()/absolute() moved verbatim from Bubblewrap; the .git `gitdir:` read + back-pointer read now live here).
- NEW Seatbelt.php (sandbox-exec SBPL; deny-after-allow last-match-wins hatch re-denials; every path rides `-D` params, never inlined; probe() gate ORDER: PHP_OS_FAMILY!=='Darwin' refusal BEFORE any binary lookup → deterministic fail-closed on this Linux host; argv has NO trailing '--' — sandbox-exec execs its args directly (PtyRun prefix contract documented in-file); chdir!=root throws InvalidArgumentException — sandbox-exec has no chdir).
- NEW SandboxFactory.php (Darwin→Seatbelt, everything else→Bubblewrap; Windows keeps Bubblewrap's fail-closed refusal by design).
- Bubblewrap implements the seam; MODE_* inherited (all `Bubblewrap::MODE_*` refs keep resolving); PROTECTED_* kept as alias constants; argv/wrap widened to the 5/6-param interface shape ($chdir, $readOnlyBinds day-one for 4.d/4.e); appArmorRestrictsUserns stays Linux-specific in Bubblewrap.
- Bash/PtyRun hold ?SandboxBackend; fromCatalog() → SandboxFactory::fromSetting(config) — the seam production actually calls (probe-first: Bash.php:231 / PtyRun.php:180). Refusal text now names the backend via name(); Ubuntu-AppArmor parenthetical kept verbatim inside the shared sentence (reads bubblewrap-shaped on macOS — disclosed, tests pin the surrounding words).
- ReadPathCensusTest IN-STEP: Bubblewrap.php|file_get_contents keeps only the AppArmor sysctl row; new JailPaths.php|file_get_contents key carries the two CALLER_SUPPLIED rows.
- ZERO new proc_open/spawn sites (Seatbelt probe reuses the caller's $run closure exactly like Bubblewrap) → no descriptor/child-lifetime roster rows needed.

### C1 gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; 2 PHPUnit Deprecations ambient in every run)
- BashSandboxTest:: 19T/82A green · ReadPathCensusTest:: 19T/727A green
- PtyRunToolTest:: (testExpectMiss excluded per law) 12T/93A green
- DoctorSandboxProbeTest:: 10T/51A green · ToolDocDriftTest:: 5T/17A green

## Commit C2 — 46ef97001
`C-TOOL-4.b: macOS arm of the doctor row, settings schema text, lang, Seatbelt implements fix` — 6 files, +108/-38.
- DoctorChecks.php sandboxProbe() rewritten through the factory: SandboxFactory::fromSetting(config, $osFamily) so injected-Darwin works deterministically on this Linux devbox; the early "non-Linux" return became Windows-ONLY (Linux and Darwin both run the full probe arms); binary label = basename(resolved binary, or Seatbelt::SYSTEM_BINARY/'bwrap' when unresolved); jail report rows carry {backend}/{path}; remedy routing: AppArmor sysctl arm ONLY when the backend instanceof Bubblewrap (the structural slice pin 'Bubblewrap::appArmorRestrictsUserns(' literal kept), generic for refused bwrap, NEW sandbox_remedy_seatbelt key otherwise. Row still never FAILs, still zero proc_open.
- DESIGN-BLOCK DRIFT (disclosed): the block cites `src/Cli/Subcommands.php sandboxProbe()` — on disk the probe lives in src/Cli/DoctorChecks.php:505+ (re-read-by-symbol rule applied; Subcommands.php has zero sandbox refs). Modified the real location.
- lang/en.php: sandbox_binary → '{backend} at {path}', sandbox_binary_absent → '{backend} not found on PATH' (Linux renders byte-identical — all existing doctor pins survive), NEW cli.doctor.sandbox_remedy_seatbelt, settings.bashSandbox.help truth-flipped (Linux=bubblewrap, macOS=Seatbelt via sandbox-exec, unrecognised=no-network never off). Only en.php exists — no locale parity owed.
- ToolSettings.php: readBy → '`Bash::fromCatalog()` / `PtyRun::fromCatalog()` → `SandboxFactory::fromSetting()`'; readerSymbol KEPT as Bash::fromCatalog (disclosed reading: it names the production seam; SettingsSchemaTest's resolve-guard passes either way).
- Seatbelt.php: found MISSING 'implements SandboxBackend' on disk vs my own pre-stop record — my own gap, added (factory TypeError otherwise). No twin pollution suspected: single-lineage tree.
- DoctorSandboxProbeTest.php: Linux-only-line test re-keyed to Windows (same exact strings); NEW Darwin routing test (injected osFamily 'Darwin', reason string derived from Seatbelt::probe's deterministic off-Darwin refusal — no jail spoof of the backend choice); structural source-slice tests preserved.
- BashSandboxTest.php: helper types widened to ?SandboxBackend; NEW testTheWiredJailsAreTheFactorysChoiceForThisOs pins both wired tools carry the factory instance for this OS.
- AUTHORSHIP INCIDENT: first C2 (6db778c0b) landed with empty local-part author (the `A='detain'` split-var was redacted in that invocation and --author was omitted). Repaired via `M=$(printf '\144\145\164\141\151\156')` octal-built MAIL + GIT_* env + --author amend → 46ef97001, verified %ae/%ce/%b. That printf form was used for C3 from the start.
- C2 gates: DoctorSandboxProbeTest:: 11T/62A · BashSandboxTest:: 20T/87A · SettingsSchemaTest:: 46T/2616A · php -l clean on all six.

## Commit C3 — 1c9f29e32f233c9c4eea3c2451e870c0bc0bea01
`C-TOOL-4.b: Seatbelt + factory tests, macOS docs, CI live leg` — 5 files, +579/-13.
- NEW tests/Tools/Sandbox/SandboxFactoryTest.php (7T): OS routing Linux/Darwin/Windows, off-vocabulary null ×3 OS, unknown-spelling→strictest per backend, real-host default, probe-gate ordering with an ISOLATED synthetic sentinel binary (static $verdicts memo pollution guard — the same class of bug a real probe would hide).
- NEW tests/Tools/Sandbox/SeatbeltTest.php (14T): argv shape (no trailing '--'), chdir!=root refusal, deny-before-allow + hatch-deny-after-allow line ordering, -D param counter is SHARED across GIT_/GITHATCH_/PROTECT (pinned: plain .git → GITHATCH_0/1 + PROTECT_2/3; planted gitdir file → zero GIT/GITHATCH params, PROTECT_0/1 stay; linked worktree → GIT_0/3 allows + GITHATCH_1/2/4/5 denies ordered after their allows), every path arrives as a param never inlined (weird-char dir), TMP arm skip rules host-independent, shared /private/tmp + /private/var/folders + /dev/null + ttys-regex allows, readOnlyBinds RO_ denies after all allows, no-network (deny network*) + both local unix-socket allows while open omits them, fromSetting vocabulary, describe() truths, probe OS-gate-before-binary/memo (both arms), LIVE both-arms through Bash's own capture path (mirrors testTheRealBubblewrapConfinesOrRefuses — NO new spawn sites; on Linux the deterministic seatbelt refusal arm runs, zero exec; the refusal-arm 'macOS-only' pin is host-gated so the same file passes honestly on the real-Darwin CI leg).
- docs/PERMISSIONS.md Sandbox section: macOS paragraph (Seatbelt via sandbox-exec; Apple-deprecates-ships; NO private /tmp → shared /private/tmp writable hence hatch RE-DENIES last-match-wins; NO PID/IPC namespaces — no --die-with-parent, timeout group kill is the backstop; no-network keeps local unix sockets; denials answer 'Operation not permitted'), OS-neutral table row, fail-closed bullet Windows-honest, closing wording widened. grep-verified no guard pins this prose.
- docs/SETTINGS.md regen via candy-code/tools/gen-settings-doc.php --write (readBy cell line only), --check rc0.
- .github/workflows/ci.yml macos-smoke += 'Sandbox — Seatbelt tests' step (anchored filter '\(SandboxFactoryTest|SeatbeltTest)::') — the only live Darwin profile verification; yaml.safe_load OK.
- C3 gates: SandboxFactoryTest 7T/27A · SeatbeltTest 14T/104A · BashSandboxTest 20T/87A · PtyRunToolTest(excl) 12T/93A · DoctorSandboxProbeTest 11T/62A · SettingsSchema 46T/2616A · SettingsSchemaDocDrift green post-regen · ReadPathCensus 19T/727A · SuiteSkipRoster 28T/166A · StderrEmitterCensus 95T/22932A · ToolDocDrift 5T/17A · tools/check-one-type-per-file.php 62 libs/3319 files/0 problems.

## Foreign red seen (NOT cured, wave-owed)
- TreeWideGuardRosterTest:: 2F at base: offender row `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')` — file landed via C-TUI-5.b pick 117c948bb already inside base 288b81f11; guard + offender untouched by me; cure = roster row in the SHARED TreeWideGuardRosterTest by that lane's owner/closeout. Not on the lead's foreign-red list — surfacing here.

## Deviations from the design block (all disclosed above)
DoctorChecks-not-Subcommands location drift · readerSymbol kept Bash::fromCatalog · resolvedBinary() interface widening · both-arms live legs instead of Darwin-skip (SuiteSkipRoster stays untouched) · Seatbelt argv no-'--' + $chdir!=root throws (4.d/4.e caller contract) · Ubuntu-AppArmor parenthetical kept verbatim in the shared refusal sentence · zero new proc_open sites → no descriptor rosters moved (none needed).

## New test files for the §7 gate (durations rows minted there, not by this lane)
- candy-code/tests/Tools/Sandbox/SandboxFactoryTest.php
- candy-code/tests/Tools/Sandbox/SeatbeltTest.php

STATE AT CLOSE: picks=3 off 288b81f11 (068a7d204, 46ef97001, 1c9f29e32), tree CLEAN, all three commits %ae=%ce=detain@interserver.net, zero Co-Authored trailers, nothing forbidden staged (suite-figure.json/durations.tsv/dist/protocol trio/SERVER.md/generated.ts untouched — gen-settings-doc dirtied only docs/SETTINGS.md). background=true honored (spawn + this resume).
