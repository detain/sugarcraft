# C-MCP-5.b — WELD record (welder #, 2026-10-31, W35 LATE)

Master before weld: `d45cc64db` (post C-TOOL-4.f weld + run-state fold).
Lane: /home/sites/cc-cmp-C-MCP-5.b @ cc/C-MCP-5.b, base 9a0c569eb, four picks
002254741 / 47d92c5bb / 81a4146c2 / 1a6130f99, tip 1a6130f99. All four shas
`git cat-file -t` = commit, read from `git log --reverse master..cc/C-MCP-5.b` on disk.

## MERGE

Explicit-list cherry-pick (never a range), zero hand-conflicts, all auto-merged:
landed master shas `97ad4e307` (verb gate + SUBCOMMAND_ACTIONS + DocFigure 11->12 +
en.php help rows), `c5eba5282` (MCP.md/README docs), `96a1ef9bc` (NEW
tests/Cli/SubcommandsMcpServeTest.php + HelpTest leg + TreeWideGuardRosterTest row),
`2ce619fb4` (r1 wording fix). NO `-x` per CHERRY-PICK-TRAILER-LAW-vs-PLAN-PRECEDENT-t646
— the four landed shas are named in the §4 heading. `git cherry master cc/C-MCP-5.b`
printed '-' x4, no '+', BEFORE prune. Authors: %ae and %ce both detain@interserver.net
on all four (verified by separate greps, count 4), zero Co-Authored-By trailers.

## BASE ANCESTRY (lead's suspicion adjudicated from disk)

Lane base 9a0c569eb IS the C-WEB-5.b weld — the guard cure `e78d5dc14` IS ALREADY IN
THE BASE (merge-base --is-ancestor green). The screenReader cure `00fedf519` is NOT
(predates base? no — it POSTDATES base: base is its ancestor; the cure landed master-side
after the lane branched). Master-side drift since base touched THREE of the lane's 8 files
(en.php, README.md, DocFigureProseDriftTest) — all auto-merged cleanly.

## EN.PHP APPEND UNION (merged-tree re-derivation)

Merged file: python dupscan over the key column (re.findall "^\s*'key' =>", Counter)
= 3408 keys, ZERO duplicates. Diff d45cc64db..HEAD on the file: exactly 3 '<' lines,
all lane-sanctioned replacements (help-screen qualifier re-wrap "except serve and
mcp serve, which run until stopped"; cli.mcp.usage row gaining `| candy-code mcp serve
[--root PATH]`) — nothing master-side lost. CliLangCatalogueTest 8T/220A OK;
LangParityTest 7T/3427A OK on the merged tree.

## SHARED ROSTERS (landed-blob truth)

- SUBCOMMAND_ACTIONS 'mcp' arm landed 5 values: ['list','auth','import','trust','serve']
  (Subcommands.php landed blob).
- DocFigureProseDriftTest leaf-row arm: assertSame(12, ...) at :4119 (11->12).
- TreeWideGuardRosterTest (landed blob): the lane's row
  'Cli/SubcommandsMcpServeTest.php' => ['scandir($dir)'] at :678 in
  WALKS_A_DIRECTORY_THE_TEST_MADE, beside ALL THREE sibling rows alive —
  :587 Agents/Live/AgentLiveRegistryTest.php · :645 Cli/LoginSubcommandTest.php
  (cure e78d5dc14, already in base) · :806 Tools/BashSandboxTest.php.
  No sibling row dropped.
- The lead dispatch said "SUBCOMMAND_ACTIONS (13->14 arms)" — LANDED TRUTH: the
  top-level arm set did NOT move (seven arms; serve is a nested value under 'mcp',
  a verb-internal action, per r1 Q1/r2 Q1: ParsedArgs.php:56 stays exactly fourteen
  top-level SUBCOMMANDS — fourteen stays fourteen). Disclosed, not corrected: r2
  independently re-derived the verb-cascade exemption.

## MERGED-TREE GATES (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe;
PtyRunToolTest::testExpectMiss never in any filter; anchored/ bare classes per law)

vs r2 lane figures:
- SubcommandsMcpServeTest: **2T/25A OK** — LIVE re-measure EXACTLY matches r2's truth
  (the circulated 3T/38A figure is refuted again at merge).
- DocFigureProseDriftTest: 92T/**4212A** OK (r2 92T/4202A — +10 assertions, identical
  test count: merged-tree walk drift from the C-TOOL-4.f/C-EXT-6.c doc surface, DISCLOSED,
  not chased).
- TreeWideGuardRosterTest BARE: 17T/**3882A** OK (r2 3868A — +14A same-shape drift).
- DuplicatedTestHelperDriftTest BARE: 14T/204A EXACT, GREEN.
- Cli\\HelpTest:: (escaped): 105T/**4028A**, sole F = CANDY_CODE_SERVICE_TIER at
  HelpTest.php:252 — accepted foreign, unchanged shape (r2 4007A, +21A drift).
- McpServeHostTest (C-MCP-5.a sibling): 17T/96A OK — untouched by this weld.
- BinCandyCodeDispatchTest::testTheCompletionsOnlyOfferTokensTheParserAccepts:
  1T/35A OK — nested mcp actions never enter the top-level capture (r1 Q5(c)).
- ReadmeSuiteFigureDriftTest: 4T/26A 2F = the accepted staleness pair; the test's OWN
  message reads 'Failed asserting that 25537 is identical to 29367' — +2T over
  C-TOOL-4.f's 29,365, exactly this weld's live 2T (§7 re-pin rider).
- Accepted foreign set carried UNCHANGED (none re-run, none cured; no new family joined):
  keepWarm 1F · 5 KeyHelpTest datasets · HelpTest[CANDY_CODE_SERVICE_TIER] ·
  BinCandyCodeDispatchTest --worktree :1893 · AttachPagingTest 2F (base-proven 364a52876) ·
  OneSidedHomeSandboxTest:435 · ReadmeSuiteFigureDrift pair (above) · GlobDialectDifferentialTest
  1F (PathGlob docblock-vs-corpus — welder asserts NEITHER number from arithmetic; live
  re-derive stays the battery's) · SettingsEditorTest:189 · ChildStderrCaptureTest 2F ·
  AccessorDeprecationTest :168/:202 (the 2 PHPUnit deprecations riding EVERY run above) ·
  BootstrapLaunchFormatConstantsTest doc-roster leg (r1 Q7 base-proven: README.md lacks the
  quoted form at BASE 9a0c569eb == tip — pre-existing staleness, NOT this lane) ·
  Cli/HelpTest env-var-roster leg (inside the 105T/4028A run above, SERVICE_TIER-only F
  shape as r2 recorded).

## GENERATORS / REGENERATE

gen-command-docs / gen-tool-docs / gen-settings-doc / gen-protocol-schema ALL `--check`
rc0 "up to date" on the merged tree → NO `weld: W35 regenerate` leg. Zero src/Protocol/*
and zero candy-code-web/* in the 8-file name-diff → web chain UNOWED, node_modules
untouched, dist untouched, no npm ci.

## REVIEW-LOOP FILE INVENTORY (byte sizes at commit time)

REPORT.md 12,374 B · REVIEW-1.md 9,888 B (FINDINGS C=0 M=0 MINOR=1 NIT=0, "Weld-ready") ·
FIX-1.md 4,001 B — NOTE: the lead's dispatch (and my own first stat at 09:04) recorded
2,217 B; the fixer APPENDED a second section at 09:06:16 (a STOP report refuting a
phantom 'thirteen/twelve D1' instruction, confirming the lane unchanged at 1a6130f99,
WELD-READY). Committed byte-exact at the NEW size, disclosed here and in the §4 mark.
REVIEW-2.md 8,952 B (VERDICT CLEAN — C=0 M=0 MINOR=0 NIT=0, r2 closure review with
independent re-derivation).

## MINOR-1 CURE (from the files, as the mark must disclose)

r1 MINOR-1: docs/MCP.md:1289-1291 lead sentence "What CandyCode serves itself is
GitMcpServer (with GitCommandHandlers)" read exclusively-stale beside the new serve
surface; FIX-1 applied r1's one-word cure verbatim in pick 1a6130f99 → "...serves
itself for git is `GitMcpServer`..." (2-line re-wrap, docs-only). r2 Q2 verified the
result TRUE AS WRITTEN: McpServeHost.php:52 is final and does NOT implement McpServer;
GitMcpServer stays the only interface-serving impl.

## VERB-CASCADE EXEMPTION (r2 Q1, independently re-derived)

serve lands as a nested value inside the mcp verb gate (Subcommands.php:3200
`if ($verb === 'serve')` before the failUsage; mcpServe() :3370 feeding
McpServeHost::run(McpServeRoster::build($root), Bootstrap::hooks(Bootstrap::permissionGate(),
$root), $root) — C-MCP-5.a's NIT-2 duty satisfied at the call site); ParsedArgs.php:56
SUBCOMMANDS stays exactly 14 top-level; completion generates serve only after 'mcp';
"eleven answer and exit" arithmetic checks (14 − serve − attach − acp = 11).

## §7 HAND-OFFS (LIST, NEVER FIX)

1. durations.tsv has NO SubcommandsMcpServeTest.php row (r2 proved: grep rc=1 over
   1,672 lines) → gate mints it at the LIVE 2T/25A.
2. suite-figure.json/README pair re-pin carries this weld's +2T/+25A (live 29,367
   per the staleness test's own message).
3. PathGlob docblock live re-derive at battery (GlobDialect rider).
4. docs/SERVER.md + generated.ts untouched by the lane — verified by name-diff.

## WARN NEXT WELDER

Every lane base predates every later weld — en.php order, SUBCOMMAND_ACTIONS, DocFigure
arms, TWGR rows, README/ARCHITECTURE word-numbers and the subcommand count MUST be
re-derived on YOUR merged tree; assertion-only drift (+10/+14/+21-class with identical
test counts) is measured and normal — disclose it, never chase it. Run TreeWideGuardRoster
and DuplicatedTestHelperDrift by BARE class name; Cli\\HelpTest needs the ESCAPED
backslash form (the single-backslash alternation silently matches zero tests).

## NIT (recorded)

The `@mcp` serve/headless owner-gated seam wording is byte-preserved: r1 verified, and
r2 re-verified 'candy-code serve is not one of them' and 'exactly fourteen' each present
once in the merged docs surface (DocFigure-pinned green).
