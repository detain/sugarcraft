# W39 — Wave Review 1 (round 1)

Anchor at review start: master = 16d1a94e85764ea408c5eedb5dad33777238e749 (disk re-derived; W40-era welds C-TUI-6.m/C-TOOL-6.b/C-TUI-6.n sit above this wave's chain — rolling execution, expected).

Members: C-TUI-6.l · C-TOOL-6.k · C-TOOL-6.i · C-TUI-6.d (plan §4 LATE; §3 roster row line 175 = "W39 (LATE) | 4").

## 0. Eligibility — §4 headings re-read from landed plan_comparison.md, all SHAs proven

| member | heading SHAs | cat-file (rev-parse --verify) | ancestor of master |
|---|---|---|---|
| C-TUI-6.l | 417b5fe13 d3f2087e9 4da8acee4 | 3/3 full-SHA resolved | 3/3 rc=0 |
| C-TOOL-6.k | 543364946 5db19f127 ba4d96b62 3e0a2f662 | 4/4 | 4/4 rc=0 |
| C-TOOL-6.i | 22391a02b 3871d0b00 b1fce23aa | 3/3 | 3/3 rc=0 |
| C-TUI-6.d | 82e22a922 33915c3f9 b7f830aae df2613aff | 4/4 | 4/4 rc=0 |

All 14 ✅ headings present on disk with exactly these SHA lists (re-read from plan_comparison.md:3944/3959/3970/3981). Weld order on master: C-TUI-6.d (atop C-TOOL-6.h weld 77beb227d) → C-TOOL-6.i (atop 0fe9fa6a2, pre-weld master 0fe9fa6a2 stated in heading) → C-TOOL-6.k (atop bfe9e6146) → C-TUI-6.l (atop 382682e01; picks sit directly at 417b5fe13..4da8acee4 then marks 29977b5ce/843dff284).

## 5. Commit law — PASS

- 14 picks: `git log --format='%h AE=%ae CE=%ce'` each SEPARATE — every one AE=detain@interserver.net AND CE=detain@interserver.net.
- Co-Authored-By trailer grep across all 14 picks = 0.
- 8 wave weld/mark commits (69ea35d9b 0fe9fa6a2 521c816fc bfe9e6146 9f817880b 382682e01 29977b5ce 843dff284): all detain/detain, Co-Authored empty.
- No `-x` cherry-pick "cherry picked from" lines expected per t646 law; heading lists ARE the landed SHAs — consistent (landed shas quoted in headings match).

## 1 Cross-lane interactions

- SETTINGS.md fused by 6.l (voice keys) + 6.i (server.clientTools row): gen-settings-doc --check rc0 + ReadmeSettingsTierClaimTest green → no dropped row.
- lang/en.php unions from 6.i (2 keys), 6.k (ShellSend/tool keys), 6.l (19 voice keys): CliLangCatalogue|LangParity|EnvRoster|KeyBinding combined filter `Tests: 351, Assertions: 49882` OK — no dup keys.
- TreeWideGuardRosterTest rows from 6.l (+7)/6.k (two licence rows)/6.d-era coexist: BARE `Tests: 17, Assertions: 4003` GREEN (walk re-derived; +38A vs 6.d-era 3965 = later-weld tree growth, self-consistent).
- dist/web ownership clean: only 6.i's span touches candy-code-web (b1fce23aa: 5 web files); 6.k span name-diff `grep -cE 'generated\.ts|schema\.json|dist/|suite-figure|durations\.tsv'` = 0; 6.l picks web-absent per heading trio/dist UNOWED.
- 6.d↔6.a/6.h shared regions (CapturesProcessOutput/ProcessContainment): retainer set green unchanged (see §4).

## 2 Merged-tree targeted gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe, -c phpunit.xml)

ALL OK (PHPUnit Deprecations: 2 = bootstrap-wide pre-existing):
- (PtyBackendTest|InteractiveWindowsArmsTest):: 12T/49A EXACT (6.d §7 live).
- SuiteSkipRosterTest:: 28T/201A; (WindowsBashSmokeTest|WindowsPtyRunSmokeTest):: 3T/3S rostered-shape design.
- Retainer trio (PtyDriverRetainTest|PtyRunBashPermissionParityTest):: 88T/157A GREEN UNMODIFIED; PtyRunToolTest:: full 13T/98A (expect-miss leg green this run; 12T/93A excluded-count compatible).
- (ClientToolMethodsTest|ServeArgsTest):: 55T/302A (6.i).
- Voice 74T/423A (6.l family incl 5 new suites 44T/208A subset).
- 6.k family (ShellSend|BashInteractiveBackground|ShellSendPermission|ShellsCommand|ShellOutput|BashBackground|ToolCatalog):: 77T/445A.
- (ServerProtoDocTest|ProtocolSchemaDriftTest):: 7T/303A EXACT 6.i claim.
- (ToolDocumentation|CommandDocumentation|ReadmeRoster|ReadmeSettingsTier|DocFigureProseDrift):: 108T/4395A GREEN (DocFigure 92T survives all four welds).
- (LayeredSettingsTest|MediaSettingsTest):: 116T/682A; pairing (BashSandbox|Bubblewrap):: 39T/192A EXACT; (SlashDispatch|Subcommands):: 36T/628A; BARE DuplicatedTestHelperDrift 14T/204A.
- RED (1): (ReadPathCensusTest|DescriptorInheritanceGuardTest):: `Tests: 34, Failures: 1` — ReadPathCensusTest.php:1571 MediaMethods.php row stale (fgc 1→2, fopen 2→3). NOT W39: the extra sinks land in 1784d207f C-TUI-6.n (W40-era, above this wave; verified via `git log 843dff284..16d1a94e8 -- MediaMethods.php`); blob at W39 tip 843dff284 matches the expected row counts (fgc=1, fopen=2). FOREIGN NEW — charge to W40/C-TUI-6.n closeout (census rows for audio.transcribe sinks), not W39.

## 3 Generated files + tooling

- gen-tool-docs/gen-command-docs/gen-settings-doc --check + scripts/gen-protocol-schema --check: all "up to date" rc0 (4/4) at anchor 16d1a94e8.
- 6.i dist/trio rebuild corroborated IN-MARK (check, not rebuild): 521c816fc carries generated.ts+53, schema.json+167, SERVER.md+14, dist asset index-BmARdb9X.js→index-Lga6Dnd-.js + index.html + manifest.json — exactly as heading claims; gen-protocol-schema --check rc0 proves regen-stable at tip.
- tools/check-path-repos.php + tools/check-child-lifetimes.php: DENIED by this agent's permission map — UNVERIFIED-BY-EXEC (6.d r1 precedent). Static substitutes: WELD arcs record rc0 at each member weld; my re-run DescriptorInheritanceGuard+ReadPathCensus (the child-lifetime-adjacent src guards) green on the W39 file set; 6.d union carries zero new spawn sites (token-census-proven in r1 Q1).
- vitest clientTools.spec: DENIED by map — UNVERIFIED-BY-EXEC; 6.i heading arc records welder-ran serial vitest; dist bytes corroborated.

## 4 Claims vs landed

- 6.l ✅ all design created/modified files present across 3 picks (VoiceConfig 117L, AudioRecorder 390L, Transcriber 209L, TranscribeCmd 191L, VoiceSession 132L, AudioRecording 30L, TranscriptionException 58L + 2 msgs + 5 test suites 362/196/324/115/189L + doc rows). §4 full arc; trio/dist UNOWED verified. CLEAN.
- 6.k ✅ 4 picks == heading (18/7/1/1 files); r1 verdict quote matches REVIEW-1.md; TWGR two licence rows landed (3e0a2f662). CLEAN.
- 6.i ✅ 3 picks: PHP protocol 13 files, ClientToolMethodsTest 334L, web 5 files; weld mark carries trio+dist exactly as claimed. CLEAN.
- 6.d ⚠ picks 1-4 all present + README/SERVER/ci.yml landed. Design "Tests — modify" NAMES PtyRunToolTest, PtyDriverRetainTest, PtyRunBashPermissionParityTest — NONE modified (span name-diff 13 files; zero Pty::open/PtyBackend refs in them → proven unnecessary: retainer trio 88T/157A green unchanged, WELD.md:50; r1 Q6 disclosed the 13-file list as design-declared 7+6). MINOR: §4 6.d heading is BARE (no weld-arc/deviation sentence unlike the other three members) — the design-test-modify deviation + NITs (Kernel32ConPty vs ConPtyApi cite, Bash.php:697 stale comment, Warnings:1 co-run) are adjudicated in lanes/C-TUI-6.d/{WELD.md,REVIEW-1.md} but absent from the plan face. E246-style law wants the deviation on the heading. No functional claim missing.

## 5 (kept above) / 6 Residue

- git worktree list = main + cc-cmp-C-TOOL-6.g + cc-cmp-C-TUI-6.h; branches = cc/C-TOOL-6.g, cc/C-TUI-6.h — exactly the sanctioned in-flight lanes. All four W39 lane worktrees+branches pruned. NO /tmp weld residue charge.
- In-flight flag-not-charge: C-TOOL-6.g (review r1; OWNS W35-completer trio regen per 6.d WELD.md:63 WARN rider), C-TUI-6.h (building @16d1a94e8).

## 7 §3 status

- plan_comparison.md line 175 row present, UNCLOSED — no §3 flip performed by any W39 member/welder (correct: wave-close is lead duty). No premature flip detected.

## Findings roll-up

- CRITICAL: 0. MAJOR: 0.
- MINOR-1: plan_comparison.md:3981 C-TUI-6.d `#### ✅` heading is bare — add one weld-arc sentence recording (a) design test-modify trio unlanded-as-unnecessary (zero refs; retainer 88T/157A green unchanged) and (b) the r1 NIT adjudications, matching the 6.l/6.k/6.i heading shape. Lead fix at §3 close flip.
- NIT-1: candy-code/src/Background/PtyRelay.php:270 still calls the deprecated `Pty::open` facade directly — live third caller after 6.d's two-site reroute (6.j file, engine-CALLED-never-edited law; WELD.md:15 already reports it for owner adjudication).
- FOREIGN (not charged): ReadPathCensusTest:1571 MediaMethods row stale via C-TUI-6.n 1784d207f (W40-era) — route to W40 closeout. StderrEmitterCensus 2F PtyRelay debt NOT re-armed as a gate here (accepted roster; unchanged shape).
- UNVERIFIED-BY-EXEC (permission map denials, static substitutes above): check-path-repos, check-child-lifetimes, vitest clientTools.spec.

WAVE VERDICT: FINDINGS C=0 M=0 MINOR=1 NIT=1
