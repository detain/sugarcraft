# WELD-1030L — live survival log (four welds: C-TOOL-2.c, C-TUI-2.g, C-TUI-4.b, C-SEC-7.a)

Base anchor: master = 7f352e5c9 (STEP 0 VERIFIED `git rev-parse --short master`).
Single master writer. No push. Cherry-pick only, never tree-copy.

## PLANNED
1. WELD 1 — C-TOOL-2.c (W25): picks 653a2349c + 77c82adb9; SETTINGS regen-by-generator; MINT 1 dur row AnthropicServerWebSearchTest (10 tests, median of 3); commit lane reviews (REVIEW-1/2 main-tree-only + FIX-1 worktree-only); §4 mark; fold; prune after cherry proof. Landed-blob proof ARCHITECTURE.md:155 == '64'.
2. WELD 2 — C-TUI-2.g (W26): picks 98f6b0f4a + 0d66f3720; PAIR regen gen-protocol-schema --write + web npm run gen:protocol in ONE commit; dist rebuild (cp-seeded node_modules local bins, no npm ci); vitest serial; ProtocolSchemaDrift + ServerProtocolDrift; vue-tsc project-scoped + --build both numbers; MINT dur row FilesDiffCheckpointTest (~11.5-12s multi-second); collect UNTRACKED FIX-1/REPORT + main-tree-only REVIEW-1/2 BEFORE prune; §7 carry +9T/+64A; SERVER.md --check re-verify.
3. WELD 3 — C-TUI-4.b (W27): picks to e5ab9c7f2; no trio regen; dist rebuild REQUIRED; commit main-tree-only REVIEW-1.md; run+report vue-tsc --build + project-scoped numbers (close reviewer's denied-node empirical item); vitest serial re-run (expect contrast+theme 36, stores neighbourhood 135); no dur rows.
4. WELD 4 — C-SEC-7.a (W25): picks to 642827a9a; base 410f5ac58 OLD — expect shared-doc drift; SETTINGS conflict -> REGEN + --check; commit main-tree-only REVIEW-5/6/8; MINT dur row HeadlessDefaultPermissionModeTest (10 methods); gates: quintet 137T/40408A, LangParity|DocFigure 99T/7347A, touched 101T/517A; §7 carry +10T.

## LOG
- step 0: master == 7f352e5c9 — OK.
- WELD1 pick1 653a2349c -> 2b34bd253 (cat-file: commit). pick2 77c82adb9 -> 1b3bbd97c (cat-file: commit). ZERO conflicts, both clean. ARCHITECTURE.md:155 committed blob reads '...dispatch arms for 64' — proof PASS (lane adds no slash command).
- WELD1 DUR row MINTED line 597: candy-code/tests/Providers/AnthropicServerWebSearchTest.php 0.240/10 (junit per-file 0.239725/0.243919/0.239913, median 0.240; live OK 10T/66A x3; tsv 1641->1642).
- WELD1 gates: settings/tool/command doc --check rc=0 AND --write zero drift. Provider window Anthropic|Usage|SearchBackends|PromptPrefixByteStability 592T/2905A 1F = UsageRecordsTest::testTheProductionWiresRideTheChokeAndTheSeam — FOREIGN-INHERITED (Bootstrap.php blob c90d824c6 byte-identical base..HEAD; rename to keptStore minted by earlier-welded d07fa240a C-SES-7.c; pin regex not updated there; NOT on standing list — reported, not chased). Guard window ReadmeRoster|DocFigure|EnvRoster|TrustKey|SettingsSchemaDoc|ToolDoc|LangParity 182T/48529A OK. §7 carry +12T recorded, not re-pinned.
