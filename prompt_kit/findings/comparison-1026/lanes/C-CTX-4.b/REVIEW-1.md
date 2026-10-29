# C-CTX-4.b — REVIEW-1 — verdict: CLEAN (0C / 0M / 0 step-local MINOR / 2 NIT)

Worktree /home/sites/cc-cmp-C-CTX-4.b, branch cc/C-CTX-4.b, tip 532bb044a, base 0c1ad6c64 (merge-base confirmed; master has moved to 103a385aa — all attributions below done against 0c1ad6c64 content, not master). Tree clean.

## Traceability / scope — PASS
- Diff = exactly the 4 declared files (InstructionFileLoader.php, ContextSources.php, MEMORY.md, PersonalInstructionFileTest.php), +337/-63. No undeclared file, no out-of-region edit.
- ContextSources.php: single hunk @@ -99,6 +99,12 @@, docblock-only above priceInstructionFiles; C-MCP-4.a answer()/mcp regions byte-untouched. Functional claim verified ON DISK: base ContextSources already carried `foreach ($loader->loadPersonal() as $doc) { … 'personal' => true }` — no code change was needed; the brief's "modify" goal (personal-tier rows) is achieved through the pre-existing generic loop.
- Forbidden files zero-diff: suite-figure.json, durations.tsv, candy-code-web/dist, protocol trio. No new test file → no durations row owed.

## Completeness — PASS
- loadPersonal(): head+personas share ONE ContainedPath::within site (:643) vs the personalDir anchor; refused persona records refusedPaths + `continue`; head refusal still hands the stand-in role to ~/.claude (fallback keeps its own gate :677) and unshifts to the HEAD slot so personas ride behind; tail extracted to private readPersonalEntry() with NO gate inside (docblock law preserved: one compare per read decision).
- Census law mechanically verified by line census on both commits: 9 code-site `ContainedPath::within(` at 0c1ad6c64 AND at tip (docblock mentions excluded) → ContainedPathInventoryTest InstructionFileLoader row + MEMORY.md "nine" wording intact. Class docblock EIGHT→NINE is a truth-fix of stale prose (base code census was already 9; prose unpinned).
- No stubs/TODOs; PERSONA_FILENAMES is consumed at :639, not dormant.

## Correctness — PASS (hand-traced)
- Normal: AGENTS.md+SOUL.md+USER.md present → [head, soul, user] in const order, each an own <user-rules>-fenced priced entry; Runtime::planInstructionDocuments (:5377, pre-existing loop) prices every entry under the SHARED combined budget (MAX_INSTRUCTION_BYTES - defer reserve) plus the per-document cap — the brief's "budgeted" rides the head's existing law; §6 has no ruling for this step (ruling: none), design default honored.
- Edge: head absent, CLAUDE.md present, SOUL.md symlink-escaped → stand-in unshifted, SOUL recorded refused ("resolves outside <dir>"), USER survives; escape of one persona never un-houses the others (INT-M2 read sideways), refusal message shape byte-carried from base (no consumer drift). Absent candidate = one is_file stat, zero entries. Dedup null keeps $headAdopted=true (base parity: a deduped primary never summons the stand-in).
- Fence containment: persona bytes escape via PromptFence::escape through the same framing loop the head always used; escape-pin test asserts the secret never reaches the system prompt.

## Conventions / laws — PASS
- Both commits: %ae=detain@interserver.net AND %ce=detain@interserver.net (separate format fields), 0 Co-Authored-By trailers.
- strict_types/PSR-12/final hold (no class-shape change); no user strings (refusal format reused verbatim); no timers (E646 n/a); no proc_open/fork sites.
- Two-commit chain against §1.3's "one step = one commit": accepted — house precedent (C-CTX-4.f shipped 2-pick lanes; prior WELD-R chains APPROVE'd multi-commit); weld cherry-picks the range.
- Test-literal hazards clear: no glob-shaped literals, no `**Refused:**`-shaped literals; GlobDialect + DuplicatedTestHelperDrift green; helpers soulPath/userPath unique.

## In-step guard law — PASS
MEMORY.md personal-tier rows + containment wording moved in the SAME tip commit as the behavior; MemoryDocumentationDrift, DocFigureProseDrift (containment arm reads live census + method-span walk), ReadmeRosterDrift family all green at tip.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- --filter PersonalInstructionFile: OK 24 tests / 78 assertions (2 PHPUnit deprecations = pre-existing AccessorDeprecationTest family; builder figure matched exactly).
- --filter 'PersonalInstructionFile|ContextSources|InstructionFileLoader|MemoryDocumentationDrift|ContainedPathInventory|SymbolCitation|DocFigureProseDrift|GlobDialect|DuplicatedTestHelper|DuplicatedDocBlockLine|ReadmeRosterDrift': Tests: 337, Assertions: 23255, Failures: 3 — all three reproduced as base-state, lane-neutral:
  1. ContainedPathInventoryTest::testTheRoutedCallSiteInventory — roster diff lines are Memory/AgentMemory +1 and Skills/SkillLoader 3→4, foreign files only.
  2. ContainedPathInventoryTest::testTheHandSpelledInventory… — 6 foreign additions + PathJail 5→6; no lane file in the diff (InstructionFileLoader row agrees at 9).
  3. SymbolCitationDriftTest::testEveryCitedTestSymbolExists — src/Chat.php:13712 cites \SugarCraft\Code\Agents\AgentPresetFieldCarryTest, real class lives at tests/Agents/ (Tests\Agents); Chat.php diff in this lane is EMPTY and 093ea6e13 (C-AGT-4.a) is an ancestor of the lane base → inherited foreign, correctly untouched per scope law.
- Staleness pair (25,537 pinned vs live tree) is the standing §7 duty, expected red.

## Tests quality — PASS
7 new methods assert derived behavior (paths arrays, exact single-candidate array incl imports=>[] and bytes=10, fenced-shape stringContainsString per canary, order arrays, pointer placement, personal:true row arrays in loader order) — not copied constants. Static mutation reasoning: neuter the persona foreach → 6 of 7 pins red (only absent-costs-nothing survives, correctly — it writes no persona files); add a gate inside readPersonalEntry → live census 10 vs the word-pinned "nine" in DocFigure + Inventory arms; drop the unshift → [claude, soul] order pin red. Discriminating set exists.

## NITs (non-blocking)
- NIT-1 (step-local, report-file only): the builder handoff table's claim that the absent-files pin reddens under M1 is false statically; the committed code/report body never claimed it. No repo file change needed.
- NIT-2 (process): live mutation execution remains denied to this reviewer class (permission map); static discrimination + weld battery is the accepted backstop, as in sibling lanes.

## Weld carries
- Range to pick: 0d530fd56 → 532bb044a (2 commits) onto master post 103a385aa; re-run at merge: --filter 'PersonalInstructionFile' (expect 24T/78A OK) + MemoryDocumentationDrift/DocFigure.
- The two ContainedPathInventory reds + SymbolCitationDrift red + staleness pair are base/foreign standing reds — do NOT charge to this lane; SymbolCitation's cure belongs to the C-AGT-4.a owner (fix Chat.php:13712 cite to \SugarCraft\Code\Tests\Agents\AgentPresetFieldCarryTest or relocate the class) at that lane's loop or the wave loop.
- No durations/suite-figure/dist duty from this lane.
