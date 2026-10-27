# P-CTX.a — Context attribution by category (core + /context text)

LANDED: one commit on cc/P-CTX.a — 06a8352ed845dbf6bc98ae37d3dbe74b4d401cc1 "P-CTX.a: Context attribution by category (core + /context text)" (base 4e3535ed4, rev-list count 1; worktree /home/sites/cc-cmp-P-CTX.a; %ae+%ce detain, no trailers).

## Files (numstat)

- candy-code/src/Context/ContextCategory.php NEW (~95 ln) — enum: 11 categories, `forSectionLabel()` total map (unknown→SystemPrompt), `label()` via `cmd.context.category.<value>`.
- candy-code/src/Context/ContextCategories.php NEW (~300 ln) — `from()` rows (category,?tokens,sub), `splitTools()` (bridges per-server by wireNameFor's unambiguous first `__`, dispatchers serverless, TaskTool roster carved at AGENTS_OPEN), `bufferTokens()` (null config→null, off→0, on→window−threshold), `contentTokens()`, `bar()` largest-remainder exact-sum.
- candy-code/src/Context/ContextBreakdown.php +40 — ctor gains mcpToolCount/mcpToolTokens/mcpServers/agentsTokens/compactorConfig (trailing named, defaults null; ride BOTH withers); measure() prices the split from the same list (var renamed $toolSplit after an in-lane collision with the cache-$split loop — caught by tests).
- candy-code/src/Backend/EngineBackend.php +18 — public `promptTools()` = turnTools(null,null,null) (IT differs from tools(): ToolLimits reshaping, MutatesContextLedger drop/rebind); promptSectionSizes() now reuses it (byte-identical call).
- candy-code/src/Host/ContextSources.php +7 — measures promptTools() (the list that ships) + passes compactorConfig; serialize() untouched (wire = later protocol step).
- candy-code/src/Commands/ContextCommand.php +55 — 'By category' block opens the report (after total, blank line); all pre-existing lines byte-unchanged.
- candy-code/lang/en.php +15 — cmd.context.category.* (en only locale; sorted slot).
- candy-code/docs/CONTEXT.md +13 — by-category paragraph (the ~-estimate law, exact-sum bar, free-space clamp, 'not measured'/not-sent honesty).
- tests/Context/ContextCategoriesTest.php NEW — 9 methods: real-Runtime label coverage (instructions/memory/repo-map/skills/env) + sum==totalTokens, unknown→System, bar cells exact + all-zero + share split, roster carve (SystemPrompt+CustomAgents==sections total, substring monotone), per-server MCP split incl real dispatcher pair (exact tool-half additivity), buffer off=0/on=formula/unknown=null, free never negative, every category label key in en.php, map totality.
- tests/Commands/ContextCommandTest.php +25 — new testTheCategoryFieldsRideBothWithers; not-measured report pins 3 block lines.
- tests/Chat/ContextCommandsTest.php +2 — real-Chat /context carries 'By category:' + Messages row.

## Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe)

- --filter 'ContextCategories|ContextCommand|AccessorDeprecation': 121T/965A OK.
- --filter 'Context': 1815T/7425A OK.
- --filter 'EngineBackend|TaskTool|McpDispatcher': 416T/2447A OK.
- --filter 'Prompt|Lang|Host': 2424T/19574A OK (1 skip baseline).
- five-guard: 133T/15380A, failures = ONLY the allowed ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 25,702 — re-pin is gate duty).
- 2 'PHPUnit Deprecations' in every window are the pre-existing AccessorDeprecationTest private-provider discovery baseline, not lane files.

## Laws/deviations

- ~ law: every figure in the block is the script-weighted estimate already on the breakdown — no second measurement anywhere (from() reads only stored fields).
- Free-space edge: max(0, window − content − buffer); over-budget shows 0 (pinned).
- Unknown honesty: sections-null ⇒ its five cats null; agentsTokens null (tools unmeasured) ⇒ SystemPrompt uncarved, CustomAgents 'not measured'; MCP instructions row = 'not sent' note (0 is NOT printed — nothing unshipped is measured).
- label() uses an interpolated double-quoted key so the LangParity literal census cannot read the prefix as a bare key (caught live; own test pins the key set instead).
- No new files in suite-figure.json / durations.tsv / dist / protocol — untouched. New test file ContextCategoriesTest.php is for the gate's durations duty.
- promptTools() justified: turnTools drops plain MutatesContextLedger tools absent a bound ledger and applies config limits — priced list ≠ launch list.
