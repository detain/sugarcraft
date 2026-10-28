# REVIEW-2 — cc/C-MCP-3.b r1 MAJOR-1 cure re-review (2026-10-28)

Fresh round-2 reviewer. READ-ONLY; this file is the sole write. Tip under review:
58966feb9901450efa5e1b01f4bea9e98f7feb1c (expected tip, cat-file real; parent e4a705244).

## VERDICT: CLEAN

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| MAJOR | 0 |
| MINOR (step-local) | 1 |
| NIT | 0 |

The r1 MAJOR-1 (docs overclaim) is genuinely cured; one cosmetic step-local
finding does not block.

## 1. Hygiene — PASS

`git status --porcelain` 0; `master..HEAD` exactly 1 commit; %ae grep -c
detain@interserver.net = 1 and %ce = 1 (SEPARATE greps); body: zero trailers.
Diff vs parent = exactly 10 files (r1's 9 lane files + DocFigureProseDriftTest),
numstat: MCP.md +67, en.php +2, EngineBackend +21, NonInteractive +12/2,
AskUserTool +17/4, McpCallTool +79/4, McpToolBridge +326/1,
NonInteractiveMcpToolsHeadlessTest +178 (new), DocFigureProseDriftTest +86/1,
McpToolProgressTest +552 (new); total +1340/−12. No weld-only files touched.

## 2. Docs truth — PASS

Shipped §"Server requests" (docs/MCP.md:627) now reads: "Only the `stdio`
transport interleaves this traffic today: it is the one server class that
carries an answer desk, so its requests are answered in place while the
exchange lock is held. The other transports — `http`, `git`, `sse` and
`claude-mcp` — surface no server-initiated requests, and their calls simply
proceed." -p paragraph: "a `stdio` server's elicitations land on the
spawn-time desk and answer `cancel` by construction (on a desk-less transport
the question never arrives)".
Independent re-derivation: class-decl census shows ONLY StdioMcpServer
implements AnswersServerRequests (StdioMcpServer.php:52 vs :61/:25/:37/:69 of
Claude/Git/Http/Sse); McpClient.php:1048 gates the per-call desk on
`$server instanceof AnswersServerRequests`; the factory's spawn-time desk is
passed only in the 'stdio' arm (McpClient.php:790-793). No reverse-request
handling exists in the other four transports. Sentence is TRUE, neither
over- nor under-scoped.

## 3. Guard arm — PASS (honest, single, structural)

testTheServerRequestsSectionNamesExactlyTheDeskCapableTransports occurs
exactly once (no twin double-add). The expected desk roster is DERIVED: it
parses buildServer()'s five factory arms via regex + the explicit
'claude-mcp' => fromGrant leg, runs class_implements() per class, and compares
the DOC's positive and negative rosters against the DERIVED sets (both
directions), plus the -p stdio sentence pin. The literals ['stdio'] and the
constructed-5 assertSame legs are fail-LOUD staleness pins on the derivation
(arm docblock states the move-together contract) — not the silent-rot hardcode
the duty feared: add a fake `implements AnswersServerRequests` to HttpMcpServer
→ $desking becomes ['http','stdio'] → both the staleness leg AND the
doc-positive-roster leg redden; delete the denial clause from MCP.md → the
'The other transports — … — surface no' pinned-shape regex match count 0 →
red. php -l clean; method-name-alone run: Tests: 1, Assertions: 16 OK.

## 4. Gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code) — PASS

- --filter 'McpTool|McpCallTool|AskUser|NonInteractive' → "Tests: 356,
  Assertions: 1587" OK (exact r1/FIX-1 figure).
- --filter 'DocFigureProseDrift|ReadmeRosterDrift' → "Tests: 102,
  Assertions: 4120" OK.
- --filter 'GlobDialect|SymbolCitationDrift|DuplicatedDocBlockLine|
  DuplicatedTestHelperDrift|StderrEmitterCensus' → "Tests: 136,
  Assertions: 39128" OK.
- gen-docs --check NOT executable by this reviewer (my permission map refuses
  `php tools/*`; disclosed). Structurally safe: the diff contains ZERO
  generated surfaces (no docs/_data, no docs/lib files), and the fixer report
  claims rc0.
- Note: every run ends "OK, but there were issues! PHPUnit Deprecations: 2" —
  PHPUnit-internal, identical across all filters, pre-existing config noise,
  not lane-introduced.

## 5. MINOR-1 (step-local) — DocFigureProseDriftTest.php:7398

Stray whitespace-only hunk: the last method's closing brace re-indented from
4 to 8 spaces (twin-absorption artifact). PSR-12 blemish, zero behavior (php -l
clean, no cs-fixer CI job). Exact fix: restore `    }`. Welder-foldable.

## 6. Twin-absorption verification — CONFIRMED HONEST

28545a1a7 exists; `rev-parse ^{tree}` of both commits = identical
d92c9279b584fdc8cb0de753e429bb6b5b673b4d; committer clocks differ by 17s
(12:12:04 vs 12:12:21). Exactly one new arm method in the final tree — the FIX-1
disclosure matches disk.

## Weld roster note

durations.tsv currently has NO rows for the two new files
(tests/Cli/NonInteractiveMcpToolsHeadlessTest.php,
tests/Tools/McpToolProgressTest.php) → welder owes EXACTLY 2 rows.
DocFigureProseDriftTest is MODIFIED with a pre-existing row (line 160) — no new
row. No riders: zero src/Protocol/** and zero candy-code-web files in the diff
(verified against the 10-file name list).
