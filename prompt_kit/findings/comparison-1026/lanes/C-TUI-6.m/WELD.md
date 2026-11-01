# C-TUI-6.m — WELD record

Welder: cc-cmp-welder (sole master writer). Date: 2026-10-31. Step: TUI push-to-talk wiring (Alt+V record/stop, Esc cancel, transcript insert). W40-era LATE member riding the welded voice core C-TUI-6.l (✅ 417b5fe13,d3f2087e9,4da8acee4) and the Windows/ConPTY era.

## Evidence read IN FULL (welder-statted bytes)
- REPORT.md **5,502 B** — `STATUS: DONE-BUILD` truthful line, builder one-pick disk-perfect, no fragment.
- REVIEW-1.md **10,629 B** — verdict line verbatim: `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (docs-jargon, weld-strip candidate) / 2 NIT — WELD-ELIGIBLE: YES (with §7 list; MINOR foldable at weld doc pass)`.
- FIX-1.md: does not exist (none appeared; preflight confirmed — MINOR ruled to the weld doc pass).

## Preflight (in welder's shell)
- `git branch --show-current` = master; pre-weld master **c0ddfd464** (6.l-tool era tip; lane base b539be830 predates 6.e weld 5fe315dd9 + 6.l-tool trio carry — MOVED MASTER, union duties armed).
- Plan §4 probe (python startswith, NEVER grep substring): `#### <✅> ` marked count = **323**; own heading `#### C-TUI-6.m — TUI push-to-talk wiring` present-UNMARKED exactly once (no phantom-reweld).
- Lead dirt observed: `.opencode/agents/cc-cmp-lead.md` M-unstaged + lanes-dir WELD.md M-set + untracked run-state-adjacent strays — index EMPTY, cherry-pick NOT blocked, no reset needed; contents untouched.

## Pick + merge
- Lane log read: ONE commit `1de1fe7bfc1ef1906901d816e7cc7fa157d48fac` (`1de1fe7bf`) — explicit SHA cherry-pick, no range, no `-x` (t646 law; landed sha lives in the mark prose).
- Zero conflicts (en.php auto-merge only). LANDED SHA **401a07c9bf851590b63dda2d1404242934d063e3** (`401a07c9b`), 11 files +477/−8.
- Law greps (range c0ddfd464..401a07c9b): `%ae` count 1 detain · `%ce` count 1 detain (SEPARATE) · Co-Authored-By count 0. Reflog: one `cherry-pick:` record, zero foreign mid-flight.
- Residue scan before commit: no `if (false` / `&& false` / `|| true` in added lines.

## Merged-tree structural proofs (BY TEXT, current positions)
- HandlesKeys.php Alt+V arm :1038-1039 (before generic Char/DRAFT_KEYS delegate :1137); Esc-while-recording :418-419 → `VoiceSession::cancel()` (engine teardown+unlink) before soft-cancel family :424 (C-TUI-3.d pairing intact); `toggleVoiceTake()` :1220 → `TranscribeCmd::cmd($take)` engine reuse, latch-busy → `AudioRecorder::cancel` + `chat.voice.busy`.
- HandlesSessionSurface.php :331 `insertString` (beside ClipboardImagePastedMsg arms; never sends) · :338 `Message::notice`.
- Renderer.php :2431-2432 passive-static cue; no timers (r1 token-scan 0 hits; E646-clean).
- KeyBindingRegistry.php :1125 `chat.voice` `Alt+V` beside `chat.plan-mode` :1119; sibling rows all survive BY TEXT; registry count 174→175 in-step (KeyBindingRegistryTest :410/:411).
- Voice-core ZERO-DIFF re-proved per-pick: `git diff b539be830..1de1fe7bf -- candy-code/src/Media/Voice` = 0 lines (engine called, never edited).
- Bash.php: 0 files in pick; `"bash -c "` double-quote alive at :538 (line drifted, content intact).
- en.php: 4 keys append-union at merged :1997 / :2535-2537; PYTHON dupscan → DUPCOUNT 0; each key ×1.
- UNOWED-ALL name-diff proof (0 files between c0ddfd464..401a07c9b): docs/protocol/candy-code.v1.schema.json · docs/SERVER.md · candy-code-web/src-web/protocol/generated.ts · candy-code-web/dist · web-strings.en.json · strings.en.generated.ts · suite-figure.json · durations.tsv. Mirror grep `chat.voice` in web-strings.en.json = 0 (no web.* keys).

## Weld doc pass (MINOR cure — executed)
- Pick introduced 3 step-ID tokens in MEDIA.md USER prose (:173 "arrived with C-TUI-6.m below", :206 "**Push-to-talk (C-TUI-6.m).**", :217 "(C-TUI-7.b)"); README added line measured TOKEN-FREE (strip scope = MEDIA only).
- Stripped leaving sentences truthful: "is documented below" / "**Push-to-talk.**" / drop "(C-TUI-7.b)". Proof: grep `C-TUI-6.m|C-TUI-7.b` README = 0→0, MEDIA = 3→0.
- RESIDUAL DISCLOSED: `## Voice input (C-TUI-6.l)` heading is BASE-LANDED 6.l prose — outside this strip's authority, NOT churned.
- Post-strip re-verify: guards (KeyBindingDrift|KeyBindingRegistry|RouteTraitParity|VoicePushToTalk|ReadmeRosterDrift):: 301T/3626A GREEN; gen-settings-doc/gen-tool-docs/gen-command-docs --check rc0×3 "up to date" (strip moved nothing generator-derived).

## Gates (welder's OWN runs; cwd=candy-code -c phpunit.xml; env -u LINES -u COLUMNS plain pipe; anchored FULL Test-suffixed stems)
- VoicePushToTalkTest:: → **Tests: 9, Assertions: 39** 0F **0S all-EXEC** (§7 count minted at 9T; palette-inert probe-skip did NOT fire).
- (KeyBindingDriftTest|KeyBindingRegistryTest|RouteTraitParityTest|VoicePushToTalkTest):: → **291T/3548A** 0F (r1-exact).
- (StatusBarModelAndReadOnlyTest|StatusBarSpendTest|StatusLineSegmentTest|HostedFrameReadsThePaneTest|FrameHeightFloorTest|PaneWidthInvariantTest):: → **247T/25939A** 0F (idle bytes identical).
- (CliLangCatalogueTest|LangParityTest):: → **15T/4263A** green (assertions +3 vs r1-era quote 4260 — content-derived recount on merged tree, disclosed).
- (WebStringsTest|WebStringsDriftTest):: → **9T/588A** green. ReadmeRosterDriftTest:: → 10T/78A green.
- BARE TreeWideGuardRosterTest:: → **17T/3993A** green (ZERO own rows; all master rows survive).
- BARE DuplicatedTestHelperDriftTest:: → **14T/204A** green.
- PAIRING (BashSandboxTest|BubblewrapHardeningTest):: ONE process → **39T/192A GREEN** (db1441a61 in force).
- BootstrapLayeredSettingsTest:: → **66T/137A** 0F (00fedf519 in force; folded NOTHING).
- MenuBarLabelDerivationTest:: → 11T/200A **exactly 1F** (base-inherited :111), voice hits 0 — NON-WORSENING re-measured.
- KeyHelpTest:: → **63T/96508A 5F +1 risky**, `chat.voice` 0 hits in full output — UNCHANGED-SHAPE vs r1 re-measured.
- Excluded from every filter (base-proven foreign): PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues · RegistryTest::testAStdoutThatShrinksAlone… · ForkedChildReaperAdoptionTest Sessions/BackgroundShellRegistryTest item.
- ACCEPTED-FOREIGN cure-NONE (five debt rows + standing roster carried unchanged-shape): StderrEmitterCensus-era 2F (PtyRelay 6.j-debt 75174ea40) · LaunchContextDelegatesTest tests/Cli:34 (4.g-debt df106a84c) · ForeignAgentPresetWiringTest PermissionMode · MenuBarLabelDerivationTest 1F (doctor/recap base-inherited — re-measured above) · rosterCarriesThirteenVerbs base-side pin (5fe315dd9-era) · keepWarm 1F · staleness pair (pinned 25,537/609,443 vs live ~29.7k-era; this weld carries a +9T-class disclosed) · GlobDialect 1F (assert NEITHER number) · SettingsEditor :189 · ChildStderrCapture 2F · AccessorDeprecation :168/:202 · BootstrapLaunchFormat doc-leg · Cli/HelpTest env-var leg · HelpTest[CANDY_CODE_SERVICE_TIER] · BinCandyCodeDispatchTest:1893 --worktree · AttachPagingTest 2F · OneSided :435.

## Commits
1. `weld: C-TUI-6.m — §4 mark + evidence` = plan_comparison.md (own §4 line only) + lanes/C-TUI-6.m/{REPORT,REVIEW-1,WELD}.md + candy-code/docs/MEDIA.md (disclosed jargon-strip doc-fix, part of the reviewer-ruled weld doc pass).
2. `weld: C-TUI-6.m — run-state fold` = lanes/run-state.json VERBATIM fresh-disk read in the same shell.

## Absorption + cleanup
- `git cherry master cc/C-TUI-6.m` = ONE `-` (1de1fe7bf…), ZERO `+` — fully absorbed, no conflict-resolution additions to content-proof.
- Ancestry: `git cat-file -t 401a07c9b` = commit; `git merge-base --is-ancestor 401a07c9b master` OK (ancestry on LANDED sha only).
- Removed worktree ../cc-cmp-C-TUI-6.m + branch cc/C-TUI-6.m after the proof. Sibling lanes untouched (cc/C-TOOL-6.g under review; any lead-cut survivors left in place).

## §7 HAND-OFFS (listed, NEVER fixed at weld)
- durations.tsv row for NEW candy-code/tests/Chat/VoicePushToTalkTest.php at live 9T/39A — battery mints.
- suite-figure/README pair re-pin carries this weld's +9T-class (in-file 174→175 rode the pick).
- RESTRICTED_TOOL_NAMES += 'Script' — LEAD rider pending.
- web.yml gen:strings — LEAD rider pending.
- NIT-1 lesson: builder gate-quotes dropped `Test` suffixes (zero-match trap); welder used anchored full stems.
- env-probe-guarded markTestSkipped at VoicePushToTalkTest:148 — disclosed, SuiteSkipRoster green, all 9 EXEC on this host.
- WARN NEXT WELDER (C-TOOL-6.g, W35-completer, in review): OWNS trio regen — schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO MANUAL canvas rows verbatim regen-wont-derive + dist rebuild; its base predates db1441a61 ⇒ WELD-MUST-GREEN pairing co-run on its merged tip; preserve 6.d ## Windows slice + 6.i manual para + 6.l-tool generated row byte-intact through ITS regen; this weld's trio state = zero drift.
- Reviewer-scratch survivor: /home/sites/rev-6m-r1-mut was never created (mutations DENIED by r1's permission map — honest denial credited); /tmp/opencode/cc6m-{menubar,keyhelp}.log = welder gate logs (lead dispose).
