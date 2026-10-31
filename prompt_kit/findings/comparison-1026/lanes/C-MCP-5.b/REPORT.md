# C-MCP-5.b — lane report (LATE W34, `mcp serve` verb gate, help, completion, docs)

Lane: `/home/sites/cc-cmp-C-MCP-5.b`, branch `cc/C-MCP-5.b`, base master `9a0c569eb`.
Transport killed this session twice at turns 1-2 (probe-only, zero writes); this file is the recovery anchor. background=true honored (spawn-side).

## Status table (updated as slices land)

| subtask | status |
|---|---|
| SLICE 1: this REPORT.md + probes | IN PROGRESS (writing now) |
| core seam: Subcommands::mcp() serve arm + SUBCOMMAND_ACTIONS + wiring | REMAINING |
| lang: cli.mcp.usage + cli.help.screen row + (no per-action gloss needed) | REMAINING |
| docs/MCP.md §Serving MCP rewrite + roster line + help-count prose | REMAINING |
| README.md mcp line | REMAINING |
| DocFigure arms in-step (leaf-rows 11→12; login-arm roster; qualifiers) | REMAINING |
| tests/Cli/SubcommandsMcpServeTest.php (NEW, child bin round trip + EOF 0) | REMAINING |
| HelpTest.php new row | REMAINING |
| gates: McpServeHostTest, DocFigure, HelpTest, ReadmeRoster, dispatch, censuses | REMAINING |

## Design block (design-mcp.md `### C-MCP-5.b`, read verbatim @ /)

- route `serve` in the `mcp` verb gate to `McpServeHost::run()`; `SUBCOMMAND_ACTIONS['mcp']` gains `serve`; help screen + `cli.mcp.usage` gain the row; MCP.md §"Serving MCP" rewritten (`mcp serve` IS an MCP server, `serve` WebSocket still is NOT, client recipe `{"command":"candy-code","args":["mcp","serve","--root","…""]}`); README `mcp list|trust|import` → `…|serve`; subcommand count stays eleven (a verb-action, not a top-level verb).
- files.create: `candy-code/tests/Cli/SubcommandsMcpServeTest.php`
- files.modify: `src/Cli/Subcommands.php`, `lang/en.php`, `docs/MCP.md`, `README.md` (+ HelpTest modify, DocFigure arms in-step per guards clause)
- tests clause: child `bin/candy-code mcp serve`: initialize round trip; EOF exits 0. HelpTest new row.
- guards clause names EXACTLY: `DocFigureProseDriftTest::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` and `::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm`; `ReadmeRosterDriftTest` if the README line is rostered.
- inherited from C-MCP-5.a weld mark (plan §4 heading + REVIEW-2): wiring MUST pass `McpServeRoster::build()` output to `run()` (r1 NIT-2); `@mcp` prose duty: NO separate prose found — the §Serving MCP rewrite IS the prose move (assertion 'the page enumerates … server halves' + the `serve is not one of them` claim stay TRUE; verified against disk).
- verb-cascade (ParsedArgs SUBCOMMANDS/14-count): NOT triggered — no NEW top-level verb. `serve` already exists as a top-level verb (the WebSocket one); `mcp serve` is a nested action. Fourteen-verb pins untouched by construction.

## Probe results (live tree @ 9a0c569eb)

- `src/MCP/Serve/McpServeHost.php` EXISTS (C-MCP-5.a welded): `public static function run(array $tools, HookManager $hooks, string $root, mixed $stdin = STDIN, mixed $stdout = STDOUT): int` at :115; loops `fgets` until false, returns 0 on EOF. Constructor private; public `new()` factory exists (test uses `McpServeHost::new($roster, $chain, $root, $write)` — TESTS/MCP/Serve/McpServeHostTest.php:78). 17T/96A figure from weld mark.
- `src/MCP/Serve/McpServeRoster.php` EXISTS: `public static function build(string $root, ?InstructionFileLoader $loader = null, ?SkillRegistry $skills = null, ?bool $rgAvailable = null, ?bool $fdAvailable = null): array` at :77 — throws if a roster name stops classifying Read.
- `src/Cli/Subcommands.php`: mcp gate = `private static function mcp(ParsedArgs $args): int` — arms `auth`→mcpAuth, `import`→mcpImport, `trust`→mcpTrust, then bail `if ($verb !== 'list')` → `NonInteractive::failUsage(... Lang::t('cli.mcp.no_action')|Lang::t('cli.mcp.unknown_action'), $args->outputFormat, Lang::t('cli.mcp.usage'))`. Dispatch table `'mcp' => self::mcp($args)` at :132. `SUBCOMMAND_ACTIONS` const at :4410 with `'mcp' => ['list', 'auth', 'import', 'trust']` at :4416. `SUBCOMMAND_DESCRIPTIONS['mcp'] => 'cli.completion.verb.mcp'` :4396 — glosses keyed PER VERB only (nested actions carry no per-action gloss keys → NO new cli.completion.* key needed; design's "if a gloss is keyed" conditional is FALSE on disk).
- `lang/en.php`: `'cli.mcp.usage'` :977 = 'Usage: candy-code mcp list | candy-code mcp trust | candy-code mcp auth login <server> | candy-code mcp import claude|opencode|claude-project <path> [--write user]'. `'cli.mcp.no_action'` :964, `'cli.mcp.unknown_action'` :976. Help screen heredoc `'cli.help.screen'` :283; mcp rows in the Subcommands block: `  mcp list …`, `  mcp trust …`, `  mcp import claude|opencode|claude-project <path> [--write user] …` (rows at heredoc lines ~356-373 abs). NO `mcp auth` separate row exists in the help block (auth rides the usage string only) — check before deciding the serve row shape.
- `docs/MCP.md`: `## Serving MCP` at :1287 (design cite :880-955 STALE). Current prose: McpServer-interface paragraph ending "`candy-code serve` is not one of them: it is the WebSocket server the web UI drives ([SERVER.md](SERVER.md)), and nothing in it speaks MCP."; later the roster sentence "`candy-code --help` lists exactly fourteen under its **Subcommands** heading — … `mcp list|trust|import`, …" at ~:1349 (design cite :940 STALE), and "eleven answer and exit, and `serve` runs until it is stopped."
- `DocFigureProseDriftTest::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` at tests/Config/:4044 — pins THAT will move in-step: (a) `assertSame(11, preg_match_all('/^  (doctor|models|session|mcp|completion)\b/m', $block))` leaf-rows → 12 with the new help row (+ its comment); (b) `foreach (['doctor','models','session list','session delete','mcp list','lsp install','lsp list','serve','completion bash|zsh|fish'])` row-presence — survives, gains nothing mandatory; (c) help qualifier sentence pin `each answers and exits except serve, which runs until stopped` — needs the design's qualifier (first long-running mcp verb); (d) MCP.md claims ` '`candy-code serve` is not one of them` ` + `eleven answer and exit, and `serve` runs until it is stopped` — both stay TRUE (top-level serve only) provided rewrite keeps the phrases; (e) `exactly fourteen` sentence pin — untouched.
- `DocFigureProseDriftTest::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm` at :4226 — pins `assertSame(['list', 'auth', 'import', 'trust'], $roster['mcp'])` → gains `serve` in-step. It ALSO pins `substr_count($mcp, 'candy-code mcp auth login <server>') === 2` — my MCP.md rewrite must not add/remove occurrences of that exact form string.
- `bootstrap` gate shape: `Bootstrap::hooks(?PermissionGate $gate = null, ?string $root = null): HookManager` at src/Cli/Bootstrap.php:6344 (installs gate as last layer, built-ins incl ProtectFilesHook included); `Bootstrap::permissionGate(bool $interactive = false, bool $announceBypassMode = true, string $consoleSurface = self::PERMISSION_SURFACE_BACKGROUND)` resolves --permission-mode/env/config. Design (5.a): gate "built the way `-p` builds its gate" — NonInteractive has NO direct PermissionGate refs; the -p shape is reached via Bootstrap (exact call still to be probed — see REMAINING PROBES).
- C-MCP-5.a weld riders: new class was DORMANT BY DESIGN, this lane is its wiring; docs/HOOKS.md row already landed; 17T/96A figure for McpServeHostTest is on the welder's §7 list already (NOT mine to re-mint).

## Remaining probes (do next, then implement)

1. Exact `-p` gate-build call shape (grep `permissionGate(` call sites + NonInteractive).
2. HelpTest.php structure for the mcp rows (what pins the help screen today).
3. README.md — locate `mcp list|trust|import` line by symbol (design cite :1551 stale) + whether ReadmeRosterDriftTest rosters it.
4. BinCandyCodeDispatchTest — any mcp usage/verb-roster pins.
5. Completion tests (bash/zsh/fish scripts) — any pinned `list auth import trust` word lists.
6. ParsedArgs: how `serve` (top-level) verb gate reads Serve::ACTIONS; ensure nested-action completion for `mcp` needs nothing else.
7. Root normalization for `$args->root` (canonical? `--root` default) — mirror what `mcp trust`/`Bootstrap::mcpServerInventory` uses.

## Refusals / boundaries

- Not touching: suite-figure.json, durations.tsv, protocol trio/SERVER.md/generated.ts, plan_comparison.md, forbidden root files. No new top-level verb → 14-verb cascade NOT fired. Foreign accepted-red set adjudicated only.
- If a guard's derived-vs-pinned array disagrees because of a FOREIGN lane's surface: STOP AND REPORT (lead's standing instruction; `screenReader` roster staleness is a §7/master item).

## Slices landed

- SLICE 2 commit 00225474168bda88159161a67c5e0492154bf7d9: Subcommands.php gate+roster+mcpServe(), lang/en.php usage+help row+qualifier, DocFigure both arms in-step (12 leaf rows, mcp serve presence, new sentence, 5-word roster). Gates: run-arm+login-arm 2T/62A green; HelpTest|McpServeHostTest|SubcommandsMcpAuthLoginTest filter 194T/100632A with ONLY the accepted foreign reds (SERVICE_TIER 1F + 5 KeyHelp).
- REMAINING: slice 3 MCP.md §Serving MCP rewrite + README:1713 line (gate: DocFigure run+login arms again — pinned phrases preserved); slice 4 tests/Cli/SubcommandsMcpServeTest.php (child bin: initialize round trip, tools/list==NAMES, EOF exit 0, mcpServe wiring source-pin for NIT-2) + HelpTest new row line; final gate family + censuses + TreeWideGuardRoster bare + DuplicatedTestHelperDrift + check-one-type-per-file.

## Slices landed (final)
- SLICE 2 002254741 — src/Cli/Subcommands.php (serve gate arm + private mcpServe() wiring McpServeHost::run(McpServeRoster::build($root), Bootstrap::hooks(Bootstrap::permissionGate(), $root), $root) + SUBCOMMAND_ACTIONS['mcp'] +serve) / lang/en.php (cli.mcp.usage append, qualifier sentence, `  mcp serve [--root PATH]` help row) / tests/Config/DocFigureProseDriftTest.php (leaf rows 11→12, presence foreach +mcp serve, sentence expectation, login-arm roster +serve).
- SLICE 3 47d92c5bb — docs/MCP.md §Serving MCP rewrite (keeps verbatim pins "candy-code serve` is not one of them", "lists exactly fourteen…", "eleven answer and exit…", appends mcp serve long-runner clause + client recipe {"command":"candy-code","args":["mcp","serve","--root",…]}; roster sentence +serve) + README.md:1713 +serve.
- SLICE 4 81a4146c2 (amended x1 for roster row) — NEW tests/Cli/SubcommandsMcpServeTest.php 2T/25A stable (child bin initialize+tools/list round trip, EOF rc 0, stderr framing pins; source-shape NIT-2 belt pins McpServeRoster::build INTO run + gate-backed chain + root resolution) + HelpTest row leg + TreeWideGuardRosterTest.php WALKS_A_DIRECTORY_THE_TEST_MADE row 'Cli/SubcommandsMcpServeTest.php' => ['scandir($dir)'] (lane-fg sanctioned pattern, tearDown walk of own sandbox).
- tip 81a4146c210525f133ac4c9d721483422fde63a9 (cat-file'd same shell), 3 picks off 9a0c569eb, porcelain 0, %ae=1 %ce=1 trailers=0.

## Gates (final battery)
- DocFigure arms (run-arm + login-arm): 2T/62A OK.
- SubcommandsMcpServeTest alone: 2T/25A OK x2 runs; mutation (gate-arm sed-deleted) → 2F, cp-restored md5-clean.
- HelpTest class: 105T/4007A, 1F = accepted SERVICE_TIER foreign only.
- Batch 164T/23635A/0F: CliLangCatalogue|StderrEmitterCensus|ReadmeRosterDrift|BootstrapMcpTrustPins|BootstrapMcpDisableEnv|SuiteSkipRoster.
- TreeWideGuardRoster bare: 17T/3868A OK (after roster row; 2 deprecations pre-existing tree noise).
- DuplicatedTestHelperDrift bare: 14T/204A OK. check-one-type-per-file: 62 libs 3331 files 0 problems.
- GlobDialect|ReadPathCensus: 32T/1093A/1F — the 1F is the ACCEPTED GlobDialectDifferentialTest PathGlob docblock-vs-corpus drift (310,245 vs derived 311,360; neither number asserted, docblock untouched — foreign §7 item). ReadPathCensus legs green. My test file contributes zero corpus patterns (all regex literals contain $ or parens → exempt; no wildcard-bearing strings).

## §7 numbers
- NEW test file: candy-code/tests/Cli/SubcommandsMcpServeTest.php — LIVE 2 tests / 25 assertions.
- Not touched (forbidden): suite-figure.json, durations.tsv, protocol trio, SERVER.md, generated.ts, plan files.
- Refused/collisions: none — no sibling owns Subcommands.php/en.php/MCP.md/README regions I touched; verb cascade untouched (design says no new top-level verb; fourteen stays fourteen).
