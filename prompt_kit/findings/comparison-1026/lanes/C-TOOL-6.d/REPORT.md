# C-TOOL-6.d — `Script` catalog tool: nested calls through the gate and hook chain

LANE: /home/sites/cc-cmp-C-TOOL-6.d · branch cc/C-TOOL-6.d · base for weld: c53098787 (lane originally cut acc851ca3, ff-merged pre-pick while zero picks).
PICKS (all cat-file'd same-shell, %ae=1 %ce=1 detain@interserver.net, 0 Co-Authored trailers, residue-grep 0 each):
- d5bd4823c slice 1 — registration seam + guard cascade (12 files, +644/−31)
- 4b9873e86 slice 2 — ScriptToolTest + SuiteSkipRoster surgery (5 files, +569/−17)
- 6d12b9b81 slice 3 — NestedToolDispatchTest + NestedStandIns (2 files, +650/−0)

## What landed
- src/Tools/DispatchesNestedTools.php — interface `withNestedDispatch(Closure $call, Closure $catalog): static` beside DelegatesToEngine; docblock states the fd-3 same-gate law (no second validator).
- src/Tools/BuiltIn/ScriptTool.php — #[BuiltInTool('Script', Write, position 46)], implements Tool+BuildsFromCatalog+AcceptsHeartbeat+AcceptsWorktreeJail+DispatchesNestedTools. §6.3 floor enforced: builds its OWN `SandboxFactory::fromSetting('no-network')` jail independent of bashSandbox; null jail or failing probe REFUSES (message quotes backend describe()); never runs an unconfined child. code ≤64 KiB, timeout clamped [1,600] default 60 via public static clampTimeoutSeconds seam. Reuses the C-TOOL-6.c ScriptRunner/ScriptBridge/ScriptPrelude/ScriptRun blobs ZERO-DIFF (never edited). Result body: exit/return/stdout/stderr/notes/caps + `--- calls` ledger rows `Name hint -> ok|error|denied (token)` (64-row window, earlier-calls tail note).
- src/Runtime.php — WRITE_CAPABLE_TOOL_NAMES += 'Script'; bind arm in executeSequentially after findTool (the dispatcher clone carries the per-execution binding); NEW private executeNested (findTool → LEAF-REFUSAL arm for DispatchesNestedTools|DelegatesToEngine|MutatesContextLedger|mcp_call|mcp__* BEFORE the gate → malformedArguments → hookContext → gate() with the SAME $onPermissionRequest → executeGuarded → settle($onEvent=null)); inner call ids `<outer>~N`; nestedCatalogRows/nestedSignature publish only leaf tools with budgeted one-line signatures.

## Deviations (disclosed)
1. position 46, not the design's 44 — 44 Advisor (C-PRV-3.d) and 45 Canvas are taken; ToolCatalog::assertUnique would LogicException. Next-free slot, gloss otherwise verbatim.
2. Binding happens in executeSequentially (the design's text), NOT EngineBackend::turnTools — turnTools builds its own tool copies; executeSequentially is the single barrier path Script always reaches (not ParallelSafe).
3. The design's child-side `call(...)` never existed: ScriptPrelude (welded 6.c) ships `tool(string,array):array` + `tools()`; disk wins, all prose/tests spell tool().
4. Ledger denial verdict spells DenialKind->token() ('denied (refused|unanswered|hook)'), not the prefix value — value is the message prefix and would duplicate it.
5. REFUSED SEAM for lead adjudication: Bootstrap::RESTRICTED_TOOL_NAMES does NOT list 'Script' (--restricted leaves the code-running tool wired). Bootstrap.php is not in this step's files.modify and the C-TOOL-4.d welder lived there; pin RestrictedSafeModeFlagsTest would need a flip in-step. Needs a follow-up step or an explicit ruling to keep.
6. SuiteSkipRoster + SuiteSkipRosterTest gained the SCRIPT_JAIL_TOOL roster rows for the host-conditional jailed e2e skip (tests-side guard in-step; 6.c's scriptJailRuns() predicate reused verbatim, lift unsets both entries together).
7. PERMISSIONS.md was edited (design mandate) in the region the 4.d weld also touches — hand edits are the pattern roster `Script` token + the Script ask/UX paragraph; re-derivation guards green AFTER the c53098787 ff-merge, so the diff the welder replays is against post-4.d master.

## §1.8 cascade record (same commit as the registration)
- Word numbers derived LIVE from this tree: BuiltIn directory census forty-one→forty-two (DocFigure map +42 entry), shipped launch roster thirty-five→Thirty-six (ReadmeRosterDrift $spelled + README prose two sites), project-tier filterable 35→36 (ReadmeSettingsTierClaimTest SPELLED_COUNTS; README sentences already consistent post-generator).
- gen-tool-docs.php --write regenerated README + ARCHITECTURE + AGENTS_AUTHORING + PERMISSIONS + SETTINGS regions; --check green. ToolDocDriftTest + PermissionsIntroDriftTest + ToolCatalogTest (tail pin flipped to [SkillWorkshop,Canvas,Script], 6.f-era stale) + testTheWriteToolRosterDoesNotDriftFromThePermissionGate green. No settings key, no env var → LayeredSettings/EnvRoster untouched. No protocol-trio drift (generator pipeline does not touch docs/protocol/*). Lang: zero new user-facing strings (model-facing prose, Canvas/6.f precedent) → en.php untouched, CliLangCatalogue unaffected.
- check-one-type-per-file: 62 libs / 3353 psr-4 files / 0 problems. GlobDialect: new literals are non-glob-shaped; corpus figure unchanged by this lane (see foreign red below).

## Gate figures (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; 'PHPUnit Deprecations: 2' = base noise)
- ScriptToolTest|NestedToolDispatchTest: TESTS 32, ASSERTIONS 139, 1 skipped (rostered jail leg — scriptJailRuns() false on this host).
- Guard family (ToolDocDrift|PermissionsIntro|ToolCatalog|write-roster|DocFigure|SuiteSkipRoster|DuplicatedTestHelperDrift|TreeWideGuardRoster): TESTS 172, ASSERTIONS 8746, green.
- Earlier slices re-ran: BuiltInToolCorpus|BinCandyCodeWiring|ToolSchemaEncoding 1427T/8300A green; gen-tool-docs --check 'up to date'.

## Mutation proofs (exec'd, restores md5-verified)
- M1 bind arm neutered → 8F/4W (relay collapses) · M2 dispatch leaf-arm neutered → exactly 1F (structural test) · M3 approver pass-through nulled → 3F (ask/gant/refused) · M4 catalog filter neutered → exactly 1F (catalog test). All pins load-bearing.
- Gate-truth probe (not belief): rule-deny and plan/dont-ask refusals surface DenialKind::Hook with the `Hook denied: Permission mode` prefix; `Permission denied:`+Refused occurs ONLY on an approver answering the ask. Pinned as measured.

## Foreign reds (NOT chased, for welder/§7)
- GlobDialectDifferentialTest corpus-figure arm ALREADY RED at base c53098787 (derived 560×556=311,360 vs PathGlob docblock 310,245=559×555) — proven in a fresh base worktree with cp -a vendor; 4.d/C-WEB-5.c-era drift.
- ReadmeSuiteFigureDrift staleness pair (25,537 pinned vs 29,558 live era) — suite-figure.json is a forbidden lane file.
- HelpTest CANDY_CODE_SERVICE_TIER + BinCandyCodeDispatchTest --worktree standing reds per run-brief roster.

## §7 — NEW test files (live counts; durations rows are the gate's duty, never minted here)
- candy-code/tests/Tools/BuiltIn/ScriptToolTest.php — 22T/75A (+1 rostered skip).
- candy-code/tests/Runtime/NestedToolDispatchTest.php — 10T/64A.
- (support, not a test class: candy-code/tests/Runtime/NestedStandIns.php.)

Lane state at report time: tree clean, three picks, master known at 828aab3eb (moved under lane; never rebased).
