# C-TUI-3.d — round 1 review (effort M, idle Esc Esc message-picker wiring)

Lane: branch cc/C-TUI-3.d @ /home/sites/cc-cmp-C-TUI-3.d atop base 6fb38eef4.
Commits (cat-file + ancestry verified, both A=C=detain@interserver.net, zero trailers):
- fa3487bb2 Chat half: HandlesKeys idle two-beat +17/−1, Chat.php +21/−3 (rewindToCheckpoint + lastEscapeAt docblock), EscapeSoftCancelTest +97/−1 (6 new methods)
- 8e1ed751d shell half: App.php +149, Tui/Renderer.php +64, KeyBindingRegistry.php +27, en.php +6, README.md +7/−3, MessagePickerWiringTest.php +299 (NEW, 11T), KeyBindingDriftTest +64/−8, KeyBindingRegistryTest +13/−4
`git rev-list --count 6fb38eef4..cc/C-TUI-3.d` = 2. NOTE for the lead: the spawn-brief said "EXACTLY 1 commit" — the builder landed the second commit (8e1ed751d) DURING this review (12:59), resolving the mid-review state (see Resolved section); pick range must be 6fb38eef4..8e1ed751d = 2 commits.

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR. Gates below measured against the tree identical to commit (`git diff HEAD` empty, byte-proven).

## Design-block conformance (read verbatim; owner ruling idle-Esc-Esc applied)
- Idle arm stamps lastEscapeAt NEGATIVE; second idle press inside doubleEscapeWindowSeconds() + empty trim(draft) + no Chat-modal (palette/sessionPicker/dirPicker/historySearch null, outer arm) → Cmd::send(new OpenMessagePickerMsg()). In-flight soft/hard cancel byte-preserved; straddle guarded structurally by the sign. Design asked "empty draft and no modal" ✓; App-modal equivalence holds because App gates keys away from Chat while a slot holds the band.
- files.create: none honored — OpenMessagePickerMsg/MessagePicker/MessagePickerAction all pre-landed from C-TUI-3.c at master (git show master: blob verified; lane zero-diff on them).
- App.php slot + arm + routing + yields ✓; Renderer renderView chain + renderMessagePicker ✓; Chat rewindToCheckpoint beside handleRewindCommand, zero reordering ✓; registry chat.message-picker + msgpicker.* ctx ✓; en.php 6 keys alphabetical ✓; README Keys row + Checkpoints section ✓; both declared test files ✓ (all four design pins present + 7 extras).

## Slot-chain consistency with C-TUI-2.e (both slots coexist)
diffReview gates/renders FIRST everywhere (renderView chain, keyboard gate :4487-before-:4494, mouse, tool-walk-chord guard now also carries messagePicker===null); coexistence pinned by testThePickerPaintsOverTheTranscriptLikeTheOtherSlots; picker keys consumed via driveMessagePicker, messagePickerYields delegates settingsEditorYields (law-consistent); wheel/clicks inert over picker (pinned); mutate() carries the slot; Chat-side Esc-Esc unreachable while any App modal holds keys, so no misfire path. renderMessagePicker mirrors renderDiffReview (composeBand/padBand, Veil withBackdrop(50) CENTER, clearZones + $lastDockFrame/$scrollRegions resets, scanChrome []).

## Vacuity mutation-reason (sign law discriminators, committed tests)
neuter `$this->lastEscapeAt < 0` → testADoubleTapStraddlingATurnEndNeverOpens reds; neuter draft check → testADoubleTapWithATypedDraftDoesNotOpen; neuter stale-window check → testAStaleIdleStampOnlyReArms; make idle stamp invisible to in-flight pairing → testAnIdleStampNeverArmsTheInFlightCancelWindow (soft-vs-hard) reds; dial/accept/store chain pinned end-to-end (checkpointed repo trait, inputBuf seeding, 1-based stepsBack incl deeper row). All four Chat-state arms ride the COMMITTED fa3487bb2 test file.

## Gates measured (plain pipe, cwd=candy-code, env -u LINES -u COLUMNS)
- MessagePickerWiringTest 11T/106A OK; EscapeSoftCancelTest 15T/42A OK (union 26T/148A).
- KeyBindingDriftTest|KeyBindingRegistryTest 271T/3340A OK on disk — includes the lane's IN-STEP cure of the foreign base-red (RegistryTest shape 156-vs-163/15-vs-16 since the C-TUI-2.e weld; now 168/168/0/17 with CONTEXT allowlist rows for both new contexts + sequence-roster row + 4 msgpicker observations + arrow recount 8→9/24→25). §1.8 answer to the hunt list: drift/guard tests ARE present; without 8e1ed751d the pick would ship both the inert feature AND leave the foreign-red uncarried.
- ReadmeRosterDrift 10T/78A OK; DuplicatedTestHelperDriftTest 14T/203A OK ("zero new duplication" TRUE).
- StatusLine 101T/4385A OK (unregressed). Overlay|DiffReview 85T/1F — the 1F is CommandKeybindingDispatchTest:222 KeyHelp overlay scroll, REPRODUCED IDENTICAL in main-tree master ad60f7a3f (no lane code) = the rostered KeyHelp 5F family; lane disclosed it and shortened keys.chat.message-picker to 43 cols precisely so no NEW roster adjudication is minted.
- Foreign reds NOT in the spawn-brief roster, proven pre-existing vs the lane base: TreeWideGuardRosterTest 2F (Cli/ServeArgsTest+NonInteractiveSessionPersistenceTest+RestrictedSafeModeFlagsTest unclassified walkers; last source touch 6485cb636 = ANCESTOR of 6fb38eef4, lane files untouched, main tree master shows same 2F). Recommend lead add to standing roster: KeyBindingRegistryTest (CURED by this lane — a weld bonus), TreeWideGuardRoster Cli-trio, CommandKeybindingDispatch KeyHelp 1F.
- Zero new getenv: git show fa3487bb2 | grep -c getenv = 0; git diff/diff-HEAD on shell commit likewise 0. No forbidden files in either commit (no suite-figure.json / durations.tsv / dist).

## Resolved mid-review (audit trail)
At review start only fa3487bb2 was committed; the shell half + wiring test sat UNCOMMITTED (MAJOR-1 drafted: pick range would ship an inert feature — Chat emits OpenMessagePickerMsg into App's :3357 delegateToChat default arm, nothing opens, no guard reddens). 12:59 the builder landed 8e1ed751d + REPORT.md (4716B) and the porcelain went clean. The spawn-brief's figures ("OpenMessagePickerMsg.php new + KeyPressHandler::messagePickerTwoBeat() process static", "14T", "TreeWideGuardRoster 9T/10619A green") match NEITHER disk nor the builder's own REPORT.md — those were brief-relay distortions, not builder misreporting; REPORT.md states 2 commits, 11T/106A, the negative-microtime sign encoding, and the TGR/KeyHelp families honestly.

## CARRIES for weld
1. Pick 6fb38eef4..8e1ed751d EXACTLY 2 commits (range exclusive-start law; prove count via git rev-list --count vs the LOCAL master pre-push).
2. DUR MINT: tests/App/MessagePickerWiringTest.php durations row — 11 cases, median-of-3 junit sum at weld (builder wall ~7.5s).
3. Suite live-enum delta +17T (+11 new file, +6 EscapeSoftCancel) — staleness-pair cure is closeout duty.
4. §4 mark anchor: LAST CONTENT pick = 8e1ed751d; evidence commit names REVIEW-1.md; collect worktree REPORT.md (4716B) + this REVIEW pre-prune; branch+worktree prune after git-cherry proof.
5. This weld removes a standing red (KeyBindingRegistryTest shape) — update the run-state roster accordingly.
