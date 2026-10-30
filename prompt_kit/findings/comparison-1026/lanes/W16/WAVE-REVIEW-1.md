# W16 wave-loop REVIEW round 1 (reviewer: fresh; master cf0cfd0be; 2026-10-28)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 3 NIT)

## 1. Hashes / PHANTOM law — PASS
All ten §4 marks exist (`git cat-file -e`), are ancestors of cf0cfd0be (`merge-base --is-ancestor`), author+committer detain@interserver.net for all 10 picks and every rider:
P-TABS.g d489f425f · C-CTX-2.d 15c1f71fc · C-EXT-4.b 3bc062fc4 · C-EXT-5.a 179ac2e3f · C-MCP-3.b 5cac857fc · C-MCP-4.b 6beef34f4 · C-PRV-2.b cf36a8458 · C-SEC-6.a be226b2b0 · C-SEC-7.d fecec0d99 · C-SEC-8.c bd539b638.
Riders verified: 8d0ffa371 (C-CTX-2.d regen trio), 50164befb, f024af640, 8c1b44b08, 37a4ef615, f0cf899f9, 454e41176, 8f0001af1 (durations), 57f7d7ae1.
C-SEC-8.c reconciliation: §4 mark bd539b638 IS the content commit (sugar-core Sanitize::stripInvisibleFormatting + 35T/2187A test, 2 files +267); 57f7d7ae1 is the successor weld-ceremony commit (plan lines + lanes/C-SEC-8.c/* + run-state only). WELD.md documents the death-mid-ceremony + finisher. Clean.
Band 96d36db45..cf0cfd0be = 19 commits, Co-Authored/Co-developed trailer count = 0 (author identity PASS).

## 2. Claims vs landing — PASS (every §4 promise landed; conditional files honest)
- P-TABS.g: strip+Sessions pane+badges+OSC2 title all in d489f425f (App 298+/Tui Renderer 62/Pane 22/KeyBindingRegistry 15/en 11/README 17; 3 new test files 644 lines). PARKED-DIFF.patch 0 bytes → parked content fully landed. HANDOFF deviations (lang key tui.pane.label.sessions outside declared prefix — REQUIRED by exhaustive Pane::label; tabCycle ripple flips; <12-row/early-modal/docked suppression; win:a: no-ops for P-AGENT.b; rekeyDeck lag; tabs()-list indexing bug found+fixed in-lane) all disclosed in REPORT.
- C-CTX-2.d: 12-file pick has NO generated trio (correct §1.8); rider 8d0ffa371 carries schema +38 / SERVER.md rows :540,:546 / generated.ts +12; events present in schema (2) + generated.ts (4); typecheck at merged tree shows only the pre-existing baseline shape (5 spec files + 2 byte-stale contextSources lines), zero prompt.suggested/context.changed TS2344/TS18046 — the self-heal disclosure holds.
- C-EXT-4.b: marketplace add/list/remove/update + install <path|git-url|plugin@marketplace> + update + search + recommend all live in Subcommands.php (1067+) AND documented verbatim in PLUGINS.md (:117-188); 2 new tests 826 lines; in-step census carries (ProjectTier+31, Stderr+5, ReadPath+17) verified green.
- C-EXT-5.a: 4 new files, intentionally unwired pending C-EXT-5.b (W21) — §4 says modify: none; not a defect.
- C-MCP-3.b: §4 BuiltInToolCorpus.php was conditional ("if it pins McpCallTool's interface set") — not needed, corpus green; elicitation/progress/heartbeat/deadline/throttle all in bridge+call-tool; ask-user-on-behalf via AskUserTool + headless refusal in NonInteractive; §Server requests doc at MCP.md:628.
- C-MCP-4.b: session-scoped disable (McpClient 129) + /mcp enable|disable verbs (McpAuthCommand 126, panel routes, MCP.md :547/:851-872, en.php 23); SettingsWriterCensus row persistSessionToggle=>3 green; patch-id context-shift disclosed, content-equivalence per WELD.
- C-PRV-2.b: Message.php not touched — §4 list vs design conditional ("only if they reject the new block type"); carrier filter verified type-agnostic (Message.php:796/:1295 is_array-only). Honest deviation.
- C-SEC-6.a: SavedRules extract (NEW 231) + command 161 + prompt -62 + docs + census +10 + 2 new tests — all landed.
- C-SEC-7.d: auto-review scope tiers (PermissionGate 93/ExecReviewer 74/Bootstrap 47 = the §4 permissionGate claim) + SETTINGS/README/PERMISSIONS + StderrEmitterCensus 40→43 flip in-step; NIT-A/B cures (WebFetch preview truth, tool-less reflection pin) present in landed tree.
- C-SEC-8.c: stripInvisibleFormatting + 35T pin in sugar-core, off candy-code hub traffic entirely — matches design.

## 3. Cross-lane interactions — PASS
- lang/en.php: zero top-level duplicate keys; all 6 W16 en.php touches coexist.
- docs/MCP.md: C-MCP-3.b §Server requests (:628) and C-MCP-4.b runtime toggle rows (:547/:851-872) merged without clobber.
- Hub discipline: exactly one W16 lane each for McpClient.php (4.b), EngineBackend/NonInteractive (3.b), Bootstrap.php (7.d), Renderer/Tui/Renderer/KeyBindingRegistry (P-TABS.g), Subcommands.php (4.b); protocol surfaces only C-CTX-2.d — §2 caps honored, no intra-wave chain violation.
- Census/guard families (StderrEmitterCensus|ReadPathCensus|SettingsWriterCensus|KeyBindingDrift|TrustKey|BuiltInToolCorpus|LangParity): OK 420T/32441A — both new SettingsWriterCensus rows green, Bootstrap emitter count agrees.
- No duplicate keybinding (sessions.toggle Alt+S unique; KeyBindingDrift green). No doc-merge dropped rows found (SERVER.md both rows; COMMANDS/PERMISSIONS/PLUGINS generator checks green).

## 4. Merged-tree targeted gates (cwd candy-code, -u LINES -u COLUMNS, plain pipe) — ALL REPRODUCE WELD FIGURES EXACT
- P-TABS: 182T/2645A OK · CTX: 20T/529A OK · tests/Bundles: 118T/499A EXACT · PaletteCatalog: 8T/401A EXACT · MCP new: 31T/134A EXACT · PRV+SEC: 79T/268A EXACT · sugar-core Sanitize: 158T/2616A EXACT.
- Drift guards (DocFigure|Glob|TreeWide|ContainedPath|ProjectTier|ReadmeRoster|EnvRoster): 233T, 4F — all four are the sanctioned standing-red roster (ProjectTierRefusalInventory 2F + ContainedPathInventory 2F cross-wave carry; identical entry lists per W14/W15 WELD). NOT W16.
- E646: added lines in C-SEC-7.d (Bootstrap/PermissionGate/ExecReviewer) and C-MCP-3.b (bridge/call/backend/noninteractive) carry ZERO timeout/deadline/set_time_limit — no blanket LLM timeout introduced; paid auto-review calls are the §6 ruling; MCP toolTimeout pre-existing per-call.
- child-lifetimes: C-EXT-4.b diff adds 0 proc_open (3 bundlesCloneDefault references = existing roster-covered helper reuse). tools/check-child-lifetimes.php re-run refused by reviewer permission map; gen-*-checks all run instead (see §5).

## 5. Generated files — PASS
gen-command-docs/gen-tool-docs/gen-settings-doc --check rc0; scripts/gen-protocol-schema.php --check rc0; candy-code-web `npm run gen:protocol -- --check` → generated.ts up to date. C-CTX-2.d trio regen landed via rider (schema+SERVER.md+generated.ts+dist).

## 6. Weld hygiene — PASS with NITs
- No weld-only file committed by a lane; lanes never touched suite-figure.json/durations.tsv (weld riders carry durations 8f0001af1 etc.).
- Generated files regenerated (rider), not hand-edited.
- ZERO W16 worktrees or cc/ branches remain (9 live worktrees are W17+ in-flight lanes; main-tree dirt is lead state — untouched).
- NIT-1: C-PRV-2.b lane committed 15 probe-scratch PHP files (mini*.php, probe*.php, M2*ProbeTest.php, review3-m2probe*.php, seg/statement/traitconst/two.php) into prompt_kit/findings/comparison-1026/lanes/C-PRV-2.b/. Harmless (no tree-walk guard covers prompt_kit) but noise — closeout may prune or annotate.
- NIT-2: P-TABS.g evidence dir carries untracked dispatch artifacts HANDOFF.md + empty PARKED-DIFF.patch (0 bytes). Decide at closeout: commit as evidence or delete.
- NIT-3: C-CTX-2.d REPORT disclosure #4: typedCompact announce is code+EventTypeTest-pinned but not behavior-pinned (compactor fold too heavy for fixture) — accept as disclosed residual test debt; fold into closeout roster.

## 7. Laws audit
E646 PASS (§4). Dormant code: C-EXT-5.a palette catalog is deliberately staged-unwired with its own 8T pin + a named wiring successor step — wired-later, not deleted-later pattern intact. Fork/child discipline PASS (no new spawn sites). Author identity PASS (19/19 commits, 0 trailers).

## Findings table
- NIT lanes/C-PRV-2.b/*: committed probe scratch — prune or annotate at closeout.
- NIT lanes/P-TABS.g/{HANDOFF.md,PARKED-DIFF.patch}: untracked leftovers in evidence dir — decide at closeout.
- NIT C-CTX-2.d behavior-pin debt: typedCompact announce reason not behavior-pinned — disclosed, record for closeout roster.

No fixer lane needed. W16 may stay closed for the wave loop; carry NITs to §7 closeout.
