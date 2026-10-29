# C-SEC-7.c — round-2 review (fresh reviewer, read-only)

VERDICT: CLEAN (0C / 0M / 0 step-local MINOR / 1 out-of-scope MINOR for closeout collection)

Chain disk-verified: base 4dd8c20ce (on master) → 2bc6bb703 → 873354b78 → 4b678a480;
all cat-file commits, all ancestors of HEAD, authors %ae=%ce detain@interserver.net,
zero Co-Authored-By. FIX-1.md wake-record (REVIEW-1.md absent on disk; r1 MAJOR
carried via wake + REPORT seam 1) accepted as the r1 record.

## r1 MAJOR — cured, verified on 4b678a480
- PlanModeSection.php:58 BODY sentence flipped: truthful both directions (plan drops
  always-refused tools; keep trio survives a Deny; outside plan, offered ≠ permitted).
- PlanModeSection.php:20-31 class docblock flipped: narrowing stated, one-break-per-
  switch cache cost accepted with pointer to the PROMPT_ENGINEERING.md carve-out;
  DeepSeek antecedent historicalized (decision record, not present-tense claim).
- PlanModeSectionTest.php:55-57: single old needle replaced by THREE needles matching
  the new body; 4T/21A green. Non-vacuity by inspection: needles quote the fixed
  source's distinctive phrasing, absent from the pre-fix text.

## Step contract (design block, BRIEF) — landed
- turnTools() (EngineBackend.php:3631-3640) filters ONLY under PermissionMode::Plan,
  gate non-null; keep trio via PLAN_MODE_ADVERTISED_NAMES; verdict consulted through
  PermissionGate::refuses(ToolDeclaration) — structurally side-effect-free
  (commitAutoStrikes:false; keystroke arm inert on name-only call; typed separation
  documented at ToolDeclaration).
- Brief's test asks: plan drops Edit/ApplyPatch (table: +Task/mcp__*), keeps
  Write/Bash/Read/PlanExit — testThePlanRosterIsTheToolsTheGateDoesNotRefuseBy
  Declaration; default unchanged — testEveryOtherModeStillAdvertisesTheFullList +
  testWithoutAGateNothingIsDropped; prefix-stability-with-one-break carve-out —
  PromptPrefixByteStabilityTest::testAModeSwitchIsTheOnePrefixBreakTheRosterDropCosts.
  11T/34A green; the coherence-law test makes offer-vs-enforcement drift red
  name-for-name.
- DENY-semantics: hide-in-plan pinned (testADenyRuleHidesItsToolUnderPlan), trio beats
  Deny pinned, Deny-under-Default does NOT hide pinned, fabricated-call end-to-end
  answers "Tool not found" through real complete() dispatch, kept-Write legs prove the
  mode promise real (non-plan target refuses naming mode 'plan'; plan-file write RUNS).

## Production reachability — confirmed single-choke
runTurn (EngineBackend.php:1927 withTools(gatedLedgerTools(turnTools(...)))) is the
ONE request-tool-block builder; gatedLedgerTools only decorates Compress/ledger tools,
never restores dropped names. TUI and serve both execute turns through the same
complete()/runTurn (SessionHost routes turn backends through EngineBackend;
promptTools() :1169 -> turnTools(null,...) is the same door, also feeding
ContextSources.php:71 serve context rows). No parallel advertisement path found.

## Cache-byte premise — pinned
Within-mode turns byte-identical (system prompt + wire rows); switch rewrites message 0
only; later history rows ride byte-for-byte (array_slice legs). PROMPT_ENGINEERING.md
item 12 and PERMISSIONS.md :484-503 prose match the tested behavior.

## Gates (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- 'PlanMode|Permission|EngineBackend|PromptPrefixByteStability|PermissionsIntro|
  ForeignAgentPresetWiring|SymbolCitation|DocFigure': 2724T / 2F = the two EXEMPT
  standing reds only (PermissionsIntroDrift 'Schedule'; ForeignAgentPresetWiring
  reader census). Zero new reds.
- 'GlobDialect|DuplicatedTestHelperDrift|DuplicatedDocBlockLine|ChildWallClock|
  SwallowingCatch|ReadmeSuiteFigureDrift': 59T / 3F = staleness pair + SkillTool
  SwallowingCatch row (all three EXEMPT standing).
- PlanModeToolRosterTest|PlanModeSectionTest|EngineBackendPermissionGateTest: 35T green.
- "PHPUnit Deprecations: 2" is process-constant in this worktree (unmoved UsageTest
  shows the same) — environment, not lane.
- Mutation probing DENIED by reviewer permission map (shell/edit on src refused);
  FIX-1.md records the builder-side neuter mutation 11T/4F + md5-clean restore, and
  the pin structure is self-evidently discriminating (derived expectations + table).

## Scope
Out-of-brief edits are exactly the r1-MAJOR cure files (PlanModeSection.php,
PlanModeSectionTest.php) plus the disclosed, surgical forced flip in
EngineBackendPermissionGateTest (one needle + docblock, region-local). EngineBackend
edit confined to declared turnTools region + its const/import block. No weld-only
files, no generated files, no lang/README touched.

## Open item re-filed
- MINOR (out-of-scope, closeout collection): candy-code/COMPARISON.md carries three
  stale claims now contradicted by this step — :383 TL-27 cells ("The tool list is
  the same in every mode" / "the tool list is unchanged"), :938 limitations list
  ("the tool list is the same in every mode"), and :3545 evidence row citing
  `docs/PERMISSIONS.md (plan: "tool list is the same in every mode")` — that quoted
  sentence no longer exists in the cited file (dangling quote). COMPARISON.md is not
  in the brief's file set and every campaign COMPARISON edit has landed via dedicated
  refresh lanes; collect into the wave/comparison doc-truth pass with the C-SEC-7
  flip wording ("plan drops gate-refused declarations; Write/Bash/PlanExit kept").

## Carries (unchanged, correctly not lane-committed)
- durations.tsv row for candy-code/tests/Backend/PlanModeToolRosterTest.php (weld).
- suite-figure.json/README staleness pair (closeout re-pin).

NIT dispositions from r1 ("2 NITs cosmetic wording", REVIEW-1.md absent): the adjacent
truth-cluster (docblock path spellings, REPORT seam attribution) is applied and green
per FIX-1.md's re-verified reads; my sweep found no other stale 'same in every mode'
wording in README.md/docs/*.md (grep clean). The COMPARISON rows above are the only
residue and are out of lane ownership.
