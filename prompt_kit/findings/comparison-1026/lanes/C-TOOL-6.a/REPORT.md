# C-TOOL-6.a — Browser-control / computer-use MCP presets (docs only)

VERDICT: DONE. Tip `c79073e207e6d3da11945f772b25440819121362` on `cc/C-TOOL-6.a`
(cat-file'd: type=commit, `%ae`/`%ce` both contain detain@interserver.net,
`%(trailers)` has zero Co-Authored lines). Base dc9eea92d, deps none.

## Files touched (2, both in the step's declared scope)
- `candy-code/docs/MCP.md` — new subsection `### Browser control and computer use`.
- `candy-code/tests/Config/McpBrowserPresetDocTest.php` — new pinning test, **4T/89A**.
  **Welder: this new test file needs a `scripts/parallel-tests-durations.tsv` row.**

## Placement note (guard-forced deviation, honest)
Design block: heading after `### Adding servers`, before `## Listing without
starting`. Landed AFTER the chapter-closing `---` rule (not before it), because
DocFigureProseDriftTest::testMcpDiscoveryGuidanceAndTheWorkedRecipeReadTheSameFacts
slices `### Adding servers` up to the first `\n---` and asserts EXACTLY two
recipe fences there (`:6397`). Inside the rule, the section's three fences made
5 and reddened that arm; after the rule the recipe slice is untouched and both
the design anchors AND the guard hold. Verified: DocFigureProseDriftTest
re-passes the full set (92T, zero failures with my files in). This is the §6
"guards move in the same commit" law satisfied WITHOUT editing the other
lane's guard — the guard never needed moving, the docs just had to sit where
its contract already lives.

## Every doc claim verified against code in THIS worktree (file:line)
- Presets are ordinary stdio entries → §Adding servers type table (MCP.md);
  `type` absent = stdio, and all three entries here carry command+args.
  [FIX-2 correction: the builder wrote "command+args+env" here and at the
  normalizeEntry row below; the section's preset blocks carry only
  {command,args} — grep of the new section for "env" = 0, re-verified 2026-10-30.]
- Fingerprint compares argv → McpTrustPins.php:27-28,159-166 (sha256 over the
  canonicalised entry incl. command/args/env/url/headers/type); the refusal
  notice wording "command line it was recorded as and the one it has now"
  MCP.md:86-89. This is WHY the docs insist on exact pins: a floating `@latest`
  argv never "changes", so the trust gate never re-prompts.
- `--isolated` = throwaway browser profile, never touches the login profile →
  verified against upstream READMEs, fetched live 2026-10-30: @playwright/mcp
  (`keep the browser profile in memory, do not save it to disk`) and
  chrome-devtools-mcp (temporary user-data-dir auto-cleaned). Version pins read
  from the registries the same day: @playwright/mcp 0.0.83,
  chrome-devtools-mcp 1.12.0 (npm, pub 2026-10-22), windows-mcp 0.8.8
  (PyPI, upload 2026-10-10; the npm @freechekn/taste candidate 404'd — rejected).
- windows-mcp = Windows-only desktop control → upstream CursorTouch/Windows-MCP:
  classifiers Windows 10/11, deps pywin32/comtypes, requires-python>=3.14; the
  console script IS `windows-mcp`, so `uvx windows-mcp==0.8.8` is the launch.
  The docs state plainly it does nothing on Linux/macOS (this host is Linux).
- `mcp__*` is write-class unless annotated → PermissionGate.php isWriteTool()
  :1776 (bare `mcp__` prefix = write) + isReadOnlyMcpTool() :1750;
  McpToolBridge.php readOnly() :261 = readOnlyHint() && openWorldHint()!==true.
  The prose says a made-up name is never read-only (the :1750 carve-out only
  resolves tools that actually rode an annotated declaration) — the docs'
  read-only allowlist therefore names the two tools Playwright itself annotates
  (browser_snapshot, browser_take_screenshot; present upstream), and says
  clearly the annotation, not the name, is the load-bearing thing.
- permissionRules example shape → PERMISSIONS.md:600-620
  `[{"pattern": "mcp__git__*", "action": "allow"}]`, first-match-wins, lives in
  the tiered config (PERMISSIONS.md:25, SETTINGS.md:1250). My example allows
  `mcp__playwright__browser_snapshot` + `mcp__playwright__browser_take_screenshot`
  by exact wire spelling — same shape the docs already teach.
- Deferred schemas → `## Deferred schemas: mcp_list and mcp_call` MCP.md:840
  (lazySchemaTokens default 8000, 0 disables; mcp_list pos 39, mcp_call pos 40).
  The caveat bullet tells readers these browser tools may be hidden behind the
  lazy pair until listed — points at the existing chapter, re-teaches nothing.
- Import path → MCP.md:218 `candy-code mcp import claude|opencode|claude-project
  <path>`; CONTAINERS['claude'] McpForeignTranslate.php:61. Caveat says claude
  tier imports feed the SAME normalizeEntry the docs' blocks pass; no new claim.
- normalizeEntry → McpForeignTranslate.php:131 public static; native
  {command,args:list,env:map} passes through unchanged; null ONLY for
  enabled:false; RuntimeException on bad shapes. All three presets carry
  command+args (no env key — FIX-2 correction of the builder's claim) and no
  enabled key → non-null; test asserts exactly this.
- "no stdio jail today" + forward pointer → sugar-mcp-client StdioMcpServer.php
  spawns argv-array proc_open (:28 documents WHY never a shell string), cwd per
  stdioSpawnCwd, no bwrap; Bubblewrap.php MODE off|on|no-network :53-55, wired
  (per SETTINGS.md:243) for Bash::fromCatalog()→Bubblewrap::fromSetting() ONLY.
  plan_comparison §34/§3765: C-TOOL-4.e (LATE, W36) jails MCP stdio via
  SandboxScope lsp+mcp. The caveat says the sandbox the plan promises is a
  LATER step, NOT current behavior, and that today a pasted stdio server runs
  unsandboxed — true against the code as it stands.
- user-tier gate → HomeDirectory::owned() prose MCP.md:104-107; the section's
  per-tier framing reuses that vocabulary without restating its rules.

## Test content (4 methods, one class)
`McpBrowserPresetDocTest` extracts the section by byte-exact heading, then:
(1) section exists + dated 2026-10-30 + all three exact-pin regexes hit;
(2) every `mcpServers` entry from every block in the slice
passes `McpForeignTranslate::normalizeEntry` AND normalises non-null (a pasted
preset that starts nothing would be caught here);
(3) every `npx`/`uvx` launcher arg carries an exact pin in its toolchain's
grammar (`name@X.Y.Z` / `name==X.Y.Z`, regexes pinned in-class) and never a
floating tag;
(4) every `permissionRules` example in the section matches the bridged
`mcp__<server>__<tool>` wire spelling and uses one of the three parser actions.
Red-before-green-after PROVEN: with base docs the class fails 4/4; with the
section it passes 4T/89A. Fence-slice regex `'/^```json\s*\n(.*?)^```/ms'`
(initially missing `s` → 0 blocks caught by the first run — fixed pre-commit).
All added literals are paren-/quote-bearing (non-glob-shaped) → GlobDialect
corpus unmoved. No numerals rostered by any drift generator → no gen-tool-docs
flip needed; verified by the guard runs below going green without regen.

## Gates (targeted only; full suites NEVER run)
- New test: 4T/89A pass. Pre-fix red: 4/4 fail at base.
- Combined guard filter `McpBrowserPresetDoc|DocFigureProseDrift` → 96T green.
- Full doc-guard sweep `DocFigureProseDrift|ReadmeRosterDrift|EnvRosterDrift|
  TrustKey|GlobDialect|DuplicatedTestHelperDrift|McpStartupOrderingDocDrift|
  McpBrowserPresetDoc` → **193T/45,778A, only the 2 standing PHPUnit deprecations**
  (pre-existing suite shape: the untouched McpStartupOrderingDocDrift filter shows
  the same 2; not mine).
- Mcp code-adjacent subsets: McpForeignTranslateTest 16T/35A green;
  McpToolBridgeWireName+McpToolShape+McpWriteBudgetShape 28T/81A green.
- Mutation checks (committed MCP.md md5 f7047c98…, restored byte-identical after
  each, verified by md5sum pair + empty git diff): @latest → 2F (pin test +
  dated-regex test); heading rename → 4F (extraction pin); broken wire spelling
  → 1F (rule test only).

## Standing reds / external issues I did NOT chase (per brief)
- The `Mcp|...` filter cannot be timed in one run on this host (boots live
  servers); I ran the doc-guards plus two bounded code-adjacent MCP subsets,
  all green. That is the intended coverage for a docs-only step.
- **McpConfigToleranceTest core-dumps mid-class on this worktree** — PROVEN
  unrelated to me: reproduces identically with my files stashed AND the crash is
  a SIGSEGV in the live-start family (the very first test boots a server); not a
  doc-reachable path (its only MCP.md reference is a message string, :128).
  Logged here for the welder/closeout, not chased.
- ReadmeSuiteFigureDrift staleness pair, GlobDialect PathGlob figure etc. —
  standing per brief; my runs reported ONLY the 2 standing deprecations.

## Lane hygiene incident (full disclosure)
Mid-session the repo-wide stash list (shared across all worktrees) raced with
another lane: my `git stash push -- docs/MCP.md` for the pre-fix red proof got
mis-popped by the P-AGENT.b/C-CTX-5.c cross-worktree activity, briefly reverting
my MCP.md and leaving their residue (`tests/Cli/UsageSubcommandTest.php` +1-line
BinCandyCodeDispatchTest patch) in my worktree. Recovered my section from my own
dangling stash object `459af53e5`; their residue copied to
`/tmp/opencode/cc-cmp-lanes/C-TOOL-6.a/foreign-residue/` and left UNSTAGED in the
worktree for its owners (I never staged or committed it; `git show` of my commit
contains exactly my 2 files). Lesson honored: no further stashes in this repo;
pre-fix proof after that used plain file copies.

## Left for closeout / welder
1. durations.tsv row for `candy-code/tests/Config/McpBrowserPresetDocTest.php`.
2. suite-figure.json tests figure: my commit adds 4 tests (file-level), welder's
   K=24 battery re-pin carries it (I never touched the figure file).
3. The McpConfigToleranceTest core-dump above — worth a glance whether it is the
   host or a real pre-existing crash; not doc-reachable, not mine.
4. plan_comparison.md §6 wording — already answered by owners; I recorded nothing
   new (the one §6-vs-guard interaction is resolved in the placement note).
