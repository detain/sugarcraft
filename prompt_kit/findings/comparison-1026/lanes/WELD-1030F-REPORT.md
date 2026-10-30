# WELD-1030F — C-PRV-3.d (Advisor tool, W24)

## PLANNED (written before any mutation)

- master pre-weld: 7646af980 (verified via git rev-parse --short master)
- lane: cc/C-PRV-3.d, expected tip 4a2739152 over base 07e49e872
- steps: cherry master..lane check -> pick -> regen generated docs on conflict -> weld-cure docblock flips -> durations.tsv row mint (AdvisorToolTest, 3-run median) -> targeted gates -> §4 mark + evidence + run-state fold -> prune after cherry '-' proof
- NOT re-pinning suite-figure.json; NOT touching candy-code-web/dist; NO push.

## LOG (append after every step, cat-file'd SHAs only)

## Step 0 preflight
- lane tip cat-file: 4a2739152ffc7fe2ae7df6f27efb9d396b63639f (author detain@interserver.net, subject "C-PRV-3.d: Advisor tool")
- base 07e49e872 confirmed ancestor of master; git cherry shows + (not absorbed)
- name-only: 17 files, all candy-code/**. grep for candy-code-web/src|docs/protocol = 0 hits -> standard procedure holds.
- cherry-pick 4a2739152 landed clean, zero conflicts: 8defce213b5173a7c8f5ad195346b35d50d6e052 (cat-file ok, author detain, 0x0p+0e grep below)
- generators --check: tool-docs/settings-doc/command-docs all "up to date" rc=0 on merged tree (no regen needed, no conflict)
- step2 weld-cures applied: ToolBuildContext.php:102 Host->Session FQN; AdvisorTool.php:92 @var unpriced:int->bool (store emits bool at EnhancedSessionStore.php:2600/:2621; code at :232 untouched)
- step3 durations row minted: AdvisorToolTest 1.508/7 at line 233 of 1636 (median of 3 junit runs 1.4935/1.5081/1.5091; all 3 OK 7 tests 44 assertions == +7T/+44A carry)
- gates on merged tree: AdvisorTool filter 11T/62A OK; wide window Advisor|ToolCatalog|BuiltInTool|LayeredSettings|SettingsSchemaDocDrift|DocFigureProseDrift|ReadmeRosterDrift|ToolDocDrift (PtyRun arm excluded) = 537T/6196A OK after HelpTest cure (before cure: 1F = ADVISOR_MODEL missing screen entry, REPRODUCED RED AT LANE TIP 4a2739152 in the lane worktree; SERVICE_TIER HelpTest arm is the standing red, still red, not chased). 2 PHPUnit deprecations present pre- and post-cure (baseline).
- generators: tool/settings/command docs --check rc=0 (three times: pre-cure, post-cure).
- weld-cure commit: de4ae441ccf66dfff08233de95560f92f33b7f95 (2 docblock NITs + en.php help entry).
- durations commit: cb2601f5dded3b4213761cb97e08286e35c96358 (row line 233/1636).
- fresh-blob proofs at HEAD=cb2601f5d: ARCHITECTURE.md blob :31 "40 built-ins + MCP bridges" parsed by DocFigureProseDriftTest blob :697-699 vs git ls-tree BuiltIn/ count == 40; DocFigure blob :812 word map carries forty=>40; LayeredSettingsTest blob :68 roster row contains advisorModel; en.php blob :620 CANDY_CODE_ADVISOR_MODEL entry line; AdvisorTool.php blob 6ddeab839.
- §4 mark written at plan_comparison.md :2884 (plan file untracked by program law — never staged).
- run-state folded from freshest live copy: weld_pending [C-PRV-3.d,C-SES-7.d,C-TUI-2.f] -> [C-SES-7.d,C-TUI-2.f]; welded 247->248 (assert count==len held); master_tip=cb2601f5d (durations commit — second-to-last; the evidence+run-state commit cannot carry its own SHA); weld_notes[batch-1030f] added. §7 carry +7T/+44A recorded there.
- gates final (post-cure): AdvisorTool 11T/62A; wide window 537T/6196A OK excl standing HelpTest SERVICE_TIER provider row; generators x3 rc=0; PtyRun expectMiss arm excluded throughout per house instruction.
- NO push (W24 needs SES-7.d; W25 pending; waves_fully_welded 1-23).
