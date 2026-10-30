# C-TUI-3.e — REVIEW-1

VERDICT: CLEAN — 0C/0M/0m

Scope: abbade61d (detain %ae/%ce, zero trailers) on master aa5ddf638; 8 files, exactly the design block's set + the declared in-step guard flips. No forbidden bytes (suite-figure.json, durations.tsv, protocol trio, dist absent from diff). Tree claims re-read from landed blobs, not the dirty main tree.

P1 session-tier: SettingsWriter::write verified on blob — refusals() gates, `SettingsTier::Session` branch returns SESSION_TARGET via SessionSettings::apply BEFORE targetPath/door; SESSION_TARGET const real. VerboseToggleKeyTest (5T/23A green at tip) pins: door throws-closure never knocked, config.json never minted, second press restores SAVED baseline with byte compare, mid-turn lands at once with pendingSettingsApply()===[] (expandToolOutput is withApplyMode(Live) and not in ENGINE_SETTINGS — verified), writer-less Chat reports + no overlay + no flip (note (d) pinned). Toast-generation guard (Chat.php:2109) makes the replaced generic-saved toast's expiry tick inert — code trace confirmed the batch(toastTick,toastTick) shape is safe (Cmd::batch accepts the Closures; sugar-core Cmd.php:52).
P2 Alt+O free at base: registry grep at aa5ddf638 shows no Alt+O row (Ctrl+O chat.tool-output is a different chord) and no alt-'o' Char arm; preceding alt-char arms are b/f/m/a only; generic Char arms all exclude alt. No shadowing.
P3 live-render truth: Renderer reads UiSettings::bool('expandToolOutput') per render (Renderer.php:745-752; plan's :702-709 cite is pre-existing drift, not lane); UiSettings fingerprint includes SessionSettings::all() and applySettings calls forget(). Key-help paints the row (KeyHelpTest #4 per-binding legs pass for chat.verbose-toggle).
P4 docs: en.php both hunks alphabetically correct (verbose after turn./before workflow.; toggle between tool-row and word-delete); lang/ has only en.php → no cross-locale duty; README Keys row added; ReadmeRosterDrift green.
Guards honest: registry 155→156 is the deliberately-pinned "declared shape" family (dated carry sentence, matches live count at tip — green); SettingsWriterCensus producer row 'Chat/Handlers/HandlesKeys.php::toggleVerboseOutput->write' derives exactly (green); KeyBindingDriftTest observation drives the real decoded chord through the real accessor with a throwing door + ambient restore (226T green).
Note (c): Bootstrap::MAC_ALT_NOTICE_TEXT names "Alt chords" generally (blob-read) → no new doc; honest.
DuplicatedTestHelperDrift at tip: open list = exactly the 4 standing rows (OPEN/CLOSE/STUB_CANDIDATE from C-TUI-3.a + rostered HEAVY_CHARS); zero rows name a lane file — lane adds none.

Base-proven reds re-attributed at tip (no lane symbols in any failure diff; grep 0): KeyHelpTest 5F, ApplySettingsTest 2F (both lists contain expandToolOutput on BOTH sides — pure foreign index-shift), ChatTest suggestions/title 5F (341T/14555A byte-identical to builder claim). Global "PHPUnit Deprecations: 2" appears even in single-file runs (bootstrap-level, not lane). Standing pair/staleness roster unchanged by this lane.

NITs (non-gating): (1) builder's base quote "footer 156→160 identical" — tip actual is 160 (lane's +1 live row shifts keyHelpMaxOffset 159→160); the base reading was presumably 159; red SET identical, immaterial, but the cure owner MUST re-pin with the Alt+O row included. (2) README Alt+O row lacks trailing period (mixed house style exists in neighbors; guard green).

Weld carries: durations.tsv row — candy-code/tests/Chat/VerboseToggleKeyTest.php, 5T/23A, accept builder's ~9.86s median (single-file run consistent, 3-run not repeated by me); §7 figure delta +5T/+23A live-enum (closeout duty, NOT mid-run); KeyHelpTest seam note above; the 4-row DuplicatedTestHelperDrift standing red stays with the C-TUI-3.a fixer.
