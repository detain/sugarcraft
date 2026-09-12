# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-12 @ tip `323605773` — **FULL REGEN from scratch at the round-67 close** (supersedes the
`61cde19c5` cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 55 rows below):** the triage live row census, 55.
Ledger arithmetic says **49** (chained: 61 at `fb5078e6d` minus wave-2's 12). **Census note (the +6 the r66
close ordered reconciled):** every open-family row was cross-checked against its backlog heading and every
worklog CLOSED claim — zero rows are closed-in-fact; the +6 is an ARITHMETIC-PROVENANCE defect (the r64
close `c611441b5` declared 131→117 while its own enumerated flips give 123; every later round chained the low
figure). Verdict + recommendation in the triage header; next re-mint derives from the row census.
CLOSED rows are PRUNED at this regen (their closeout record lives in the triage rows + worklog rounds — the
map is a scheduling aid, not the history). Round-67 lane letters (**da–df**, **dh–dl**, **ks**) are retired
history: the wave-1 `⚠da..⚠df` letters on surviving rows record collision history only. Round-68 lane
ownership (ea–ee) is defined in `crush_code_RESUME.md` §0-NOW-69 §2 — `src/Renderer.php` is RESERVED for eb;
the `r67` column below carries each id's round-67 disposition status. Tier/lane analysis lives in
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
- ``⚠xx` = RETIRED round-67 lane letter (wave-1 scheduling history; dh–dl closures were pruned with their rows);
  retired letters (⚠α..⚠ε, ⚠ba..⚠be, ⚠ca..⚠ch) on CLOSED rows record the lane that shipped the fix.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r67 status |
|---|---|---|---|---|---|---|
| E3 | PARTIAL | HIGH | sugar-crush/src/Chat.php;sugar-crush/src/Palette/PaletteState.php⚠de | chat-input | M | w1:de PARTIAL (caret → eb) |
| E5 | OPEN | HIGH | sugar-crush/src/Renderer.php | tui-render | S | Renderer family — eb owns the file |
| E9 | STALE-CITATION | MED | sugar-crush/README.md;docs/plans/crush_code_worklog.md | docs | M | — |
| E10 | OPEN | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php;sugar-crush/tests/Tools/BuiltInToolTest.php | tools-skills | M | — |
| E16 | OPEN | MED | sugar-crush/src/Runtime.php;sugar-crush/src/Hooks/HookManager.php | tools-skills | M | — |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | → ec verdict |
| E36 | UNCERTAIN | LOW | sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠dc⚠df | tests-harness | S | df carried → ec verdict |
| E43 | PARTIAL | HIGH | sugar-crush/src/Renderer.php | tui-render | S | r62/63 PARTIAL; adoption unowned |
| E54 | PARTIAL | HIGH | sugar-crush/src/Tui/AgentViewPane.php;sugar-crush/src/Renderer.php;sugar-crush/src/Tui/Renderer.php;sugar-crush/src/Tui/Components/AgentDashboardPane.php | tui-render | M | Renderer family — eb owns the file |
| E93 | OPEN | MED | sugar-crush/src/Skills/SkillRegistry.php;sugar-crush/src/Util/PathGlob.php⚠df | tools-skills | M | — |
| E107 | OPEN | LOW | UNKNOWN(re-derive)⚠df | other | S | → ec verdict |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S | — |
| E140 | OPEN | HIGH | sugar-crush/src/Support/ToolIpcFiles.php;sugar-crush/src/Runtime.php | tools-skills | M | — |
| E158 | PARTIAL | HIGH | sugar-crush/tests/Integration/BinSugarcrushAutoloadGuardTest.php | tests-harness | S | — |
| E165 | OPEN | MED | sugar-crush/tests/Config/ReadmeRosterDriftTest.php⚠dd⚠df | tests-harness | S | → ec verdict |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php | tools-skills | S | — |
| E176 | PARTIAL | MED | sugar-crush/tests/Support/ChildStderrCaptureTest.php;sugar-crush/src/Cli/NonInteractive.php⚠dc⚠db | cli-config | M | — |
| E194 | OPEN | HIGH | sugar-crush/phpunit.xml;sugar-crush/tests/ | tests-harness | M | — |
| E199 | OPEN | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php | cli-config | S | — |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M | — |
| E208 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitScanner.php;sugar-crush/tests/Support/TokenFunctionRanges.php | tests-harness | M | — |
| E214 | PARTIAL | HIGH | sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tests-harness | S | r67 CLOSED claim REFUSED |
| E228 | PARTIAL | MED | sugar-crush/tests/Cli/StderrEmitterCensusTest.php⚠db | tests-harness | S | r67 CLOSED claim REFUSED |
| E242 | OPEN | HIGH | sugar-crush/tests/bootstrap.php⚠dc | tests-harness | S | — |
| E246 | PARTIAL | MED | sugar-crush/src/Runtime.php;sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | M | — |
| E257 | PARTIAL | MED | sugar-crush/tests/Integration/BinSugarcrushWiringTest.php;sugar-crush/tests/Tools/BuiltInToolCorpusTest.php | tests-harness | M | — |
| E281 | OPEN | HIGH | sugar-crush/tests/Agents/TeamTest.php | tests-harness | S | — |
| E291 | PARTIAL | HIGH | sugar-crush/src/Sessions/BackgroundSessionRunner.php;sugar-crush/tests/Support/ForkedChildExitConventionTest.php | sessions | M | — |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | — |
| E319 | PARTIAL | HIGH | sugar-crush/tests/Support/ForkedChildTest.php;sugar-crush/tests/ChatTest.php;sugar-crush/tests/Backend/EngineBackendTest.php;sugar-crush/tests/Support/SuiteChildStdinIsolationTest.php;sugar-crush/tests/bootstrap.php⚠dc | tests-harness | L | w2:dj inversion dead → ee |
| E322 | PARTIAL | MED | sugar-crush/tests/TtyStreamArgumentCensusTest.php | tests-harness | S | — |
| E325 | OPEN | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php | tests-harness | M | — |
| E347 | PARTIAL | MED | sugar-crush/tests/Cli/RefusalStderrSurfaceTest.php;sugar-crush/src/Permissions/DenialKind.php | tools-skills | M | — |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S | — |
| E369 | OPEN | HIGH | sugar-crush/tests/StdinConstantReaderCensusTest.php | tests-harness | S | — |
| E375 | OPEN | MED | sugar-crush/src/Permissions/DenialKind.php | tools-skills | S | — |
| E376 | OPEN | HIGH | sugar-crush/src/Cli/HeadlessPermissionPrompt.php | cli-config | S | — |
| E378 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | S | — |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠dc⚠df | tests-harness | M | — |
| E391 | OPEN | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php⚠dc | tests-harness | S | — |
| E419 | OPEN | MED | sugar-crush/tests/Support/ChildLifetimeScanner.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Agents/ProcessExecutor.php⚠dc | tests-harness | M | — |
| E424 | OPEN | HIGH | sugar-crush/tests/Support/ChildLifetimeScannerFixtureTest.php;sugar-crush/tests/Support/DescriptorInheritanceGuardTest.php;sugar-crush/src/Support/ProcessReaper.php⚠dc | tests-harness | M | — |
| E445 | OPEN | HIGH | sugar-crush/tests/Support/ForkedChildExitConventionTest.php;sugar-crush/tests/Support/ForkedChildReaperAdoptionTest.php | tests-harness | M | — |
| E469 | OPEN | HIGH | sugar-crush/tests/SuiteSkipRosterTest.php | tests-harness | S | — |
| E493 | OPEN | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php⚠de | providers | M | — |
| E505 | PARTIAL | MED | sugar-crush/tests/ClaudeCodeMcpClientStdinWedgeTest.php;sugar-crush/tests/LSP/LspConnectionStdinWedgeTest.php;sugar-crush/tests/MCP/StdioMcpServerShutdownTest.php;sugar-crush/tests/Integration/McpToolWiringTest.php⚠dc | tests-harness | M | — |
| E541 | PARTIAL | MED | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | — |
| E547 | PARTIAL | HIGH | sugar-crush/tests/Backend/StreamingCommandBackendTest.php⚠dd | tests-harness | S | dd held → ea |
| E564 | OPEN | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S | — |
| E583 | OPEN | HIGH | sugar-crush/tests/;sugar-crush/tests/SymbolCitationDriftTest.php⚠dd | tests-harness | M | dd held → ea |
| E609 | OPEN | HIGH | sugar-crush/tests/Backend/BackendSignatureNullabilityTest.php;sugar-crush/tests/Support/DuplicatedDocBlockLineTest.php | tests-harness | M | — |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | — |
| E616 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php | tools-skills | S | — |
| E633 | PARTIAL | HIGH | sugar-crush/src/Context/RepoMapBlock.php | other | S | — |
| E686 | PARTIAL | MED | sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md⚠be⚠cb | tests-harness | M | w2:dl t4 arms=23 → ed |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 26 | E36, E158, E165, E194, E208, E214, E228, E242, E257, E281, E319, E322, E325, E369, E378, E390, E391, E419, E424, E445, E469, E505, E547, E583, E609, E686 |
| tools-skills | 11 | E10, E16, E93, E140, E172, E246, E309, E347, E375, E541, E616 |
| other | 5 | E25, E107, E564, E611, E633 |
| docs | 4 | E9, E134, E204, E353 |
| cli-config | 3 | E176, E199, E376 |
| tui-render | 3 | E5, E43, E54 |
| chat-input | 1 | E3 |
| providers | 1 | E493 |
| sessions | 1 | E291 |

## Cross-file collision clusters (≥3 actionable ids sharing a file)

- **`sugar-crush/src/Runtime.php**` — E16, E140, E246, E493
- **`sugar-crush/tests/DenialPrefixRosterTest.php**` — E246, E309, E541, E616
- **`sugar-crush/src/Renderer.php**` — E5, E43, E54
- **`sugar-crush/tests/**` — E194, E583, E686

- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — single-owner guard file; di's wave-2 polity work landed here (E272/E331/E356/E481/E565/E610 all CLOSED) — future const/drift roster edits are ONE-lane work.
- **`sugar-crush/tests/Support/ForkedChildTest.php` / `tests/ChatTest.php`** — E319's stale-comment trio (ForkedChildTest:182+:275, ChatTest:846) is **ee**'s, with the vocabulary-guard docblock exemplar in the same lane.


*Derived from `docs/plans/crush_code_backlog_triage.md` + `docs/plans/crush_code_hardening_backlog.md` headings at `323605773`; round-67 closeout.*
