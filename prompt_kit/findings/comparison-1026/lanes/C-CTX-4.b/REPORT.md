# C-CTX-4.b — lane report — STATUS: LANDED, awaiting review

Branch cc/C-CTX-4.b @ /home/sites/cc-cmp-C-CTX-4.b, base 0c1ad6c64. Tip cat-file-verified commit:
- 0d530fd568df69a795557d93947623214d3b9fe4 "C-CTX-4.b: declare PERSONA_FILENAMES..." (+20, 2 files)
- 532bb044ad1bb2c67beb50e30c68e988a2293fe1 "C-CTX-4.b: loadPersonal reads the SOUL.md/USER.md persona files through the shared personal gate" (+317/−63, 3 files)
Both %ae=%ce=detain (separate greps), 0 Co-Authored trailers. Tree clean.

## What landed
- loadPersonal(): head+personas loop ([PERSONAL_FILENAME, ...PERSONA_FILENAMES]) sharing ONE ContainedPath::within site vs personalDir anchor; ~/.claude/CLAUDE.md stand-in keeps its own gate + takes the HEAD slot (unshift); shared tail extracted to private readPersonalEntry() with NO gate inside. File census stays EXACTLY 9 (machine-verified comment-stripped token count) → ContainedPathInventoryTest row 9 + ContainedPath.php 64/32 wording untouched.
- New public const PERSONA_FILENAMES = ['SOUL.md','USER.md'] (OpenClaw names) with law docblock.
- INT-M2 law generalized honestly: refused head records refusal + fallback judged (pins unchanged); a refused persona records its own line and skips, others survive.
- ContextSources::priceInstructionFiles: docblock truth only (the existing personal foreach already prices every loadPersonal() entry as personal:true rows; tiers/badges/panel rows flow through instructionRows/ContextCategories unchanged). NOT edited functionally — co-tenant C-MCP-4.a answer()/mcp regions untouched.
- docs/MEMORY.md: personal-tier bullet extended (SOUL/USER, stand-in=head slot only, per-file personal rows); containment sentence reworded honestly ("personal-directory gate (shared by the head file and its persona candidates)" — 'nine' + 7 `X()`'s forms preserved for the DocFigure arm); "personal file is outside the filter"→"personal tier"; on-disk tree gains SOUL.md/USER.md rows.
- Class docblock census prose fixed EIGHT→NINE (was stale vs the machine-pinned 9; now mirrors MEMORY.md incl ancestorRoot home floor).
- Tests: 7 new methods in tests/Context/PersonalInstructionFileTest.php (order head,soul,user; absent personas cost nothing (byte-exact single-candidate shape incl imports=>[]); all-personas fenced <user-rules>+preamble sections lead inline before repo; persona symlink escape refused+recorded while head+other persona survive + secret never in prompt; CLAUDE stand-in head slot + persona rides behind; oversized persona = own null-body pointer entry w/ pointer text, head unaffected; ContextSources pricing end-to-end = per-file personal:true rows in loader order). GlobDialect-safe (no wildcard literals); no new helpers sharing names cross-file.

## Gate figures (cwd=candy-code, -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS)
- PersonalInstructionFileTest: OK 24 tests / 78 assertions (2 PHPUnit deprecations = pre-existing family)
- Filter (PersonalInstructionFile|ContextSources|MemoryDocumentationDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelper|DuplicatedDocBlockLine): 173 tests, 1 failure ONLY = inherited foreign SymbolCitationDriftTest::testEveryCitedTestSymbolExists (src/Chat.php:13712 cites \SugarCraft\Code\Agents\AgentPresetFieldCarryTest, real class is ...\Tests\Agents\ — introduced by CO-TENANT pick 093ea6e13 C-AGT-4.a, identical at base, zero Chat.php diff in this lane; NOT fixed here per scope law — welder/lead route to C-AGT-4.a owner).
- ContainedPathInventoryTest: 2 failures at BASE (roster drift for Memory/AgentMemory.php +1, Skills/SkillLoader.php 3→4, Cli/LaunchContext.php, Context/EnvironmentBlock.php, Host/Commands/ContextHostCommand.php, Host/Commands/MemoryCommand.php, Host/TranscriptProjector.php, Tools/PathJail.php 5→6 — all foreign merged-step census never re-rostered; Context/InstructionFileLoader.php row NOT in diff = my 9-count agrees). Inherited standing reds, disclosed not touched.
- Staleness pair (25,537 pinned vs live) expected standing state; suite-figure.json/durations.tsv/dist/protocol trio NEVER touched; no proc_open added.

## Not done / handoff
- Mutation runs left to reviewer (discriminating set suggested: neuter persona foreach → order/escape/absent pins red; move gate into readPersonalEntry → census 10 → DocFigure+Inventory arms red; drop unshift → stand-in slot pin red).
- MEMORY.md personal rows also flow to /instructions listing + wire automatically via the row builder (verified by reading instructionRows/ContextCommand:188/InstructionsCommand:66 — per-row badge, no per-file case).

## Review handoff
Reviewer spawned background (ses_e79a72f5effe80bV8F7UAQH22i) on tip 532bb044a with checklist incl. suggested mutations (persona-foreach neuter -> order/absent/escape pins red; gate moved into readPersonalEntry -> census 10 -> DocFigure+Inventory arms red; unshift dropped -> stand-in slot pin red). Reviewer is read-only on the worktree; verdict goes to REVIEW.md beside this report.

## Review CLOSED
cc-cmp-reviewer (ses_e79a72f5effe80bV8F7UAQH22i): APPROVE 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT. REVIEW.md written beside this report. Census law mechanically verified (within-count 9 at base AND tip); all gates green; 2N are handoff-wording nit (absent-files pin does NOT redden under foreach-neuter — it was never claimed red in the committed report body, only the handoff suggestion over-scoped) + reviewer permission-map could not run live mutations (weld battery backstop). Lane terminal state: tip 532bb044a, clean, ready for weld.
