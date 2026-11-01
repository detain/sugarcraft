# C-TOOL-6.b — NotebookEdit tool + Read cell pages

STATUS: IN PROGRESS (model landed on disk, uncommitted; tool + Read branch + tests next)
Lane: /home/sites/cc-cmp-C-TOOL-6.b, branch cc/C-TOOL-6.b, base master c0ddfd464 (tip==base re-verified @ this write). Self-dated 2026-10-31.

## Design block
Read verbatim from prompt_kit/findings/comparison-1026/design-tool.md:754 in-lane. No design-vs-disk contradiction hit; one position deviation below.

## POSITION RULING (ACCEPT-AND-DISCLOSE)
Design declares position 42; on the LIVE registry 42–47 are taken (42=SkillsSearch, 43=SkillWorkshop, 44=AdvisorTool, 45=CanvasTool, 46=ScriptTool, 47=ShellSend — measured via #[BuiltInTool] scan of src/Tools/BuiltIn + tools/, occupancy 1-22 and 30-47). NotebookEdit mints position **48** per the attribute docblock's own "next free number rather than an earlier one" law — same class of deviation as C-TOOL-6.k's position-47. No test pins literal position numbers (ToolCatalog/corpus guards derive).

## Model (src/Notebook/Notebook.php) + pages (src/Notebook/NotebookPages.php)
- nbformat v4 gate: parse() returns null for non-v4/non-JSON/non-list cells/malformed cells ⇒ nothing written (tool) / text paging (Read).
- json_decode(assoc=false) so {} vs [] survive; stdClass keeps file key order; float 1.0 pinned via JSON_PRESERVE_ZERO_FRACTION (fixture carries "figure_width": 1.0).
- serialize(): detected indent (regex on first line after `{`, fallback 1), JSON_UNESCAPED_SLASHES|UNESCAPED_UNICODE|UNESCAPED_LINE_TERMINATORS|PRESERVE_ZERO_FRACTION, 4-space PRETTY_PRINT rebased to detected indent, \uXXXX hex lower-cased to Python spelling (walks doubled backslashes first), trailing "\n". PROBE-MEASURED: byte-identical round trip of tests/fixtures/notebooks/v45-outputs.ipynb and v44-idless.ipynb (incl. raw U+2028 leg — design line 798 char verified as E2 80 A8 via od).
- Addressing: cell id when present, else cell-<index>; duplicate ids address first occurrence; recomputed per derived instance.
- Edit semantics recorded in the class docblock exactly as the design states (replace keeps id+metadata, code cell outputs=[] + execution_count=null; insert after cell_id or first, requires cell_type, mints 8-hex id on minor>=5 only; delete; type flip rebuilds cell in canonical sorted key order, same-type replace mutates in place keeping key order; source normalized to line-list on write).
- nbformat NOT installed on host (ModuleNotFoundError measured) — fixtures written via stdlib json dumps(indent=1, sort_keys=True, ensure_ascii=False)+"\n" mimicking nbformat.v4's writer; generator + this fallback live at /tmp/opencode/cc-cmp/C-TOOL-6.b/make-fixtures.py. DISCLOSED.

## Fixtures (tests/fixtures/notebooks/)
v45-outputs.ipynb (4.5, ids; markdown w/ metadata.attachments + U+2028 + café; code w/ stdout stream×10 + display_data image/png, meta tags:[]/collapsed:false/figure_width:1.0, exec 7; error cell ZeroDivisionError exec 8; plain tail; top-level widgets:{}), v44-idless.ipynb (4.4, string-source markdown + code exec 1), v3-legacy.ipynb (nbformat 3 worksheets — refused).

## Roster joins (parities with Edit — table at commit time)
PermissionRule SUBJECT_ARGUMENTS+file_path / PATH_SUBJECT_TOOLS; PermissionGate EDIT_TOOLS + AUTO_REVIEW_WRITE_TOOLS; SafetyClassifier match arm; ProtectFilesHook inputsFor 'Notebookedit'; FilesTouched MODIFY_TOOLS; TurnContextBlock FILE_WRITING_TOOLS; PruningPolicy PROTECTED_TOOLS; StaleReadStrategy WRITERS; Runtime WRITE_CAPABLE_TOOL_NAMES; ToolSummary Live; AcpUpdateMapper KINDS edit; ToolLimits requireReadBeforeWrite instanceof chain.
DECLINED w/ reasons (reported seams, not edits): ReadLedger::in() (design does not list it; Read/Write always shipped so ledger lookup unaffected); AskDiffPreview/Renderer preview maps (no cell-diff machinery in design; null ⇒ documented arg-view degradation); PATCH_PROXY_TOOLS (name-only Deny NotebookEdit over-binding every ApplyPatch call would be wrong; path denials already bind patches via Edit/Write arms); AgentDefinition defaultTools + ForeignAgentPresetRegistry (design-silent; foreign names absent from the alias map pass through untouched already); ParallelSafe (never — mutates workspace, mirrors Edit/Write docblock law).

## Docs cascade (measured)
tools/gen-tool-docs.php --write owns: README tool roster markers + spelled count anchors ("thirty-seven of them ship" ×2 sites, README:1580 'out of the thirty-seven built-in tools'), SETTINGS.md:940/:984/:1043, disabledTools samples. Hand-kept edits: PERMISSIONS.md pattern-roster para (~:760), :607 subject-argument sentence, :204 auto row. Guard maps: ReadmeRosterDriftTest $spelled += 38=>'Thirty-eight'; ReadmeSettingsTierClaimTest SPELLED_COUNTS += 'thirty-eight'. DocFigure arms count subcommands/memory-notes, NOT tool counts (measured :741/:812 — no join). ReadPathCensus: one new row Tools/BuiltIn/NotebookEdit.php|file_get_contents (bounded head read, PATH_JAIL); Notebook model + Pages hold ZERO sinks by design (bytes handed in). TreeWideGuardRoster: tests avoid dir-walks (explicit unlink + rmdir) ⇒ no rows.
en.php: design block carries NO lang keys ⇒ zero lang move ⇒ mirrors untouched (no regen needed; verified no strings.en.generated.ts / web-strings drift expected).

## Tests planned
tests/Notebook/NotebookTest.php; tests/Tools/BuiltIn/NotebookEditTest.php; tests/Tools/BuiltIn/ReadNotebookTest.php; tests/Permissions/NotebookEditPermissionTest.php; SafetyClassifierTest roster arm ride; guard rides as above. §7 new-test list + LIVE counts at close.

## FINAL — 2026-10-31 (self-dated)
STATUS: COMPLETE. Branch cc/C-TOOL-6.b, 5 picks off base c0ddfd464:
9997e8374 (model+pages+tool+fixtures), 0313a9acd (Read cell pages+all roster joins),
0cff3acf3 (own suites + in-step guard rows + src jail-adopt fix), 193d4cc89 (permission
test + gen-tool-docs regen + PERMISSIONS.md hand-edits), 76f32a46b (guard cascade:
ToolCatalogTest trailing window, DocFigure forty-four, DuplicatedTestHelperDrift
harmonizations). porcelain 0. All %ae/%ce detain@interserver.net (separate greps 1/1
each commit), zero Co-Authored trailers, residue greps clean each commit.

POSITION RULING: design 42 TAKEN live (42 SkillsSearch, 43 SkillWorkshop, 44 AdvisorTool,
45 CanvasTool, 46 ScriptTool, 47 ShellSend) ⇒ NotebookEdit=48, ACCEPT-AND-DISCLOSE per
6.k precedent, recorded in slice-1 commit body + attribute docblock.

§7 NEW TEST FILES (live counts, battery mints durations rows — lane never touched
suite-figure.json/durations.tsv): tests/Notebook/NotebookTest.php 12T/71A;
tests/Tools/BuiltIn/NotebookEditTest.php 19T/269A; tests/Tools/BuiltIn/ReadNotebookTest.php
7T/95A; tests/Permissions/NotebookEditPermissionTest.php 7T/20A. Own suites 45T/455A OK.

GATES (quoted, non-zero): own 4 suites 45T/455A OK; guard family (Notebook|BuiltInToolCorpus|
ToolCatalog|RuntimeTest|PermissionRulePattern|PermissionGate|SafetyClassifier|ReadPathCensus)
1047T/4263A OK; (DocFigureProseDriftTest|DuplicatedDocBlockLineTest) 96T/4299A OK; doc guards
(ToolDocDrift|PermissionsIntroDrift|ReadmeRosterDrift|ReadmeSettingsTierClaim) 26T/159A OK;
BARE TreeWideGuardRosterTest 17T/3999A OK; BARE DuplicatedTestHelperDriftTest 14T/204A OK;
SafetyClassifierTest 49T/51A OK; BashSandbox|BubblewrapHardening co-run 39T GREEN;
final combined (NotebookTest|NotebookEditTest|ToolCatalogTest|DuplicatedTestHelperDriftTest)
62T/797A OK. check-one-type-per-file: 62 libs 3388 files 0 problems. php -l clean all touched.

MUTATIONS 6/6 EXEC-discriminating (cp+md5 restored, src porcelain 0 after): M1 strip
UNESCAPED_LINE_TERMINATORS ⇒ byte-identity pin 1F; M2 neuter staleness ⇒ verbatim-wording
pin 1F; M3 blank parse-null refusal ⇒ nothing-written pin 2E (loud, disclosed);
M4 drop root $path=$resolved ⇒ 6F class-level (disclosed: not the jail test alone);
M5 Read offset→1 ⇒ cell-count pin 1F; M6 drop PATH_SUBJECT_TOOLS join ⇒ path-rule pin 1F.
UNCONFIRMED-BY-RUN: serialize() InvalidArgumentException floor guard (no reachable fixture);
ReadLedger capped-null-hash interplay on >cap notebooks (over-cap never parses — by design).

FOREIGN REDS adjudicated cure-NONE (base re-runs in this lane's shell): GlobDialectDifferential
corpus figure byte-identical red AT c0ddfd464; BootstrapLaunchFormatConstants doc-leg identical
diff at base; StderrEmitterCensus 2F = PtyRelay 6.j debt + autoload-guard leg (files not mine);
ReadmeSuiteFigureDrift staleness pair untouched per law.

DEVIATIONS/SEAMS: position 48 (above); cell headers carry full 8-hex ids (design ellipsis
'4f1c…' — full id is the addressing key, ellipsis would mislead); NotebookEdit returns no diff
in ToolResult (design silent; Edit's diff rides old/new string pairs — a cell rewrite's whole-file
unified diff is noise, summary line carries +N -M); markdown attachments STAY on type-flip (block
mandates outputs/execution_count add/drop only); ReadLedger::in() roster DECLINED (design silent;
four members always ship); PATCH_PROXY_TOOLS DECLINED (would over-bind name-only Edit/Write denies
to NotebookEdit); notebook answers return before instruction-prepend (binaryAnswer precedent);
new_source required for replace/insert enforced in execute, schema required=[file_path,edit_mode].
Docs: README/ARCHITECTURE/AGENTS_AUTHORING/SETTINGS regenerated via gen-tool-docs --write;
PERMISSIONS.md hand-edits: pattern roster +NotebookEdit, auto row 'Edit/Write/NotebookEdit by
target', accept-edits bullet. en.php untouched (zero lang keys — Edit-literal family). No web
touches; no protocol mirrors moved.
