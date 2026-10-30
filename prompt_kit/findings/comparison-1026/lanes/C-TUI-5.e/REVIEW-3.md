VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local minors (r3 fresh reviewer, /home/sites/cc-cmp-C-TUI-5.e @ de6aeb15e, base 671105299; chain verified 996ba8c0d → 73bb33e2a → de6aeb15e, 12-file set = design-declared + r2's App.php/FirstRunWizardWiringTest re-touches).

## r1 findings — cured, disk-proven
- CRITICAL-1 CURED: App.php:2052-2053 probe now `(\is_resource(STDIN) && stream_isatty(STDIN)) || (\is_resource(STDOUT) && stream_isatty(STDOUT))` — exact house shape (HeadlessPermissionPrompt.php:495 `\is_resource($this->in) && \stream_isatty($this->in)` verified on disk); real-TTY semantics byte-equivalent (both streams resources ⇒ true-guard is no-op); docblock truth-flipped to the throw-then-false story. RE-RAN gate: `--filter 'AppModelTest|KittyKeyboardNegotiationTest'` → **Tests: 68, Assertions: 1786, OK (2 baseline Deprecations), 0E** (was 4E/2E shapes at r1).
- MAJOR-1 CURED, non-vacuity independently derived (read-only, no mutation): pin at FirstRunWizardWiringTest.php:173; window start needle `public function init(): ?\Closure` occurs EXACTLY ONCE in App.php (:3347); first `\n    }` match is :3374 = init()'s own close (deeper nested braces are 8-space, no heredoc/false match in between — grep census); the dial `$this->firstRunWizardOfferCmd(),` sits at :3372 inside the window. Call moved out of init() → 0 → red; duplicated → 2 → red; comment-stripped (`~(/\*.*?\*/|//[^\n]*|#[^\n]*)~s`, same regex as Kitty's sweep) → commented-out line counts as removed — FIX-2's raw-window-survived story is exactly the mutation that arm defeats. Window neither too wide (siblings unscanned) nor too narrow.
- MINOR/NIT as claimed: KeyHelpTest wizard-grep in output = 0; 5F+1 risky matches standing roster byte-shape; +7 welder re-pin stands.

## r1 CLEAN areas — unregressed (all re-run this round)
- `--filter FirstRunWizard` → 39T/135A OK (38+new pin). Lane union (9 classes) → **428T/7994A OK** = r1's 427/7991 + exactly the pin (+1T/+3A). Guard families GlobDialect/DuplicatedTestHelperDrift/SwallowingCatch → green.
- Web leg serial: `npx vitest run --no-file-parallelism src-web/views/__tests__/dashboardNoProvider.spec.ts` → 1 file, 4/4 passed (node_modules present, lane tree).
- ReadPathCensus/SettingsWriterCensus carry unchanged (stamp door lives in 5.d surface, r1-verified). E646 clean; Bootstrap untouched.

## Law/guard checks
- Trio not owed: `git diff 671105299...HEAD --name-only` has ZERO src/Protocol/** + zero generated.ts; suite-figure.json/durations.tsv/dist absent from all three `git show --name-only`.
- Authors 3/3: %ae=%ce=detain@interserver.net, 0 Co-Authored trailers.
- ReadmeSuiteFigureDrift 2F at tip = staleness pair BY DESIGN (pinned 25,537 vs live 29,011 — program-era drift + this lane's +1T; welder re-pin rider).

## Out-of-scope disclosure (non-blocking, NOT this lane's dirt)
OneSidedHomeSandboxTest::testNoTestFileSandboxesOnlyHalfOfHome is RED at lane tip — INHERITED at base: guard file and both offenders (tests/Runtime/ToolOutputRedactionTest.php, tests/Tools/SendMessageCrossSessionTest.php) are byte-identical 671105299 vs tip (lane diff 0 lines on all three), offenders not lane-derived (pin's own test file stays unflagged). Not in run-state standing-reds roster → welder/lead should mint a roster row or a fixer lane owns it; C-TUI-5.e must NOT touch the shared guard.

## Welder riders restated (CLEAN)
DUR row FirstRunWizardWiringTest.php; dist rebuild (src-web touched); KeyHelpTest +7 figure re-pin; foreign --worktree:1889 + LayeredSettings cascade standing-reds disclosed; ADD: OneSidedHomeSandbox base-inherited red above.
