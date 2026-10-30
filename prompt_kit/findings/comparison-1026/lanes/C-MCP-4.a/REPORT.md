# C-MCP-4.a — VERDICT: ALREADY-LANDED (parked re-offer; zero new commits, correctly made none)

Re-dispatched 2026-10-29 into worktree /home/sites/cc-cmp-C-MCP-4.a
(branch cc/C-MCP-4.a, reset --hard to 103a385aa per brief; branch tip == base,
cat-file-verified: 103a385aa1a7224243e0e02b6d6a616942644f70 ... see tip line below).

## Disk-verified landed state (all ancestors of base 103a385aa)
- a6fa47900 C-MCP-4.a: context.sources reports user-tier MCP rows and liveness
  (6 files: ContextSources.php, Definitions.php, ContextSourcesMcpUserTierTest.php
  created, ContextSourcesTest.php, ContextPanel.vue, contextPanel.spec.ts)
- 9f02221a0 weld regen: candy-code.v1.schema.json + SERVER.md + generated.ts + dist
- 604ccdc3d weld: C-MCP-4.a (absorption record)

## Design-block checklist — all PRESENT in live tree
- mcp gains userStatus/userError/userSkipped: ContextSources.php answer() return
  (verified live) + Definitions.php required-list + schema.json :268-279/:350-352.
- per-row tier: project rows then user rows appended, `tier` enum
  ['project','user'] in schema; shadowed names excluded by the census
  (pinned by testANameTheProjectDeclaresNeverAppearsAsAUserRow).
- nullable liveness {up:bool|null, tools:int} from
  Bootstrap::mcpLivenessSnapshot($root) MEMO-ONLY, never a launch
  (Bootstrap.php:8446 -> McpDecision::mcpLivenessSnapshot, C-MCP-4.c dep, welded).
- answer() injectable ?array $mcpLiveness: present (named param, default null).
- schema in Definitions.php CONTEXT_SOURCES mcp object: present with C-MCP-4.a annotations.
- ContextPanel.vue: ONE MCP section (project split + user heading + livenessDot()
  cold/up/down/unknown); P-CTX-BREAKDOWN restyles later — untouched here.
- all four required test pins exist by name in ContextSourcesMcpUserTierTest.php
  (:41 :61 :77 :97); web spec covers user rows + dot states (contextPanel.spec.ts :81).

## Gate run in this worktree (linked, cwd=candy-code, plain pipe)
vendor/bin/phpunit -c phpunit.xml
  tests/Protocol/ContextSourcesMcpUserTierTest.php tests/Protocol/ContextSourcesTest.php
  tests/Protocol/ProtocolSchemaDriftTest.php tests/Config/ServerProtocolDocumentationDriftTest.php
=> OK (26 tests, 483 assertions) — protocol trio + SERVER.md drift GREEN on regen-committed state.
git status --porcelain on every step file: EMPTY (tree pristine).

## DUR carry for the welder
- NO new test files minted this dispatch (ContextSourcesMcpUserTierTest.php already
  has its durations.tsv row from the original weld era — verify present, mint only if absent).
- No suite-figure/durations/dist change owed (zero edits).

## Erratum note (per lead standing order)
Prior-era REPORT.md/REVIEW-1.md in this lane dir were deleted at batch-18a as
pre-my-lead phantoms; this REPORT is the only lane-dir record and it records a
no-op dispatch, not new work.
