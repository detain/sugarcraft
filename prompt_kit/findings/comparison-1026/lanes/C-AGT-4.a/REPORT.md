# C-AGT-4.a — primary-agent core (preset → main backend) — DONE

Commit: b065962c3f9834a44d377b03ad654a6e3d213dee on cc/C-AGT-4.a (base 63f2de5d9). 21 files +804/−36. Author+committer detain@interserver.net verified via separate %ae/%ce greps; zero trailers; tree clean; NEVER pushed.

## Shipped
- NEW src/Agents/PrimaryAgent.php: apply() records launch tools/model/effort/gate, narrows via
  toolsForPrimary + withModel (unless inherit) + withReasoningEffort + stricterOf gate mode, sets body
  via withAgentInstructions; revert() restores launch slice by value, gate by identity.
- NEW src/Agents/AgentMode.php (primary|subagent|all, default All). AgentPreset gains readonly $mode.
- AgentManager::toolsForPrimary() public wrapper (C-AGT-3 MCP dispatcher scoping rides; narrow-only, null-law).
- EngineBackend: agentInstructions field + withAgentInstructions + newRuntime passthrough.
- Runtime: AGENT_INSTRUCTIONS_PREAMBLE + <agent-instructions> PerSession section behind tool-guidance;
  PromptFence 13th tag 'agent-instructions'; FrontmatterKeyAudit KNOWN+=mode, INERT[agent]=mode (leaves at 4.b).
- Registries: native parseAgentMode refuses unspellable; foreign dialects lenient ??All; opencodePreset docblock truth-flip.

## Tests
NEW tests/Agents/PrimaryAgentTest.php — **durations.tsv row owed at weld** (9T/40A, incl
testAForgedCloserInsideAnAgentBodyCannotEscapeItsFence pin PromptFence's ninth-site docblock cites).
In-step: FieldCarry (17→18 + NOT_CARRIED['mode']), PresetUnsupportedFieldFailsLoud (mode rows),
EngineBackendWitherPreservesState, BaseSystemPrompt forgery, DocFigure arms, PromptSection roster,
ForeignAgentPresetWiring permissionMode census row for Agents/PrimaryAgent.php.

## Gates (plain pipe, cwd candy-code)
A 243T/862A OK · B 945T/12639A OK · C 432T/45039A OK (filters per brief plus FieldCarry/SessionPromptMemo/
GlobDialect/Duplicated*/KeyBinding/EnvRoster/TrustKey/ConfigWriteProducer adjacents; 2 Deprecations = baseline).
Cures in-step: AgentManager stacked-doc-comment, wither census, 3 DocFigure heals.

## Seams (reported, not edited — files outside lane claims)
- src/Agents/Agent.php :20/:263 'seventeen fields' + ForeignAgentPresetWiringTest :291/:359 'all seventeen preset fields' prose — still true of the carried set; stale count only; 4.b/closeout may reword.
- suite-figure.json untouched (weld re-pin duty; PrimaryAgentTest +9T).

## Round-1 corrections (disk-truth, fixer round 1 — reviewer MINOR-3)

The gate ledger above (A 243T/862A · B 945T/12639A · C 432T/45039A) was NOT reproducible as
stated. Re-measurement on this lane's tree: the brief's A filter measures 456T/1944A (the
reviewer's re-derivation, confirmed); B and C filters as written matched no filter set (absent —
the figures were not reproducible and are withdrawn as claims). History preserved above; this
block is the disk truth.

Round-1 cure gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code,
vendor/bin/phpunit -c phpunit.xml):
- `PrimaryAgent` family: 10T/45A OK (was 9T/40A; +1T/+5A = new ordering pin
  testTheAgentSlotRidesBehindToolGuidanceAndBeforeTheRepoMap).
- `ForeignAgentPreset` family: 47T/151A on ForeignAgentPresetRegistryTest (was 45T; +2T = the
  two lenient-mode-pin tests).
- `PrimaryAgent|ForeignAgentPreset` combo: 94T/313A OK.
- `FieldCarry|BaseSystemPrompt|PromptSection|FrontmatterKeyAudit`: 104T/773A OK.
- `DocFigureProseDrift`: 91T/3980A OK (roster arm re-anchored in-step with the page rewrite).
- 2 PHPUnit Deprecations throughout = FOREIGN tests/Config/AccessorDeprecationTest providers.

Mutation proofs (cp-restore, md5-verified each): M1 delete opencodePreset mode line → opencode
lenient test red; M2 delete claudePreset mode line → claude test red; M3 claude `?? AgentMode::All`
→ throw → lenient fallback legs red; M4 move `<agent-instructions>` slot after repoMapSnapshot →
exactly the ordering test red (1F/10T); M5 doc 'seventeen carried'→'eighteen carried' → exactly
the DocFigure carry-count leg red (:5500).
