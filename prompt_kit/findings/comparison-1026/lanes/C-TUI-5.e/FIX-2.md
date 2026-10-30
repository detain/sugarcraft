VERDICT: FIX PASS COMPLETE — CRITICAL-1 + MAJOR-1 cured, gates green, mutation-proven. Tree /home/sites/cc-cmp-C-TUI-5.e (branch cc/C-TUI-5.e, was tips 996ba8c0d + 73bb33e2a).

## CRITICAL-1 (offer-path TypeError) — CURED
`candy-code/src/App/App.php` firstRunWizardOfferCmd default probe now
`(\is_resource(STDIN) && stream_isatty(STDIN)) || (\is_resource(STDOUT) && stream_isatty(STDOUT))`
— exactly the reviewer's shape, house precedent HeadlessPermissionPrompt::isInteractive (read first: `is_resource($this->in) && stream_isatty($this->in)`), with a WHY comment naming the bootstrap fd-0 free. Docblock truth-flipped: the old "the tty probe answers false on a piped stdout (every test run), so the default would never fire here" replaced with the honest sentence (unguarded stream_isatty THROWS on the freed \STDIN under strict_types; with the guard the default answers false in test context). No behavior change on real TTYs: is_resource is true for both live streams, so the || semantics are byte-equivalent there.
Gate: `--filter 'AppModelTest|KittyKeyboardNegotiationTest'` → **Tests: 68, Assertions: 1786, OK (2 baseline Deprecations), 0E** (was 64T/2E + 4T/2E).

## MAJOR-1 (init() wiring unpinned) — CURED
`candy-code/tests/App/FirstRunWizardWiringTest.php::testTheLaunchBatchDialsTheOfferExactlyOnce`: source-scan pin mirroring the KittyKeyboardNegotiationTest house shape — slices `public function init(): ?\Closure` to its `\n    }`, STRIPS COMMENTS with the same regex Kitty's sweep uses (`~(/\*.*?\*/|//[^\n]*|#[^\n]*)~s`), asserts `substr_count($body, '$this->firstRunWizardOfferCmd(),') === 1`. Helper NOT copied (methodBody exists in 3 files already; inlined to dodge DuplicatedTestHelperDrift).
Mutation proof: sed-commented the batch line at :3372 → exactly **1F = the new pin alone** (14T/34A, other 13 wiring tests green — the vacuity the reviewer named); cp-restore md5-verified 1b1f8d7bbd01d113aa11ee07d5427c17 → green. FIRST mutation round with the raw (un-stripped) window SURVIVED (a `//` line still contains the needle) — that is why the strip arm exists; pinned live.

## MINOR (KeyHelpTest 5F+1 risky) — untouched, verified
Reviewer's pre-existence claim re-confirmed: `--filter 'KeyHelpTest'` output greps **0** lines naming wizard; standing roster values shift with the lane's 8 new key rows, +7 figure re-pin left to welder (per brief). Zero edits to KeyHelpTest.

## NIT (builder gate figures) — recorded, no action
REPORT's 446T/8057A union figure remains non-reproducible; REVIEW-1's 427T/7991A is the measured shape (fix-pass union below differs only by filter membership).

## Gates at fix tip (cwd=candy-code -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'FirstRunWizard'` → Tests: 39, Assertions: 135, OK, 0F (38 + new pin).
- `--filter 'FirstRunWizard|KeyBindingDrift|KeyBindingRegistry|CommandKeybindingDispatch|ReadmeRosterDrift|DocFigureProseDrift'` → Tests: 426, Assertions: 7869, OK, 0F (2 baseline Deprecations).
- php -l both touched files clean. No src-web change → no vitest leg, dist untouched (welder duty).
