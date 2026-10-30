# C-TUI-3.d — Message-picker wiring (idle Esc Esc) — DONE

Branch `cc/C-TUI-3.d` cut at 6fb38eef4. Two commits, both cat-file-verified on disk:
- `fa3487bb2` C-TUI-3.d: idle Escape two-beat arms and emits OpenMessagePickerMsg — src/Chat/Handlers/HandlesKeys.php +17/−1, src/Chat.php +21/−3, tests/Chat/EscapeSoftCancelTest.php +97/−1
- `8e1ed751d` C-TUI-3.d: wire the idle Esc Esc message picker through the shell — README.md +7/−3, lang/en.php +6/0, src/App/App.php +149/−1, src/Commands/KeyBindingRegistry.php +27/0, src/Tui/Renderer.php +64/0, tests/App/MessagePickerWiringTest.php +299/0 (NEW), tests/Commands/KeyBindingDriftTest.php +64/−8, tests/Commands/KeyBindingRegistryTest.php +13/−4
- This REPORT rides as the lane's third commit (this file's own SHA is the tip reported back); cherry-pick range for the welder is `6fb38eef4..cc/C-TUI-3.d` — state ranges base..tip (exclusive-start), prove with `git rev-list --count` (= 3). Main-tree master moved to ad60f7a3f mid-run (other lanes' welds — branch ancestry vs the cut is what the welder cherry-picks; never rebased here).

## What landed
Idle `Esc Esc` (both presses idle: no turn in flight, empty draft, no modal, within `doubleEscapeWindowSeconds()`) stamps then sends `Cmd::send(new OpenMessagePickerMsg())`; App's dial arm reads `EnhancedSessionStore::listCheckpoints()` prompts newest-first into the new `?MessagePicker $messagePicker` shell slot (property/open/close/drive/apply/action/yields/disarm/mutate-carrier/update-arm/wheel-inert/modal-gate, mirroring C-TUI-2.e's diffReview shape — the two slots coexist, diffReview wins the render/gate chain if both were ever set). Enter → `Chat::rewindToCheckpoint(int)` (NEW public, added beside `handleRewindCommand`, runs `RewindCommand` with `/rewind N --chat`; RestoreCheckpoint seeds the draft — pinned). Esc closes; keys/wheel never leak to the composer. Registry: `chat.message-picker` 'Esc Esc' row + CONTEXT_MSG_PICKER + `msgpicker.{move,accept,close,erase}` rows; en.php `keys.chat.message-picker`, `keys.context.msgpicker`, `keys.msgpicker.*`; README Keys-table row, double-`Esc` fine print, and Checkpoints section all carry the idle twin. Straddling guard is structural: idle stamps are stored NEGATIVE (`-$now`), in-flight stamps positive, so a press during a turn can never pair with an idle press and vice versa — the in-flight cancel arm is byte-preserved.

## Deviations (disclosed)
1. Negative-microtime sign encoding instead of a new Chat property — serialized law keeps Chat.php at `rewindToCheckpoint` + the `lastEscapeAt` ctor-docblock truth-flip only (§1.8 doc moved in-step with behavior).
2. `OpenMessagePickerMsg.php` pre-landed from C-TUI-3.c — untouched at tip (zero diff; one accidental pre-commit overwrite was reverted via git checkout before any commit).
3. TabScoped parked-claim list NOT extended (matches the landed OpenDiffReviewMsg precedent; parked-tab message no-ops).
4. No Space/typing registry rows — every typed char filters but isn't a chord (docblock note in `messagePicker()`).
5. `keys.chat.message-picker` shortened to 43 cols to avoid the KeyHelp >49-col long-description adjudication roster.
6. KeyBindingRegistryTest foreign-red repair rider folded into S2: the file was ALREADY red at base 6fb38eef4 (C-TUI-2.e shipped 7 diffreview rows without carrying it: allowlist lacked CONTEXT_DIFF_REVIEW; shape pin 156/156/0/15 vs live 163/16). Fixed in-step with a docblock-history paragraph; now 168/168/0/17. Spawn-law: my new rows legitimately changed footer/roster pins — disclosed.

## Gates (plain-pipe, cwd=candy-code -c phpunit.xml, PtyRunTool expect-miss never matched)
- MessagePickerWiringTest (NEW) + EscapeSoftCancelTest + KeyBindingRegistryTest + RegistryTranslationTest + ReadmeRosterDriftTest + DiffReviewWiringTest: **OK 86 tests / 2571 assertions**.
- KeyBindingDriftTest (carries all 5 new observation legs + sequence-roster + arrow-count flips): **OK 238 / 1958**.
- KeyBindingDriftTest|HistorySearchTest|MentionFilePopupTest|ComposerVimModeTest: **OK 284 / 2186** (2 pre-existing foreign PHPUnit deprecations).
- php -l: every touched file CLEAN.
- KeyHelpTest --filter: 63T, **5F+1R — foreign standing red family, unchanged in shape by this lane** (slash-help '/skills' hint staleness, 176-vs-156 + 63-vs-35 footer counts, 3 foreign ids chat.agent-cycle/chat.session-cycle/chat.session-cycle-prev in the scroll roster — MY ids absent after shortening, ellipsis 0>0, 60s RISKY abort). Listed, not chased.

## DUR-ready (§7/welder duty — lane never touched suite-figure.json/durations.tsv)
- NEW test file for durations.tsv: `tests/App/MessagePickerWiringTest.php` — 11 test methods / 106 assertions, wall ~7.5s measured.
- Suite live-enumeration delta: +17T (6 new EscapeSoftCancelTest methods + 11 new-file methods). EscapeSoftCancelTest now 15T/42A (modified file — no durations row owed).
