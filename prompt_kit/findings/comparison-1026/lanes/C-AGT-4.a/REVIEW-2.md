# C-AGT-4.a — REVIEW-2 (r2 full review of 1cee96571, read-only, disk-grounded)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR. All five REVIEW-1 cures verified present in the 1cee96571 blobs and re-gated live.

## Ref law / hygiene
- Branch `cc/C-AGT-4.a` tip = `1cee965710f8bf71939c5f00bf4ed866f0075252` (git cat-file: commit), ONE commit on base 63f2de5d9, porcelain clean. %ae and %ce each grep-detain=1 (separate greps), zero trailers. 22 files +949/−39.
- No suite-figure.json / parallel-tests-durations.tsv / dist / lang paths. PrimaryAgentTest durations row + figure re-pin correctly left to weld.
- File set = BRIEF declared files + the licensed §1.8 in-step set (docs/MEMORY.md + docs/PROMPT_ENGINEERING.md fence-roster enumerations, PresetUnsupportedFieldFailsLoudTest mode rows, FieldCarry NOT_CARRIED['mode'], wither census row, BaseSystemPrompt/PromptSection/DocFigure roster arms, ForeignAgentPresetWiring permissionMode census row for Agents/PrimaryAgent.php). Nothing undeclared.
- Phantom audit: `b83a58d4d`, `testPromptSectionsOrderCarriesNothingAndGuidanceLast`, `testTheAgentInstructionsSlotIsBuiltOncePerSession` → ZERO hits tree-wide (grep incl. tests). Confirmed never-landed phantom claims; the real tree stands on 1cee96571 alone; nothing here depends on those names.

## REVIEW-1 cure verification (git show blobs)
- **MAJOR-1 (foreign lenient mode path unpinned) — CURED.** `tests/Agents/ForeignAgentPresetRegistryTest.php` now carries `testDiscoverClaudeCarriesDeclaredModeAndReadsForeignSpellingsLeniently` (:359) and the opencode twin (:667): each covers declared `mode: primary`/`mode: subagent` honored, `mode: sideways` (unspellable) → All with reason message, absent key → All. Mutation-reasoned by construction: deleting the `mode: $this->enum(...) ?? AgentMode::All` leg falls the ctor default `All` → the Primary/Subagent asserts go red; replacing refusal-bearing makes the `sideways` rows red. The lenient path is genuinely pinned both directions on both dialects.
- **MINOR-1 (AGENTS_AUTHORING "all eighteen onto the row" false) — CURED.** :174-184 now reads "every one of the eighteen … accounted for — seventeen carried onto the `Agent` row, `mode` read and stopped at the preset", matching the `mode` table row (:228) and the AgentMode/AgentPreset/PrimaryAgent docblocks. Truth-in-code verified: `fromPreset()` reads every preset field except `mode`; the DocFigure arm `testAgentsAuthoringPresetRosterDividesTheRegistryItNames` derives the carry count (18 − named-uncarried = 17) and canonicalizes the reads census, so doc and wiring cannot drift silently. Ordering claim cross-check: assembly is `Runtime::systemPromptSections()` (base → maxims → tool-guidance → `<agent-instructions>` PerSession → repoMap → symbolMap → standing slab): the "behind tool-guidance, ahead of repo layers" claim is TRUE in code. (The r2-brief's "Renderer.php ~:1073-1077" anchor is a mis-route — Renderer.php contains zero agent-instructions references; no competing claim exists there.)
- **MINOR-2 (ordering pin, optional-at-weld) — LANDED anyway (status record).** `PrimaryAgentTest::testTheAgentSlotRidesBehindToolGuidanceAndBeforeTheRepoMap` (:191): guidance-canary index < agent-slot index < RepoMapBlock-instance index, with assertNotNull guards on each anchor so a vanishing layer fails loudly instead of vacuously.
- **MINOR-3 (unreproducible gate ledger) — CURED.** REPORT.md carries a "Round-1 corrections (disk-truth)" section: original A/B/C claims explicitly withdrawn (brief A filter re-measured 456T/1944A; B/C filters absent), new figures listed. All new figures reproduced EXACTLY in my runs (below).
- **NIT-1 (stray blank line, optional — status record).** Blob `src/Agents/AgentManager.php` :1515-1530: `*/` directly abuts `private function resolveGrantedTools` — adjacency restored.

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code, linked vendor; no filter touched PtyRunToolTest)
- `--filter PrimaryAgentTest` → **OK: 10T/45A** (+2 PHPUnit Deprecations = foreign AccessorDeprecationTest providers, expected).
- `--filter ForeignAgentPresetRegistryTest` → **OK: 47T/151A**.
- `--filter 'PrimaryAgent|ForeignAgentPreset'` → **OK: 94T/313A**.
- `--filter 'FieldCarry|BaseSystemPrompt|PromptSection|FrontmatterKeyAudit'` → **OK: 104T/773A**.
- `--filter DocFigureProseDrift` → **OK: 91T/3980A** (FIX-1's figure; the spawn's ~3978A is the pre-cure count — the +2A rides the new carry-count leg).
- `--filter LangParity` → **OK: 7T/2852A**.
- ReadmeSuiteFigureDriftTest staleness pair excluded as foreign standing red (weld §7 duty), not a finding.

## Semantics focus
- **E646:** zero timers/timeouts added anywhere in the diff (grep: only the docblock's explicit "no LLM request timeouts" disclaimer).
- **Prompt-cache byte-stability:** `agentInstructions` is a readonly EngineBackend field threaded once via `newRuntime`; the Runtime slot is assembled straight from it each build (no SessionPromptMemo consultation) and is `Stability::PerSession` — bytes cannot churn per turn; churn happens only at apply/revert = one disclosed cache miss per switch, stated in PrimaryAgent's "THE COST SAID PLAINLY".
- **Fence-forgery containment:** body routed through `PromptFence::escape()` under the 13th TAGS entry; `testAForgedCloserInsideAnAgentBodyCannotEscapeItsFence` pins 1 opener / 1 closer / `&lt;/agent-instructions>` defanged survivor; roster pinned by the two whole-roster sites + this assembler-level one (PromptFence docblock now honestly counts NINE pinning sites).
- **Inert-subtask deferral (§6.1 / C-AGT-4.b):** `FrontmatterKeyAudit::INERT['agent']['mode']` joined with the leave-it note; AGENTS_AUTHORING's single Inert row pinned bidirectionally by InertFrontmatterDocumentationDriftTest; zero production callers of `PrimaryAgent::apply()` (grep src/: only docblock references) — picker/cycle correctly deferred to 4.b.
- Narrow-only doctrine in apply/revert hand-traced clean: null-law tools, inherit-model skip, effort-null skip, stricterOf gate (no-new-object when unchanged, never invents a gate, revert restores launch gate by identity), blank body → no section. Revert restores by value — branch-free, cannot depend on narrowing bookkeeping.

## Notes (not findings)
- Deviation, benign: BRIEF named `tests/Agents/AgentPresetRegistryTest.php` for the native `mode` parse pins; they landed in `PresetUnsupportedFieldFailsLoudTest` (refuse provider row `primarry`, camel `SubAgent` honored, absent→All) — better host, same promise, green.
- Weld carries: durations.tsv row for PrimaryAgentTest.php + suite-figure/README re-pin (+10T family net), already stamped in REPORT.

**Conclusion: APPROVE — step ready to weld; no fixer round needed.**
