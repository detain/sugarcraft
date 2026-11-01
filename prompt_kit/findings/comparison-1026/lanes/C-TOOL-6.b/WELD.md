# C-TOOL-6.b — WELD record

Welder: cc-cmp-welder, background. Master pre-weld **31000e3be** (6.m run-state fold tip). Lane base c0ddfd464 predates the 6.m weld — MOVED master, handled BY TEXT.

## Evidence read IN FULL + restated bytes
- REPORT.md **9,566 B** — builder record; its stale top `STATUS: IN PROGRESS` vs later COMPLETE block ruled MINOR-1 record-only by r1 + lead (6.l/6.m precedent); its "summary line carries +N -M" claim ruled MINOR-2 record-only false-to-code (picks carry none; code semantics correct per r1 Q3). Both corrections cited in the §4 mark; neither claim repeated as fact.
- REVIEW-1.md **8,298 B** — `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR / 2 NIT — WELD-ELIGIBLE: YES`. NITs record-only (NIT-1 class docblock omits 48 — commit body/test/REPORT carry it; NIT-2 render() ""-vs-null at offset==total+1 — caller-guarded).
- FIX-1.md never existed (0 MAJOR/CRITICAL). Not invented.

## Picks
Five explicit shas read FROM the lane via `git log --format='%h %s' c0ddfd464..HEAD` (never a range, no -x per t646 — landed shas live in the mark):
`9997e8374 → 7a5d5b5e7` · `0313a9acd → e9bb3620e` · `0cff3acf3 → fb94c9033` · `193d4cc89 → 1f5f46541` · `76f32a46b → e8dad69cd`
ZERO conflicts — master-side overlap was ONLY candy-code/README.md (6.m Alt+V keybinding row, disjoint hunk region). Span 34 files +2187/−45 (matches lead t1081). Lane law re-derived by welder: %ae 5/5 + %ce 5/5 SEPARATE count greps detain@interserver.net, Co-Authored 0, porcelain 0. Landed set: %ae/%ce separate counts 5/5, Co-Authored 0, residue grep (dead-branch literals: never-take if / && never-bool / || always-bool) 0 across 31000e3be..HEAD. Reflog: five cherry-picks atop 31000e3be, ZERO foreign movement.

## Merge duties (all BY TEXT on merged tip)
1. **en.php** — lane adds ZERO keys, held: name-diff 0 touches in picks; python dupscan over key column: 3,419 keys, **0 dups**; regression `(CliLangCatalogueTest::|LangParityTest::)` **15T/4263A** NON-ZERO green.
2. **DocFigure forty-four** — re-derived LIVE on merged blob: `ls src/Tools/BuiltIn/*.php | wc -l` = **44** (master 43 pre-pick + NotebookEdit.php; the 3 master commits 31000e3be−c0ddfd464 add zero BuiltIn files); map row rides pick 5. (DocFigureProseDriftTest::|DuplicatedDocBlockLineTest) **96T/4299A** green.
3. **PermissionGate ordering** — user__ → client__ → verbatim `mcp__`-literal-last source-slice PROVEN at merged tip :1863-1883 (write-class slice); picks joined only EDIT_TOOLS/AUTO_REVIEW arms; union scrambled nothing.
4. **Bash.php** — lane touches NONE (name-diff 0); `"bash -c "` double-quote blob-count **1** at merged tip (line drifted from :535-era by the 6.m span; content alive — PermissionGateScopedWriteTest source-scan pin class).
5. **KeyBindingRegistry / Renderer / HandlesKeys** — lane touches NONE (name-diff grep 0 — zero-web zero-TUI proven).
6. **TreeWideGuardRosterTest BARE** 17T/**4001A** GREEN (master rows all survive BY TEXT; +2A vs r1-era 3,999 = master-side era drift, disclosed) · **DuplicatedTestHelperDriftTest BARE** 14T/204A GREEN.
7. **UNOWED trio/dist/mirrors/SERVER.md** — candy-code.v1.schema.json, docs/SERVER.md, src-web/protocol/generated.ts, candy-code-web/dist, web-strings.en.json, strings.en.generated.ts, suite-figure.json, durations.tsv: grep over ALL per-pick `git show --name-only` = **0 hits**; likewise absent from commit-1 path-list (printed below).

## Gates (welder's OWN runs; cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, anchored full Test stems, NON-ZERO sanity each; the 2 PHPUnit deprecations on every run = accepted foreign AccessorDeprecationTest provider roster per r1 Q6 — not a skip)
- Own four r1-exact: `NotebookTest::` **12T/71A** (FQ-class re-run; bare-substring `NotebookTest::` also surfaces ReadNotebookTest = 19T/166A combined — disclosed, FQ run is the mint) · `NotebookEditTest::` **19T/269A** · `ReadNotebookTest::` **7T/95A** · `NotebookEditPermissionTest::` **7T/20A**. Combined **45T/455A**, ZERO skips (a skip where r1 saw 0S would have been a STOP-finding; none).
- Guard family (Notebook|BuiltInToolCorpus|ToolCatalog|RuntimeTest|PermissionRulePattern|PermissionGate|SafetyClassifier|ReadPathCensus) **1048T/4265A** GREEN (r1-era exact).
- Doc guards (ToolDocDrift|PermissionsIntroDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim) **26T/159A** GREEN.
- SafetyClassifierTest:: **49T/51A** GREEN.
- PAIRING (BashSandboxTest|BubblewrapHardeningTest) ONE process **39T/192A GREEN** (cure db1441a61 in force; never cured at weld).
- BootstrapLayeredSettingsTest:: **99T/423A Failures: 0** (cure 00fedf519 in force, folded NOTHING).
- EXCLUDED from every filter (base-proven foreign): PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues · RegistryTest::testAStdoutThatShrinksAlone... · ForkedChildReaperAdoptionTest Sessions/BackgroundShellRegistryTest item.
- ACCEPTED-FOREIGN carried unchanged-shape, cure NONE: ReadmeSuiteFigureDrift staleness pair (pinned 25,537/609,443 vs live ~29.7k-class — battery re-pin duty; this weld's +45T-class carry DISCLOSED; the two files NEVER touched) · SelfUpdateTest rosterCarriesThirteenVerbs 1F (fifth debt row) · keepWarm 1F · KeyHelp 5F+risky · HelpTest[CANDY_CODE_SERVICE_TIER] · BinCandyCodeDispatchTest --worktree :1893 · AttachPagingTest 2F · OneSidedHomeSandboxTest :435 · MenuBarLabelDerivationTest doctor/recap 1F · ForeignAgentPresetWiringTest PermissionMode · LaunchContextDelegatesTest tests/Cli:34 (df106a84c) · StderrEmitterCensus-era 2F PtyRelay (75174ea40) · GlobDialect 1F (asserted NEITHER number) · SettingsEditor :189 · ChildStderrCapture 2F · AccessorDeprecation :168/:202 · BootstrapLaunchFormat doc-leg · Cli/HelpTest env-var leg.

## Substance clauses (from r1, carried into the mark)
Position **48** ACCEPT-AND-DISCLOSE (design 42; 42–47 taken live: SkillsSearch/SkillWorkshop/Advisor/Canvas/Script/ShellSend; reflection pin NotebookEditTest:55-69; commit body carries). Permission parity **13/13** Edit-naming rosters + 14th-sweep none missed + converse clean + declined seams judged sound (ReadLedger shared-context ledger via fromCatalog; PATCH_PROXY_TOOLS over-binding risk). Write chain NUL→jail→spill→shape→require-read→cap→RAW-staleness→parse-refuse-nothing-written→AtomicFileWriter→record, refusal literals byte-identical to Edit. Read cell-pages offset/limit=CELLS, RAW-byte staleness, fallback, cross-leg PAIR; full-8-hex id deviation disclosed. Builder mutations M1–M6 6/6 EXEC; r1 honest map-denial UNCONFIRMED-BY-RUN credited. Zero-lang/zero-mirrors/zero-trio/zero-web.

## §7 hand-offs (LISTED, NEVER FIXED here)
durations.tsv rows at welder live counts — NotebookTest.php 12/71 · NotebookEditTest.php 19/269 · ReadNotebookTest.php 7/95 · NotebookEditPermissionTest.php 7/20 (battery mints) · +45T-class suite carry disclose · RESTRICTED_TOOL_NAMES += 'Script' LEAD rider pending · web.yml gen:strings LEAD rider pending · sweeps /tmp/opencode/rev-6j-r2-mut + /tmp/opencode/rev6k/ + /tmp/opencode/6l-names.txt · lead-index re-stage /tmp/opencode/cc-cmp-weld-C-TUI-6.d/lead-index-before-reset.txt (this weld: index was CLEAN — no reset needed, file untouched).

## WARN NEXT WELDER
C-TOOL-6.g (W35-completer, in review) OWNS trio regen: schema +2/−1 MEDIA_REF html + generated.ts +1/−1 :227 + SERVER.md TWO MANUAL canvas rows verbatim (regen-wont-derive) + dist rebuild + WELD-MUST-GREEN co-run — must preserve THIS weld's merged state (incl 6.d ## Windows slice c0c0501d5dc0 + 6.i para 761aa2a0) through ITS regen.

## Absorption
`git cherry master cc/C-TOOL-6.b` = FIVE `-`, ZERO `+` (no conflict resolutions — no content-proof needed). Staged-index untouched (0 entries at preflight — no reset performed, no snapshot needed). Lead's `.opencode/agents/cc-cmp-lead.md` M-dirt + AGENT_LAW_SNIPPET.md + LEAD_LAWS.md left EXACTLY as found; one harness-redacted untracked stray left untouched (never in any explicit-path commit). Prune executed after the proof: worktree ../cc-cmp-C-TOOL-6.b + branch cc/C-TOOL-6.b removed (post-commit appendage = disclosed lanes-dirt). Survivors re-read: `cc/C-TOOL-6.g` + `cc/C-TUI-6.n` expected-exact.
