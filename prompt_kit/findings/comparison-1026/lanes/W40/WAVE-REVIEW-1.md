# W40 wave review — round 1 (2026-11-01, read-only)

Anchor: master = `16d1a94e85764ea408c5eedb5dad33777238e749` (re-derived disk; UNCHANGED end-to-end — no mid-review advance). Tip pair 5b49ab265+16d1a94e8 IS the 6.n weld; the eight W40 commits sit at tip.

## 0. Eligibility
All four members §4-landed with ✅ headings re-read from landed plan_comparison.md: C-TUI-6.e ✅ 5fe315dd9 (:4006-era) · C-TUI-6.m ✅ 401a07c9b (:4008) · C-TUI-6.n ✅ 1784d207f,7dc6d59a7,e990fb014 (:4020) · C-TOOL-6.d ✅ 764624ea8,874497c87,413a0b456. All 8 SHAs cat-file OK + ancestors of master (proved earlier in pass). §4 heading census under ### W40: all ✅, zero unlanded. PLAN-VERDICT row present as expected.

## 1. Cross-lane interactions
- en.php: 6.e 3 keys + 6.m 4 keys + 6.n 8 keys — dupscan 0 dups (earlier 3,427-line extract; 6.n weld cites its own 4,032-key python scan), chat.voice.busy / cli.selfupdate.staged_windows / web.composer.voice.* each exactly-once; web.composer.voice count = 8 EXACT.
- Single voice engine preserved: `TranscribeCmd` ONE class-def (src/Media/Voice/TranscribeCmd.php:42); 6.m TUI toggle and 6.n web serve both ride it — no parallel transcriber.
- Voice core called-never-edited: `git diff 22f346e92..HEAD -- candy-code/src/Media/Voice` = EMPTY (re-proved).
- KeyBindingRegistry chat.voice Alt+V row live at :1125 EXACT as claimed; 174→175 flip rides drift quad (green).
- MEDIA.md weld-strip: grep C-TUI-6.m|C-TUI-7.b = 0 (README 0). Disclosed residual '## Voice input (C-TUI-6.l)' at MEDIA.md:168 = base-landed 6.l prose, outside W40 authority — W39/closeout ledger item, NOT churned (correct).
- Trio regen single-carrier (6.n): schema 8,170 / SERVER.md 1,624 / generated.ts 2,415 wc EXACT; audio.transcribe in schema; dist assets on-disk == claimed names (i18n-BRXRQkR2.js / index-BmdNjbGT.css / index-D7O4eLLT.js). 6.m/6.d trio UNOWED-consistent (no count contradiction).
- 6.d Script pos-46 + nested dispatch: test files byte-zero-diff since 413a0b456; RESTRICTED_TOOL_NAMES (Bootstrap.php:9802) does NOT carry 'Script' = LEAD rider OPEN exactly as §7-disclosed (not a finding).
- C-TOOL-6.b (W41 late-riding between 6.m and 6.n) no collision: 6.n heading proves zero-conflict union; my merged-tree gates cover the stack.

## 2. Merged-tree targeted gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe, anchored stems, NON-ZERO each)
- (WindowsSwapTest|VoicePushToTalkTest|AudioTranscribeMethodTest):: 27T/139A OK = 8+9+10 / 45+39+55 EXACT decomposition.
- SelfUpdateTest:: 44T/794A 1F — F = rosterCarriesThirteenVerbs = accepted-foreign exact shape.
- BuiltIn\ScriptToolTest:: 22T/75A 1S EXACT (substring trap avoided: bare stem also matches UserScriptToolTest → disclosed method-note; per-file figure holds).
- NestedToolDispatchTest:: 10T/64A EXACT. Trio-with-ToolCatalog combined 53T/334A 1S all green — catalog-count drift vs 42T/291A weld-era quote is post-6.d/6.b tool registrations, files byte-untouched, disclosed.
- PAIRING (BashSandboxTest|BubblewrapHardeningTest):: ONE process 39T/192A GREEN (cure db1441a61 holds on this tip).
- BootstrapLayeredSettingsTest:: 99T/423A F=0 (claimed era-pair; in band).
- TWGR BARE 17T/4003A · DTH BARE 14T/204A — both claimed-exact (4,003 band edge, disclosed).
- Drift quad (KeyBindingDrift|KeyBindingRegistry|RouteTraitParity|VoicePushToTalk):: 291T/3548A EXACT.
- Status-bar family (StatusBarModelAndReadOnly|StatusBarSpend|StatusLineSegment|HostedFrameReadsThePane|FrameHeightFloor|PaneWidthInvariant):: 247T/25939A EXACT.
- (CliLangCatalogue|LangParity):: 15T/4271A · (WebStrings|WebStringsDrift):: 9T/596A OK (6.n post-regen figures reproduced EXACT).
- Voice server family 179T/4853A OK — welder own-quote was 166/4784, r1 179/4845 on identical stems; 179T reproduces the r1 class on merged tip, 8A era drift disclosed, ZERO failures (r1-figures rule; not charged).
- ReadmeRosterDriftTest:: 10T/78A · (ProtocolSchemaDrift|ServerProtocolDocDrift):: 13T/362A OK EXACT.
- MenuBarLabelDerivationTest:: 11T/200A exactly 1F (:111 doctor/recap foreign shape EXACT) · KeyHelpTest:: 63T/96508A 5F+1 risky, chat.voice 0-hits — accepted shape EXACT.
- DocFigureProseDriftTest:: 92T/4276A OK — 6.e-era quote 92T/4269A; T exact, +7A content-derived drift disclosed (arms live, zero failures).
- E646 spot: MediaMethods.php zero addTimer/Loop::/sleep/stream_select; caps 8_388_608 + 11_184_812 present (:124/:131). Exclusions honored every filter (PtyRun expect-miss · RegistryTest stdout-shrinks · ForkedChildReaper item).

## 3. Generated files / tools
- gen-settings-doc · gen-tool-docs · gen-command-docs --check rc0×3 (no output = up to date). gen-protocol-schema --check: "protocol schema: up to date" PASS.
- check-path-repos + check-child-lifetimes: DENIED by reviewer shell map (5 honest denials; not executable here). Substitutes: committed lib manifests grep '"type": "path"' = ZERO outside root ✓; src proc_open/popen/shell_exec added-delta across all 8 W40 commits = 0 (5fe315dd9's +5 are tests/ autoload-dev fixtures — exempt per tool-walks-roots-only precedent). Welder runs rc0 on record.
- dist rebuilt by 6.n weld ONLY (web sources moved there) — names match; 6.m/6.d/6.e carry none, confirmed by trio/mirror zero-movement claims + my spot-counts.

## 4. Claims vs landing — all four members: every quoted gate reproduced or disclosed-band; §4 texts HONEST (see §2 rows; ScriptToolTest 22T/75A/1S and per-member decompositions EXACT).

## 5. Commit law — 8/8 tip commits %ae AND %ce = detain@interserver.net (separate greps), Co-Authored-By 0 each (earlier per-commit proof + log-format census this pass).

## 6. Residue — worktree list EXACTLY main + cc-cmp-C-TOOL-6.g (review r1, in-flight NOT charged) + cc-cmp-C-TUI-6.h (building, base=current tip, NOT charged). Zero cc/C-TUI-6.{e,m,n} / cc/C-TOOL-6.d branches or worktrees. Main-tree dirt = lead-side WELD.md/run-state.json/.opencode (foreign, untouched).

## 7. §3 flip — W40 row (:176) UNflipped, member §4 marks only. Wave-close remains lead duty. ✓

## Ledger carried unchanged-shape (cure NONE): staleness pair 25,537/609,443-vs-~29.7k (battery owns) · all §2 EXACT-shape foreign reds above · §7 hand-offs standing: durations rows for VoicePushToTalkTest(9T)+AudioTranscribeMethodTest(10T)+Notebook 4-file set · RESTRICTED_TOOL_NAMES 'Script' + web.yml gen:strings LEAD riders · VITEST-28/4 full-web foreign shape · next trio carry is C-TOOL-6.g (WELD-MUST-GREEN pairing on its pre-db1441a61 base; preserve 8,170/1,624/2,415 + 6.d/6.i slices + shells.input row).

MID-REVIEW ADVANCE (disclosed): after the closing re-check, master moved 16d1a94e8 → 16fe158c8 = the C-TOOL-6.g (W35) weld landing six commits (canvas serve/web/trio regen + dist). Every W40 member commit remains an unmodified ancestor of the new tip; all §2/§3 certifications above were run ON 16d1a94e8, the wave's true merged state. 6.g's trio regen moves the post-W40 file counts — that carry is W35 review domain, not re-adjudicated here; the preservation duties W40 handed it (§7 row) stand on record.

WAVE VERDICT: CLEAN
