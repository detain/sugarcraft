# WAVE 14 — review r1 (wave loop reviewer)

Date: 2026-10-28 · master under review: set as of `a812ea659 weld: C-AGT-5.b`; reviewed against HEAD
(`15c1f71fc C-CTX-2.d` — foreign W16 weld landed mid-review, disregarded except where noted).

## VERDICT: FINDINGS — 1 NIT (non-blocking); everything else CLEAN

One W14-attributable NIT from the second-pass durations audit (NIT-2, §7): the wave's own
scripts row for DoctorSandboxProbeTest carries the assertion count in the third column.
All step hashes, authors, regions, the ruling conformance, and every targeted gate otherwise
PASS. Report-only foreign notes in §4 and §7.

## 1. Step hash verification — 10/10 PASS

Re-derived §4 marks from plan_comparison.md lines 1696–1804 (not the brief's list). Each: real
commit, ancestor of master, `%ae`=1 and `%ce`=1 detain@interserver.net on SEPARATE greps, commit
bodies contain zero `Co-authored` matches (git show -s all ten | grep -ci = 0).

| step | §4 mark (pick) | weld | anc | author |
|---|---|---|---|---|
| P-TABS.e | 2a6cd3c1f | 2cd5294e9 | Y | detain a/c |
| C-WEB-3.e | 395bc096a | a58cf11bb (+dist rider 5a45aa4c0) | Y | detain a/c |
| C-TOOL-4.a | 1945e347f | b264527ff | Y | detain a/c |
| C-AGT-4.a | 093ea6e13 | 55d66b158 | Y | detain a/c |
| C-AGT-5.b | 93448849594e9c779e12a6979f2d5ac7e721f05d | a812ea659 (+durations 65061ab0f) | Y | detain a/c |
| C-EXT-4.a | 533a881ca | 102021de6 | Y | detain a/c |
| C-PRV-2.a | b4ff5dec3 | 11d272903 | Y | detain a/c |
| C-SES-7.a | bc898875f15a828b0d7dba103cf5a6a0321a992c | c385e13df (+durations 3c0dd82b9) | Y | detain a/c |
| C-TOOL-3.d | 734f97dda76b02e912933a996f62368877349cdc | 872d6da44 (+durations 54167db3e) | Y | detain a/c |
| C-TUI-2.a | ba057cad2 | b51a0ef37 | Y | detain a/c |

## 2. Union-diff cross-step review

File lists of all ten picks match the §4 Files promises (spot-diffed per lane). Interactions:

- **ReadPathCensusTest** touched by TOOL-4.a (+3), EXT-4.a (+16), SES-7.a (+5) — merged tree carries
  all rows: `Bundles/Marketplace.php|file_get_contents` (:300) and `Session/Import/SessionImporter.php|file_get_contents`
  (:825) present; full `ReadPathCensus` filter green in the guard batch.
- **lang/en.php** touched by 5 lanes — 2896 keys, `uniq -d` over key-spellings returns EMPTY (no
  duplicate keys), shapes well-formed (`cli.doctor.sandbox_*`, `cmd.shells.*`, `session.import.*`
  families visible); the `cmd.share.*` block is the pre-existing /share surface, C-WEB-3.e is
  web-only (0 lang touches in its pick). en-only honored (11-locale sweep is C-TUI-6.h, W19, LAST —
  not flagged).
- **Region discipline (§2/W14 header):** Chat.php claims are P-TABS.e `withNoticeDrain` (:9378 ✓) and
  C-TOOL-3.d `handleShellsCommand` (:15801) + `isReadOnlySafeCommand` (:10654 ✓). C-AGT-5.b's Chat.php
  touch is DOCBLOCK-ONLY (handleSchedules summary, 5 lines) — no unclaimed code region. Bootstrap.php
  claim `[bootstrap:workspace]` (C-TOOL-3.d) verified: wires `BackgroundShellRegistry` into workspace
  via the SAME memoized `backgroundShells()` registry (:2103 hunk), not a second table/reaper.
- **C-AGT-5.b delete guard vs §6.3 owner ruling (line ~4161, option (a) ROOT-SCOPE):** `ScheduleFactory::deleteRefusal`
  (ScheduleFactory.php:110-125): door-first (`includeOperatorRows` → null), operator rows byte-frozen
  sentence (C-AGT-5.a pinned), cross-root model rows refused naming the bound root, same-root model
  rows unrestricted. Human `/schedules delete` surface deliberately NOT behind the guard. Exact match.
- **C-EXT-4.a** ContainedPathInventory rows for the new user-tier reads landed (its 2 Marketplace rows
  are absent from the live-vs-roster diff — see §4 for the unrelated reds); `ContainedPath.php` 2-line
  touch is part of the same commit (one type per file check: guard green in batch).
- **MenuBarLabelDerivationTest** co-touch by AGT-5.b (+2) and TOOL-3.d (+1): both rows (`shells`,
  `schedules`) present on master; the test reds ONLY on the foreign `/pr` row (§4).
- **Web:** C-WEB-3.e sessions.ts calls `session.share`/`session.unshare`/`session.shares` (real callers
  for the formerly-dormary wire — gap closed); Sidebar gates write-scope items (read-only connection
  sees neither). C-AGT-5.b BackgroundPanel schedules table + spec present. dist: share markers grep
  live (rider 5a45aa4c0); schedules markers (`bg-schedules-refresh`) live ONLY via foreign
  `8d0ffa371 weld: C-CTX-2.d regenerate` — see §5 NIT-1.

## 3. Merged-tree battery (targeted, plain-pipe, cwd candy-code; PtyRunToolTest never used)

All green:
- `DocFigureProseDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|KeyBindingDrift` → 373T/44,989A OK.
- `LangParity|ScheduleCrossRootDeleteTest` → 15T OK. `Schedule` → 171T, 1F = DreamPassTest (roster item).
- `ShellsCommandTest` → 9T/45A OK (weld's "10T/51A" = +derived BinCandyCodeWiring census row, explained in its WELD.md).
- `SessionImporter|TranscriptReaders` → 26T/111A and `Import` → 234T/845A — EXACT to C-SES-7.a weld claims.
- `PaletteCatalogTest|BuiltInToolCorpus` → 61T OK. `AutoReviewScope` → 0 tests (brief mis-anchor, §5).
- All-ten-new-test-files sweep → 110T/511A OK.
- sugar-core `RegexHighlighter` → 80T/165A (EXACT weld claim); sugar-markdown `SyntaxHighlighter|ReadmeSnippets` → 106T/173A OK.
- Generators: gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema --check → all "up to date" rc0.
- Vitest --no-file-parallelism, the 2 new specs (sidebarShare + backgroundSchedules) → 2 files / 9 tests PASS.
- Guards family batch (`ReadPathCensus|StderrEmitterCensus|TreeWideGuardRoster|SettingsWriterCensus|FrontmatterKeyAudit|ContainedPathInventory|DuplicatedTestHelperDrift|GlobDialect`) → 239T, 2F, both ContainedPathInventory foreign (§4).

Reds (all foreign-attributed, see §4): `MenuBar|Palette|ShellMenu` 4F (pr), ContainedPathInventory 2F.

## 4. STANDING-RED ROSTER status + NEW foreign reds (report-only, NOT W14 findings)

Roster items confirmed as expected shapes: ReadmeSuiteFigureDrift staleness pair (outside my filters);
PermissionsIntroDriftTest 'Schedule' row (did not fire in Schedule filter — rostered anyway);
DreamPassTest name-collide (fired, attributed); MouseWiringTest ctxmeter (referenced by C-TUI-2.a
weld smoke note); EXT-5.a guard-trio wobble / numeral 'thirty-seven' items (C-TOOL-3.d WELD.md keep-once
documented — benign).

NEW foreign-inherited reds — NOT on the given roster, NOT attributable to W14:
1. **MenuBarLabelDerivationTest + ShellMenuTest + PaletteSupersetDispatchTest (4F)** — the `/pr` row 40
   added by C-AGT-6.d (W15 pick `b6369af78`, weld `a7abfe61c`, already in master). That pick does not
   touch MenuBarLabelDerivationTest (git show grep = 0) and ShellMenuTest/palette survivor-set moved
   with it. Cure belongs to the C-AGT-6.d wave loop (in-step roster + palette/judged-set flips).
2. **ContainedPathInventoryTest (2F)** — missing `Memory/AgentMemory.php|1` row (call site introduced by
   C-AGT-3.b, W6) and `Skills/SkillLoader.php` count 3→4 (C-EXT-2.a, W2). Pre-W14 lanes; cure = add
   the AgentMemory row and bump the SkillLoader count, both hand-spelled lists.

## 5. NIT (benign, resolved at HEAD)

NIT-1: C-AGT-5.b §4 entry promises "rebuild candy-code-web/dist/ (weld)"; at `a812ea659` no paired
regenerate commit existed (C-WEB-3.e set the pattern with 5a45aa4c0). The schedules-table markers
reached dist in the foreign `8d0ffa371 weld: C-CTX-2.d regenerate` after the wave tip — stale-dist
window between wave-tip and that commit, now closed. No action: HEAD dist is coherent; future web-
touching welds should carry their own regenerate rider.

## 6. Laws & hygiene

- E646: OpenAiResponsesWire docblock holds "NO total request timeout" law by reference (:33, :684);
  no wall-clock kill introduced by any W14 pick in the greps run.
- Weld hygiene: zero cc-cmp worktrees / cc/ branches for the 10 W14 steps (7 remaining worktrees are
  live W15-W17 lanes: AGT-5.c, EXT-4.b, MCP-3.b, MCP-4.b, PRV-2.b, SEC-6.a, SEC-7.d — not residue).
  Generated docs regenerated not hand-edited (all --check green). run-state.json welded count 141 at
  C-SES-7.a (brief's list order matches plan §4 marks).
- /tmp fallbacks present (evidence-of-record, not copied): /tmp/opencode/cc-cmp-lanes/{C-AGT-5.b, C-TUI-2.a}
  (+ a stray `C-EXT-4.b-r2-bundleupdatetest.php` file, foreign). C-TUI-2.a's committed WELD.md already
  records the "REVIEW-2.md disk-absence anomaly / blob-match precedent" — consistent.
- C-AGT-4.a `ForeignAgentPresetWiringTest` census row `Agents/PrimaryAgent.php` present (:484) with
  by-design docblock (:478) — the §4 docs/guards promise landed.

## 7. Second pass (post-`8d0ffa371`, full durations-column audit + keystone deep-read)

Battery re-run (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, PtyRunToolTest never used):
- `ScheduleCrossRootDeleteTest` → **8T/25A OK** (8 methods: 5 guard-polarity legs incl the byte-frozen
  operator sentence, + catalog-root tool legs refused-then-door, same-root free, description/schema spelling).
- `Schedule|Schedules` → **Tests: 171, Assertions: 756, Failures: 1** — sole red DreamPassTest:288 (roster).
- `ShellsCommandTest` file-only → **9T/45A OK**; `ShellsCommand` filter → **10T/51A OK** (EXACT brief shape;
  10th row derived BinCandyCodeWiring census — lane WELD/FIX-1 reconcile verbatim).
- Guard family `DocFigureProseDriftTest|GlobDialect|DuplicatedTestHelperDrift|ChildWallClock` →
  **130T/16354A OK** (T exact vs WELD's 130/16141-era; A drift is tree-scan additions).
- `MenuBar|Palette` → **294T/5100A/2F**; `MenuBarLabelDerivation|PaletteSupersetDispatch` → **17T/356A/2F** —
  CURRENT STATE pinned: both diffs' SOLE delta is `'pr' => 'Push the current'` inserted at index 6
  (PaletteSupersetDispatchTest:169, MenuBarLabelDerivationTest:90); shells/schedules rows agree both sides.
- `AutoReview` → **12T/41A OK**. `SessionImporter|TranscriptReaders` → **26T/111A OK**; `Import` → **234T/845A OK**.
- `SkillsFork|SkillToolFork|InertFrontmatter` → **16T/122A OK** (brief's "115T/4118A-era" reproduces in no
  W14 lane record; nearest claims 16T/82A SkillFork|SkillToolFork and 32T/114A InertFrontmatter|FrontmatterKeyAudit — green).
- `DoctorSandboxProbe|Bubblewrap` → **12T/60A OK**; `DoctorSandboxProbeTest` file → **10T/51A OK**.
  `Doctor|Bubblewrap|Sandbox` → **102T/1691A/1F**: red is `OneSidedHomeSandboxTest::testNoTestFileSandboxesOnlyHalfOfHome`
  — migration-backlog roster stale (4 files vanished from the one-sided scan: RuntimeNoticeSinkDeliveryTest,
  MemoryPromptWiringTest, WorkflowExecutionTest, SessionTest). NOT W14: zero W14 picks touch the four files,
  the guard, or HomeSandboxTrait (grep over all ten diffs); last touches are W4 cure ba6176184, W12 97113b210,
  rename e6567b98c. New foreign standing red — add to the roster; cure = delete the four rows in an owning wave.
- Docs guards `DocFigureProseDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|
  ConfigWriteProducerDocumentationDrift|KeyBindingDrift|LangParity` → **395T/48011A OK**.
  `ProtocolSchemaDrift|ServerProtocolDocumentationDrift` → **12T/340A OK**.
- vitest `--no-file-parallelism`: sidebarShare **4 passed**; sidebarActions+generated **8 passed**.
- tools/check-path-repos.php + check-child-lifetimes.php: runner DENIED by this pass's permission map
  (first pass recorded them rc0); §7 gate re-runs regardless.

RULING CONFORMANCE (keystone): ScheduleFactory::deleteRefusal (ScheduleFactory.php:110-125) implements
§6.3:4161 option (a) exactly — door-first, createdBy sentence byte-frozen (legacy sentence asserted
character-for-character in testAnOperatorWrittenRowKeepsTheLegacySentenceByteForByte), cross-root model
refusal names the bound root and the /schedules human route, same-root model null, unbound-model reads
foreign. Production call site ScheduleTool.php:235 is the single choke; test docblock states the ruling
in-file. **MATCH.**

NIT-2 (W14-attributable): scripts/parallel-tests-durations.tsv row `candy-code/tests/Cli/DoctorSandboxProbeTest.php
0.048 51` — third field holds the ASSERTION count; canonical junit-regen semantics (parallel-tests-make-shards.php:129-144,
`$n++` per testcase) make it the testcase count, which is 10 (measured OK (10 tests, 51 assertions)). The wave's OWN
precedent fixed exactly this shape: 50bc9cd2b wrote PrimaryAgentTest 45, 15ef514de corrected to 10 "row to junit truth".
All 13 other W14 rows are testcase counts. Balancer reads only cols 1-2, so shard plans are unaffected; §7 durations
regen self-heals. Cure if fixed in-wave: one-liner flipping 51→10 beside 66c0cb2b6's row (follow-up scripts commit,
author-law shape of 15ef514de).
