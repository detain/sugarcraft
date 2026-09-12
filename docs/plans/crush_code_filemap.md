# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-12 @ tip `378cb9fa6` — **FULL REGEN from scratch at the round-68 close** (supersedes the
`323605773` cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 49 rows below): actionable 49 BY ROW CENSUS**
(OPEN 30 / PARTIAL 18 / STALE-CITATION 1 / UNCERTAIN 0) — **the +6 chained-drift question retired at this
close per §0-NOW-70 + the triage ROUND-68 CLOSE paragraph** (census-derivation adopted: the r67 cut's 55 rows
minus r68's six census-exits — E107/E165/E583, E319/E547, E36). Census rule: E3 and E686 carry PARTIAL stamp
prose but sit physically in the triage OPEN table and count there; the `stamp` column below shows each id's
ledger disposition, so the map itself reads 28 OPEN + 20 PARTIAL + 1 STALE.
CLOSED rows are PRUNED at this regen (their closeout record lives in the triage rows + worklog rounds — the
map is a scheduling aid, not the history). Round-68 lane letters (**ea–ee**) are retired at this close; the
only `⚠` marks kept are `⚠ea` on the DEFERRED citation-roster files (ea retired; the roster carried to r69
**fi**) — earlier retired-wave marks (⚠da..⚠ks) are dropped here; their collision history lives in the
worklog. Round-69 lane ownership (fa–fj) defined in `crush_code_RESUME.md` §0-NOW-70 §2 —
`src/Renderer.php` RESERVED for **fj**, `src/Runtime.php` RESERVED for **fa**. The `r68 status` column below
carries each id's round-68 disposition status. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a
file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`), normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/Cli/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠ea` = RETIRED round-68 lane letter — marks the files named in ea's `DEFERRED_BARE_CITATIONS` roster
  (the guard itself lives at `sugar-crush/tests/SymbolCitationDriftTest.php`; staleness arm :1262-1268
  enforces the same-commit retire when **fi** clears the roster).

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r68 status |
|---|---|---|---|---|---|---|
| E3 | PARTIAL | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Palette/PaletteState.php;sugar-crush/src/Renderer.php;sugar-crush/tests/Renderer/PaletteCaretHonoursHandoffTest.php | chat-input | M | w1:eb caret CLOSED, cursor → fj |
| E5 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S | Renderer family — fj owns the file (r69 reserved) |
| E9 | STALE-CITATION | MED | sugar-crush/README.md;docs/plans/crush_code_worklog.md | docs | M | → fh |
| E10 | OPEN | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php;sugar-crush/tests/Tools/BuiltInToolTest.php | tools-skills | M | → fc |
| E16 | OPEN | MED | sugar-crush/src/Runtime.php;sugar-crush/src/Hooks/HookManager.php | tools-skills | M | → fa |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 pinned; p2 design → carry |
| E43 | PARTIAL | HIGH | sugar-crush/src/Renderer.php | tui-render | S | DECLINED r68/eb measurement → fj |
| E54 | PARTIAL | HIGH | sugar-crush/src/Tui/AgentViewPane.php;sugar-crush/src/Renderer.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/src/Tui/Components/AgentDashboardPane.php | tui-render | M | Renderer family — fj owns the file (r69 reserved) |
| E93 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php;sugar-crush/src/Util/PathGlob.php | tools-skills | M | → fc (⚠ never touch glob-shaped literals — GlobDialect corpus) |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S | → fh |
| E140 | OPEN | HIGH | sugar-crush/src/Support/ToolIpcFiles.php;sugar-crush/src/Runtime.php | tools-skills | M | → fa |
| E158 | PARTIAL | HIGH | sugar-crush/tests/Integration/BinSugarcrushAutoloadGuardTest.php | tests-harness | S | → fe |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php | tools-skills | S | → fc |
| E176 | PARTIAL | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/src/Cli/NonInteractive.php | cli-config | M | → ff (census flips IN-STEP) |
| E194 | OPEN | HIGH | sugar-crush/phpunit.xml;sugar-crush/tests/ | tests-harness | M | → fd (row BROAD — scope-narrow with evidence first) |
| E199 | OPEN | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php | cli-config | S | → fc |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M | → fh (supervisor owns closeout — actionable half only) |
| E208 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitScanner.php;sugar-crush/tests/Support/TokenFunctionRanges.php | tests-harness | M | → fd |
| E214 | PARTIAL | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S | r67 CLOSED claim REFUSED; → ff (census flips IN-STEP) |
| E228 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php | tests-harness | S | r67 CLOSED claim REFUSED; → ff (census flips IN-STEP) |
| E242 | OPEN | HIGH | sugar-crush/tests/bootstrap.php | tests-harness | S | — |
| E246 | PARTIAL | MED | sugar-crush/src/Runtime.php;sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | M | — (unlaned; cites fa's + fb's reserved files — schedule after them) |
| E257 | PARTIAL | MED | sugar-crush/tests/Integration/BinSugarcrushWiringTest.php;sugar-crush/tests/Tools/BuiltInToolCorpusTest.php | tests-harness | M | → fe (owns BuiltInToolCorpusTest — fc flags fe before new src files) |
| E281 | OPEN | HIGH | sugar-crush/tests/Agents/TeamTest.php | tests-harness | S | → fe |
| E291 | PARTIAL | HIGH | sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/tests/Support/ForkedChildExitConventionTest.php | sessions | M | → fh |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | → fb |
| E322 | PARTIAL | MED | sugar-crush/tests/TtyStreamArgumentCensusTest.php | tests-harness | S | → fe |
| E325 | OPEN | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php | tests-harness | M | → fd |
| E347 | PARTIAL | MED | sugar-crush/tests/Cli/RefusalStderrSurfaceTest.php;sugar-crush/src/Permissions/DenialKind.php | tools-skills | M | → fb (r68 judged NOT the citation owner) |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S | → fh |
| E369 | OPEN | HIGH | sugar-crush/tests/StdinConstantReaderCensusTest.php | tests-harness | S | → fd |
| E375 | OPEN | MED | sugar-crush/src/Permissions/DenialKind.php | tools-skills | S | → fb |
| E376 | OPEN | HIGH | sugar-crush/src/Cli/HeadlessPermissionPrompt.php | cli-config | S | → fc |
| E378 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | S | → fg |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php | tests-harness | M | → fg |
| E391 | OPEN | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php | tests-harness | S | → fg |
| E419 | OPEN | MED | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Agents/ProcessExecutor.php | tests-harness | M | — (children-lifetime family — coordinate with fg) |
| E424 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScannerFixtureTest.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Support/ProcessReaper.php | tests-harness | M | — (children-lifetime family — coordinate with fg) |
| E445 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitConventionTest.php;sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | M | → fg |
| E469 | OPEN | HIGH | sugar-crush/tests/SuiteSkipRosterTest.php | tests-harness | S | → fe |
| E493 | OPEN | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php | providers | M | → fa |
| E505 | PARTIAL | MED | sugar-crush/tests/ClaudeCodeMcpClientStdinWedgeTest.php;sugar-crush/tests/LSP/LspConnectionStdinWedgeTest.php;sugar-crush/tests/MCP/StdioMcpServerShutdownTest.php;sugar-crush/tests/Integration/McpToolWiringTest.php | tests-harness | M | → fg (stdin-wedge group) |
| E541 | PARTIAL | MED | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | → fb |
| E564 | OPEN | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S | → fh |
| E609 | OPEN | HIGH | sugar-crush/tests/Backend/BackendSignatureNullabilityTest.php;sugar-crush/tests/Support/DuplicatedDocBlockLineTest.php⚠ea | tests-harness | M | → fi (E609 rides fi, NOT fe — r68 decision) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | → fh (re-derive the row first) |
| E616 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | → fb |
| E633 | PARTIAL | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S | → fh |
| E686 | PARTIAL | MED | sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md;sugar-crush/tests/Config/DocFigureProseDriftTest.php | tests-harness | M | t5 arms=29 → t6 carry ed/measures.md |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 21 | E158, E194, E208, E214, E228, E242, E257, E281, E322, E325, E369, E378, E390, E391, E419, E424, E445, E469, E505, E609, E686 |
| tools-skills | 11 | E10, E16, E93, E140, E172, E246, E309, E347, E375, E541, E616 |
| other | 4 | E25, E564, E611, E633 |
| docs | 4 | E9, E134, E204, E353 |
| cli-config | 3 | E176, E199, E376 |
| tui-render | 3 | E5, E43, E54 |
| chat-input | 1 | E3 |
| providers | 1 | E493 |
| sessions | 1 | E291 |

Sum = 49 ✓ (equals the table's row count).

## Cross-file collision clusters (≥3 actionable ids sharing a file)

- **`sugar-crush/src/Renderer.php`** — E3 (caret gate landed r68/eb; remainder routes fj), E5, E43, E54 — the file is RESERVED for **fj** this round; every Renderer touch rides fj or waits.
- **`sugar-crush/src/Runtime.php`** — E16, E140, E246, E493 — RESERVED for **fa**; E246 also shares fb's DenialPrefixRosterTest.
- **`sugar-crush/tests/DenialPrefixRosterTest.php`** — E246, E309, E541, E616 — **fb** owns the roster (E309/E541/E616); E246 unlaned, schedule after fb+fa.
**Notes (below the ≥3 threshold — kept for scheduling, not clusters):**

- **`sugar-crush/tests/Config/DocFigureProseDriftTest.php`** — E686 only in the table, but it is the campaign file: arms count + census numeral trio flip IN-STEP with any tranche.
- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — single-owner guard file; di's wave-2 polity work landed there (E272/E331/E356/E481/E565/E610 all CLOSED) — future const/drift roster edits are ONE-lane work.
- **ea's DEFERRED citation roster (⚠ea)** — tests/Chat/SessionStartHookWireTest.php :275+:373, tests/Context/GlobDialectDifferentialTest.php:961, tests/Integration/RulePathScopingWiringTest.php:398, tests/RuntimeInitialDispatchOrderTest.php:22, tests/Support/DuplicatedDocBlockLineTest.php:30/:113 → r69 **fi** (line cites are the guard's comment-open anchors; tokens sit at :400/:38 in the latter two). Only DuplicatedDocBlockLineTest overlaps a table row (E609 → fi); the roster itself lives in `tests/SymbolCitationDriftTest.php` (staleness arm :1262-1268 enforces same-commit retire).


*Derived from `docs/plans/crush_code_backlog_triage.md` + `docs/plans/crush_code_hardening_backlog.md` headings at `378cb9fa6`; round-68 closeout.*
