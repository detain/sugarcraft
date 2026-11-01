# C-TUI-6.l — WELD (2026-10-31, welder #?/6.l)

Echo: `STEP=C-TUI-6.l ROLE=welder`. Sole master writer; no fetch/pull/push performed.

## Preflight
- master branch, HEAD `382682e01` (weld: C-TOOL-6.k — run-state fold). Porcelain clean apart from `lanes/` + lead-owned dirt (`.opencode/agents/cc-cmp-lead.md` M, AGENT_LAW_SNIPPET.md/LEAD_LAWS.md untracked, one redacted untracked entry) — left exactly as found, never staged.
- Own heading present-UNMARKED exact-form by python startswith probe: `#### C-TUI-6.l —` at :3944; marked count 320 at preflight. No phantom-reweld.
- Lane `/home/sites/cc-cmp-C-TUI-6.l` (cc/C-TUI-6.l), porcelain 0, tip `51cc0fdb0`, base `85bfba37f` (lawful zero-pick ff-merge — 61c449790's sole parent is 85bfba37f; dispatch's WELD-7 note 4 confirms). Explicit list read from lane `git log --format='%h %s' 85bfba37f..HEAD` (reverse): `61c449790` → `8f861e657` → `51cc0fdb0`. Law re-derived on lane: %ae=3, %ce=3 detain (separate counts), Co-Authored 0.

## Cherry-picks (explicit list, in order, no -x, never a range)
- `61c449790` → landed **417b5fe13** — CONFLICT (content) in `candy-code/docs/SETTINGS.md` ONLY: adjacency union vs landed 6.k/6.i restart-row edits (master side `server.clientTools` + eighty-four; lane side voice.* + eighty-eight). Resolved BY TEXT UNION: fused restart row carries voice.* five keys AND `server.clientTools`/`server.browseRoot`; **In numbers:** re-derived by `tools/gen-settings-doc.php --write` → eighty-nine (never hand-typed). One tooling slip self-inflicted and cured same session: my first fusion script mis-ordered the restart row after next-launch and left a stray blank — detected by blob-truth read of the pick diff (canonical order live→next turn→reload→restart→next launch), repaired before `--continue`. `lang/en.php` auto-merged (voice runs landed sorted).
- `8f861e657` → landed **d3f2087e9** — clean, zero conflict.
- `51cc0fdb0` → landed **4da8acee4** — clean, zero conflict.
- Merged-tree total: 25 files **+2592/−12** (`git diff --stat 382682e01..HEAD` after picks, before records).

## Union re-derivations on merged blob (never inherited)
- en.php: python key-walk over merged `lang/en.php` — **4015 keys, 0 dups** (voice keys 17: 5 label + 5 help + 7 media.voice refusals). Guards: `(CliLangCatalogueTest::|LangParityTest::)` anchored co-run → **Tests: 15, Assertions: 4254, OK-but-deprecations** (non-zero, zero failures; the Deprecations: 2 pair is the tree-wide pre-existing shape).
- TreeWideGuardRosterTest: lane +2 rows (`Media/Voice/TranscriberTest.php`, `Media/Voice/VoiceSessionTest.php` scandir licences) land ADDITIVE — merged-file diff vs `382682e01` shows only `+` lines, zero `-` (no foreign row dropped, 6.k-era licence rows survive by text). BARE run **17T/3987A** green (+A drift vs 6.k-era disclosed, never hidden).
- ReadPathCensusTest ×3 rows + DescriptorInheritanceGuard ×1 row: co-run anchored `(ReadPathCensusTest::|DescriptorInheritanceGuard)` → **34T/5964A** green (= 19T/773A + 15T/5191A, same shape as r1).
- `Bash.php` untouched by picks (name-diff 0); `"bash -c "` double-quote blob count stays **1** (before and after).
- ZERO-DIFF proofs vs `85bfba37f..cc/C-TUI-6.l` AND vs `382682e01..HEAD` for PermissionGate / PtyRelay / BackgroundShellRegistry / SandboxFactory / ScriptHook / WindowsShell / PosixShellQuote / ForkedChild / Bash: **0 paths**. Voice layer calls the welded engines, never edits.
- Mirrors/trio/dist UNOWED-ALL: protocol trio + `docs/SERVER.md` + `src-web/protocol/generated.ts` + `web-strings.en.json` + `strings.en.generated.ts` + `dist` ABSENT from the whole pick range (name-list grep over all three picks + merged diff = 0). No `src/Protocol/` touch → `gen-protocol-schema` NOT owed; no web change → web regen NOT run (would contradict UNOWED-ALL). Contrast note per dispatch: unlike the 6.i weld, this step carries no trio regen.
- README + docs/SETTINGS.md + docs/MEDIA.md append-unions fused; no TRUE conflict beyond the SETTINGS.md adjacency above.

## Generators
- `gen-command-docs --check` rc0 ("up to date"); `gen-tool-docs --check` rc0; `gen-settings-doc --check` rc0 at final tip. The one genuine regen was the SETTINGS.md apply-table fusion above — folded INSIDE landed commit-1 417b5fe13 (disclosed: that commit carries the generator's eighty-nine derivation).

## Gates (merged tip, targeted only, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- Own five solos (MINTED LIVE §7 counts): AudioRecorderTest 12T/58A · VoiceConfigTest 6T/45A · TranscriberTest 12T/58A · VoiceSessionTest 6T/19A · TranscribeCmdTest 8T/28A — combined **44T/208A OK**, walls ~10.4s. Matches r1 EXACT.
- BARE TreeWideGuardRosterTest 17T/3987A · BARE DuplicatedTestHelperDriftTest 14T/204A · SettingsSchemaDocDriftTest:: 19T/203A · ReadPathCensus+DescriptorInheritanceGuard 34T/5964A — all green.
- PAIRING `(BashSandboxTest|BubblewrapHardeningTest)` ONE process: **39T/192A GREEN** (cure db1441a61 shape; no stop-and-report).
- `LayeredSettingsTest::` anchored: **99T/423A, 0F** (cure 00fedf519 in force — nothing folded; voice absent from PROJECT_TIER_KEYS, user-tier-only pin survives).
- Excluded from every filter (base-proven foreign, report-only): PtyRunToolTest::testExpectMiss…, RegistryTest shrink case, ForkedChildReaperAdoptionTest item.
- Accepted-foreign debt roster NOT re-run and NOT cured (headline): StderrEmitterCensus-era 2F PtyRelay-debt (75174ea40), LaunchContextDelegatesTest (df106a84c), ForeignAgentPresetWiringTest PermissionMode, MenuBarLabelDerivationTest base-inherited; keepWarm 1F · KeyHelp risky · HelpTest[TIER] · BinCandyCodeDispatch :1893 · AttachPagingTest 2F · OneSided :435 · staleness pair (pinned 25,537/609,443 vs live ~29.7k+ era — this weld's +44T/208A carry DISCLOSED for the battery; suite-figure.json/durations.tsv NEVER touched) · GlobDialectDifferentialTest 1F (r1/r3 measured BASE-CARRIED docblock rot 311,360 vs 310,245 — assert-NEITHER honored: neither number re-derived at this weld, report-only).
- No full suite, no composer/npm/refresh-deps (vendor pre-seeded copy; no symlinks into main tree).

## Progress marks + records
- §4: marked-count python startswith('#### ✅ ') 320→321; own heading renumbered to LANDED shas `#### ✅ 417b5fe13,d3f2087e9,4da8acee4 C-TUI-6.l — …` (disk-read landed hashes). §3 untouched. Disclosure bullets (loop arc incl CONTAMINATED-WAKE void, privacy posture, §7 hand-offs) added to own block only.
- run-state.json: folded VERBATIM as the lead staged it — re-read fresh from disk immediately before commit-2, md5 disk==blob printed.

## Absorption + cleanup (recorded after commit-1/2 land; see footer)
