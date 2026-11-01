# C-TOOL-6.h — WELD (master)

Welder: cc-cmp-welder. Master before: 13f9a1829 (C-TUI-6.a close-record tip). Nine explicit picks read from the LANE git log (`git -C /home/sites/cc-cmp-C-TOOL-6.h log --format='%h %s' b45117223..HEAD`), cherry-picked that list in chronological order, NEVER a range, no -x.

## Pick map (lane → landed, disk-read in the citing shell)
- 3086cda45 → 97b2787d4 — client tool spec, registry, proxy, pending-call core + write-class fold
- eaa3dbd04 → adf70e27f — client_call/client_result frames on the turn channel
- a8cf68313 → 1fa7ae06d — engine sink, caller bind, and the parent-side pending-call loop
- a49711e18 → 99ca97f4c — client__* is write class — gate arms and the permission twin
- 9e1c141bc → 7c5d46c32 — session-hosted tool registry, pending-call hands, turn roster append
- f69538669 → df04f0f97 — client__* in the permission docs and the tools:classes tail
- 2f4373c8c → e06b5dbfc — the client-call round trip in a real fork
- 5fae61054 → 9a79f6423 — session-host pins
- 12aa62289 → 85bfba37f — r1 fix: PendingClientCall docblock truth (1 file +4/−3, pure comment)

All nine landed commits: cat-file -t = commit, merge-base --is-ancestor = yes (same shell); %ae detain / %ce detain separate greps = 1/1 on each; Co-Authored-By = 0 on each. Zero cherry-pick conflicts.

## Mechanism disclosure (6.a precedent, identical shape)
Cherry-pick initially refused at unpack (rc=128) on the leads 12-path STAGED dirt — provably disjoint from every pick (zero candy-code entries; the same 12-path list as the 6.a weld, md5 e5f6b9456d2046a8c2231d0eb6e014f0). Resolution = content-preserving mixed reset to HEAD (intersection of staged set and worktree-diff set proven EMPTY — every staged path disk==index, disk never touched), true cherry-pick landing; staged state RESTORED at terminal by re-staging the recorded 12-path set. Reflog = 9 cherry-pick entries + that disclosed reset, zero foreign; no mid-flight foreign commit.

## Collision-union re-derivations (BY TEXT, first-landed owns)
- `src/Tools/BuiltIn/Bash.php:535` reads the 6.a-restored DOUBLE-QUOTED form verbatim: `$cmd = "bash -c " . PosixShellQuote::quote($script);` — survived-in-merge (lane fileset contains no Bash.php — disjoint by construction per r1 Q5).
- `PermissionGate::isWriteTool` source-slice at merged tip: user__ NAME_PREFIX arm (`return true`) → client__ NAME_PREFIX arm (`return true`) → verbatim final `return str_starts_with($call->name, 'mcp__');` (:1836). Ordering law holds. `evaluateAuto`: client__ Ask arm :1095 BEFORE `ShellSubjectTools::stepTexts` (:1113) and `classifier->classify` (:1117) — neither-strike-nor-safe breaker law (6.e law mirrored).
- `src/Runtime.php` prefix constants union at merged tip: MCP_TOOL_PREFIX :848, USER_TOOL_PREFIX :860, CLIENT_TOOL_PREFIX :873 (derived from ClientToolProxy::NAME_PREFIX) all live; isWriteCapableTool reads all three.
- ProcessContainment / BackgroundShellRegistry: absent from all nine picks (per-pick name-only scan) — 6.a regions untouched.

## Merged-tree duties results
- en.php: ZERO diff across the span (per-pick forbidden-surface scan hits=0 ×9, covering lang/en.php + both sanctioned web mirrors web-strings.en.json + strings.en.generated.ts) ⇒ zero-lang; CliLangCatalogue/LangParity UNOWED. i18n counter NOT raised (14/67-era counters belong to battery re-derivation, untouched here).
- TRIO+DIST UNOWED: candy-code.v1.schema.json, docs/SERVER.md, protocol/generated.ts, candy-code-web/dist, suite-figure.json, durations.tsv — ABSENT from all NINE per-pick `git show --name-only` scans (and commit-1 itself is 10 files, none forbidden). gen-protocol-schema UNOWED (no src/Protocol/ touch). Web chain (npm/vitest) UNOWED (zero web files in range).
- TreeWideGuardRosterTest BARE: 17T/3957A GREEN at merged tip; lane added ZERO rows (Census|Roster grep over the 22-file span = 0) and all master rows survive BY TEXT (positions drift; A-count moved 3947→3957 with merged-master census, guard green).
- ReadPathCensus: 0 new sites (guard absent from span; lane test literals use the chr(42) house split); co-run BARE `ReadPathCensusTest` 19T/767A GREEN unedited.
- LayeredSettingsTest::: 99T/413A 0F — cure 00fedf519 holds, nothing folded.
- PAIRING `BashSandboxTest|BubblewrapHardeningTest` ONE process: 39T/192A GREEN — db1441a61 carry holds.
- Generators `--check`: gen-command-docs "command docs: up to date" rc0; gen-tool-docs "tool docs: up to date" rc0 (docs rode df04f0f97); gen-settings-doc "settings docs: up to date" rc0. REGEN legs NOT taken — zero measured drift.

## Gates at merged tip (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored full Test stems, NON-ZERO every line)
- `(ClientToolRegistryTest|ClientToolProxyTest|ClientToolPermissionTest)::` → OK 53T/136A (solos 39/91 · 8/23 · 6/22 — sums exact, §7 durations facts).
- `ForkChannelClientCallRoundTripTest::` → OK 7T/40A (real-fork legs EXEC green, zero skips — the ext-pcntl setUp guard never fires on this host).
- `SessionHostTest::` → OK 18T/83A · `TurnPathContractTest::` → OK 9T/68A.
- BARE TreeWideGuardRosterTest → OK 17T/3957A · BARE DuplicatedTestHelperDriftTest → OK 14T/204A.
- Wide window `--filter Permission` → 2332T/13192A/1F — EXACTLY the r1-observed shape; sole F named by measurement = ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode (base-carried foreign; offenders Config/StatusLineSession.php + Host/Commands/SchedulesCommand.php outside the 22-file span; cure-NONE).

## Foreign reds (ACCEPTED-FOREIGN roster, report-only, cure-NONE — named BY MEASUREMENT per the r2 census-name correction)
- StderrEmitterCensusTest 95T/2F — PtyRelay 6.j-debt shapes (bare emitter 75174ea40 + roster "nine"/"thirty-eight"-vs-47). Lane adds zero stderr writes.
- ForeignAgentPresetWiringTest 1F (above). GlobDialect 1F base-carried corpus figure (docblock-vs-derived; asserted NEITHER number; 13T/364A/1F standalone confirmed present, outside the Permission window by name).
- ReadmeSuiteFigureDrift staleness pair — NEVER touched (suite-figure.json/durations.tsv byte-zero across the span, proven). This weld carries +60T-class net-new (four new files: 39+8+6+7=60T, 91+23+22+40=176A) disclosed for §7 battery re-pin.
- AccessorDeprecationTest :168/:202 ambient Deprecations:2 pair present identically on every run (roster foreign).
- 'SeamCallSiteCensusTest' label retired per r2 correction — not a real class; LaunchContextDelegatesTest IS real (tests/Cli/LaunchContextDelegatesTest.php:34, df106a84c 4.g-debt row) — not re-run here, battery adjudicates.

## §7 hand-offs LISTED never fixed (battery/lead owns)
- durations.tsv rows to mint at battery, THIS welders live counts: tests/Host/ClientTools/ClientToolRegistryTest.php 39T/91A · tests/Host/ClientTools/ClientToolProxyTest.php 8T/23A · tests/Permissions/ClientToolPermissionTest.php 6T/22A · tests/Backend/ForkChannelClientCallRoundTripTest.php 7T/40A (60T net-new; plus carry on SessionHostTest/RuntimeTest/TurnPathContractTest/Wither/AskRoundTrip/BuiltInToolCorpus MODIFIED files).
- suite-figure pair: +60T-class carry disclosed; pinned 25,537/609,443 vs live ~29.6k+ staleness shape unchanged (battery re-pin owns; fail-loud asserts of OLD state).
- RESTRICTED_TOOL_NAMES += Script — LEAD ruling still pending. web.yml gen:strings — LEAD rider.
- WARN NEXT WELDER (6.g): 6.g OWNS the protocol-trio regen (schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO manual canvas rows verbatim + dist rebuild + ProtocolSchemaDrift by-design green-at-regen); 6.g base predates db1441a61 ⇒ its BashSandbox|Bubblewrap pairing co-run MUST read GREEN on THIS merged tip (39T/192A here).

## Absorption proof + cleanup
- `git cherry master cc/C-TOOL-6.h` → NINE `-`, ZERO `+` (patch-id-exact; no conflict resolutions, content-proof contingency unused).
- Worktree /home/sites/cc-cmp-C-TOOL-6.h removed + branch cc/C-TOOL-6.h deleted after proof. `git worktree list` re-read: survivors EXACTLY cc/C-TOOL-6.g + cc/C-TUI-6.l + cc/C-TUI-6.d (live lanes — never touched).

## Loop arc (one honest clause)
First guard-cut builder retired confused-identity (zero commits; the old 5,234 B REVIEW-1-era files were later superseded on disk — zero authority cited, nothing from them named in the §4 mark) → fresh cut DELIVERED the eight picks → r1 FINDINGS 0/0/1/2 WELD-ELIGIBLE YES, one-line docblock MINOR cured in FIX-1 (6,625 B) → r2 12,753 B CLEAN closure `0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (record-only ext-pcntl setUp guard), WELD-ELIGIBLE: YES`.

No push (lead pushes at wave close; W35 does NOT close here). §3 untouched. Evidence committed: REPORT.md 17,365 B · REVIEW-1.md 7,167 B · FIX-1.md 6,625 B · REVIEW-2.md 12,753 B · WELD.md (this file) — lanes-dir untracked NOTES.md stays uncommitted lane dirt per the five-file spec.
