# Design — TUI & UX (C-TUI-1 … C-TUI-7)

Scope: the owner's picks C-TUI-1, C-TUI-2, C-TUI-3, C-TUI-4, C-TUI-5, C-TUI-6 (LATE) and C-TUI-7. Paths were verified against the live tree at HEAD `5e9195687` (2026-10-26). Every MODIFY path exists; every CREATE path was checked absent.

**Design choice that shapes most wiring steps.** Another designer owns session tabs, the agent attach window, the context panel and the image menu, and those touch the `Chat.php` view/layout regions heavily. So the new full-band surfaces here (diff review, message picker, first-run wizard) ride the **App shell-slot route** that `SettingsEditor`, `MediaForm` and `VideoPlayerScreen` already use: a slot property on `src/App/App.php`, an `Open…Msg`, key routing beside `settingsEditor`/`mediaForm` (App.php ~L3538-3581), and a render arm in `src/Tui/Renderer.php::renderView()` (~L642-660). That route does not use the HistorySearch-style Chat modal, which needs a constructor property, a mutate key, and about six modal gates in Chat.php. Each wiring step's Chat.php touch is one named method. The cost is that `App.php` and `Tui/Renderer.php::renderView()` become this area's hot files (see Notes).

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-TUI-1.a | Vim text objects, `c` and counts in ComposerVim | M | [lang:settings.vimMode.] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] | none |
| C-TUI-1.b | Line-wise `V` + VisualLine j/k flip (R4) | M | [lang:settings.vimMode.] | C-TUI-1.a |
| C-TUI-2.a | Highlighter language breadth + path→language | M | none | none |
| C-TUI-2.b | sugar-diff side-by-side writer | S | none | none |
| C-TUI-2.c | TUI split diffs, highlighted diff bodies, `diffStyle` | M | [renderer:Renderer::renderDiff] [lang:settings.diffStyle.] [docs:SETTINGS.md] [docs:README.md] [hot:InterfaceSettings.php] [hot:LayeredSettings.php] [hot:UiSettings.php] | C-TUI-2.a, C-TUI-2.b |
| C-TUI-2.d | Diff-review core (model, per-file stats, per-turn diff) | M | [lang:tui.diffreview.] | none |
| C-TUI-2.e | Diff-review wiring (App slot, `/diff`, keys) | M | [chat:handleDiffCommand] [renderer:Tui/Renderer::renderView] [hot:App.php] [hot:KeyBindingRegistry.php] [lang:keys.diffreview.] [docs:README.md] [docs:COMMANDS.md] | C-TUI-2.c, C-TUI-2.d |
| C-TUI-2.f | Web split + highlighted DiffView | S | [hot:DiffView.vue] [hot:stores/settings.ts] | C-SEC-2 |
| C-TUI-2.g | Web checkpoint base selector (`files.diff` checkpoint) | M | [protocol] [docs:SERVER.md] | none |
| C-TUI-3.a | `/copy [n]` in the TUI | M | [chat:handleCopyCommand, READ_ONLY_COMMANDS] [commands] [lang:cmd.copy., chat.copy.] [docs:README.md] [docs:COMMANDS.md] | none |
| C-TUI-3.b | Web copy buttons, "Rewind to here", "Edit & resend" | M | [hot:TranscriptItem.vue] [hot:stores/session.ts] [hot:views/SessionView.vue] | none |
| C-TUI-3.c | Message-picker core | S | [lang:tui.msgpicker.] | none |
| C-TUI-3.d | Message-picker wiring (idle Esc Esc) | M | [chat:rewindToCheckpoint] [renderer:Tui/Renderer::renderView] [hot:App.php] [hot:HandlesKeys.php] [hot:KeyBindingRegistry.php] [lang:keys.] [docs:README.md] | C-TUI-3.c |
| C-TUI-3.e | One-key verbose toggle (TUI) | S | [hot:HandlesKeys.php] [hot:KeyBindingRegistry.php] [lang:keys.chat.verbose-toggle] [docs:README.md] | none |
| C-TUI-3.f | Recap core (`RecapHostCommand`) | S | [lang:host.recap.] | none |
| C-TUI-3.g | Away recap wiring + `/recap` | M | [chat:programOptions, handleRecapCommand, READ_ONLY_COMMANDS] [commands] [hot:HandlesRunControl.php] [hot:InterfaceSettings.php] [hot:LayeredSettings.php] [hot:UiSettings.php] [lang:cmd.recap., settings.sessions.awayRecap.] [docs:README.md] [docs:COMMANDS.md] [docs:SETTINGS.md] | C-TUI-3.f |
| C-TUI-4.a | Custom TUI themes from `~/.candy-code/themes/` | M | [hot:InterfaceSettings.php] [lang:settings.theme., chat.theme.] [docs:SETTINGS.md] [docs:README.md] | none |
| C-TUI-4.b | Web dracula + tokyoNight theme sets | S | [hot:stores/settings.ts] [hot:styles/tokens.css] | none |
| C-TUI-4.c | `statusLine` session JSON on stdin | M | [hot:HandlesRunControl.php] [lang:settings.statusLine.] [docs:SETTINGS.md] | none |
| C-TUI-5.a | `/doctor` host command + AppArmor userns probe | M | [headless] [chat:handleDoctorCommand, READ_ONLY_COMMANDS] [commands] [lang:cmd.doctor., host.doctor., cli.doctor.] [docs:README.md] [docs:COMMANDS.md] [docs:TROUBLESHOOTING.md] | none |
| C-TUI-5.b | `candy-code import claude` | M | [headless] [lang:cli.sub.import.] [docs:README.md] [docs:PERMISSIONS.md] | C-TUI-5.d |
| C-TUI-5.c | Settings hot reload + `/settings reload` | M | [chat:handleSettingsCommand] [hot:HandlesTurnCompletion.php] [lang:cmd.settings., chat.settings.reload.] [docs:SETTINGS.md] [docs:COMMANDS.md] [docs:README.md] | none |
| C-TUI-5.d | First-run wizard core + `ProviderKeyStore` | M | [lang:tui.wizard.] | none |
| C-TUI-5.e | First-run wizard wiring (App slot, web notice) | M | [chat:switchProvider] [renderer:Tui/Renderer::renderView] [hot:App.php] [hot:KeyBindingRegistry.php] [lang:keys.wizard.] [docs:README.md] | C-TUI-5.d |
| C-TUI-7.a | Startup summary: personal files + gather off `view()` | M | [chat:init, applyCommandResult (ClearTranscript/SwitchSession arms)] [hot:StartupSummary.php] [hot:HandlesRunControl.php] [lang:chat.startup.] | none |
| C-TUI-7.b | Mac Option-as-Meta notice: once per version, tmux-aware | S | [bootstrap:macAltNotice, terminalSpeaksKittyKeyboard, announceMacAltFallback] [lang:cli.notice.mac_alt] | none |

| C-TUI-6.a | Windows process layer: bash quoting, bash resolution, tree kill (LATE) | M | [hot:ProcessContainment.php] [hot:BackgroundShellRegistry.php] [hot:Bash.php] [hot:DescriptorInheritanceGuardTest.php] [hot:ReadPathCensusTest.php] | C-TOOL-4 |
| C-TUI-6.b | sugar-pty ConPTY FFI primitives (LATE) | M | none | none |
| C-TUI-6.c | sugar-pty ConPTY Contract adapters + factory + WINDOWS_LIBS (LATE) | M | [workflows] [hot:scripts/affected-libs.php] [hot:docs/MATCHUPS.md] | C-TUI-6.b |
| C-TUI-6.d | candy-code on Windows: PtyRun/interactive Bash over ConPTY, README banner, CI smoke (LATE) | M | [workflows] [docs:README.md] [docs:SERVER.md] [hot:ProcessContainment.php] [hot:CapturesProcessOutput.php] | C-TUI-6.a, C-TUI-6.c |
| C-TUI-6.e | Windows self-update relaunch swap (LATE) | S | [lang:cli.selfupdate.staged_windows, cli.selfupdate.swap_] [hot:SelfUpdate.php] [hot:DescriptorInheritanceGuardTest.php] [hot:ReadPathCensusTest.php] | C-SES-5, C-TUI-6.a |
| C-TUI-6.k | Web dictation via the Web Speech API (**phase normal**) | S | [hot:Composer.vue] | none |
| C-TUI-6.f | Web i18n infra: `server.strings` + chrome migration (LATE) | M | [protocol] [lang:web.chrome.] [docs:SERVER.md] [hot:App.vue, StatusBar.vue, SidePanels.vue, ConnectionBanner.vue, candy-code-web/package.json, ReadPathCensusTest.php] | none |
| C-TUI-6.g | Web i18n: migrate the remaining 39 components (LATE) | M | [lang:web.] [hot:Composer.vue] [hot:candy-code-web/src-web — the only web lane in its wave] | C-TUI-6.f, C-TUI-6.k |
| C-TUI-6.i | Screen-reader core + `screenReader` setting (LATE) | M | [lang:settings.screenReader.] [docs:SETTINGS.md] [docs:README.md] [docs:ENVIRONMENT.md] [hot:InterfaceSettings.php, LayeredSettings.php, UiSettings.php, AgentLiveRegistry.php] | none |
| C-TUI-6.j | Screen-reader wiring in the renderers + bell cues (LATE) | M | [renderer:overlay chain, border/glyph sites, renderPalette, renderSessionPicker, renderPermissionPrompt] [chat:requestPermission] [lang:tui.sr.] [hot:TerminalNotifier.php] | C-TUI-6.i |
| C-TUI-6.l | Voice core: Transcriber, AudioRecorder, voice settings (LATE) | M | [lang:settings.voice., media.voice.] [docs:SETTINGS.md] [docs:README.md] [docs:MEDIA.md] [hot:MediaSettings.php, LayeredSettings.php, ReadPathCensusTest.php, DescriptorInheritanceGuardTest.php] | none |
| C-TUI-6.m | TUI push-to-talk wiring (LATE) | M | [hot:KeyBindingRegistry.php, HandlesKeys.php, HandlesSessionSurface.php] [renderer:Renderer::renderStatusBar] [lang:keys.chat.voice, chat.voice.] [docs:README.md] [docs:MEDIA.md] | C-TUI-6.l |
| C-TUI-6.n | Web MediaRecorder + `audio.transcribe` + `canTranscribe` flag (LATE) | M | [protocol] [docs:SERVER.md] [docs:MEDIA.md] [lang:web.composer.voice.] [hot:Composer.vue, stores/connection.ts] | C-TUI-6.l, C-TUI-6.k, C-TUI-6.g |
| C-TUI-6.h | Community locales `candy-code/lang/<code>.php` ×11 ruled set (LATE; lands last) | M | [lang:whole file ×11] [docs:README.md] | C-TUI-6.g, C-TUI-6.e, C-TUI-6.j, C-TUI-6.m, C-TUI-6.n (and, under strict parity, every lane that adds lang keys) |

**Totals:** 40 steps: 9 S, 31 M, 0 L. 27 are `phase: normal` (C-TUI-1/2/3/4/5/7 plus C-TUI-6.k) and 13 are `phase: LATE` (the rest of C-TUI-6). No normal step depends on a LATE step.

---

## C-TUI-1 — Vim text objects & counts

### C-TUI-1.a — Vim text objects, `c` and counts in ComposerVim
- item: C-TUI-1 (sources: GAP-UI-07, k3-dorm#2, k3-sum §6.6 vim, p2-miss: TUI-3 counts/`cw`/text objects)
- kind: dormant-wire · effort M
- approach: The sugar-forms text-object machinery is never called from the composer:
  - `VimKeyHandler::handleTextObject()` (`sugar-forms/src/Vim/VimKeyHandler.php:289-304`)
  - `TextObject::isTarget()`/`resolve()` (`sugar-forms/src/Vim/TextObject.php:62,78`)
  - `VimOperator::fromKey()` (`sugar-forms/src/Vim/VimOperator.php:31`)
  - `TextObjectScope::fromKey()` (`sugar-forms/src/Vim/TextObjectScope.php:27`)
  - `VimAction::Delete/Change/YankTextObject` (`sugar-forms/src/Vim/VimAction.php:65-71`)

  Correction to k3-dorm#2: `sugar-readline/src/Mode/ViMode.php:347-386` already consumes them; the composer path does not.

  Extend ComposerVim's pending grammar:
  - After `d`/`y`/`c`, an `i`/`a` arms a `pendingScope`. The target key resolves through `handleTextObject(op, scope, key, $input->lines[$input->row], $input->col)` into the existing `applySpan()`.
  - `c` is the `d` kill, then Insert by feeding the widget `i` (the `openLine()` precedent). Also `cc`, `C`, and `cw` (≡ `ce`, vim's rule).
  - A `count` field accumulates `1-9`, then `0-9`. It multiplies `dd/yy/dj/dk/x/p/P` and repeats delegated motions (`3w`, `d2w`).
  - New `isArmed()`, plus an instance `claimsKey()` that claims digits, `c` and `C`, and claims `0` and the lib motions only while a count is armed.

  sugar-forms is consumed unchanged in this step. What the user sees: with `vimMode` on, `ci"`, `da(`, `diw`, `yi{`, `cw`, `3dd` and `2yy` work in the draft box.
- files.create: none
- files.modify:
  - `candy-code/src/Tui/ComposerVim.php`: state fields; `CLAIMED_RUNES`→`claimsKey()`; `resolve()`; `start()`; the "Deliberately NOT implemented" docblock.
  - `candy-code/src/Chat/Handlers/HandlesKeys.php`, TUI-3A arm (b) ~L343: `pending !== null` → `isArmed()`.
  - The same file, claim arm ~L1047-1055: `ComposerVim::claims()` → `$this->composerVim->claimsKey()`.
  - `candy-code/lang/en.php`: `settings.vimMode.help`.
- tests:
  - Create `candy-code/tests/Tui/ComposerVimTextObjectTest.php`: `ci"`, `da(`, `diw` and `yi{` on, before and outside pairs; the empty object; a beep cancels.
  - Create `candy-code/tests/Tui/ComposerVimCountTest.php`: `3dd`; `2yy` then `p`; `d2w`; `3x`; `0` after a count vs bare `0` (line start); a count then Esc or Tab re-routes.
  - Modify `candy-code/tests/Tui/ComposerVimTest.php`: the claims roster.
  - Modify `candy-code/tests/Chat/ComposerVimModeTest.php`: digits are claimed only in NORMAL with no popup; `c` lands in INSERT; a forgotten count does not hold Tab.
- docs/guards: the `settings.vimMode.help` text only. Vim keys are a mode, not KeyBindingRegistry rows, so README Keys does not move. LangParityTest.
- serialized: [lang:settings.vimMode.] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php (TUI-3A vim arms)]
- deps: none
- phase: normal
- ruling: none
- risks: `0` is both line-start and a count digit, so the claim must key on the armed count or `d0` breaks.

### C-TUI-1.b — Line-wise `V` and the VisualLine j/k flip (R4)
- item: C-TUI-1 (sources: p2-miss: TUI-3/R4 ruling, k3-sum §6.6 vim)
- kind: dormant-wire · effort M
- approach: R4 is the pinned "deliberately NOT flipped" VisualLine mapping: `j→CursorLeft`, `k→CursorRight` (`sugar-forms/src/Vim/VimKeyHandler.php:259-260`). Nothing in the repo enters VisualLine. `TextArea` dispatches the state (`TextArea.php:328`) but never enters it, and `TextInput.php:280` and `sugar-readline/src/Mode/ViMode.php:129,293` use Normal and Visual only. So the flip changes no live consumer.

  On a yes:
  - In VisualLine, map `j→CursorDown`, `k→CursorUp`, `d`/`x`→`DeleteSelection` and `y`→`YankSelection`.
  - Normal-mode `V`, checked case-sensitively before the lowercase, returns `EnterVisualLineMode`.
  - TextArea enters VisualLine with a whole-row selection and operates line-wise.
  - TextInput and ViMode map `EnterVisualLineMode` onto their existing Visual entry, so their `V` is unchanged.
  - ComposerVim: a line-wise visual yank still goes through OSC 52 (the TUI-3A seam). Update its docblock and the help text.

  What the user sees: `Vjjd` deletes three whole lines.
- files.create: none
- files.modify:
  - `sugar-forms/src/Vim/VimKeyHandler.php`: `handleNormalMode` `V`; `handleVisualLineMode`.
  - `sugar-forms/src/TextArea/TextArea.php`: `consumeVimAction` `EnterVisualLineMode`; the VisualLine `vimUpdateViaHandler` branch; line-wise `normalizedSelection`; the `handleEnterVisualMode` docblock.
  - `sugar-forms/src/TextInput/TextInput.php`: action mapping.
  - `sugar-readline/src/Mode/ViMode.php`: action mapping.
  - `sugar-forms/README.md`: vim section L297-308.
  - `candy-code/src/Tui/ComposerVim.php`: docblock.
  - `candy-code/lang/en.php`: `settings.vimMode.help`.
- tests:
  - Modify `sugar-forms/tests/Vim/VimKeyHandlerTest.php`: the L188-212 VisualLine pins flip. `testVisualModeOperatorsAreCtrlSwallowedAndVisualLineStaysPending` now expects `DeleteSelection`/`YankSelection`.
  - Modify `sugar-forms/tests/TextArea/TextAreaVimModeTest.php`: the L430-434 `V` pin becomes line-wise. Add `Vjjd` and `Vky`.
  - Modify `sugar-forms/tests/TextInput/TextInputTest.php`: `V` still opens visual.
  - Modify `sugar-readline/tests/Mode/ViModeTest.php`: `V` unchanged.
  - Modify `candy-code/tests/Chat/ComposerVimModeTest.php`: `V` then `d` kills whole rows.
- docs/guards: the sugar-forms and sugar-readline lib suites; `sugar-forms/README.md`; the candy-code lang help.
- serialized: [lang:settings.vimMode.]
- deps: C-TUI-1.a (both edit ComposerVim and the same help key)
- phase: normal
- ruling: R4: flip VisualLine `j`/`k` to vim's down/up and make `V` line-wise? If unanswered, skip this step; `V` keeps aliasing `v`.
- risks: three libraries move in one commit; an out-of-tree consumer of VisualLine `j`/`k` would see the flip.

## C-TUI-2 — Diff review & highlighting

### C-TUI-2.a — Highlighter language breadth + path→language
- item: C-TUI-2 (sources: GAP-UI-04)
- kind: gap · effort M
- approach: RegexHighlighter ships seven keyword sets: php, js, ts, python, go, bash and sql (`sugar-core/src/Syntax/RegexHighlighter.php:42-96`).
  - Add rust, java, c/cpp, ruby, yaml and html/css, with aliases (`rs`, `cc`/`h`/`hpp`, `rb`, `yml`) and per-language comment and string rules.
  - Add a pure `RegexHighlighter::languageForPath(string $path): ?string` extension map. C-TUI-2.c uses it to highlight diff bodies.
  - sugar-markdown's fence highlighting picks the new languages up through the shared tokenizer. Update its docblock and README language list (`sugar-markdown/README.md:174`).

  What the user sees: rust, java, C, ruby and YAML fences are highlighted.
- files.create: none
- files.modify: `sugar-core/src/Syntax/RegexHighlighter.php` (KEYWORDS, ALIASES, comment rules, `languageForPath`); `sugar-markdown/src/SyntaxHighlighter.php` (docblock roster); `sugar-markdown/README.md` (L174)
- tests:
  - Modify `sugar-core/tests/Syntax/RegexHighlighterTest.php`: each new language tokenizes a keyword, a string and a comment; the `languageForPath` table.
  - Modify `sugar-markdown/tests/SyntaxHighlighterTest.php`: a rust fence is highlighted.
- docs/guards: sugar-core and sugar-markdown suites; `sugar-markdown` ReadmeSnippetsTest
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: the `MAX_TOKENIZE_BYTES` budget is unchanged; the new regexes must stay linear (no catastrophic backtracking on HTML).

### C-TUI-2.b — sugar-diff side-by-side writer
- item: C-TUI-2 (sources: GAP-UI-05)
- kind: gap · effort S
- approach: sugar-diff writes unified output only (`sugar-diff/src/Writer/UnifiedFormat.php`). Add `SideBySideFormat`:
  - `rows(Diff)` and `fromUnified(string)`, the latter over `Reader/UnifiedScan`.
  - Both return paired `[left?, right?]` rows. Each side carries its line number and kind, so a removed/added run pairs line by line and the leftover lines stand alone.
  - It is pure data. Width and paint belong to the caller.
- files.create: `sugar-diff/src/Writer/SideBySideFormat.php`; `sugar-diff/src/Writer/SideBySideRow.php` (one type per file)
- files.modify: `sugar-diff/README.md` (writer list)
- tests: create `sugar-diff/tests/Writer/SideBySideFormatTest.php` (pairing, uneven runs, context passthrough, `fromUnified` parity with `rows()`)
- docs/guards: sugar-diff suite; `tools/check-one-type-per-file.php`
- serialized: none
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

### C-TUI-2.c — TUI split diffs, highlighted diff bodies, `diffStyle`
- item: C-TUI-2 (sources: GAP-UI-05, GAP-UI-04)
- kind: gap · effort M
- approach:
  - Split mode: `Renderer::renderDiff()` (`candy-code/src/Renderer.php` ~L5388-5600) paints unified rows through `DiffGutter::forDiff()`. Add a split layout from `SideBySideFormat::fromUnified()` when the new `diffStyle` setting (`auto|unified|split`, default `auto`) resolves to split. `auto` means split at ≥ 120 cols.
  - Highlighting: tint tokens inside add/remove/context lines by `RegexHighlighter::languageForPath()` of the file header. The marker column keeps its colour.
  - The PR #1403 width invariant holds: each half is clipped to `(cols − gutter)/2` after tab expansion.
  - The setting is cosmetic, project-settable and Live, read through `UiSettings`.

  What the user sees: wide terminals get two-column diffs, and diff bodies are coloured.
- files.create: none
- files.modify:
  - `candy-code/src/Renderer.php`: `renderDiff()`, `styleDiffLine()`, a new `diffStyle()` reader.
  - `candy-code/src/Tui/DiffGutter.php`: per-side gutters.
  - `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`: the `diffStyle` definition.
  - `candy-code/src/Config/LayeredSettings.php`: the LAYERED list (~L600-625).
  - `candy-code/src/Config/Settings/UiSettings.php`: `KEYS`.
  - `candy-code/lang/en.php`: `settings.diffStyle.*`.
  - `candy-code/docs/SETTINGS.md`: regenerate.
  - `candy-code/README.md`: the layered roster, regenerated.
- tests:
  - Create `candy-code/tests/Tui/DiffSplitRenderTest.php`: split at width, no row wider than cols, tabs expanded, highlight spans present.
  - Modify `candy-code/tests/Tui/DiffGutterTest.php`.
- docs/guards: `php tools/gen-settings-doc.php --write`; SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; the DocFigureProseDriftTest settings-count prose; `tests/Renderer/PaneWidthInvariantTest.php` must stay green.
- serialized: [renderer:Renderer::renderDiff/styleDiffLine] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/UiSettings.php] [lang:settings.diffStyle.] [docs:SETTINGS.md] [docs:README.md]
- deps: C-TUI-2.a, C-TUI-2.b
- phase: normal
- ruling: none
- risks: an over-wide row reintroduces the absolute-cursorTo row collision, so the split width math is the one thing to pin.

### C-TUI-2.d — Diff-review core (model, per-file stats, per-turn diff)
- item: C-TUI-2 (sources: GAP-GIT-09)
- kind: gap · effort M
- approach: Today `/diff [n]` prints fenced text (`src/Host/Commands/DiffCommand.php`) from `CheckpointDiff::of()`, which is the checkpoint against now.
  - `CheckpointDiff` gains `files()`: per-file `{status, path, added, removed, patch slice}` parsed from its bounded patch with sugar-diff `UnifiedScan`.
  - It also gains `between()`: checkpoint k against checkpoint k−1, from two `WorkspaceCheckpointer::trees()` targets. That is the per-turn view.
  - A pure `DiffReview` model holds the file list with ±counts, the selected file, the base index (steps back), a `this turn only` toggle (`t`) and the body scroll. `update(KeyMsg)` returns `[self, ?DiffReviewAction]`.
  - The view is a file list plus a body painted through DiffGutter.
  - `DiffReviewSource` loads a review for (store, session id, root, stepsBack) via `Checkpoints::fileCheckpointPositions()` and reports how many bases exist.

  No wiring in this step.
- files.create:
  - `candy-code/src/Tui/DiffReview/DiffReview.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewAction.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewActionKind.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewSource.php`
  - `candy-code/src/Tui/DiffReview/OpenDiffReviewMsg.php`
- files.modify: `candy-code/src/Workspace/CheckpointDiff.php` (`files()`, `between()`); `candy-code/lang/en.php` (`tui.diffreview.*`)
- tests:
  - Create `candy-code/tests/Tui/DiffReview/DiffReviewTest.php`: navigation, base cycling bounds, the turn toggle, empty and omitted-lines states.
  - Create `candy-code/tests/Workspace/CheckpointDiffFilesTest.php`: per-file counts; `between()` on two real git snapshots; binary rows.
- docs/guards: `tools/check-one-type-per-file.php`; LangParityTest
- serialized: [lang:tui.diffreview.]
- deps: none
- phase: normal
- ruling: none
- risks: `between()` must keep the `screenSafe`/`bounded` treatment, or a binary diff reaches the frame.

### C-TUI-2.e — Diff-review wiring (App slot, `/diff`, keys)
- item: C-TUI-2 (sources: GAP-GIT-09)
- kind: gap · effort M
- approach: In the TUI, a bare `/diff [n]` opens the review as an App shell slot. `Chat::handleDiffCommand()` (`Chat.php:15582`) returns `Cmd::send(new OpenDiffReviewMsg($n))`, the `openSettingsView()` technique, and `/diff [n] --print` keeps today's fenced rows. Wiring:
  - App holds a `?DiffReview $diffReview` slot and opens it through `DiffReviewSource` from `$this->chat`'s store, session and root.
  - Keys and the wheel route to it beside `mediaForm`; there is a `diffReviewYields()` gate.
  - `Tui/Renderer::renderView()` paints it in the slot chain.
  - The review body uses C-TUI-2.c's split/highlight path.
  - `DiffCommand` accepts and ignores `--print`, so the shared hint stays valid on serve. Served sessions still print.

  What the user sees: a Claude Code-style review. `↑`/`↓` pick a file, `Enter` opens it, `←`/`→` cycle the checkpoint base, `t` toggles the per-turn view, `Esc` closes.
- files.create: none
- files.modify:
  - `candy-code/src/App/App.php`: slot property, `withDiffReview`, `openDiffReview`/`closeDiffReview`, the `OpenDiffReviewMsg` arm in the update block ~L2722, key routing and yields ~L3538-3581, the wheel arm ~L2902-2927.
  - `candy-code/src/Tui/Renderer.php`: the `renderView()` slot chain ~L642-660, plus `renderDiffReview()`.
  - `candy-code/src/Chat.php`: `handleDiffCommand` only.
  - `candy-code/src/Host/Commands/DiffCommand.php`: tolerate `--print`.
  - `candy-code/src/Commands/KeyBindingRegistry.php`: a new `diffreview.*` context.
  - `candy-code/lang/en.php`: `keys.diffreview.*`, `cmd.diff.hint`.
  - `candy-code/README.md`: Keys; "Checkpoints: undo, redo, rewind and diff" ~L1082.
  - `candy-code/docs/COMMANDS.md`: regenerate.
- tests:
  - Create `candy-code/tests/App/DiffReviewWiringTest.php`: `/diff` opens the slot; `--print` prints; Esc closes; keys don't leak to the composer.
  - Modify the DiffCommand host test (the `tests/Host/Commands` `/diff` cases) for `--print`.
- docs/guards: `php tools/gen-command-docs.php --write`; CommandDocsDriftTest; KeyBindingDriftTest; ReadmeRosterDriftTest
- serialized: [chat:handleDiffCommand] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.diffreview., cmd.diff.] [docs:README.md] [docs:COMMANDS.md]
- deps: C-TUI-2.c, C-TUI-2.d
- phase: normal
- ruling: none
- risks: it changes what a bare TUI `/diff` does (overlay instead of rows), but served `/diff` is unchanged. Check that the palette's `ShowDiff` action still routes through `handleDiffCommand`.

### C-TUI-2.f — Web split + highlighted DiffView
- item: C-TUI-2 (sources: GAP-UI-05, GAP-UI-04)
- kind: gap · effort S
- approach: Client-only. `lib/diff.ts` pairs removed and added runs into side-by-side rows (the C-TUI-2.b rule). `DiffView.vue` adds a split mode: `auto` splits at ≥ 1100 px, and a client pref `diffStyle` lives in `stores/settings.ts`. Diff bodies are highlighted by file extension through `lib/highlight.ts`, which gains a `languageForPath` twin.
- files.create: none
- files.modify: `candy-code-web/src-web/components/DiffView.vue`; `candy-code-web/src-web/lib/diff.ts`; `candy-code-web/src-web/lib/highlight.ts`; `candy-code-web/src-web/stores/settings.ts`
- tests: create `candy-code-web/src-web/lib/__tests__/diffSplit.spec.ts` and `candy-code-web/src-web/components/__tests__/diffView.spec.ts`; modify `candy-code-web/src-web/lib/__tests__/highlight.spec.ts`
- docs/guards: vitest `--no-file-parallelism`; the `dist/` rebuild at weld
- serialized: [hot:candy-code-web/src-web/components/DiffView.vue] [hot:candy-code-web/src-web/stores/settings.ts]
- deps: C-SEC-2 (its server-computed approval diff also renders through DiffView/lib/diff)
- phase: normal
- ruling: none
- risks: collides with C-SEC-2 on DiffView if they run in one wave.

### C-TUI-2.g — Web checkpoint base selector (`files.diff` checkpoint)
- item: C-TUI-2 (sources: GAP-GIT-09)
- kind: gap · effort M
- approach: `files.diff` (`src/Protocol/Methods/FilesMethods.php:44-58`) diffs the workspace against a git `ref` only.
  - Add an optional `checkpoint:int` with a required `sessionId`. It resolves through `Checkpoints::fileCheckpointPositions()` + `CheckpointDiff::of()`, counted like `/diff n`.
  - The result adds `checkpoints` (how many bases exist) and `omittedLines`.
  - `ChangesPanel.vue` gets a base `<select>`: HEAD, then "1 turn ago" through N.
- files.create: none
- files.modify:
  - `candy-code/src/Protocol/Methods/FilesMethods.php`: `diff()`.
  - `candy-code/src/Protocol/Schema/MethodSchemas.php`: the `files.diff` entry ~L566.
  - `candy-code/docs/protocol/candy-code.v1.schema.json` and `candy-code/docs/SERVER.md`: regenerate with `candy-code/scripts/gen-protocol-schema.php`.
  - `candy-code-web/src-web/protocol/generated.ts`: `npm run gen:protocol`.
  - `candy-code-web/src-web/components/panels/ChangesPanel.vue`.
- tests:
  - Create `candy-code/tests/Protocol/FilesDiffCheckpointTest.php`: checkpoint resolution, a missing `sessionId` refused, an out-of-range clamp, no snapshot.
  - Modify `candy-code-web/src-web/components/panels/__tests__/changesPanel.spec.ts`: L67 pins `params: {}` today.
- docs/guards: ProtocolSchemaDriftTest; ServerProtocolDocumentationDriftTest; `gen-protocol-schema.php --check`; `npm run gen:protocol -- --check`; dist rebuild at weld
- serialized: [protocol] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: the session is read on the gateway's process, so check that the checkpoint store is reachable for non-primary roots, the WEB-3-P1 shape.

## C-TUI-3 — Transcript actions

### C-TUI-3.a — `/copy [n]` in the TUI
- item: C-TUI-3 (sources: GAP-UI-16)
- kind: gap · effort M
- approach: The TUI copies only by mouse drag.
  - A pure `CopyCommand::select(history, arg)` picks text: bare means the last assistant reply; `n` means the nth fenced code block, counting from the end of that reply.
  - `Chat::handleCopyCommand()` uses the drag-select precedent at `Chat.php:6336-6343`: `relayWidgetCmd(Cmd::setClipboard($text))` (OSC 52) batched with `SystemClipboard::copy()`, plus a uiOnly notice ("Copied N chars").
  - It is client-only, with no host body: the web refuses it as `ui_only` and gets copy buttons in C-TUI-3.b. Add it to `READ_ONLY_COMMANDS`.
  - New spec file `builtin-commands/1910-copy.php`. It is per-command-disjoint, so not serialized.
- files.create: `candy-code/src/Commands/CopyCommand.php`; `candy-code/builtin-commands/1910-copy.php`
- files.modify: `candy-code/src/Chat.php` (new `handleCopyCommand()`; `READ_ONLY_COMMANDS` L10266); `candy-code/lang/en.php` (`cmd.copy.*`, `chat.copy.*`); `candy-code/README.md` (commands roster, regenerated); `candy-code/docs/COMMANDS.md` (regenerated)
- tests: create `candy-code/tests/Commands/CopyCommandTest.php` (last reply, the nth block, out of range, no reply yet) and `candy-code/tests/Chat/CopySlashCommandTest.php` (the OSC 52 Cmd is raised and the notice is uiOnly)
- docs/guards: `php tools/gen-command-docs.php --write`; CommandDocsDriftTest; ReadmeRosterDriftTest; BuiltInCommandsTest
- serialized: [chat:handleCopyCommand (new method), READ_ONLY_COMMANDS] [commands] [lang:cmd.copy., chat.copy.] [docs:README.md] [docs:COMMANDS.md]
- deps: none
- phase: normal
- ruling: none
- risks: OSC 52 is silently dropped by some terminals; the SystemClipboard leg covers local shells.

### C-TUI-3.b — Web copy buttons, "Rewind to here", "Edit & resend"
- item: C-TUI-3 (sources: GAP-UI-16, GAP-GIT-06, GAP-UI-15)
- kind: gap · effort M
- approach: Client-only.
  - Copy buttons: on code blocks (`MessageMarkdown.vue`) and on assistant rows (`TranscriptItem.vue`) via `navigator.clipboard.writeText`. They are hidden outside a secure context, the notifications precedent.
  - User rows get "Rewind to here" and "Edit & resend". Both send `command.exec "/rewind n --chat"` through `stores/session.ts`.
  - "Edit & resend" also seeds the composer. The draft is a `SessionView.vue` model, relayed by an emit through `Transcript.vue`.
  - The snapshot refresh repaints the transcript, and a confirm guards both actions.

  The web "expand all tool cards" (GAP-UI-32 web half) belongs to C-WEB-4, not this step.
- files.create: none
- files.modify: `candy-code-web/src-web/components/MessageMarkdown.vue`; `candy-code-web/src-web/components/TranscriptItem.vue`; `candy-code-web/src-web/components/Transcript.vue`; `candy-code-web/src-web/views/SessionView.vue`; `candy-code-web/src-web/stores/session.ts` (`rewindTo(n)`)
- tests: create `candy-code-web/src-web/components/__tests__/transcriptActions.spec.ts` and `candy-code-web/src-web/components/__tests__/codeBlockCopy.spec.ts`
- docs/guards: vitest `--no-file-parallelism`; dist rebuild at weld
- serialized: [hot:candy-code-web/src-web/components/TranscriptItem.vue] [hot:candy-code-web/src-web/stores/session.ts] [hot:candy-code-web/src-web/views/SessionView.vue]
- deps: none
- phase: normal
- ruling: none
- risks: `n` must count exactly as `Checkpoints::rewindConversation()` counts. Verify against the store's checkpoint positions; do not re-derive it from rows.

### C-TUI-3.c — Message-picker core
- item: C-TUI-3 (sources: GAP-UI-15)
- kind: gap · effort S
- approach: A pure `MessagePicker` in the HistorySearch shape.
  - Its rows are the session's conversation checkpoints, read from the store (the positions `/rewind n --chat` counts), each labelled with the prompt it restores.
  - Newest first; filter-as-you-type.
  - `update(KeyMsg)` returns `[self, ?MessagePickerAction]`, with `Accept(stepsBack)` or `Close`.
  - `view(cols, rows, Theme)`.
- files.create: `candy-code/src/Tui/MessagePicker/MessagePicker.php`; `candy-code/src/Tui/MessagePicker/MessagePickerAction.php`; `candy-code/src/Tui/MessagePicker/OpenMessagePickerMsg.php`
- files.modify: `candy-code/lang/en.php` (`tui.msgpicker.*`)
- tests: create `candy-code/tests/Tui/MessagePicker/MessagePickerTest.php` (ordering, filter, accept index = stepsBack, an empty session)
- docs/guards: `tools/check-one-type-per-file.php`; LangParityTest
- serialized: [lang:tui.msgpicker.]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

### C-TUI-3.d — Message-picker wiring (idle Esc Esc)
- item: C-TUI-3 (sources: GAP-UI-15)
- kind: gap · effort M
- approach: Claude Code opens its message picker on Esc Esc. Here Esc Esc is `chat.cancel` while a turn is in flight. When idle, the Escape arm only clears `lastEscapeAt` (`HandlesKeys.php:387-390`), so idle Esc Esc is free.
  - The idle arm stamps `lastEscapeAt`.
  - A second Escape inside `doubleEscapeWindowSeconds()`, with no turn in flight, an empty draft and no modal, returns `Cmd::send(new OpenMessagePickerMsg())`.
  - App holds the picker as a shell slot. On Accept it calls a new public `Chat::rewindToCheckpoint(int $stepsBack)`, which runs `RewindCommand` with `--chat`. The `RestoreCheckpoint` effect already seeds the draft.
- files.create: none
- files.modify:
  - `candy-code/src/App/App.php`: slot property, open/close, the `OpenMessagePickerMsg` arm, key routing and yields.
  - `candy-code/src/Tui/Renderer.php`: the `renderView()` slot chain, plus `renderMessagePicker()`.
  - `candy-code/src/Chat.php`: new `rewindToCheckpoint()` beside `handleRewindCommand` L15458.
  - `candy-code/src/Chat/Handlers/HandlesKeys.php`: the idle Escape arm ~L387-390.
  - `candy-code/src/Commands/KeyBindingRegistry.php`: a `chat.message-picker` "Esc Esc" row and a `msgpicker.*` context.
  - `candy-code/lang/en.php`: `keys.chat.message-picker`, `keys.msgpicker.*`.
  - `candy-code/README.md`: Keys; the Checkpoints section.
- tests:
  - Create `candy-code/tests/App/MessagePickerWiringTest.php`: idle Esc Esc opens it; in-flight Esc Esc still cancels; a non-empty draft does not open it; Accept rewinds and seeds the draft.
  - Modify `candy-code/tests/Chat/EscapeSoftCancelTest.php`: idle stamping.
- docs/guards: KeyBindingDriftTest; ReadmeRosterDriftTest
- serialized: [chat:rewindToCheckpoint (new method)] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.chat.message-picker, keys.msgpicker.] [docs:README.md]
- deps: C-TUI-3.c
- phase: normal
- ruling: Chord: open the message picker on idle Esc Esc (empty draft, no turn), as in Claude Code? If unanswered, idle Esc Esc.
- risks: a double-tap that straddles a turn ending could open the picker; requiring both presses to land while idle avoids that.

### C-TUI-3.e — One-key verbose toggle (TUI)
- item: C-TUI-3 (sources: GAP-UI-32 TUI half)
- kind: gap · effort S
- approach: `expandToolOutput` is Live (`Renderer.php:702-709` reads `UiSettings`), but flipping it takes the settings view.
  - A new `chat.verbose-toggle` binding (proposed `Alt+O`, free in the registry) flips it in the **session tier**. The writer comes from `$this->workspace?->service(SettingsWriter::class)`, the `Chat.php:12334` precedent.
  - The arm then calls `applySettings(['expandToolOutput'], SettingsWriter::SESSION_TARGET)`.
  - Nothing is written to disk. A toast says which way it went.
- files.create: none
- files.modify: `candy-code/src/Chat/Handlers/HandlesKeys.php` (an `Alt+O` arm); `candy-code/src/Commands/KeyBindingRegistry.php` (`chat.verbose-toggle`); `candy-code/lang/en.php` (`keys.chat.verbose-toggle`, `chat.verbose.*`); `candy-code/README.md` (Keys)
- tests: create `candy-code/tests/Chat/VerboseToggleKeyTest.php` (it flips, writes nothing to disk, the second press restores, mid-turn is safe)
- docs/guards: KeyBindingDriftTest; ReadmeRosterDriftTest; the `tests/Config/Settings/SettingsWriterCensusTest.php` producer row (a new caller of the writer)
- serialized: [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.chat.verbose-toggle, chat.verbose.] [docs:README.md] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php]
- deps: none
- phase: normal
- ruling: none (chord default `Alt+O`)
- risks: Alt chords on macOS Terminal need Option-as-Meta, the same caveat as the C-TUI-7.b notice.

### C-TUI-3.f — Recap core (`RecapHostCommand`)
- item: C-TUI-3 (sources: GAP-UI-N4)
- kind: gap · effort S
- approach: `/btw` already does a tool-less side call on the title backend as an async effect, with a server row mapper (`src/Host/Commands/BtwHostCommand.php:44-111`, `CommandEffect::async($call, self::answerRowMessage(...))`, `SideQuestionAnsweredMsg`).
  - `RecapHostCommand` reuses that seam with a fixed prompt: "in one or two sentences, where did this session leave off and what is next". It goes over `TranscriptDigest`.
  - It needs no new Msg class, no Chat arm (the `SideQuestionAnsweredMsg` fold in `HandlesTurnCompletion.php:363` lands it) and no TitleService change.
  - The call is the same title-backend request, so no total timeout is added (E646).
  - `BtwHostCommand`'s `call()` is opened up to take a prompt, if it is private today.
- files.create: `candy-code/src/Host/Commands/RecapHostCommand.php`
- files.modify: `candy-code/src/Host/Commands/BtwHostCommand.php` (`call()`/`request()` take the framing prompt); `candy-code/lang/en.php` (`host.recap.*`)
- tests: create `candy-code/tests/Host/Commands/RecapHostCommandTest.php` (no title backend gives an error row; the async effect shape; the served row mapper)
- docs/guards: none (the command row lands in C-TUI-3.g)
- serialized: [lang:host.recap.]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

### C-TUI-3.g — Away recap wiring + `/recap`
- item: C-TUI-3 (sources: GAP-UI-N4)
- kind: gap · effort M
- approach: Claude Code shows a one-line recap after the user has been away for at least 3 minutes.
  - Focus: sugar-core has `FocusLostMsg`/`FocusGainedMsg` and a ProgramOptions `reportFocus`, which Program tears down itself (`sugar-core/src/Program.php:1370-1373,1606-1610`). `Chat::programOptions()` (`Chat.php:7441`) turns it on.
  - A process-global `AwayTracker` stamps focus loss. On regain past 180 s, with at least 3 user turns, idle, an empty draft and `sessions.awayRecap` on (default true), the `HandlesRunControl` arm runs `RecapHostCommand` without the echo row.
  - `/recap` on demand: `builtin-commands/3510-recap.php` (new, per-command-disjoint), with `withHandler('handleRecapCommand')->withHostCommand(RecapHostCommand::class)`, so the web gets it through `command.exec`.
- files.create: `candy-code/builtin-commands/3510-recap.php`; `candy-code/src/Tui/AwayTracker.php`
- files.modify:
  - `candy-code/src/Chat.php`: `programOptions()`; new `handleRecapCommand()`; `READ_ONLY_COMMANDS` += `recap`.
  - `candy-code/src/Chat/Handlers/HandlesRunControl.php`: new `FocusLostMsg`/`FocusGainedMsg` arms.
  - `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`: `sessions.awayRecap`.
  - `candy-code/src/Config/LayeredSettings.php`: LAYERED list.
  - `candy-code/src/Config/Settings/UiSettings.php`: `KEYS`.
  - `candy-code/lang/en.php`: `cmd.recap.*`, `settings.sessions.awayRecap.*`.
  - `candy-code/README.md` (commands roster and layered roster); `candy-code/docs/COMMANDS.md`; `candy-code/docs/SETTINGS.md`.
- tests:
  - Create `candy-code/tests/Chat/AwayRecapTest.php`: under threshold nothing happens; the threshold is honoured; mid-turn nothing happens; setting off nothing happens; the recap fires once per absence.
  - Create `candy-code/tests/Tui/AwayTrackerTest.php`.
- docs/guards: gen-command-docs + gen-settings-doc; CommandDocsDriftTest; ReadmeRosterDriftTest; SettingsSchemaDocDriftTest; `tests/Chat/RouteTraitParityTest.php` (new Msg arms)
- serialized: [chat:programOptions, handleRecapCommand (new), READ_ONLY_COMMANDS] [commands] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/UiSettings.php] [lang:cmd.recap., settings.sessions.awayRecap.] [docs:README.md] [docs:COMMANDS.md] [docs:SETTINGS.md]
- deps: C-TUI-3.f
- phase: normal
- ruling: none
- risks: the automatic call spends on the user's key. It defaults on (Claude Code parity) and is bounded to one recap per absence.

## C-TUI-4 — Custom themes & statusLine

### C-TUI-4.a — Custom TUI themes from `~/.candy-code/themes/`
- item: C-TUI-4 (sources: GAP-UI-02)
- kind: gap · effort M
- approach: `Theme::byName()` throws for anything outside the six names (`src/Theme.php:60,158-214`).
  - `ThemeFile` loads `~/.candy-code/themes/<name>.json` from the user directory only, never the project: `{"extends": "dark|light|…", "tokens": {...StyleTheme token → #hex}}`.
  - The loaded colours still go through `Theme::pair()` contrast projection.
  - `Theme::names()` becomes built-ins plus discovered names, so every Chat consumer (palette themes list, picker, `/theme`) picks them up with **no Chat edit**. A new `Theme::builtInNames()` feeds docs.
  - The `theme` definition moves from the implied `EnumValidator(Theme::names())` to a `ThemeNameValidator` with did-you-mean. `docs/SETTINGS.md` then never lists one machine's themes.
  - A bad file becomes a launch notice through `RuntimeNoticeSink`, and the theme falls back to `extends`.
- files.create: `candy-code/src/Config/ThemeFile.php`; `candy-code/src/Config/Settings/Validator/ThemeNameValidator.php`
- files.modify:
  - `candy-code/src/Theme.php`: `names()`, `builtInNames()`, `byName()` fallback.
  - `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`: the `theme` definition L56-65.
  - `candy-code/src/Config/Settings/OptionsProvider.php`: L31.
  - `candy-code/lang/en.php`: `settings.theme.help`, `chat.theme.file_*`.
  - `candy-code/docs/SETTINGS.md`: theme prose ~L98-107 plus the regenerated table.
  - `candy-code/README.md`: Interface settings ~L1563.
- tests:
  - Create `candy-code/tests/Config/ThemeFileTest.php`: valid, malformed, low-contrast token projected, unknown token, project dir ignored.
  - Modify `candy-code/tests/ThemeTest.php`.
  - Modify `candy-code/tests/Chat/PaletteThemePickerTest.php`: a custom name is listed.
- docs/guards: gen-settings-doc; SettingsSchemaDocDriftTest; a `tests/Support/ReadPathCensusTest.php` row (verdict OWNED_HOME)
- serialized: [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [lang:settings.theme., chat.theme.] [docs:SETTINGS.md] [docs:README.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: `theme` is project-settable. A project naming a user theme that doesn't exist must degrade to the default, never throw at launch.

### C-TUI-4.b — Web dracula + tokyoNight theme sets
- item: C-TUI-4 (sources: GAP-UI-02 web half)
- kind: gap · effort S
- approach: The web has auto, dark and light only (`stores/settings.ts` `ThemeChoice`).
  - Add `[data-theme='dracula']` and `[data-theme='tokyoNight']` token sets mirroring the TUI palettes in `styles/tokens.css`.
  - Widen `ThemeChoice` and `cycleTheme()`.
  - `App.vue`'s button reads `settings.theme`, so it is untouched.
- files.create: none
- files.modify: `candy-code-web/src-web/styles/tokens.css`; `candy-code-web/src-web/stores/settings.ts`
- tests: modify `candy-code-web/src-web/styles/__tests__/contrast.spec.ts` (both new sets reach 4.5:1); create `candy-code-web/src-web/stores/__tests__/settingsTheme.spec.ts`
- docs/guards: vitest; dist rebuild at weld
- serialized: [hot:candy-code-web/src-web/stores/settings.ts] [hot:candy-code-web/src-web/styles/tokens.css]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

### C-TUI-4.c — `statusLine` session JSON on stdin
- item: C-TUI-4 (sources: GAP-UI-23)
- kind: gap · effort M
- approach: `StatusLineCommand::run()` closes the child's stdin unwritten (`src/Config/StatusLineCommand.php:594-623`), so Claude Code statusLine scripts that read JSON get nothing.
  - A pure `StatusLineSession` builds Claude Code's stdin shape: `session_id`, `cwd`, `model{id,display_name}`, `workspace{current_dir,project_dir}`, `version`, `cost{total_cost_usd}`. candy-code extras go under `candy_code`: provider, permission mode, context tokens and percent, branch.
  - The tick arm (`src/Chat/Handlers/HandlesRunControl.php:261-273`) builds it from the accessors the status bar already reads, and passes `StatusLineCommand::cmd($json)`.
  - `run($cwd, $stdin)` writes to `$pipes[0]` before `fclose` and keeps the deadline. The sync `refresh()` path passes the last snapshot.
- files.create: `candy-code/src/Config/StatusLineSession.php`
- files.modify:
  - `candy-code/src/Config/StatusLineCommand.php`: `cmd()`, `run()`, `refresh()`.
  - `candy-code/src/Chat/Handlers/HandlesRunControl.php`: the `StatusLineTickMsg` arm.
  - `candy-code/lang/en.php`: `settings.statusLine.help`.
  - `candy-code/docs/SETTINGS.md`: statusLine prose ~L661, which documents the schema.
- tests:
  - Create `candy-code/tests/Config/StatusLineSessionTest.php`.
  - Modify `candy-code/tests/Config/StatusLineCommandTest.php`: a `cat` command echoes the JSON; a command that never reads stdin still finishes within its deadline (no EPIPE hang).
- docs/guards: SettingsSchemaDocDriftTest (if the help text appears); DocFigureProseDriftTest
- serialized: [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [lang:settings.statusLine.] [docs:SETTINGS.md]
- deps: none
- phase: normal
- ruling: none
- risks: a write to a child that exits early must not raise or block. Pin it.

## C-TUI-5 — Setup, doctor, config reload

### C-TUI-5.a — `/doctor` host command + AppArmor userns probe
- item: C-TUI-5 (sources: GAP-CFG-06, CC-new#11 doctor diagnostic)
- kind: gap · effort M
- approach: `candy-code doctor` probes live inside `Subcommands::doctorProbes(ParsedArgs)` (`src/Cli/Subcommands.php:381-~620`). They use only `$args->root` and do no network I/O; the provider probe is factory-only.
  - Extract them to `DoctorChecks::probes(?string $root)` and `run()`, which the CLI delegates to.
  - The `sandbox`/AppArmor probe is C-TOOL-4.a's (it lands first); move it into `DoctorChecks` with the others. Do not add a second one.
  - `DoctorHostCommand` returns TranscriptTable rows. It is read-only and has a host body, so the web gets it through `command.exec` with no protocol change.
  - The slash command `/doctor` is distinct from the model-facing `doctor` tool.
- files.create: `candy-code/src/Cli/DoctorChecks.php`; `candy-code/src/Host/Commands/DoctorHostCommand.php`; `candy-code/builtin-commands/1345-doctor.php` (per-command-disjoint)
- files.modify:
  - `candy-code/src/Cli/Subcommands.php`: `doctor()`, `doctorProbes()`.
  - `candy-code/src/Chat.php`: new `handleDoctorCommand()`; `READ_ONLY_COMMANDS` += `doctor`.
  - `candy-code/lang/en.php`: `cmd.doctor.*`, `host.doctor.*`, `cli.doctor.sandbox*`.
  - `candy-code/README.md`: commands roster regenerated; the Subcommands doctor paragraph.
  - `candy-code/docs/COMMANDS.md`: regenerated.
  - `candy-code/docs/TROUBLESHOOTING.md`: an AppArmor userns entry.
- tests:
  - Create `candy-code/tests/Cli/DoctorChecksTest.php`: the probe roster; the moved C-TOOL-4.a sandbox probe still reports through `DoctorChecks`.
  - Create `candy-code/tests/Host/Commands/DoctorHostCommandTest.php`.
  - Keep green: `tests/Cli/DoctorBypassNoticeProbeTest.php`, `DoctorExtensionProbesTest.php` and `DoctorFts5ProbeTest.php`.
- docs/guards: gen-command-docs; CommandDocsDriftTest; ReadmeRosterDriftTest; BuiltInCommandsTest; a ReadPathCensusTest row for the `/proc/sys` read
- serialized: [headless] [chat:handleDoctorCommand (new), READ_ONLY_COMMANDS] [commands] [lang:cmd.doctor., host.doctor., cli.doctor.] [docs:README.md] [docs:COMMANDS.md] [docs:TROUBLESHOOTING.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-TOOL-4.a
- phase: normal
- ruling: none
- risks: C-TOOL-4 (LATE) also cites CC-new#11. This step owns the doctor probe, so C-TOOL-4 must not add a second one.

### C-TUI-5.b — `candy-code import claude`
- item: C-TUI-5 (sources: GAP-CFG-08)
- kind: gap · effort M
- approach: Imports today cover only MCP and memory.
  - `ClaudeSettingsImport` reads `~/.claude/settings.json`, plus the project's `.claude/settings.json` with `--project`.
  - It maps `permissions.allow/deny/ask` to `permissionRules` through `PermissionRule`'s `(prefix:*)` translator (`src/Permissions/PermissionRule.php:1289`), with deny winning, and maps `model`.
  - Keys candy-code has no home for are listed as skipped.
  - It prints a preview, then writes the user tier only after `--yes` or an interactive confirm.
  - New verb `import`. `Subcommands::loginStoreKey()` (L936-986) now delegates its persist half to `ProviderKeyStore` (from C-TUI-5.d), which removes the duplicated merge logic.
- files.create: `candy-code/src/Config/ClaudeSettingsImport.php`
- files.modify:
  - `candy-code/src/Cli/Subcommands.php`: dispatch `import`; the completion rosters ~L2569/2587; `loginStoreKey`.
  - `candy-code/src/Cli/ParsedArgs.php`: `SUBCOMMANDS`, verb flags.
  - `candy-code/src/Cli/ArgvParser.php`: verb flags.
  - `candy-code/src/Cli/Help.php`.
  - `candy-code/lang/en.php`: `cli.sub.import.*`.
  - `candy-code/README.md`: `### Subcommands` ~L2097.
  - `candy-code/docs/PERMISSIONS.md`: an import note.
- tests:
  - Create `candy-code/tests/Config/ClaudeSettingsImportTest.php`: translation, deny wins, skipped keys, nothing written without confirmation.
  - Create `candy-code/tests/Cli/ImportSubcommandTest.php`.
  - Modify `candy-code/tests/Cli/LoginSubcommandTest.php` (delegation) and `tests/Cli/ArgvParserTest.php`.
- docs/guards: ReadmeRosterDriftTest; DocFigureProseDriftTest (the subcommand count words); HelpTest; `tests/Integration/BinCandyCodeDispatchTest.php`; SettingsWriterCensusTest and ConfigWriteProducerDocumentationDriftTest (a new `config.json` writer door); ReadPathCensusTest rows (two foreign reads)
- serialized: [headless] [lang:cli.sub.import.] [docs:README.md] [docs:PERMISSIONS.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php]
- deps: C-TUI-5.d
- phase: normal
- ruling: none
- risks: importing `allow` rules widens permissions, so the preview must list every widening rule.

### C-TUI-5.c — Settings hot reload + `/settings reload`
- item: C-TUI-5 (sources: GAP-CFG-05)
- kind: gap · effort M
- approach: Next-turn keys are already re-read every turn. Live keys only change through `Chat::applySettings()` (`Chat.php:7591`), so an external edit to `config.json`/`settings.json` waits for a restart.
  - `SettingsFileWatch` keeps a process-global fingerprint (mtime, size, hash) of the user and project settings files (`LayeredSettings::userLayer`/`projectFiles`), plus `changedKeys()` diffed from the merged settings.
  - At turn settle (`HandlesTurnCompletion`), a cheap stat compare runs `applySettings($changed, 'reloaded from disk')`.
  - `/settings reload` (`handleSettingsCommand`, `Chat.php:11813`) forces it and toasts "nothing changed" when that is true.
  - Restart keys keep their existing "applies at restart" toast.
  - Moving restart-roster keys to next-turn is out of scope.
- files.create: `candy-code/src/Config/SettingsFileWatch.php`
- files.modify:
  - `candy-code/src/Chat.php`: `handleSettingsCommand`.
  - `candy-code/src/Chat/Handlers/HandlesTurnCompletion.php`: the turn-settle arm.
  - `candy-code/lang/en.php`: `cmd.settings.hint`, `chat.settings.reload.*`.
  - `candy-code/docs/SETTINGS.md`: apply-mode prose ~L1389.
  - `candy-code/docs/COMMANDS.md`: regenerated.
  - `candy-code/README.md`: Interface settings ~L1561-1570.
- tests: create `candy-code/tests/Config/SettingsFileWatchTest.php` and `candy-code/tests/Chat/SettingsReloadTest.php` (an external theme edit applies at turn end; `/settings reload`; restart keys toast)
- docs/guards: gen-command-docs; CommandDocsDriftTest; SettingsSchemaDocDriftTest; a ReadPathCensusTest row (`hash_file`)
- serialized: [chat:handleSettingsCommand] [hot:candy-code/src/Chat/Handlers/HandlesTurnCompletion.php] [lang:cmd.settings., chat.settings.reload.] [docs:SETTINGS.md] [docs:COMMANDS.md] [docs:README.md] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: engine keys mid-turn must keep using the existing `pendingSettingsApply` park.

### C-TUI-5.d — First-run wizard core + `ProviderKeyStore`
- item: C-TUI-5 (sources: GAP-UI-29)
- kind: gap · effort M
- approach: A first launch with no provider runs the echo backend behind a notice (`Bootstrap.php` ~L3339, L3473).
  - `ProviderKeyStore` extracts the persist half of `Subcommands::loginStoreKey()` (L936-986): merge into the `providers` map, refuse control characters, re-read to verify.
  - `FirstRunWizard` is a pure sugar-forms model. Step 1 picks from `ProviderFactory::availableTypes()`. Step 2 takes a masked key, or offers "run `candy-code login <p>`", or skips. `update()` returns Finish, Skip or Cancel actions.
  - `FirstRunWizard::shouldOffer()` returns true when there is no selected provider, no configured provider, a TTY, and no dismissal stamp in `~/.candy-code/cache/`.

  No wiring in this step.
- files.create:
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizard.php`
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizardAction.php`
  - `candy-code/src/Tui/FirstRunWizard/FirstRunWizardActionKind.php`
  - `candy-code/src/Tui/FirstRunWizard/OpenFirstRunWizardMsg.php`
  - `candy-code/src/Config/ProviderKeyStore.php`
- files.modify: `candy-code/lang/en.php` (`tui.wizard.*`)
- tests: create `candy-code/tests/Tui/FirstRunWizard/FirstRunWizardTest.php` and `candy-code/tests/Config/ProviderKeyStoreTest.php` (keeps other providers, refuses control characters, verifies the write)
- docs/guards: SettingsWriterCensusTest (`ProviderKeyStore` is a `config.json` door); ReadPathCensusTest (the dismissal stamp); `tools/check-one-type-per-file.php`
- serialized: [lang:tui.wizard.] [hot:candy-code/tests/Config/Settings/SettingsWriterCensusTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: `shouldOffer()` must be false under the suite's HOME sandbox and for `-p`/`serve`/`attach`.

### C-TUI-5.e — First-run wizard wiring (App slot, web notice)
- item: C-TUI-5 (sources: GAP-UI-29)
- kind: gap · effort M
- approach:
  - `App::init()` (`App.php:2631`) adds the wizard opener when `FirstRunWizard::shouldOffer()` is true. `Bootstrap` is untouched.
  - The App slot routes keys like `mediaForm`.
  - On Finish: `ProviderKeyStore::store()`, then a new public `Chat::switchProvider(string)` that wraps the `/model <provider>` path of the private `handleModelCommand` (`Chat.php:12181`).
  - Skip writes the dismissal stamp.
  - Web: `DashboardView.vue` shows a "no provider configured — run `candy-code login`" notice when `server.info.providers` has no real provider. This part is client-only.
- files.create: none
- files.modify:
  - `candy-code/src/App/App.php`: `init()` batch, slot property, open/close, key routing and yields.
  - `candy-code/src/Tui/Renderer.php`: the `renderView()` slot chain, plus `renderFirstRunWizard()`.
  - `candy-code/src/Chat.php`: new `switchProvider()` beside `handleModelCommand`.
  - `candy-code/src/Commands/KeyBindingRegistry.php`: a `wizard.*` context.
  - `candy-code/lang/en.php`: `keys.wizard.*`.
  - `candy-code/README.md`: Keys; "Choosing a provider without editing anything" ~L184.
  - `candy-code-web/src-web/views/DashboardView.vue`.
- tests: create `candy-code/tests/App/FirstRunWizardWiringTest.php` (offered once; Finish switches the live provider; Skip stamps) and `candy-code-web/src-web/views/__tests__/dashboardNoProvider.spec.ts`
- docs/guards: KeyBindingDriftTest; ReadmeRosterDriftTest; vitest; dist rebuild at weld
- serialized: [chat:switchProvider (new method)] [renderer:Tui/Renderer::renderView slot chain] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php] [lang:keys.wizard.] [docs:README.md]
- deps: C-TUI-5.d
- phase: normal
- ruling: none
- risks: check whether `server.info.providers` includes `echo`; the notice predicate must match.

## C-TUI-7 — TUI minor/nit cleanup

### C-TUI-7.a — Startup summary: personal files + gather off `view()`
- item: C-TUI-7 (sources: VIS-1-P1, VIS-1-P2)
- kind: fix · MINOR/NIT · effort M
- approach:
  - VIS-1-P1: `StartupSummary::instructionFiles()` (`src/Support/StartupSummary.php:282-290`) lists `loadDocuments()` only, while `/context` also lists `loadPersonal()`. Fix: append the personal documents with a personal marker, deduplicated by realpath.
  - VIS-1-P2: `render()` memoizes on session/root/mode/provider/model only (L80-88). On a miss it runs `git branch` (L265) and a full `SkillManager::loadAll` (L323-326) **inside `view()`**. Root-cause fix: a new `gatherCmd(Chat)` computes branch, instruction, memory, skill and MCP facts off the view path and lands a `StartupSummaryGatheredMsg`.
  - `render()` paints the cheap rows until those facts arrive.
  - The gather runs from `Chat::init()` and again from the `ClearTranscript`/`SwitchSession` arms of `applyCommandResult()`.
- files.create: `candy-code/src/App/StartupSummaryGatheredMsg.php`
- files.modify:
  - `candy-code/src/Support/StartupSummary.php`: `render`, `instructionFiles`, new `gatherCmd`/`store`.
  - `candy-code/src/Chat.php`: `init()` L1873; the `applyCommandResult()` L11551 `ClearTranscript`/`SwitchSession` arms.
  - `candy-code/src/Chat/Handlers/HandlesRunControl.php`: a new gathered-facts arm.
  - `candy-code/lang/en.php`: `chat.startup.personal`.
- tests:
  - Modify `candy-code/tests/Support/StartupSummaryTest.php`: personal docs listed and deduplicated.
  - Create `candy-code/tests/Chat/StartupSummaryGatherTest.php`: `view()` never spawns git (a GitRunner spy); `/clear` re-gathers a changed branch.
- docs/guards: `tests/Chat/RouteTraitParityTest.php`
- serialized: [chat:init, applyCommandResult (ClearTranscript/SwitchSession arms)] [hot:candy-code/src/Support/StartupSummary.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [lang:chat.startup.]
- deps: none
- phase: normal
- ruling: none
- risks: C-SES-6 adds an "update available" line to the same file, so keep it out of this step's wave.

### C-TUI-7.b — Mac Option-as-Meta notice: once per version, tmux-aware
- item: C-TUI-7 (sources: TUI-5-P1)
- kind: fix · NIT · effort S
- approach: `Bootstrap::terminalSpeaksKittyKeyboard()` (`Bootstrap.php:10001-10015`) checks only `TERM`/`TERM_PROGRAM`. So tmux-on-kitty (`TERM_PROGRAM=tmux`), and a Terminal.app user who already set Option-as-Meta, see the row on **every** launch.
  - A pure `MacAltNotice` also checks the kitty-family variables that survive tmux: `KITTY_WINDOW_ID`, `GHOSTTY_RESOURCES_DIR`, `WEZTERM_PANE`.
  - It shows the row once per candy-code version, using a stamp in `~/.candy-code/cache/mac-alt-notice`.
  - The Bootstrap methods delegate and keep their public signatures for `MacAltNoticeTest`.
  - The COMPARISON §6.6 wording flip belongs to the COMPARISON owner (see Notes).
- files.create: `candy-code/src/Support/MacAltNotice.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`macAltNotice()` L9992, `terminalSpeaksKittyKeyboard()` L10001, `announceMacAltFallback()` L10024); `candy-code/lang/en.php` (only if the row wording changes)
- tests:
  - Modify `candy-code/tests/Commands/MacAltNoticeTest.php`: its L46-81 source-scan pins follow the move.
  - Create `candy-code/tests/Support/MacAltNoticeStampTest.php`: the same version is silent; a new version shows once; tmux-on-kitty is silent.
- docs/guards: a ReadPathCensusTest row (the stamp read)
- serialized: [bootstrap:macAltNotice, terminalSpeaksKittyKeyboard, announceMacAltFallback] [lang:cli.notice.mac_alt] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: normal
- ruling: none
- risks: none notable.

## C-TUI-6 — Windows, i18n, a11y, voice (LATE)

Every step here is `phase: LATE` except C-TUI-6.k (web dictation), which is `phase: normal`. **Doc order follows dependencies, not letters:** 6.k sits before 6.f/6.g because 6.g migrates its strings, and 6.h (the community locale) sits last because, under the strict parity policy, it must be the plan's final lane.

### C-TUI-6.a — Windows process layer (bash quoting, bash resolution, tree kill)
- item: C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2))
- kind: gap · effort M
- approach: Three root causes break every shell tool on NT before any PTY is involved:
  - **Quoting.** The bash-script builders use `escapeshellarg()`, which is host-dependent: on Windows it emits `"…"` and blanks `%`, `!` and `"`. The sites are `ProcessContainment::cdGuard()` (`ProcessContainment.php:446`), `Bash::runCommand()` (`Bash.php:478-480`) and the background wrapper (`BackgroundShellRegistry.php:374`). The receiver is always bash, so a host-independent `PosixShellQuote::quote()` replaces those three calls. On POSIX it is byte-identical to `escapeshellarg`, and a test pins that.
  - **Resolution.** `locateOnPath()` (`:1340`) splits `PATH` on `:` and ignores `PATHEXT`, so a `C:\…` entry breaks and `bash.exe` is never found. Fix: `PATH_SEPARATOR` plus `PATHEXT`. `WindowsShell::bash()` resolves Git for Windows' `bash.exe` (PATH first, then `%ProgramFiles%\Git\bin\bash.exe`). When there is none, the Bash tool refuses with an "install Git for Windows" reason and never falls back to `cmd.exe`.
  - **Spawn shape.** On Windows, Bash and the background wrapper hand `proc_open` an ARRAY, `[bash.exe, '-c', $script]`. PHP quotes it by CreateProcess rules, with no `cmd.exe` in between, and it still passes through `ProcessContainment::spawnSpec()`, which already returns it unchanged there (`:193`, `:227-232`).
  - **Tree kill.** `terminate()` (`:495`) falls back to `proc_terminate()`, which kills the wrapper only. `terminatePid()`, `killTree()` and `killTreeAsync()` (`:616`, `:694`, `:809`) return early without `posix_kill`, so every descendant survives. The Windows arm calls `WindowsTaskKill::tree($pid)`, which runs `taskkill /T /F /PID <pid>` as a short-lived child through `spawnSpec`. `ProcessTree` (`ProcessTree.php:31`) stays Linux-only by construction, and the Windows arm never consults it.
  - Background rows keep `grouped: false` on Windows, which is honest. The wall falls to C-TOOL-4's watchdog fallback.
  - **RULING ANSWERED 2026-10-28 — ADOPT (all bullets as written):** Git-for-Windows bash.exe only (PATH → `%ProgramFiles%\Git\bin\bash.exe`), NO cmd.exe fallback, missing bash refuses with "install Git for Windows"; array spawn spec; host-independent PosixShellQuote; WindowsTaskKill `taskkill /T /F` tree kill; background rows stay grouped:false.
  - **Ownership.** This step owns ONLY the Windows arms. C-TOOL-4 owns the sandbox-backend seam, the macOS setsid helper and the `timeout` fallback in these same files.
- files.create: `candy-code/src/Support/PosixShellQuote.php`; `candy-code/src/Support/WindowsShell.php`; `candy-code/src/Support/WindowsTaskKill.php`
- files.modify:
  - `candy-code/src/Support/ProcessContainment.php`: `cdGuard()` L444, `terminate()` L495, `terminatePid()` L616, `killTree()` L694, `killTreeAsync()` L809, `locateOnPath()` L1340.
  - `candy-code/src/Tools/BuiltIn/Bash.php`: `runCommand()` L478-480.
  - `candy-code/src/Background/BackgroundShellRegistry.php`: the spawn ~L282, the wrapper quoting ~L374.
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`: a row for the `taskkill` site if the scanner does not classify it as short-lived.
  - `candy-code/tests/Support/ReadPathCensusTest.php`: a row for the `taskkill` exec sink.
- tests:
  - Create `candy-code/tests/Support/PosixShellQuoteTest.php`: byte-identical to `escapeshellarg` on POSIX over a corpus with `'`, `%`, `!`, `"` and newlines.
  - Create `candy-code/tests/Support/WindowsShellTest.php`: PATH/PATHEXT resolution through an injected `osFamily` and env seam; a missing bash gives the refusal text.
  - Create `candy-code/tests/Support/WindowsTaskKillTest.php`: the argv, routing through `spawnSpec`, and refusal of a pid ≤ 0, with an injected runner.
  - Create `candy-code/tests/Tools/BuiltIn/BashWindowsSpecTest.php` and `candy-code/tests/Sessions/BackgroundShellWindowsArmTest.php`: the array spec under an injected Windows family; `%PATH%` and `!x` survive.
  - Keep green: `tests/Support/ProcessTreeKillTest.php`, `tests/Support/ProcessTreeKillAsyncTest.php`, `tests/Sessions/BackgroundShellRegistryTest.php`, `tests/Tools/BuiltIn/BashTimeoutTest.php`.
- docs/guards:
  - DescriptorInheritanceGuardTest is candy-code's own child-lifetime roster. `tools/check-child-lifetimes.php` skips candy-code and must stay green.
  - ReadPathCensusTest.
  - `tools/check-one-type-per-file.php`.
  - No doc edits here: the platform docs land in C-TUI-6.d.
- serialized: [hot:candy-code/src/Support/ProcessContainment.php] [hot:candy-code/src/Background/BackgroundShellRegistry.php] [hot:candy-code/src/Tools/BuiltIn/Bash.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-TOOL-4 (other area, LATE: the sandbox seam, macOS setsid helper and watchdog fallback on the same three files)
- phase: LATE
- ruling: none
- risks: replacing `escapeshellarg` at three sites is a POSIX no-op only if the byte-identity pin holds. Run the full Bash/background suites, not just the new tests.

### C-TUI-6.b — sugar-pty ConPTY FFI primitives
- item: C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2))
- kind: gap · effort M
- approach: `sugar-pty` has no Windows code (`src/Windows/` does not exist). `PtySystemFactory.php:17` throws `UnsupportedPlatformException` ("v2 ConPTY").
  - This step adds only the FFI layer and the pure helpers, with no contract adapters yet.
  - It mirrors sugar-core's kernel32 precedent (`sugar-core/src/Util/Tty/Kernel32.php` + `Kernel32Interface.php`): instance methods, handles as plain `int`, and an interface so a test double runs on Linux.
  - `ConPtyApi` (the interface) and `Kernel32ConPty` (the cdef) cover:
    - pseudo-console calls: `CreatePseudoConsole`, `ResizePseudoConsole`, `ClosePseudoConsole`;
    - pipes and process launch: `CreatePipe`, the `*ProcThreadAttributeList` trio, `CreateProcessW`;
    - pipe I/O: `PeekNamedPipe`, `ReadFile`, `WriteFile`;
    - process state: `WaitForSingleObject`, `GetExitCodeProcess`, `CloseHandle`;
    - a job object (`CreateJobObjectW`, `SetInformationJobObject` with `KILL_ON_JOB_CLOSE`, `AssignProcessToJobObject`, `TerminateJobObject`), which gives tree containment for free.
  - `ConPtyApi::supported()` is a probe with no side effects: Windows, PHP ≥ 8.4 (AGENTS.md: Windows FFI needs 8.4+), ext-ffi, and the `CreatePseudoConsole` export present (Windows 10 1809+). It never opens a console.
  - Pure helpers:
    - `CommandLine::fromArgv()`: argv to a command line by MSVCRT/`CommandLineToArgvW` rules (quotes, trailing backslashes).
    - `EnvBlock::encode()`: a UTF-16LE, case-insensitively sorted, double-NUL environment block.
    - `WideString`: UTF-8 ↔ UTF-16LE.
- files.create: `sugar-pty/src/Windows/ConPtyApi.php`; `sugar-pty/src/Windows/Kernel32ConPty.php`; `sugar-pty/src/Windows/CommandLine.php`; `sugar-pty/src/Windows/EnvBlock.php`; `sugar-pty/src/Windows/WideString.php`
- files.modify: `sugar-pty/src/Exception/UnsupportedPlatformException.php` (a new `forConPtyUnavailable(string $reason)` factory)
- tests:
  - Create `sugar-pty/tests/Windows/CommandLineTest.php`: the round-trip corpus from the MS quoting rules, including the empty arg, embedded quotes and trailing backslashes.
  - Create `sugar-pty/tests/Windows/EnvBlockTest.php`: ordering, double-NUL termination, and refusal of `=` in names.
  - Create `sugar-pty/tests/Windows/WideStringTest.php`.
  - Create `sugar-pty/tests/Windows/Kernel32ConPtyTest.php`: the cdef parses and the symbols resolve. It runs only on Windows with PHP 8.4 and is skipped elsewhere.
  - Create the support double `sugar-pty/tests/Windows/FakeConPtyApi.php`, which is not a test.
  - Modify `sugar-pty/tests/Exception/UnsupportedPlatformExceptionTest.php`.
- docs/guards: `tools/check-one-type-per-file.php`. No `proc_open` is added: `CreateProcessW` is not a PHP spawn, so `tools/check-child-lifetimes.php` sees no new site, and the job object is the containment.
- serialized: none
- deps: none
- phase: LATE
- ruling: none
- risks: FFI struct layouts (`STARTUPINFOEXW`, `COORD` passed by value) are only proven on a real Windows host. The live test only runs once C-TUI-6.c adds sugar-pty to the Windows CI pool.

### C-TUI-6.c — sugar-pty ConPTY Contract adapters + factory + WINDOWS_LIBS
- item: C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2))
- kind: gap · effort M
- approach: Implement `sugar-pty/src/Contract/*` over `ConPtyApi`.
  - `ConPtySystem::open()` creates two pipes and a pseudo console. `capabilities()` reports `conpty: true`, `controllingTerminal: false`, `signals: false` and `fd: false`.
  - `ConPtySlavePty::spawn()` builds `CreateProcessW` with the pseudo-console attribute plus the job object. `path()` answers `conpty:<handle>`, because ConPTY has no device path.
  - `ConPtyMasterPty`:
    - `read()` polls `PeekNamedPipe` to honour the null-on-timeout / `''`-on-EOF contract.
    - `resize()` calls `ResizePseudoConsole`.
    - `stream()` and `fd()` throw, as `MasterPty.php` already anticipates ("any Windows Pty implementation … should throw").
    - `close()` is idempotent.
  - `ConPtyChild::kill()` maps every signal to `TerminateJobObject`, which is documented.
  - Factory changes:
    - `forPlatform('Windows')` returns `new ConPtySystem()`. It stays dependency-free, and FFI loads at `open()`.
    - `SUGARCRAFT_PTY_BACKEND` gains `conpty`, which throws on POSIX.
    - The old "v2 sidecar" wording goes.
  - CI: add `sugar-pty` to `WINDOWS_LIBS` (`scripts/affected-libs.php:30`). The `windows-test` job's run step (`ci.yml` ~L580) gains a `tests\Windows` branch, so only the Windows suite runs there. sugar-pty's POSIX suite needs libc FFI, and its `HangWatchdog` already no-ops on Windows.
- files.create: `sugar-pty/src/Windows/ConPtySystem.php`; `sugar-pty/src/Windows/ConPtyPair.php`; `sugar-pty/src/Windows/ConPtyMasterPty.php`; `sugar-pty/src/Windows/ConPtySlavePty.php`; `sugar-pty/src/Windows/ConPtyChild.php`
- files.modify:
  - `sugar-pty/src/PtySystemFactory.php`: `VALID_BACKENDS`, `new()`, `forPlatform()`, class docblock.
  - `scripts/affected-libs.php`: `WINDOWS_LIBS` L30.
  - `.github/workflows/ci.yml`: the `windows-test` "Run tests" step.
  - `sugar-pty/README.md`: Status L14, factory L31-37, env table L454 (`conpty`), matrix L490, limits L618.
  - `sugar-pty/CALIBER_LEARNINGS.md`.
  - `docs/MATCHUPS.md`: the SugarPty row L56 ("Windows ConPTY planned") and the L104 comment.
  - `docs/_data/sugar-pty.body.html` L81, then `php tools/gen-docs.php`, which regenerates `docs/lib/sugar-pty.html`.
- tests:
  - Create `sugar-pty/tests/Windows/ConPtySystemTest.php`: open and close through the fake, plus capabilities.
  - Create `sugar-pty/tests/Windows/ConPtyMasterPtyTest.php`: timeout → null, EOF → `''`, `stream()`/`fd()` throw, double close.
  - Create `sugar-pty/tests/Windows/ConPtyChildTest.php`: exit-code mapping and kill → job terminate.
  - Create `sugar-pty/tests/Windows/ConPtyLiveTest.php`: on Windows with PHP 8.4 only, spawn `cmd /c echo hi`, read `hi`, resize, and check the exit code.
  - Modify `sugar-pty/tests/PtySystemFactoryTest.php`: `testWindowsThrowsUnsupportedPlatformException` flips to "returns ConPtySystem"; add `conpty` backend cases.
  - Modify `sugar-pty/tests/Contract/InterfacesTest.php`: the adapters implement the contracts.
- docs/guards:
  - `sugar-pty/tests/EnvRosterTest.php`: the README env table.
  - `tools/check-docs-assets.php`, after gen-docs.
  - `tools/check-path-repos.php --no-lib-path-repos`.
- serialized: [workflows] [hot:scripts/affected-libs.php] [hot:docs/MATCHUPS.md]
- deps: C-TUI-6.b
- phase: LATE
- ruling: none
- risks:
  - `ConPtyMasterPty` has no PHP stream, so `Pump`/`ReactPump`/`MultiPump` stay POSIX-only. The README must say so; candy-code uses only `read()`/`write()`.
  - The PHP 8.3 Windows cell runs the pure tests only.

### C-TUI-6.d — candy-code on Windows: PtyRun / interactive Bash over ConPTY, README banner, CI smoke job
- item: C-TUI-6 (sources: GAP-PLAT-04, p2-miss: Windows support (×2))
- kind: gap · effort M
- approach:
  - **The PTY opener.** candy-code opens PTYs through the deprecated POSIX-only facade `\SugarCraft\Pty\Pty::open()`, in `PtyDriver.php:116` and `CapturesProcessOutput.php:518`. A new `PtyBackend::open($cols, $rows)` returns a contract `PtyPair` from `PtySystemFactory::new()`. Both sites spawn via `$pair->slave()->spawn()` and do I/O on `$pair->master()`. On POSIX this is the same canonical `PosixPtySystem` path the facade already delegates to.
  - **Availability gate.** `ProcessContainment::interactiveAvailable()` (L538-560, Windows refusal at L544) gains a Windows arm: `ConPtyApi::supported()`. It is stat/version-only, keeping the docblock's "never a spawn" law.
  - **Spawn command.** `interactiveSpawnCommand()` (L598) replaces `/bin/sh -c` with `WindowsShell::bash() -c` on Windows. Kill already flows through `terminatePid()`'s Windows arm (C-TUI-6.a).
  - **PtyRun.** `PtyRun.php` needs no edit, since it reaches ConPTY through `PtyDriver`. With `bashSandbox` on, its bwrap probe refuses honestly on Windows.
  - **Docs.** The README banner (L21-23) becomes "Windows: experimental — PHP 8.4+, Windows 10 1809+, Git for Windows' bash on PATH; `serve` stays Linux/macOS/WSL". The Install section and `docs/SERVER.md` "## Windows" (~L1389) say the same.
  - **CI.** A `candy-code-windows-smoke` job (windows-latest, PHP 8.4, ffi) runs `php bin/candy-code --version` and `vendor/bin/phpunit tests/Windows` only. The full suite would fatal on POSIX-only constants.
- files.create: `candy-code/src/Support/PtyBackend.php`
- files.modify:
  - `candy-code/src/Support/PtyDriver.php`: `run()` L106-140.
  - `candy-code/src/Tools/Concerns/CapturesProcessOutput.php`: `runCapturedInteractive()` L505-530.
  - `candy-code/src/Support/ProcessContainment.php`: `interactiveAvailable()`, `interactiveSpawnCommand()`.
  - `candy-code/README.md`: banner L21-23, Install L112.
  - `candy-code/docs/SERVER.md`: "## Windows" prose only, outside the generated tables.
  - `.github/workflows/ci.yml`: the new smoke job, gated on the candy-code affected set.
- tests:
  - Create `candy-code/tests/Support/PtyBackendTest.php`: it returns `PosixPtySystem` pairs on POSIX and takes an injected system.
  - Create `candy-code/tests/Windows/WindowsBashSmokeTest.php`: Bash runs `echo "%PATH%" !x` intact, and a timeout kills the tree.
  - Create `candy-code/tests/Windows/WindowsPtyRunSmokeTest.php`: PtyRun drives `read x; echo got:$x` over ConPTY.
  - Both Windows tests are skipped off Windows.
  - Keep green: `tests/Tools/PtyRunToolTest.php`, `tests/Support/PtyDriverRetainTest.php`, `tests/Permissions/PtyRunBashPermissionParityTest.php`.
- docs/guards:
  - DescriptorInheritanceGuardTest: the `ACCOUNTED_FOR_IN_LIBS` rows follow if the sugar-pty site set changes.
  - ReadmeRosterDriftTest: the README is touched outside the rosters.
  - The new tests are listed for the weld.
- serialized: [workflows] [docs:README.md] [docs:SERVER.md] [hot:candy-code/src/Support/ProcessContainment.php] [hot:candy-code/src/Tools/Concerns/CapturesProcessOutput.php]
- deps: C-TUI-6.a, C-TUI-6.c
- phase: LATE
- ruling: none
- risks: `candy-code/tests/bootstrap.php` and the async/fork paths are unproven on Windows (no pcntl means the blocking fallback). The smoke job is the first real measurement, so expect a follow-up lane.

### C-TUI-6.e — Windows self-update relaunch swap
- item: C-TUI-6 (sources: p2-miss: Windows support (×2))
- kind: gap · effort S
- approach: `SelfUpdate::run()` ends with `rename($temp, $phar)` (`SelfUpdate.php:451`), which fails on NT while the phar is the running image. The WINDOWS CAVEAT (L71-78) leaves the user with a manual file replace "until someone rules a relaunch dance".
  - On Windows, after verification and the `.old` copy (L444), the temp is staged as `<phar>.new`.
  - `WindowsSwap::launch()` spawns a detached `[PHP_BINARY, '-r', WindowsSwap::HELPER, <parentPid>, <new>, <phar>]`:
    - the helper is a fixed literal; the paths travel as argv only;
    - it goes through `ProcessContainment::spawnSpec`;
    - it uses `create_process_group` and NUL descriptors, so no fd is inherited.
  - The helper retries `rename(new, phar)` every 200 ms for up to 60 s, as soon as the parent's image lock clears. It writes `<phar>.swap.log`.
  - The verb exits 0 with `cli.selfupdate.staged_windows`: "update applies when this process exits".
  - `--rollback` (`rollback()` L730) uses the same dance on Windows.
  - POSIX paths are byte-unchanged.
- files.create: `candy-code/src/Cli/WindowsSwap.php`
- files.modify:
  - `candy-code/src/Cli/SelfUpdate.php`: the swap leg L450-456, `rollback()`, the WINDOWS CAVEAT docblock.
  - `candy-code/lang/en.php`: `cli.selfupdate.staged_windows`, `cli.selfupdate.swap_*`.
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`: an `ACCOUNTED_FOR` row. The helper deliberately outlives its parent, with NUL handles only.
  - `candy-code/tests/Support/ReadPathCensusTest.php`: the `PHP_BINARY` exec sink, with verdict "derived from the installation".
- tests:
  - Create `candy-code/tests/Cli/WindowsSwapTest.php`:
    - the argv shape;
    - the helper source passes `php -l`;
    - the helper run on Linux in a child against temp files, with an already-exited parent pid, renames and logs;
    - a target that never frees leaves `.new` plus a log line and no partial file.
  - Modify `candy-code/tests/Cli/SelfUpdateTest.php`:
    - the L457-463 pins follow the reworded caveat;
    - the Windows branch is selected through an injected `osFamily` seam.
- docs/guards: README's self-update paragraph, if it states the manual replace; DescriptorInheritanceGuardTest; ReadPathCensusTest
- serialized: [lang:cli.selfupdate.staged_windows, cli.selfupdate.swap_] [hot:candy-code/src/Cli/SelfUpdate.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: C-SES-5.a, C-SES-5.b (other area: self-update fixes on the same `SelfUpdate.php`), C-TUI-6.a
- phase: LATE
- ruling: **ANSWERED 2026-10-28 — yes.** The detached `PHP_BINARY -r` helper is the relaunch swap dance `SelfUpdate.php:76-78` waits on; this step is that dance.
- risks: an antivirus scanner holding the new file can outlast 60 s. The log plus the preserved `.old` keep the outcome recoverable, and the next `self-update` reports a leftover `.new`.

### C-TUI-6.k — Web dictation via the Web Speech API
- item: C-TUI-6 (sources: GAP-WEB-13, GAP-MOD-23)
- kind: gap · effort S
- approach:
  - **Why normal, not LATE.** This is the cheap, independent sliver the brief allows. It is S effort, client-only, needs no ruling, touches no protocol or PHP, and has no dependency.
  - `lib/dictation.ts`:
    - `dictationSupported(win)` requires `SpeechRecognition` or `webkitSpeechRecognition` AND `window.isSecureContext`. Plain-http serve on a LAN host is not a secure context, so the button stays hidden.
    - `createDictation({lang, onInterim, onFinal, onError})` returns `{start, stop}` with `continuous` and `interimResults` on.
  - `Composer.vue`:
    - adds a mic toggle, which shows only when supported;
    - shows interim text as a dim hint under the textarea;
    - inserts final text at the caret and never auto-sends;
    - Esc stops dictation;
    - maps `not-allowed` / `no-speech` errors to a short inline notice.
  - **Privacy.** The button's title discloses that the browser vendor's speech service hears the audio. Nothing reaches the candy-code server.
  - **Strings.** They stay hard-coded English like the rest of today's chrome. C-TUI-6.g migrates them later.
- files.create: `candy-code-web/src-web/lib/dictation.ts`
- files.modify: `candy-code-web/src-web/components/Composer.vue` (toolbar button, interim hint, caret insert)
- tests: create `candy-code-web/src-web/lib/__tests__/dictation.spec.ts` (feature detection including an insecure context, lifecycle against a fake recognition class, error mapping) and `candy-code-web/src-web/components/__tests__/composerDictation.spec.ts` (hidden when unsupported; the toggle; final text inserted at the caret and not sent)
- docs/guards: vitest; dist rebuild at weld
- serialized: [hot:candy-code-web/src-web/components/Composer.vue]
- deps: none
- phase: normal
- ruling: none
- risks: C-WEB-4 and C-CTX-3 also edit `Composer.vue`, so keep this step out of their wave. Firefox has no SpeechRecognition, so the button is simply absent there.

### C-TUI-6.f — Web i18n infrastructure: `server.strings` + chrome migration  <!-- RULING ANSWERED 2026-10-28: chrome/UI strings only (server.strings); server/tool/model text stay untranslated -->
- item: C-TUI-6 (sources: GAP-PLAT-10, p2-miss: WEB-7c i18n)
- kind: gap · effort M
- approach: Web chrome strings are hard-coded English in the `.vue` files, and there is no i18n code in `src-web`. `candy-code/src/Lang.php` already resolves exact → base → `en` per key.
  - **Server.**
    - `WebStrings` serves ONLY the `web.` key prefix from `lang/en.php`. It resolves a requested tag key by key through `T::translate($key, [], $tag)` and returns raw `{placeholder}` templates.
    - It validates the tag with `Lang::useLaunchLocale()`'s regex before the tag can become part of a `lang/<tag>.php` path, which closes a path-traversal door.
    - New Read-scope method `server.strings {locale?}` → `{locale, strings}`, registered in `ServerMethods::register()` (L41-46).
  - **English bundle.** The client needs English before or without a server (`ConnectionBanner` paints while disconnected). `scripts/gen-web-strings.php --write|--check` writes `docs/protocol/web-strings.en.json` under `Lang::inLocale('en')`. `candy-code-web/scripts/gen-web-strings.mjs` (`npm run gen:strings`) turns it into `src-web/lib/strings.en.generated.ts`. This is split-repo-safe like `gen-protocol.mjs`, and it is deliberately NOT in the protocol gen, so later string lanes do not serialize on `[protocol]`.
  - **Client.**
    - `lib/i18n.ts` exports `t(key, params?)`, resolving active catalogue → English bundle → raw key, with `{name}` interpolation.
    - `stores/locale.ts` picks `navigator.languages[0]`. On `connection.onHello()` it calls `server.strings` only if `features.methods` lists it, and keeps English on failure.
  - **Scope.** Migrate `App.vue`, `StatusBar.vue`, `SidePanels.vue` and `ConnectionBanner.vue` to `web.chrome.*`. Server-sent text, tool output and model text are never translated.
- files.create:
  - `candy-code/src/Protocol/WebStrings.php`
  - `candy-code/scripts/gen-web-strings.php`
  - `candy-code/docs/protocol/web-strings.en.json` (generated)
  - `candy-code-web/scripts/gen-web-strings.mjs`
  - `candy-code-web/src-web/lib/strings.en.generated.ts` (generated)
  - `candy-code-web/src-web/lib/i18n.ts`
  - `candy-code-web/src-web/stores/locale.ts`
- files.modify:
  - `candy-code/src/Protocol/Methods/ServerMethods.php`: `register()`, a new `strings()`.
  - `candy-code/src/Protocol/Schema/MethodSchemas.php`: `server.strings`.
  - The gen outputs: `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md` (method table plus a short "Web UI language" paragraph), `candy-code-web/src-web/protocol/generated.ts`.
  - `candy-code/lang/en.php`: `web.chrome.*`.
  - `candy-code-web/package.json`: a `gen:strings` script.
  - `candy-code-web/src-web/App.vue`, `components/StatusBar.vue`, `components/panels/SidePanels.vue`, `components/ConnectionBanner.vue`.
- tests:
  - Create `candy-code/tests/Protocol/WebStringsTest.php`:
    - only `web.` keys are exposed;
    - base-language fallback;
    - `../x` and `en/../../y` are refused;
    - placeholders survive.
  - Create `candy-code/tests/Protocol/WebStringsDriftTest.php`: both `--check` gens.
  - Create `candy-code-web/src-web/lib/__tests__/i18n.spec.ts`.
  - Create `candy-code-web/src-web/stores/__tests__/locale.spec.ts`: loads on hello; skipped on an old server; English kept on error.
  - Create `candy-code-web/src-web/__tests__/i18nCoverage.spec.ts`: every `t('web.…')` literal in `src-web` exists in the English bundle. This is the guard C-TUI-6.g leans on.
  - Keep green: `components/__tests__/statusBar.spec.ts`, `connectionBanner.spec.ts`, `__tests__/app.spec.ts` (the English is unchanged).
- docs/guards:
  - ProtocolSchemaDriftTest and ServerProtocolDocumentationDriftTest, plus both protocol gens `--check`.
  - The new WebStringsDriftTest.
  - A ReadPathCensusTest row: `WebStrings` reads the installation's `lang/en.php` key list.
  - vitest.
  - dist rebuild at weld.
- serialized: [protocol] [lang:web.chrome.] [docs:SERVER.md] [hot:candy-code-web/src-web/App.vue] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/src-web/components/panels/SidePanels.vue] [hot:candy-code-web/src-web/components/ConnectionBanner.vue] [hot:candy-code-web/package.json] [hot:candy-code/tests/Support/ReadPathCensusTest.php]
- deps: none
- phase: LATE
- ruling: **ANSWERED 2026-10-28 — yes.** Chrome/UI strings only (`server.strings`); server, tool and model text stay untranslated. (R6 deferral reversed.)
- risks: `navigator.language` and the server's launch `LANG` can differ. This step follows the browser; a picker is out of scope.

### C-TUI-6.g — Web i18n: migrate the remaining components
- item: C-TUI-6 (sources: GAP-PLAT-10, p2-miss: WEB-7c i18n)
- kind: gap · effort M
- approach: Mechanical migration of the other 39 `.vue` files under `src-web` (43 in total, minus the 4 done in C-TUI-6.f) to `t()`.
  - Key prefixes by area: `web.composer.*`, `web.transcript.*`, `web.panels.*`, `web.agents.*`, `web.approvals.*`, `web.settings.*`, `web.grid.*`, `web.media.*`, `web.views.*`.
  - This includes C-TUI-6.k's dictation strings and whatever chrome the normal-phase web lanes added before this wave.
  - Regenerate the English bundle with `gen-web-strings.php --write` + `npm run gen:strings`.
  - No protocol change: `server.strings` already serves the whole `web.` prefix.
  - English output stays byte-identical, so existing component specs stay green unchanged.
- files.create: none
- files.modify:
  - The remaining `candy-code-web/src-web/**/*.vue` (`components/*.vue` except StatusBar and ConnectionBanner, `components/{agents,approvals,grid,panels,settings}/*.vue` except SidePanels, `views/*.vue`), including `components/Composer.vue`.
  - `candy-code/lang/en.php`: the `web.` prefixes above.
  - `candy-code/docs/protocol/web-strings.en.json` and `candy-code-web/src-web/lib/strings.en.generated.ts`, regenerated.
- tests: none created. `src-web/__tests__/i18nCoverage.spec.ts` (from C-TUI-6.f) must stay green; every existing `components/__tests__/*.spec.ts` stays green.
- docs/guards: WebStringsDriftTest; vitest; dist rebuild at weld
- serialized: [lang:web.] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web — nearly every component; run as the ONLY web lane in its wave]
- deps: C-TUI-6.f, C-TUI-6.k
- phase: LATE
- ruling: none
- risks: 39 files is at the top of M. If the wave needs it, split by directory into two lanes: `components/{panels,agents,approvals,grid,settings}` and the rest.

### C-TUI-6.i — Screen-reader core + `screenReader` setting
- item: C-TUI-6 (sources: GAP-UI-30)
- kind: gap · effort M
- approach: Today there is reduced motion (`CANDY_CODE_REDUCED_MOTION`, read at `Bootstrap.php:1238`, launch-only), contrast and a row cursor, but no screen-reader mode.
  - `ScreenReaderMode` is process-global and reads `UiSettings::bool('screenReader')`. That read is memoised and Live: `Chat::applySettings()` already calls `UiSettings::forget()` on save.
  - Helpers for C-TUI-6.j:
    - `border(Border): Border` maps to `Border::hidden()` and keeps the title text.
    - `glyph(string): string` maps raw box-drawing glyphs to spaces.
    - `menuRow(int $n, int $of, string $label, bool $selected): string` gives a textual position plus a `>` marker, not reverse video alone.
    - `cue(): ?\Closure` is a BEL Cmd, never written from `view()`.
  - `AgentLiveRegistry::staticSpinner()` (L297-301) becomes `self::$staticSpinner || ScreenReaderMode::enabled()`. The frame index (L280) reads the getter, so the spinner goes static without touching Bootstrap.
  - New `screenReader` Bool setting:
    - Interface category, Cosmetic, `withLayered()`, Live;
    - user tier only, like `vimMode`;
    - template `InterfaceSettings.php` ~L299.
  - No renderer wiring in this step.
- files.create: `candy-code/src/Tui/ScreenReaderMode.php`
- files.modify:
  - `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`: a `screenReader` definition.
  - `candy-code/src/Config/LayeredSettings.php`: the LAYERED list ~L600-625.
  - `candy-code/src/Config/Settings/UiSettings.php`: `KEYS` ~L44-60.
  - `candy-code/src/Agents/Live/AgentLiveRegistry.php`: L280, `staticSpinner()`.
  - `candy-code/lang/en.php`: `settings.screenReader.label`, `settings.screenReader.help`.
  - `candy-code/docs/SETTINGS.md` and the README `<!-- settings:layered -->` roster: regenerated by `php tools/gen-settings-doc.php --write` (cwd candy-code).
  - `candy-code/docs/ENVIRONMENT.md` L65: "there is no settings key, the switch is launch-only" now names `screenReader`.
- tests:
  - Create `candy-code/tests/Tui/ScreenReaderModeTest.php`: the helpers; off by default; a project tier cannot set it; a save applies live.
  - Modify `candy-code/tests/Agents/Live/AgentLiveRegistryTest.php`: the setting pins the frame to 0 with the env switch unset.
- docs/guards: SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; DocFigureProseDriftTest (the settings count words); `tools/check-one-type-per-file.php`
- serialized: [lang:settings.screenReader.] [docs:SETTINGS.md] [docs:README.md] [docs:ENVIRONMENT.md] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/UiSettings.php] [hot:candy-code/src/Agents/Live/AgentLiveRegistry.php]
- deps: none
- phase: LATE
- ruling: none
- risks: `staticSpinner()` now reads settings. It must stay cheap per frame, which the memo guarantees, and `AgentLiveRegistryTest` must run under the HOME sandbox.

### C-TUI-6.j — Screen-reader wiring in the renderers + bell cues
- item: C-TUI-6 (sources: GAP-UI-30)
- kind: gap · effort M
- approach: With `screenReader` on:
  - **Borders.** The 7 `Border::` sites in `src/Renderer.php` (L5467, 6069, 6186, 6538, 6917, 7090, 7894) go through `ScreenReaderMode::border()`. So do the raw `│`/`─` glyph sites: about 15 in `Renderer.php` and about 5 in `Tui/Renderer.php` (e.g. L2192).
  - **Overlays.** The overlay chain (`Renderer.php` ~L2004-2024: key help → permission → palette → session picker → directory picker → history search) REPLACES the body instead of compositing a Veil over the transcript. Composited boxes read as interleaved noise.
  - **Menus.** Palette, session picker and permission options (`renderPalette` L6441, `renderSessionPicker` L6373, `renderPermissionPrompt` L6964) paint `menuRow()` with "n of m" and `>`. No new keys, so KeyBindingDriftTest does not move.
  - **Bell cues.**
    - `TerminalNotifier::fromConfig()` (`TerminalNotifier.php:61`) upgrades `notify: off` to `bell` when screen-reader mode is on. That covers turn end (`HandlesTurnCompletion.php:343`) and goal end with no Chat.php edit.
    - `Chat::requestPermission()` (L2761) batches `ScreenReaderMode::cue()`, so an ask is audible.
- files.create: none
- files.modify:
  - `candy-code/src/Renderer.php`: the overlay chain ~L2004-2024, the 7 border sites, the raw glyph sites, `renderPalette`, `renderSessionPicker`, `renderPermissionPrompt`.
  - `candy-code/src/Tui/Renderer.php`: the raw glyph sites only.
  - `candy-code/src/Tui/TerminalNotifier.php`: `fromConfig()`.
  - `candy-code/src/Chat.php`: `requestPermission()`.
  - `candy-code/lang/en.php`: `tui.sr.*`, e.g. "{n} of {m}".
- tests:
  - Create `candy-code/tests/Renderer/ScreenReaderRenderTest.php`:
    - no U+2500–U+257F in a full frame with the mode on;
    - palette rows carry the position and marker;
    - an open overlay replaces the body;
    - mode off is byte-identical to today, which needs golden pins.
  - Create `candy-code/tests/Chat/ScreenReaderBellCueTest.php`: a permission ask returns a BEL Cmd.
  - Modify `candy-code/tests/Chat/TurnEndNotificationTest.php`: `notify: off` plus the mode still bells.
- docs/guards: a `docs/SETTINGS.md` help-text tweak only if the 6.i help changes (regenerate); RouteTraitParityTest stays green (no new Msg)
- serialized: [renderer:Renderer overlay chain ~L2004-2024, border/glyph sites, renderPalette, renderSessionPicker, renderPermissionPrompt; Tui/Renderer glyph sites] [chat:requestPermission] [lang:tui.sr.] [hot:candy-code/src/Tui/TerminalNotifier.php]
- deps: C-TUI-6.i
- phase: LATE
- ruling: none
- risks:
  - `Renderer.php` is the area's hottest file. This lane must not share a wave with any other `[renderer]` step.
  - Hardware-cursor placement, which screen readers track, is not changed here.

### C-TUI-6.l — Voice core: `Transcriber`, `AudioRecorder`, voice settings
- item: C-TUI-6 (sources: GAP-MOD-23, OC-new#10)
- kind: gap · effort M
- approach: `src/Media/Voice/` does not exist. This step builds the core with no key or protocol wiring.
  - **`AudioRecorder`.** It picks `arecord -q -f S16_LE -r 16000 -c 1 -d <max> <wav>`, else `sox -q -d -r 16000 -c 1 -b 16 <wav> trim 0 <max>`, else "unavailable" with a reason.
    - It spawns through `ProcessContainment::spawnSpec` with fds 0-2 on `/dev/null`, writing a 0600 WAV under `~/.candy-code/cache/voice/`.
    - The `-d`/`trim` cap means an orphan stops itself.
    - Stop sends SIGINT, so the WAV header is finalized, then the bounded `terminate()` ladder.
  - **`VoiceSession`.** Process-global static state (the `StatusLineCommand` pattern) with `start`, `active`, `stop`, `cancel` and `resetForTesting`. It registers a shutdown cancel.
  - **`Transcriber`.** A Guzzle multipart POST to an OpenAI-compatible `<base>/audio/transcriptions` with `file`, `model` and optional `language`, which returns trimmed text.
    - The URL is built as `rtrim(base,'/') . '/audio/transcriptions'`, not via `base_uri` plus a leading-slash path, which drops a `/v1` suffix.
    - `connect_timeout` plus a low-speed stall abort only, never a total timeout (E646).
    - Errors are a `TranscriptionException` that never carries the key.
    - The WAV is deleted after use.
  - **`TranscribeCmd::cmd()`.** A fork child plus loop poll, mirroring `StatusLineCommand::cmd()`/`settle()` (`StatusLineCommand.php:492-558`), with the blocking fallback when pcntl is missing. It resolves `VoiceTranscribedMsg` or `VoiceFailedMsg`.
  - **Settings** in `MediaSettings.php`, all `withLayered()`, following the `sd.baseUrl` egress posture: `voice.baseUrl` (Url, RiskClass::Egress, user tier, NO default), `voice.apiKey` (Secret, `${VAR}`), `voice.model` (default `whisper-1`), `voice.language`, and `voice.maxSeconds` (default 120, range 5–600).
- files.create:
  - `candy-code/src/Media/Voice/VoiceConfig.php`
  - `candy-code/src/Media/Voice/AudioRecorder.php`
  - `candy-code/src/Media/Voice/AudioRecording.php`
  - `candy-code/src/Media/Voice/VoiceSession.php`
  - `candy-code/src/Media/Voice/Transcriber.php`
  - `candy-code/src/Media/Voice/TranscriptionException.php`
  - `candy-code/src/Media/Voice/TranscribeCmd.php`
  - `candy-code/src/App/VoiceTranscribedMsg.php`
  - `candy-code/src/App/VoiceFailedMsg.php`
- files.modify:
  - `candy-code/src/Config/Settings/Definitions/MediaSettings.php`: the `voice.*` definitions.
  - `candy-code/src/Config/LayeredSettings.php`: LAYERED, beside `sd.*` ~L570.
  - `candy-code/lang/en.php`: `settings.voice.*`, `media.voice.*`.
  - `candy-code/docs/SETTINGS.md` and the README settings:layered roster, via gen-settings-doc.
  - `candy-code/docs/MEDIA.md`: a new "Voice input" section covering configuration and the egress posture.
  - `candy-code/tests/Support/ReadPathCensusTest.php`: the recorder exec and WAV read.
  - `candy-code/tests/Support/DescriptorInheritanceGuardTest.php`: the long-lived recorder and the fork child.
- tests: create under `candy-code/tests/Media/Voice/`:
  - `AudioRecorderTest.php`: the argv per backend; none → unavailable; routed through spawnSpec; 0600; the cap arg is present.
  - `TranscriberTest.php`: a Guzzle MockHandler checks the multipart fields, that `/v1` is kept, that 4xx errors are key-free, and that there is no `timeout` option.
  - `VoiceSessionTest.php`.
  - `TranscribeCmdTest.php`: the fork path and the no-pcntl fallback.
  - `VoiceConfigTest.php`: off while `voice.baseUrl` is unset; a project tier cannot set it.
- docs/guards: SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; DocFigureProseDriftTest; ReadPathCensusTest; DescriptorInheritanceGuardTest; `tools/check-one-type-per-file.php`
- serialized: [lang:settings.voice., media.voice.] [docs:SETTINGS.md] [docs:README.md] [docs:MEDIA.md] [hot:candy-code/src/Config/Settings/Definitions/MediaSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/tests/Support/ReadPathCensusTest.php] [hot:candy-code/tests/Support/DescriptorInheritanceGuardTest.php]
- deps: none
- phase: LATE
- ruling: **ANSWERED 2026-10-28 — ADOPT.** No default endpoint; voice off until a user-tier sets `voice.baseUrl`; audio never leaves the machine by default.
- risks: no recorder exists on stock Windows or macOS without sox. That is reported as "unavailable", not faked; an ffmpeg backend is a follow-up.

### C-TUI-6.m — TUI push-to-talk wiring
- item: C-TUI-6 (sources: GAP-MOD-23)
- kind: gap · effort M
- approach: Terminals deliver no key-up, so "push-to-talk" is a toggle.
  - Alt+V starts recording, through an arm before the generic Char arm in `HandlesKeys.php`, beside Alt+M at ~L959.
  - Alt+V again stops it and returns `TranscribeCmd::cmd()`.
  - Esc while recording cancels and deletes the WAV, checked before the soft-cancel arms.
  - With voice off, Alt+V toasts "set `voice.baseUrl`".
  - `VoiceTranscribedMsg` inserts its text at the caret via `insertString()`, beside the `ClipboardImagePastedMsg` arm (`HandlesSessionSurface.php:264`). It never sends. `VoiceFailedMsg` raises a notice.
  - `renderStatusBar` (`Renderer.php:2245`) paints a static "● rec · Alt+V stop · Esc cancel", or "transcribing…". There is no elapsed clock, so no new tick is needed.
  - New row: `chat.voice` `Alt+V` in `KeyBindingRegistry` (CONTEXT_CHAT, beside `chat.plan-mode` L979). User overrides apply through the existing `keybindings` setting.
- files.create: none
- files.modify:
  - `candy-code/src/Commands/KeyBindingRegistry.php`: the `chat.voice` row.
  - `candy-code/src/Chat/Handlers/HandlesKeys.php`: the Alt+V and recording-Esc arms.
  - `candy-code/src/Chat/Handlers/HandlesSessionSurface.php`: the two Msg arms.
  - `candy-code/src/Renderer.php`: `renderStatusBar()`.
  - `candy-code/lang/en.php`: `keys.chat.voice`, `chat.voice.*`.
  - `candy-code/README.md`: `### Keys` L235.
  - `candy-code/docs/MEDIA.md`: the voice usage paragraph.
- tests: create `candy-code/tests/Chat/VoicePushToTalkTest.php`, with a fake recorder and transcriber. It pins start/stop, text inserted and not sent, Esc cancelling and deleting the WAV, the voice-off toast, and Alt+V inert while the palette or a modal is open.
- docs/guards: KeyBindingDriftTest; RouteTraitParityTest (the two new Msg arms); ReadmeRosterDriftTest
- serialized: [hot:candy-code/src/Commands/KeyBindingRegistry.php] [hot:candy-code/src/Chat/Handlers/HandlesKeys.php] [hot:candy-code/src/Chat/Handlers/HandlesSessionSurface.php] [renderer:Renderer::renderStatusBar] [lang:keys.chat.voice, chat.voice.] [docs:README.md] [docs:MEDIA.md]
- deps: C-TUI-6.l
- phase: LATE
- ruling: **ANSWERED 2026-10-28 — Alt+V.** Alt+V is free in `KeyBindingRegistry`; Ctrl+V stays `chat.paste-image` (L923); the permission prompt's bare `v` is not a chord.
- risks: Mac terminals without Option-as-Meta deliver `√` for Alt+V (see C-TUI-7.b), so the README Keys row must name the `keybindings` override.

### C-TUI-6.n — Web MediaRecorder + `audio.transcribe` + the `canTranscribe` flag
- item: C-TUI-6 (sources: GAP-MOD-23, GAP-WEB-13, OC-new#10)
- kind: gap · effort M
- approach:
  - **Server.**
    - `MediaMethods::register()` (L91-111) gains `audio.transcribe`: Write scope, `{audioBase64, mime, language?}`, decoded size ≤ 8 MiB (under `MAX_CLIENT_FRAME_BYTES`). It writes a 0600 temp, forks the C-TUI-6.l `Transcriber` via `TurnController::forkPayload()` (`TurnController.php:913`) and answers `{jobId}`. This follows `generate.submit`'s precedent, because a handler that waited would stall the serve loop.
    - It also gains `audio.status` (Read), which polls `{state, text?, error?}`. With voice off, both answer `voice_unconfigured`.
  - **Flag.** `MediaAffordance::flags()` (`MediaAffordance.php:85`) adds `transcribe`, which is true when `voice.baseUrl` is set. It rides `server.hello` `features.media`, beside image/video. That is where the `canGenerateVideo` precedent reads it (`stores/connection.ts:89-91`); `server.info` carries no media flags. It is optional in the schema, so older clients and servers interoperate.
  - **Web.**
    - `lib/recorder.ts` wraps getUserMedia + MediaRecorder, choosing opus/webm, then ogg, then mp4, with the `voice.maxSeconds` cap.
    - `connection.ts` adds `canTranscribe`.
    - `dictation.ts` gains `pickEngine()`: `server` when `canTranscribe` (the user's own endpoint), else the browser engine, else none.
    - `Composer.vue`'s mic button uses that engine. Strings are `web.composer.voice.*`.
- files.create: `candy-code-web/src-web/lib/recorder.ts`
- files.modify:
  - `candy-code/src/Protocol/Methods/MediaMethods.php`: `register()`, new `transcribe()`/`transcribeStatus()`.
  - `candy-code/src/Protocol/Schema/MethodSchemas.php`: `audio.*` and `features.media.transcribe` (~L103-107).
  - `candy-code/src/Media/Capability/MediaAffordance.php`: `flags()`.
  - The gen outputs: `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`.
  - `candy-code/docs/MEDIA.md`: "Server and web surface" L167.
  - `candy-code-web/src-web/stores/connection.ts`: L89-91.
  - `candy-code-web/src-web/lib/dictation.ts`.
  - `candy-code-web/src-web/components/Composer.vue`.
  - `candy-code/lang/en.php` + the regenerated English bundle: `web.composer.voice.*`.
- tests:
  - Create `candy-code/tests/Protocol/AudioTranscribeMethodTest.php`:
    - the scopes;
    - the size cap is refused;
    - `voice_unconfigured`;
    - the job reaches `done` with a fake transcriber;
    - no key in errors.
  - Modify `candy-code/tests/Media/MediaAffordanceTest.php` (the `transcribe` flag) and `candy-code-web/src-web/stores/__tests__/connectionMedia.spec.ts` (`canTranscribe`).
  - Create `candy-code-web/src-web/lib/__tests__/recorder.spec.ts` (mime pick, cap, permission denied) and `candy-code-web/src-web/components/__tests__/composerVoice.spec.ts` (server engine preferred; text inserted, not sent).
- docs/guards: ProtocolSchemaDriftTest; ServerProtocolDocumentationDriftTest; both protocol gens `--check`; WebStringsDriftTest; vitest; dist rebuild at weld
- serialized: [protocol] [docs:SERVER.md] [docs:MEDIA.md] [lang:web.composer.voice.] [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/stores/connection.ts]
- deps: C-TUI-6.l, C-TUI-6.k, C-TUI-6.g (so the new strings are born as `t()` keys)
- phase: LATE
- ruling: none (inherits C-TUI-6.l's endpoint ruling)
- risks: `audio.transcribe` is new egress reachable from any Write-scope client. It is bounded by the user-tier `voice.baseUrl` and the size cap, but name it in the SECURITY posture section of `docs/MEDIA.md`.

### C-TUI-6.h — Community locale `candy-code/lang/<code>.php`
- item: C-TUI-6 (sources: GAP-PLAT-10)
- kind: gap · effort M
- approach: Only `lang/en.php` ships, at 3,292 lines. `Lang::useLaunchLocale()` already picks `lang/<tag>.php` from `LC_ALL`/`LC_MESSAGES`/`LANG`, and C-TUI-6.f's `server.strings` serves the same file's `web.*` slice to the browser.
  - **RULING ANSWERED 2026-10-28 (owner): ship the SET, not one.** All 11 recommended non-English codes from LOCALES.md — fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko — bare base codes per its rule 1, regional file only where the set already carries one (pt-br). Translation source: machine translation with machine review (no native-review gate).
  - It translates every key, `web.*` included, and keeps every `{placeholder}`.
  - Model-read text (tool descriptions, prompt layers) is not in `lang/` and stays English.
  - Add a short "Languages" note to the README listing ALL shipped codes (`LANG=fr_FR.UTF-8 candy-code` as the example) and the translation and parity rule to `docs/CONTRIBUTING.md`.
  - **Parity policy, strict by default.** `LangParityTest::testEveryLocaleCarriesEveryEnglishKey` (L59-71) requires the exact English key set. Once the locale ships, every later lane that adds an English key must translate it.
    - So this is the plan's FINAL lane, after every other lane (all LATE items included) that adds `lang/en.php` keys.
    - The alternative is to relax the test to "present keys must match placeholders, missing keys fall back to English". That is a one-method edit to `LangParityTest`, and only that option allows landing earlier.
- files.create: `candy-code/lang/<code>.php` × **11** (fr.php de.php es.php pt.php pt-br.php zh-cn.php zh-tw.php ja.php ru.php it.php ko.php; only `en.php` exists today, so all are absent)
- files.modify: `candy-code/README.md` (a Languages note near Install L112); `candy-code/docs/CONTRIBUTING.md` (translation and parity rule); `candy-code/tests/LangParityTest.php` ONLY under the relaxed option
- tests:
  - `LangParityTest` picks the file up by glob (L42-50), with no edit under strict.
  - Create `candy-code/tests/LangCommunityLocaleTest.php`:
    - `Lang::inLocale('<code>')` resolves sample keys to non-English;
    - `WebStrings::forLocale('<code>')` answers `<code>`;
    - a regional tag (`<code>-xx`) falls back to the base.
- docs/guards: LangParityTest; LangLaunchLocaleTest; the generated pages stay English because the generators run under `Lang::inLocale('en')`
- serialized: [lang:whole file ×11 — each locale file is read whole, one lane owns all 11] [docs:README.md]
- deps: C-TUI-6.g, C-TUI-6.e, C-TUI-6.j, C-TUI-6.m, C-TUI-6.n (and, under strict, every other lane in the plan that adds `lang/en.php` keys)
- phase: LATE
- ruling: **ANSWERED 2026-10-28** — the 11-code recommended set; machine translation + machine review; strict parity, lands last.
- risks: a test asserting English `Lang::t()` text without pinning the locale goes red on a host whose `LANG` is `<code>`. CI runs `C.UTF-8`, so audit for this before shipping.

## Notes for the orchestrator

**Chat.php discipline.** No step here touches the `Chat.php` view/layout regions that P-SESSION-TABS, P-AGENT-ATTACH, P-CTX-BREAKDOWN and P-IMAGE-MENU will own. Each Chat.php touch is one named method:
- `handleDiffCommand` (2.e)
- new `handleCopyCommand` (3.a)
- new `rewindToCheckpoint` (3.d)
- `programOptions` + new `handleRecapCommand` (3.g)
- new `handleDoctorCommand` (5.a)
- `handleSettingsCommand` (5.c)
- new `switchProvider` (5.e)
- `init` + the `applyCommandResult` ClearTranscript/SwitchSession arms (7.a)
- `requestPermission` (6.j)

The `READ_ONLY_COMMANDS` roster (`Chat.php:10266`, [commands]) is touched by 3.a, 3.g and 5.a. Put at most one of those per wave, or have the weld merge the one-word appends.

**Shared hot files, with the steps that touch them:**
- `candy-code/src/App/App.php` shell slots: 2.e, 3.d, 5.e. P-AGENT-ATTACH and P-IMAGE-MENU likely touch it too. Run one App-slot lane per wave.
- `candy-code/src/Tui/Renderer.php::renderView()` slot chain (~L642-660, [renderer]): 2.e, 3.d, 5.e, 6.j, plus P-IMAGE-MENU's `renderMediaForm`.
- Chat handler traits:
  - `src/Chat/Handlers/HandlesKeys.php`: 1.a, 3.d, 3.e, 6.m.
  - `HandlesRunControl.php`: 3.g, 4.c, 7.a.
  - `HandlesTurnCompletion.php`: 5.c.
  - `HandlesSessionSurface.php`: 6.m.
  - New Msg arms in any of these are pinned by `tests/Chat/RouteTraitParityTest.php`.
- `candy-code/src/Commands/KeyBindingRegistry.php` + README `### Keys`: 2.e, 3.d, 3.e, 5.e, 6.m, and C-EXT-5 (key-bound commands).
- The settings files `Definitions/InterfaceSettings.php` + `LayeredSettings.php` + `Settings/UiSettings.php`: 2.c, 3.g, 4.a, 6.i. `Definitions/MediaSettings.php`: 6.l. Each regenerates `docs/SETTINGS.md` and the README layered roster through `tools/gen-settings-doc.php`, so run one settings-adding lane per wave, or regenerate at weld.
- `docs/COMMANDS.md` + the README commands roster (`tools/gen-command-docs.php`): 2.e, 3.a, 3.g, 5.a, 5.c.
- Census test files:
  - `candy-code/tests/Support/ReadPathCensusTest.php`: 4.a, 5.a, 5.b, 5.c, 5.d, 6.a, 6.e, 6.f, 6.l, 7.b.
  - `tests/Config/Settings/SettingsWriterCensusTest.php`: 3.e, 5.b, 5.d.
  - `tests/Support/DescriptorInheritanceGuardTest.php`: 6.a, 6.e, 6.l.
  - Rows are append-only. Let the weld merge them, or serialize.
- Single files shared with other areas:
  - `src/Support/StartupSummary.php`: 7.a and C-SES-6.
  - `src/Support/ProcessContainment.php`: 6.a, 6.d and C-TOOL-4.
  - `src/Cli/SelfUpdate.php`: 6.e and C-SES-5.
- Web files:
  - `candy-code-web/src-web/components/Composer.vue`: 6.k, 6.g, 6.n, plus C-WEB-4 and C-CTX-3.
  - `stores/settings.ts`: 2.f, 4.b, plus C-WEB-4.
  - `components/DiffView.vue` + `lib/diff.ts`: 2.f and C-SEC-2.
  - `TranscriptItem.vue`, `stores/session.ts`, `views/SessionView.vue`: 3.b.
- The committed `candy-code-web/dist/`: rebuild it once per wave at the weld, never per lane, or every pair of web lanes collides.

**Owner rulings needed:**
- R4 (1.b): flip VisualLine j/k and make `V` line-wise. The default skips the step.
- The message-picker chord (3.d): idle Esc Esc.
- 6.a: the Windows shell and support tier.
- 6.e: the detached `PHP_BINARY -r` relaunch helper.
- 6.f: reverse the old R6 deferral; web i18n scope is chrome-only first.
- 6.h: which locale and translation source, and the LangParityTest policy. Under strict parity, every lane after the locale ships must translate its new keys, so 6.h lands in the very last wave.
- 6.l: the voice endpoint and audio egress default. Proposed: no default endpoint, voice off until `voice.baseUrl` is set.
- 6.m: the push-to-talk chord (Alt+V).

Chord defaults that need no ruling: 3.e `Alt+O`. Recap default: 3.g `sessions.awayRecap` on, Claude Code parity, at most one cheap title-model call per absence.

**Cross-area dependencies and ownership:**
- 2.f runs after C-SEC-2.
- 5.a owns the CC-new#11 AppArmor userns doctor probe, so C-TOOL-4 must not add a second one.
- 6.a runs after C-TOOL-4, which owns the macOS setsid/timeout half; 6.a owns only the Windows arm.
- 6.e runs after C-SES-5.
- The web "expand all tool cards" (GAP-UI-32 web half) stays in C-WEB-4. 3.e is TUI-only.

**Corrections to the evidence:**
- k3-dorm#2 says the sugar-forms text-object code has zero consumers. In fact `sugar-readline/src/Mode/ViMode.php:347-386` consumes it; only the composer path is dormant.
- candy-code's child-lifetime rows live in `tests/Support/DescriptorInheritanceGuardTest.php`; `tools/check-child-lifetimes.php` skips candy-code. The brief's fork law should name both.
- The `canTranscribe` flag (6.n) rides `server.hello` `features.media`, beside `canGenerateVideo`, not `server.info`.
- `audio.transcribe` is job + poll (the `generate.submit` precedent).

**For the COMPARISON.md owner:** TUI-5-P1 asks for a truth-flip of §6.6's "launch-notice when negotiation didn't land" wording to "an env-name probe, shown once per version". Do it in the doc, not in a lane.

**Not verified (no runtime, or no Windows host):**
- ConPTY FFI feasibility on PHP 8.4/Windows (6.b-6.d).
- The exact checkpoint counting per user row (3.b, 3.c). Lanes must read it from the store.
- Whether the palette's `ShowDiff` routes through `handleDiffCommand` (2.e).
- Whether `server.info.providers` lists `echo` (5.e).
- EPIPE behaviour when a statusLine command never reads stdin (4.c; pin it with a test).
