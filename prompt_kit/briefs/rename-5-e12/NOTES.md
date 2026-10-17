# rename-5-e12 NOTES — candy-pty → sugar-pty <!-- @rename-keep -->

Lane: e12 (batch-E line 54, kind=rename, ns Pty→Pty UNCHANGED, type=lib, bins {}, no extra_literals/protect, status pending at base). Branch `rename/e12` off `6709f44bd`, worktree `/home/sites/sugarcraft-e12`. NEVER write master; push ONLY rename/e12. No R2/R8/R9 — brief law "NO remote/packagist" (weld/serial phase owns remotes; same law as D1). <!-- @rename-keep -->

## Entry facts (verified)

- Derived: pascal CandyPty→SugarPty, compact candypty (ZERO sites), env prefixes CANDY_PTY_/CANDYPTY_ → SUGAR_PTY_. R1 dry-run at base: 359 files / 12 path renames; R2=29 R3=1506 R5=23 R6=1 (R6 no-op — src/Lang.php NAMESPACE already 'pty', ns unchanged). <!-- @rename-keep historical provenance -->
- PROD env vars keep the SUGARCRAFT_ prefix and are NOT brand vars: SUGARCRAFT_PTY_BACKEND, SUGARCRAFT_TERMIOS, SUGARCRAFT_LIBC — byte-untouched (C.4 law). The only CANDY_PTY_* env read is CANDY_PTY_HANG_BUDGET (tests/Support/HangWatchdog.php:190 — TEST INFRA ONLY, nothing in src/ reads it). <!-- @rename-keep historical provenance -->
- PLAN MISLABEL CORRECTED: `CANDY_PTY_COPY` is NOT an env var — it is `private const CANDY_PTY_COPY` in tools/tests/ToolsEnvRosterTest.php:64 (a path-pointer constant to the package's EnvRosterTest copy). R5 rewrites the identifier CANDY_PTY_COPY→SUGAR_PTY_COPY mechanically (constant name, no compat surface) — no fallback needed for it. <!-- @rename-keep historical provenance -->
- LegacyEnv fallback per brief+plan: implement in sugar-pty/tests/bootstrap.php via `LegacyEnv::import(['CANDY_PTY_' => 'SUGAR_PTY_'])` AFTER autoload, BEFORE HangWatchdog::install() (install reads the budget at arm time; ORDER LAW: LoopPin first to touch Loop::get(), watchdog after — import touches no loop, sits between autoload and LoopPin; verify byte-intact order). Rationale: CANDY_PTY_HANG_BUDGET=0 is the documented opt-out; EnvRosterTest's own docblock names "tabulated-but-unread = what a rename leaves behind" as the defect. Candy-core is a require-dep of the lib → LegacyEnv resolvable. Scanner-safe: import() call form is a static method call with array literals — invisible to envAccessesIn (keys on getenv/putenv/superglobal-name). README gets the R4.5 deprecation sentence (@rename-keep).
- Glued/CamelCase blindspots (tool-invisible, manual): (1) `testTheScannerHasNotDriftedFromTheCandyPtyCopy` ×2 (tools/tests/ToolsEnvRosterTest.php:542 + sugar-pty/tests/EnvRosterTest.php:39 cite) → …SugarPtyCopy; (2) `testDirPointsToLangFolderInCandyPty` (LangTest.php:32) → …InSugarPty; (3) glued `plan_candy-pty.md` pointers ×7 (src/Posix ReactPump/ChildPollTrait/MultiPump docblocks, findings/findings_resume_plan ×3, findings/plan.md); (4) glued `sugarcraft_candy-pty.md` content pointer in update_sugarcraft_* repo_map files. NO .vhs dir (no tape tokens); ansi/sugarcraft/*.ansi art CONTENT carries candy-pty wordmark — content-excluded → rename-followups.md. <!-- @rename-keep historical provenance -->
- Consumers (composer require sugarcraft/candy-pty): candy-core, candy-gifview, candy-scriptui, candy-vcr, candy-wish, sugar-animation, sugar-crush, sugar-quickpick. sugar-crush is the gated direct consumer (PtyRun tool + src/Support/PtyDriver + tests). <!-- @rename-keep historical provenance -->
- Tooling-file scope (brief): (a) tools/check-child-lifetimes.php rows `candy-pty/src/Spawn.php::proc` + `candy-pty/src/Posix/PosixProcess.php::spawn` — R3 flips keys automatically, guard is fail-closed → RUN IT as proof after; (b) .github/workflows/pty-matrix.yml paths/cd/cache-keys — R3 auto (13 hits); (c) scripts/affected-libs.php MACOS_LIBS — R3 auto (3 hits; the `debug/macos-candy-pty` historical PR-branch token JUDGED-REWRITE: R3 leading-hyphen law rewrites it, comment prose reads true as label of the historical experiment whose artifacts are the files being renamed anyway). <!-- @rename-keep historical provenance -->
- KNOWN PRE-EXISTING: ReactPumpExtendedTest stream_select 4096-bytes-lost warning carry (r67df base-carry) — never chase. Suite carries HangWatchdog (Part A rule 9) — brief law: timeout 900 env -u LINES -u COLUMNS vendor/bin/phpunit </dev/null; NO sleep+pkill.
- Comment-only-token sites: PosixPtySystem/Cloexec/SizeIoctl /dev/fd-stty-dup comment laws — R3 flips `candy-pty` path tokens only, logic byte-identical (verify via diff grep for non-comment changed lines == none). <!-- @rename-keep historical provenance -->
- Baselines (rename-baseline.md — RE-MEASURED at base per brief law, drift disclosed): (pending below)

## Steps

- (pending) tag-prep commit:
- (pending) R1 dry-run: 359 files / 12 paths — matched plan Phase 7 + R3 census rows (pty-matrix.yml, affected-libs.php, check-child-lifetimes.php all present).
- (pending) R2 remote rename: SKIPPED per brief law.
- (pending) R3 tool run:
- (pending) R4 manual:
- (pending) R5 wiring:
- (pending) R6 gates:
- (pending) R7 commit+push:
