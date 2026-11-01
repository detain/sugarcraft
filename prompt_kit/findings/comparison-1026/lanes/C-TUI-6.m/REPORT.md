# C-TUI-6.m — TUI push-to-talk wiring

STATUS: DONE-BUILD. One pick `1de1fe7bf` on cc/C-TUI-6.m (base b539be830). Tree clean at tip; all targeted gates green (foreign roster reds unchanged). Not pushed. origin/master moved to 7e042da7b after my cut — pick on top at weld.

## Design conformance (design-tui.md:1093, verbatim block)
1. Alt+V starts recording via arm BEFORE generic Char fallthrough: `HandlesKeys.php:1038-1039` (arm) < `:1137` (DRAFT_KEYS delegateToInput) — match reached only after all modal/popup surfaces above returned ⇒ Alt+V inert while palette/modal open (by construction + exec-pinned).
2. Alt+V again stops → `toggleVoiceTake()` `:1220` → `VoiceSession::stop()` → returns `TranscribeCmd::cmd($take)` as the deferred Msg cmd. Latch-busy edge (cmd null while a prior take inFlight): the fresh take is discarded via the engine's `AudioRecorder::cancel()` (no WAV strand) + `chat.voice.busy` warning toast.
3. Esc while recording CANCELS + deletes WAV, checked BEFORE soft-cancel arms: `HandlesKeys.php:418-419` (arm) < `:424` (soft-cancel family). Cancels via engine `VoiceSession::cancel()` (teardown + unlink). No lastEscapeAt stamp written (assertSame($chat,$next) pinned).
4. Voice off ⇒ toast names `voice.baseUrl`: refusal reuses welded `media.voice.off` verbatim ("Voice input is off: set `voice.baseUrl` (user tier) first.") — no new off-toast keys minted.
5. `VoiceTranscribedMsg` inserts at caret via `insertString()` beside the ClipboardImagePastedMsg arm: `HandlesSessionSurface.php:331` (never sends; history untouched — pinned). `VoiceFailedMsg` raises `Message::notice` at `:338`.
6. `renderStatusBar` (`Renderer.php:2430-2434`) paints static `Lang::t('chat.voice.recording')` = "● rec · Alt+V stop · Esc cancel" while recording, else "transcribing…" while `TranscribeCmd::inFlight()`; no elapsed clock, no new tick/timer (passive read; E646 clean). Idle bar byte-identical (247T/25939A status-bar family green).
7. Registry row `chat.voice` `Alt+V` CONTEXT_CHAT beside `chat.plan-mode`: `KeyBindingRegistry.php:1125`; user override rides the existing `keybindings` setting (no new machinery).

## Files (11, +477/-8)
src: KeyBindingRegistry.php, Chat/Handlers/HandlesKeys.php, Chat/Handlers/HandlesSessionSurface.php, Renderer.php (renderStatusBar hint slot only); lang/en.php (4 keys: keys.chat.voice reworded per no-chord-naming guard, chat.voice.busy/recording/transcribing — sorted spans re-proved, text-level dupscan clean); docs: README.md ### Keys row, docs/MEDIA.md push-to-talk paragraph; guards: KeyBindingRegistryTest (174→175 live count + carry-on note), KeyBindingDriftTest (chat.voice observation closure pressing Alt+V), RouteTraitParityTest (VoiceFailedMsg/VoiceTranscribedMsg roster rows); NEW tests/Chat/VoicePushToTalkTest.php.

## Voice-core zero-diff proof
`git diff --name-only b539be830..HEAD -- candy-code/src/Media/Voice` → 0 lines at tip. Engine called, never edited.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- (KeyBindingDriftTest|KeyBindingRegistryTest|RouteTraitParityTest|VoicePushToTalkTest):: → Tests: 291, Assertions: 3548, 0F (first run 1F = drift guard naming Alt+V in label ⇒ reworded in-step).
- VoicePushToTalkTest:: → Tests: 9, Assertions: 39 green. All 9 pins EXEC (incl. statusbar paint via ReflectionMethod invoke + palette-inert with probe-skip guard).
- (CliLangCatalogueTest|LangParityTest):: → 15T/4260A green.
- BARE TreeWideGuardRosterTest → 17T green (no roster row needed: test walks no dirs). BARE DuplicatedTestHelperDriftTest → 14T green (peekToast byte-identical to VerboseToggleKeyTest's).
- ReadmeRosterDriftTest:: → 10T/78A green.
- Status-bar family (StatusBarModelAndReadOnly|StatusBarSpend|StatusLineSegment|HostedFrameReadsThePane|FrameHeightFloor|PaneWidthInvariant):: → 247T/25939A green (idle bytes unchanged).
- Pairing (BashSandboxTest|BubblewrapHardeningTest):: → 39T green. BootstrapLayeredSettingsTest:: → 66T/137A green.
- FOREIGN (cure-NONE, verified non-worsened): KeyHelpTest 5F+1 risky — identical red shape at base; `chat.voice` appears 0 times in its full output (my label fits the 49-col room, absent from overflow roster). MenuBarLabelDerivationTest 1F testEverySlashOnlyRowReadsAsThisTable — the base-inherited :111 red, not worsened. Every suite reports 2 pre-existing PHPUnit Deprecations.

## §7 durations (lead/gate duty)
NEW candy-code test file: tests/Chat/VoicePushToTalkTest.php — needs scripts/parallel-tests-durations.tsv row (9T live; suite-figure.json re-pin +1T ⇒ live counts: VoicePushToTalkTest 9T/39A; KeyBindingRegistryTest count flip in-file; KeyBindingDriftTest +1 test method live; RouteTraitParityTest roster rows ride existing providers).

## Deviations / adjudications
- keys.chat.voice label avoids naming its own chord (KeyBindingDriftTest::testNoDescriptionNamesAKeyOutsideTheOrForm is load-bearing; "press again to transcribe" instead).
- Added chat.voice.busy (inside design's `chat.voice.*` span): prevents stranding the stopped-but-latched WAV; cancel rides the welded engine surface.
- Toast for voice-off reuses welded `media.voice.off` verbatim (design (4) wording is satisfied by it; no duplicate keys).
- No web.*/protocol/dist touches: trio + dist + mirrors name-diff proof = zero files under docs/protocol/, src-web/, candy-code-web/ in the pick.
- No seam in src/Media/Voice needed. REDIRECT-GUARD: no believed-but-absent instruction encountered.
