# C-TUI-2.e — REVIEW round 1 (reviewer lane, 2026-10-30)

VERDICT: FINDINGS — 0 CRITICAL / 2 MAJOR / 0 minor step-local / 2 NIT.

Lane: branch cc/C-TUI-2.e @ 9643543f8 atop 1e40e6df7 atop 80fe2275b (both SHAs cat-file-verified: commit objects, subjects match the brief). Authors: `git log --format='%ae %ce'` → both fields detain@interserver.net on both commits; trailers key=Co-Authored-By → empty. PASS.

## Gate results (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, linked vendor in worktree)
- KeyBindingDriftTest: 233T/1917A OK (deprecations 2 = house baseline).
- CommandDocsDriftTest: 6T/11A OK. ReadmeRosterDriftTest: 10T/78A OK.
- DocFigureProseDriftTest: 92T/4157A OK. GlobDialectDifferentialTest: 13T/366A OK. DuplicatedTestHelperDriftTest: 14T/203A OK.
- DiffReviewWiringTest: 9T/87A OK **on disk** (contains an UNCOMMITTED one-line fix; committed blob is red — MAJOR-1).
- UndoRedoDiffCommandsTest: 10T/97A OK **on disk** (contains UNCOMMITTED --print edits; committed blob is red — MAJOR-2).

## Uncommitted porcelain (disclosed, NOT judged as lane content, but it proves the two MAJORs)
`git status` in worktree: M tests/App/DiffReviewWiringTest.php (1 line), M tests/Chat/UndoRedoDiffCommandsTest.php (16 lines), ?? prompt_kit/.../lanes/C-TUI-2.e/ and ?? prompt_kit/.../plan_comparison.md (prompt_kit — out of scope, not staged). The two M files are fixes-for-the-red-committed-tests that the lead's 9643543f8 snapshot missed (brief says the lead committed the builder's in-flight work — the fixes were made after the snapshot).

## FINDINGS

### MAJOR-1 — committed tests/App/DiffReviewWiringTest.php:145 can never pass
Committed line: `$this->assertSame($wheeled, $clicked, 'the band is keyboard-only: clicks are inert');`
Trace (committed content): `$wheeled = $app->update(MouseWheelMsg)` → App.php diffReview wheel arm (:3441-3452) → `driveDiffReview` → `mutate(diffReview:)` → a NEW App clone. `$clicked = $app->update(MouseClickMsg)` → same arm, `!$msg instanceof MouseWheelMsg` branch (:3442-3443) returns `[$this, null]` → `$clicked === $app !== $wheeled` → assertSame RED. (Even any-clone-normalizing would still be red: two different fresh clones.) The uncommitted worktree line `$this->assertSame($app->diffReview, $clicked->diffReview, ...)` is the correct identity-free form and measures green.
FIX: `git commit` the worktree version of candy-code/tests/App/DiffReviewWiringTest.php as a lane rider (content already gate-verified 9T/87A OK).

### MAJOR-2 — committed tests/Chat/UndoRedoDiffCommandsTest.php is red; design's required host-test flip never landed
Design block (files/DoD + tests bullet) required the bare-`/diff` host rows flipped to `--print`. Committed file still sends bare `/diff` in diffShowsWhatChangedSinceCheckpoint (:129/:138), the no-changes + truncation + escape/control cases and the Enter-submit case (:168-171). Its fixture chat is CheckpointedRepoTrait::chatAt with `sessionStore: new EnhancedSessionStore(...)`, `currentSessionId: 'undo-session'` (trait :59/:101-102) — exactly the triple that opens the new overlay door in Chat::handleDiffCommand, so bare `/diff` now answers `Cmd::send(OpenDiffReviewMsg)` with NO transcript rows → the `assertStringStartsWith('1 file changed since checkpoint 1', ...)` and sibling legs FAIL. The 16-line uncommitted worktree fix (`/diff` → `/diff --print` in the five subjects + docblock truth-flip naming DiffReviewWiringTest for the bare form) measures green 10T/97A.
FIX: commit the worktree version of candy-code/tests/Chat/UndoRedoDiffCommandsTest.php alongside MAJOR-1's file. Without either, the weld ships two red files.

## Known-findings adjudication (against the COMMITTED blobs)
- "KeyBindingDriftTest ~8 RED legs (:531/:436/:696/:201/:222/:252/:263/:368/:379)": OBSOLETE. 9643543f8 carries +113 lines of drift-test updates (context allowlist row for Diff review, the 23→24 arrow-count flip with docblock, seven diffreview.* observation legs + diffReviewApp()/diffReviewFiles() fixtures). Measured 233T OK. Builder's "guards green" is TRUE for KeyBindingDrift, FALSE for the two test files above (self-certified — contradicted).
- "DiffReviewWiringTest:47 expects ToggleOverlayMsg": OBSOLETE at commit. `ToggleOverlayMsg` exists nowhere in candy-code/src or the committed test (grep whole word, src+tests: zero); the committed test asserts OpenDiffReviewMsg at every door leg — matching design. No action.

## Design fidelity + reachability trace (all PASS)
- `Chat::handleDiffCommand` (Chat.php:16928) overlay door: no --print + (no arg | one digit ≥1) + EnhancedSessionStore + non-empty session id + DiffReviewSource::load returns DiffReview → `[$this->withInputBuf(''), Cmd::send(new OpenDiffReviewMsg($review))]`; every refusal (flag present, usage noise, plain store, load reason) falls through to `runHostCommand(DiffCommand)` — design's openSettingsView() technique. Handed the review model (not raw $n as the design sentence sketched) — benign: load-failure fallback needs the result, and App::openDiffReview docblock states it.
- Typed route: builtin-commands/2830-diff.php (exists; hint via cmd.diff.hint `[n] [--print]`, paletteAction ShowDiff) → Chat::dispatchCommand table-driven `$this->{$handler}($text)` (:11967-11971) → handleDiffCommand. Palette route: dispatchPaletteCommand (:18979) → '/diff' → same funnel — the design's flagged RISK (palette ShowDiff still routes) CONFIRMED intact.
- Serve route: server-side command.exec → CommandMethods::exec → runBuiltIn($host,...) → Host\Commands\DiffCommand directly — never enters Chat's overlay door; commit 1 makes DiffCommand accept-and-ignore `--print` (word-filter, count>1 → usage, order-agnostic) — "served sessions still print" holds; the 9643543f8 en hint + COMMANDS regen ride commit 1e40e6df7, gen-consistent (CommandDocsDriftTest green).
- App wiring: `?DiffReview $diffReview = null` promoted ctor slot + mutate carry (:6549); openDiffReview (closes menu, takes slot) / closeDiffReview (idempotent) / withDiffReview; OpenDiffReviewMsg arm (:3182-3192) — null review + reason → withStatus, empty reason → strict no-op; keys: modal gate (:4346-4361) after the player drive with diffReviewYields()===settingsEditorYields() (one full-band-modal law); wheel beside the media-form arm (:3436-3453) paging via synthetic PgUp/PgDn keys, clicks inert; Ctrl+O/Ctrl+F walk gates gain `diffReview === null` (:5313/:5323); Esc → model Close action → applyDiffReviewAction → closeDiffReview — teardown restores the band (pinned testTheShellOpensTheSlotRoutesKeysAndCloses).
- Tui/Renderer: slot-chain arm after mediaForm, before videoPlayer (:692-701, comment states the ordering intent); renderDiffReview full-band compose (title/rule/file-list+patch/grid/notice/hints, 50-backdrop Veil, clipWidth/clipTail/scanChrome on the mediaForm law); untrusted text via Sanitize::stripZoneSentinels+untrustedForDisplay (both exist in sugar-core :314); diff vocabulary copied from C-TUI-2.c per the file-ownership note (LiveRenderer diff helpers private; documented WHY, satisfies the "reuses 2.c split/highlight" intent within ownership — single-file unified column is the review's shape, side-by-side elision documented).
- KeyBindingRegistry: CONTEXT_DIFF_REVIEW constant, allBindings() + contextLabel() rows, diffReview() 7 rows (file-move/body-page/body-ends/base-back/base-forward/turn/close); lang/en.php keys.context.diffreview + 7 keys.diffreview.*. README: task-table row + Checkpoints prose rewritten to the actual key set; both drift guards green.
- Hand-trace normal: `/diff` on 2-checkpoint repo → steps=1 → load(file list) → slot opens, ↑/↓ clamps, - redials store stepsBack=2 (testTheBaseKeyRedialsTheStoreForTheNewWindow pins the real-store redial). Edge: `/diff 0`/`/diff last`/`/diff 2 3` → door closed → host usage rows; no-checkpoint window → load reason → rows path (testANoCheckpoints + OpenDiffReviewMsg(null,reason) footer leg for mid-session move).

## Design-key deviation (disclosed, not a finding)
Design "what the user sees" sentence names `Enter` opens / `←`/`→` cycle base; the WIRED key set is the one the already-welded C-TUI-2.c `DiffReview::update()` actually answers (↑↓/kj, PgUp/PgDn, Home/End, -/+/=, t, Esc). Binding Enter/arrows would mean editing the 2.c model — outside this lane's file set. README/en/registry/tests are all internally consistent with the real key set; if the owner wants the design's Enter/←→ surface, it is a follow-up step on C-TUI-2.c scope, not a fix for this lane's fixer.

## Vacuity hunt (PASS)
DiffReviewWiringTest mutations-reason: door-removal reds tests 1/3/7/8; --print-strip removal reds test 2; close-arm removal reds test 6; redial (dialDiffReview) neuter reds test 8 (stepsBack/fileCount from real store); reason-arm removal reds test 9. KeyBindingDriftTest diffreview legs assert model clamps/counts on a synthetic fixture (no global-state reliance); fixture literals are not glob-shaped (GlobDialect green). No fabricated success paths; no hardcoded replies.

## NITs
- NIT-1 (step-local): the lane tip leaves no scripts/parallel-tests-durations.tsv row for NEW tests/App/DiffReviewWiringTest.php and no suite-figure re-pin — per plan these are weld/closeout duties (disclose in weld brief; the file would run in zero shards otherwise).
- NIT-2 (out-of-scope): Chat.php:16929-16959 and App::dialDiffReview use inline fully-qualified `\SugarCraft\Code\Tui\DiffReview\...` / `\SugarCraft\Code\Session\EnhancedSessionStore` spellings where the house pattern is `use` imports; matches some neighbors, cosmetic only.

## Verdict
FINDINGS: MAJOR-1 + MAJOR-2 — both fixed by committing the two worktree-modified test files byte-as-they-stand (contents independently gate-green: DiffReviewWiringTest 9T/87A, UndoRedoDiffCommandsTest 10T/97A). Wiring, renderer, registry, lang, README and both commit-1 files are sound and design-faithful; all other listed known findings are OBSOLETE at the committed tip. One fixer round on the two test files → round-2 expect CLEAN.
