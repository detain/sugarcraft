# rename-5-C7 — sugar-mcp → sugar-mcp-client (lib, ns Mcp→McpClient) <!-- @rename-keep -->

<!-- @rename-keep: this file is the lane work-record; old-name mentions below are AS WRITTEN history. -->

## Verdict
(IN PROGRESS)

## Entry facts (verified)

- tools/renames.json line 28: kind=rename, sugar-mcp → sugar-mcp-client, lib, ns Mcp→McpClient, bins {}, no extra_literals. <!-- @rename-keep -->
- Derived: pascal SugarMcp→SugarMcpClient, compact sugarmcp→sugar-mcp-client(R4), env SUGAR_MCP_/SUGARMCP_→SUGAR_MCP_CLIENT_. <!-- @rename-keep -->
- Baseline suite (rename-baseline.md:77): **226T/899A/0F/0E/0S/0W**. tools/tests floor 426T/1484A exact. <!-- @rename-keep -->

## Hazard rulings (decision log)

- H1 SELF-PREFIX: `sugar-mcp` is a strict prefix of `sugar-mcp-client` — R3 boundary `(?![A-Za-z0-9_])` PASSES on `-client` (hyphen is a separator by C.1 design) → single tool run is safe (preg_replace single-pass, replacement not rescanned) but a SECOND run would double-suffix AND the residue checker would flag every legit `sugar-mcp-client`. RULING: data-only fix — add `"protect":["sugar-mcp-client"]` to the entry (r3Pattern only admits protect strings starting `old-`; produces `(?!-client(?![A-Za-z0-9_]))`; same mechanism as sugar-crush's `sugar-crush-web` protect). Residue tool shares r3Pattern → covered. Tool run EXACTLY ONCE regardless. <!-- @rename-keep -->
- H1b R2 PASCAL: `SugarMcp`→`SugarMcpClient` self-matches residue R2 (`(?![a-z0-9])` passes on uppercase C) — NO protect lever for R2. Only 16 `SugarMcp` sites, all brand DISPLAY (root README:74, PROJECT_NAMES, MATCHUPS×2, index.html×2, docs/_data×4, generated docs/lib html×6). The lib's own H1 is already space-form "Sugar MCP". RULING: pre-R0 manual rewrite `SugarMcp`→`Sugar MCP Client` (data-only, zero tool/test change, floor preserved); tool R2 then legitimately 0 replacements (R1-ns rule stays non-zero — the plan's STOP condition is about R1, not R2). sugar-dash→sugar-dashboard comparison: its continuation is lowercase → residue-safe by construction, this one is not. <!-- @rename-keep -->
- H2 McpClient COLLISION: lib exports ZERO classes named McpClient (src: ArgumentShape, ExchangeLock, McpMessage, McpRouter, McpServer, McpTool, RequestIdSequence, StdioMcpServer). `SugarCraft\McpClient` string tree-wide at base: ZERO. sugar-crush's `final class McpClient` lives in `SugarCraft\Crush\MCP` (src/MCP/McpClient.php:13) — distinct FQN, never co-imports the lib under that short name. crush's `StdioMcpServer`/`McpRouter`/`McpTool` same-short-name/different-ns pairs ALREADY exist today; R1 only flips the FQN prefix text → structure preserved. RULING: NO collision. <!-- @rename-keep -->
- H3 CONSUMER = sugar-crush: requires sugarcraft/sugar-mcp (only consumer composer.json besides root). Pin sites all textual pointers, both sides rewrite in-step: DescriptorInheritanceGuardTest roster keys 'sugar-mcp/StdioMcpServer.php::start' ×2 + prose ×2; tools/check-child-lifetimes.php ACCOUNTED key 'sugar-mcp/src/StdioMcpServer.php::start' + prose; DocFigureProseDriftTest :490/:524 docblock cites; ARCHITECTURE.md:1293 roster sentence; ~60 `SugarCraft\Mcp\` use/import sites src+tests. RULING: R5 refresh-deps linked sugar-crush+sugar-mcp-client; R6 crush filters per brief. <!-- @rename-keep -->
- H4 WIRE/ENV BARE TOKENS: `MCP` bare, `SUGARCRUSH_MCP_DISABLE`/MCP_DISABLE_ENV, protocol strings `2024-11-05`-class — none match sugar-mcp/SugarMcp/SUGAR_MCP_ shapes → untouched by law. SUGAR_MCP_*/SUGARMCP_* env: ZERO sites in lib and crush → R5 rule 0 replacements, no LegacyEnv shim (honest none, B12 precedent). No Lang.php in lib → R6 lang no-op. No data paths/XDG in lib (it is a transport lib). <!-- @rename-keep -->
- No `Sugar-mcp` capital-hyphen sites (B5 law: zero). No glued `_sugar-mcp` content cites outside excluded/generated territory. No docs/repo_map page, no findings/sugar-mcp.md, no ansi dir, no .vhs dir, not in vhs.yml. Zero mentions in docs/plans/*, CONTEXT.md, AGENTS.md/CLAUDE.md → ledger tagging NOT needed. <!-- @rename-keep -->

## Steps

- R0 tag-prep @6469b43e1: protect added (JSON-validated), brand pre-edit 16 sites/6 files to space-form, baseline row :77 tagged. %ae/%ce==1 verified. <!-- @rename-keep -->
- R1 dry-run: 86 files / 5+1 paths / R1=121 R2=0 R3=126 R4=0 R5=0 (R2/R4/R5 zero = deliberate, hazard rulings above). Full eye-review passed; notable: crush own-ns `SugarCraft\Crush\MCP` files untouched except their lib-FQN cites; charmbracelet_mods repo_map rows + prompt_kit campaign pointers rewrite as live pointers; PROJECT_NAMES.md absent from dry-run (holds no hyphen-slug after brand edit — honest). TOOL RUN EXACTLY ONCE (protect makes a 2nd run a no-op but no reason to risk it). <!-- @rename-keep -->
- R2 remote rename @rc=0: gh repo sugarcraft/OLD -> sugar-mcp-client (301 redirect lives); verified record name/description (desc keeps truthful 'Extracted from sugar-crush'). <!-- @rename-keep -->
- H1 PROTECT PROVEN LIVE: the tool's one untagged NOTES rewrite converted exactly the historical bare-mention while the legit `sugar-mcp-client` token on the SAME line survived un-doubled (no `-client-client`). Restored as-written + tagged. <!-- @rename-keep -->
- BASE gate figures (pre-R3, linked cwd=worktree): sugar-mcp 226T/899A OK == baseline row; crush --filter Mcp 1058T/5271A/1S(canary); --filter Lsp 287T/1206A; --filter DocFigureProseDrift|DescriptorInheritance|ProcessReaper 111T/8436A; tools/tests pinned 426T/1484A. <!-- @rename-keep -->
- R3 tool run:
- R4 manual:
- R5 wiring:
- R6 gates:
- R7 commit:

## R6 gates (worktree, post-R5, all EXACT vs base-measured baselines)
- residue `check-rename-residue --old=sugar-mcp`: 0 findings BEFORE staging. @rename-keep
- sugar-mcp-client lib: 226T/899A OK (baseline 226/899 exact).
- crush --filter Mcp: 1058T/5271A/1S(canary) exact; --filter Lsp: 287T/1206A exact;
  --filter DocFigureProseDrift|DescriptorInheritance|ProcessReaper: 111T/8436A exact.
- tools/tests: 426T/1484A (via candy-code/vendor/bin/phpunit — fresh worktree lacks
  candy-core/vendor; equivalent binary, pinned figures hold). <!-- @rename-keep historical provenance -->
- check-path-repos --no-lib-path-repos rc=0; check-child-lifetimes 60 libs/28 sites/
  8 findings/8 accounted/0 problems (renamed StdioMcpServer key rides tools/ rewrite);
  gen-docs --check + gen-ansi --check rc=0.
- R5 wiring: root composer update sugarcraft/sugar-mcp-client --no-install (lock M,
  committed R7); refresh-deps linked sugar-mcp-client,candy-code; stale dangling
  sugar-crush/vendor/sugarcraft/sugar-mcp symlink removed (escaping=1 -> 23/23 0). @rename-keep

## R7
- renames.json line 28 status pending -> renamed (lane-terminal; packagist R8/R9
  owed by weld, NOT this lane). Commit `sugar-mcp-client: rename from sugar-mcp`. @rename-keep
- push origin rename/c7 ONLY. Old-name pkg sugarcraft/sugar-mcp must NOT be @rename-keep
  re-created; GitHub 301 sugarcraft/sugar-mcp -> sugar-mcp-client verified at R2. @rename-keep
