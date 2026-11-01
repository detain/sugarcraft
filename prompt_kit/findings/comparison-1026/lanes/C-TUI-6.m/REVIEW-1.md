# C-TUI-6.m — REVIEW-1

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (docs-jargon, weld-strip candidate) / 2 NIT — WELD-ELIGIBLE: YES (with §7 list; MINOR foldable at weld doc pass)

STEP=C-TUI-6.m LANE=/home/sites/cc-cmp-C-TUI-6.m

## Q0 Lane state (re-derived, mine)
- Branch cc/C-TUI-6.m, ONE pick `1de1fe7bfc1ef1906901d816e7cc7fa157d48fac` on base b539be830; porcelain 0; `%ae`=1 detain, `%ce`=1 detain (SEPARATE greps); Co-Authored-By 0; 11 files +477/−8. `git diff --name-only b539be830..HEAD -- candy-code/src/Media/Voice` → 0 lines (engine called, never edited; re-derived per-pick — same single pick).
- REPORT.md 5,502 B read fully; findings derived from it + blobs, not paraphrase.

## Q1 Arm ordering (named class) — CLEAN (by blob structure + exec'd pins; scratch mutation UNCONFIRMED-BY-RUN, see Q4)
(a) Alt+V toggle arm at HandlesKeys.php:1038-1039 (`Char && alt && !ctrl && strtolower(rune)==='v' => toggleVoiceTake()`), BEFORE the generic `DRAFT_KEYS => delegateToInput` arm at :1137-1138 (Char ∈ DRAFT_KEYS, Chat.php:213-214). First-match-wins: a moved arm would type `V` into the draft. REDDY-WITHOUT-MOVE proof holds even in the merged shape: KeyBindingDriftTest's per-row observation `'chat.voice'` presses the chord parsed from the row's OWN label and asserts the voice.baseUrl toast (drift test :1238+ region) — moving the arm after the delegate kills that exec-green test, plus VoicePushToTalkTest::testAltVEscapesTheGenericCharArmsAndTypesNothing (:81) and testTheSecondPressStops… (:93). `!$msg->ctrl` keeps Ctrl+V = chat.paste-image per ruling.
(b) Esc-while-recording arm at :418-419 BEFORE the soft-cancel family at :424 (C-TUI-3.d idle pairing). Cancel rides the engine seam `VoiceSession::cancel()` (VoiceSession.php:86: killed recorder, AudioRecorder::cancel unlink) — no second WAV deleter. Pin discriminates: testEscWhileRecordingCancelsDeletesTheWavAndTouchesNothingElse (:126) asserts `assertSame($chat,$next)` (no lastEscapeAt stamp), file gone, and later idle Esc Esc STILL reaches the picker.
(c) toggleVoiceTake at :1220 (quoted verbatim): stop path returns `TranscribeCmd::cmd($take)` — engine reuse, no parallel transcriber (TranscribeCmd.php:56); latch-busy (cmd null while prior inFlight) → `AudioRecorder::cancel($take)` + `chat.voice.busy` warning toast — no silent swallow, no double-take (VoiceSession::$recording single static slot; start only when !active()).

## Q2 Surfaces + E646 — CLEAN
HandlesSessionSurface.php:331-332 VoiceTranscribedMsg → `$this->input->insertString($msg->text)` — same insert engine as the ClipboardImagePastedMsg/mention arms at :293/:307/:321 (no second engine; caret semantics reused). :338 VoiceFailedMsg → `Message::notice` history row. Both Msg classes welded in 6.l core (`git log` shows App/Voice*Msg.php landed d3f2087e9) — existing msg pipeline, no new channel.
Renderer.php:2430-2434 hint slot: `VoiceSession::active() ? Lang::t('chat.voice.recording') : (TranscribeCmd::inFlight() ? … : '')` — two passive static reads; token-scan of ALL added diff lines for Timer|Loop::add|addReadStream|pcntl_alarm|sleep( = 0 hits. E646 clean; no server timers (client-triggered). Idle bar byte-identical: every non-voice frame takes the untouched fitProcessingHint branch; status-bar family 247T/25939A green (mine) + explicit `assertSame($idle, …)` idle-bytes pin executed in testTheStatusBarCarriesTheStaticCueInEachLiveState (paints "● rec · Alt+V stop · Esc cancel" verbatim — anchor spot-checked by measurement, green).

## Q3 §1.8 per-class (one commit) — CLEAN, one MINOR (jargon)
- KeyBindingRegistry.php:1126 `chat.voice` `Alt+V` CONTEXT_CHAT row beside chat.plan-mode; user override rides the existing `keybindings` setting (no new machinery; MEDIA.md names the alias funnel). Registry count 174→175 live in-file (KeyBindingRegistryTest :405-411 flipped in-step, my run green).
- Drift-reward judgment: the FIRST-run 1F on `testNoDescriptionNamesAKeyOutsideTheOrForm` (:320-333) is HONEST GUARD-ACCOMMODATION, not coverup: the guard scans EVERY `KeyBindingRegistry::all()` description and reds on any key-name outside `(or …)`; the reword "press again to transcribe" satisfies it, and the same arm reddens if the label names a chord again — structural, plus the new observation closure presses the real chord (would red if the label lied about what it does). Sibling test :335+ documents the pattern's positive power.
- RouteTraitParityTest +2 roster rows (VoiceFailedMsg/VoiceTranscribedMsg, sorted span) same commit. README ### Keys row + docs/MEDIA.md Push-to-talk paragraph; ReadmeRosterDriftTest:: 10T/78A green (mine).
- en.php: FOUR keys (keys.chat.voice :1994; chat.voice.busy/recording/transcribing :2532-2534) both in sorted spans (verified against neighbours). Dup scan via awk-extract|sort|uniq -d = 0 (never grep -c). `(CliLangCatalogueTest|LangParityTest)::` → mine: **Tests: 15, Assertions: 4260, Deprecations: 2** green — matches claim.
- Mirrors `docs/protocol/web-strings.en.json` + `src-web/lib/strings.en.generated.ts` and the protocol trio + `candy-code-web/dist`: ZERO-touch proved by the 11-name list (no docs/protocol/, no src-web/, no candy-code-web/ files); no web.* keys minted. Path precision holds.
- MINOR (step-local, docs-hygiene): this pick introduces campaign step IDs into USER-FACING prose that base carried none of — MEDIA.md "arrived with C-TUI-6.m below", "**Push-to-talk (C-TUI-6.m).**" and README/MEDIA "C-TUI-7.b" references. The docs-rework law (plan-step refs out of user docs) says strip these at weld (tokens only; in-code docblock step cites stay house-standard).
- Accept-pattern deviations judged SOUND: chat.voice.busy inside the design's `chat.voice.*` span (prevents stranding the stopped-but-latched WAV — rationale in docblock, engine cancel); off-toast reuses welded `media.voice.off` verbatim (reuse > duplicate; test pins `Lang::t('media.voice.off')` substring).

## Q4 Gates (MY figures rule §7) — all reproduced EXACT
cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe:
- `VoicePushToTalkTest::` → **Tests: 9, Assertions: 39** green — ALL 9 EXECuted on this host (probe()=== ‘‘ so the palette-inert skip guard did NOT fire; durations row owed at 9T).
- `(KeyBindingDriftTest|KeyBindingRegistryTest|RouteTraitParityTest|VoicePushToTalkTest)::` → **Tests: 291, Assertions: 3548** 0F.
- Status-bar family — builder's REPORT stem list omitted the `Test` suffix (their literal filter as written matches ZERO — false-green trap shape); re-run with full Test stems `(StatusBarModelAndReadOnlyTest|StatusBarSpendTest|StatusLineSegmentTest|HostedFrameReadsThePaneTest|FrameHeightFloorTest|PaneWidthInvariantTest)::` → **Tests: 247, Assertions: 25939** 0F — their figure real, their quote sloppy.
- BARE class runs: TreeWideGuardRosterTest:: → 17T/3991A green (no roster row needed); DuplicatedTestHelperDriftTest:: → **14T/204A** green (peekToast byte-identical to VerboseToggleKeyTest's — claim stands).
- `SuiteSkipRosterTest::` → 28T/201A green. Pairing `(BashSandboxTest|BubblewrapHardeningTest)::` → 39T/192A GREEN co-run (report-never-cure; base carries db1441a61).
- MenuBarLabelDerivationTest:: → exactly 1F = testEverySlashOnlyRowReadsAsThisTable, failure diff contains **0** voice mentions — base-inherited doctor/recap red, NON-WORSENING proved by count+content in MY run.
- KeyHelpTest:: (re-run foreground to completion, MY figure): **Tests: 63, Assertions: 96508, Failures: 5, Deprecations: 2, Risky: 1** — shape matches the builder claim (5F+1 risky, names = known footer/scroll/ellipsis family); `chat.voice` appears **0** times in the full output (measured, not their word) — overflow roster shape unchanged, accepted-foreign NON-WORSENING holds.
- Excluded per brief from every filter: PtyRunToolTest::testExpectMiss…, RegistryTest::testAStdoutThatShrinksAlone…, ForkedChildReaperAdoptionTest Sessions item — none matched my stems.
- VITEST: name-list proves zero web files → N/A (28/4 rider not engaged). Full candy-code suite NOT run (targeted only, per law).
- Scratch mutation: `cp -a` to /home/sites/rev-6m-r1-mut DENIED by this reviewer's permission map (twice; honest denial per the 5.e/6.e models) → arm-moves + label-revert UNCONFIRMED-BY-RUN; rulings above rest on blob structure + the exec-green drift observation that presses real chords.

## Q5 Hygiene + inventory + weld-readiness
- 11 files == design files.modify + design-named test create + declared deviations; no extras. Zero weakened legs base..tip (guard diffs additive only). GlobDialect: zero glob-shaped literals in added lines (my scan; corpus figure untouched — I assert NEITHER PathGlob number). No `**Refused:**`-shaped literals. random_bytes home sentinels (ct6m_p2t_); uniqid not used but same class. Residue: porcelain 0 at tip.
- ONE new `markTestSkipped` (VoicePushToTalkTest:148) — env-probe-guarded (host with a recorder binary would record), loudly reasoned, disclosed, SuiteSkipRoster green: observation/NIT, not a hidden failure.
- Residual reds in my runs EXACTLY the accepted-foreign roster (MenuBar 1F, KeyHelp shape, staleness pair §7-owed by design); no NEW family observed.
- Bash.php :535 double-quote: preserved — Bash.php in no pick (name-diff 0). src/Media/Voice 0. cc/C-TOOL-6.g never touched.

## §7 LIST (welder duty — never edited here)
1. DURATIONS: add `scripts/parallel-tests-durations.tsv` row for `candy-code/tests/Chat/VoicePushToTalkTest.php` (MY live count 9T) at weld.
2. Suite-figure/README pair carries +9T-class (in-file count flips 174→175 already in pick); pair pinned 25,537/609,443 stale vs ~29.7k-era battery re-pins — re-pin only at K=24 battery, never here.
3. WELD SHAPE: ONE explicit pick `1de1fe7bf` off `b539be830`, read-from-lane, cherry (NO range, NO -x) onto MOVED master (6.e weld landing now, 5fe315dd9-era); renderer/HandlesKeys/en.php/registry unions BY TEXT vs 6.e-era + landed 6.a/6.d/6.h/6.i/6.k/6.l family; conflict = STOP.
4. Docs-jargon strip MINOR (§3) can ride the weld doc pass: remove `C-TUI-6.m`/`C-TUI-7.b` tokens from README Keys row + MEDIA.md prose (keep the `keybindings` override sentence).
5. KeyHelp fold-in DONE: 63T/96508A/5F/1R, chat.voice 0 hits — NON-WORSENING measured.
6. NIT ledger: (a) REPORT gate-quote filters dropped `Test` suffixes (status-bar stem as written matches 0 — figures themselves proven real); (b) one new env-probe-guarded markTestSkipped (VoicePushToTalkTest:148) — disclosed, SuiteSkipRoster 28T green, all 9 tests EXECuted on this host.
