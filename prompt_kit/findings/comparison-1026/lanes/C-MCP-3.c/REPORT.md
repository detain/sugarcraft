# C-MCP-3.c — SALVAGE COMPLETE, COMMITTED

**Verdict: DONE.** Commit `dbd11a501` on `cc/C-MCP-3.c` (base `74acd2472`), tree clean, no push.
%ae=%ce=`detain@interserver.net`, 0 Co-Authored-By, explicit-path staging (11 files).

## Salvage arc
The 3 dead incarnations' WIP carried the whole SSE leg (SseLineParser stream-mode
reader, SseMcpServer desk + idle pump + capabilities, interim doc/guard moves) — kept
verbatim where sound, unified with the http leg. The missing half (HttpMcpServer desk +
GET channel, McpClient http arm + pump, both new test files, the desk-census reconciliation
the WIP left red) was finished this run.

## Gates (cwd candy-code, plain pipes, `-c phpunit.xml`, targeted only)
- tests/MCP whole dir: **OK 864 tests / 3222 assertions** (1 pre-existing ext skip)
- DocFigureProseDrift + GlobFigureDrift + TreeWideGuardRoster: **OK 171 / 10309**
- Readme + Env + ConfigWriteProducer drift: **OK 62 / 39304**

## Mutation-proofs (4, all reddened then restored byte-exact)
interface-drop→census guard red · `stream`-row-drop→Keys guard red · dispatch-drain-drop→
GET-ping pin red · sse-hasDesk-false→mid-call-answer pin red.

## Welder carries
- NEW test files needing `scripts/parallel-tests-durations.tsv` rows:
  `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php` (5 tests),
  `candy-code/tests/MCP/SseMcpServerServerRequestTest.php` (5 tests).
- suite-figure delta to re-pin: +10 tests / +85 assertions vs base; `tests/Config/Support/suite-figure.json`
  and durations TSV deliberately NOT committed (law).
- Docs moved in-step: `.mcp.json` http Keys row gained `stream` AND `cwd`; sse row gained
  `cwd` (the WIP's sse arm reads `$config['cwd']` for the desk root — the Keys guard forces
  the row; deviation from the brief's letter documented here per §1.8).

## Notes
- `McpClient::stdioClientCapabilities` duplication left untouched (outside the declared hub region).
- §4 mark: cite `dbd11a501`.

## FIX ROUND 1 (2026-10-28)

MAJOR-1 CURED: ReadmeJsonErrorContractDriftTest now READS the JSON-RPC wire
shape instead of only reddening on it. New adjudication roster
`JSONRPC_WIRE_ENVELOPES` (test file): a type-less shape-B `error` envelope is
skipped ONLY when it is genuinely JSON-RPC-shaped (`code`+`message` entries,
no `type` — `isJsonRpcErrorEnvelope()`) AND its `file::method` site is named
in the roster (`enclosingFunctionKey()`, innermost range; closures and
top-level never key onto a roster row). producerSites fails loudly with the
add-it-with-adjudication instruction on any other type-less envelope, and
shippedTypes() asserts roster == the keys actually skipped (staleness arm: a
site refactored away reddens too). Wire shapes untouched.

FINDING EXTENDED (evidence): the review's "the two are the only ones
tree-wide" is file-order masking — src/MCP/McpServerRequests.php carries
THREE pre-existing (since C-MCP-3.b @ base 74acd2472) array-shaped type-less
envelopes in handle()/:134 and elicitation()/:150,:159, and the walk aborts
at the alphabetically-first offender (HttpMcpServer) so the isolated run
never surfaced them. A 2-row roster leaves the guard red (proved: M-drop
run). The roster therefore adjudicates the whole wire family — 4 functions,
one protocol, one decision — with the provenance stated in its doc-block.

NIT CURED: McpClient::stdioSpawnCwd throw message now reads 'MCP cwd (stdio,
http and sse arms alike) must be an absolute existing directory, got %s'.
The only pin (StdioMcpUserTierCwdTest:224) asserts the surviving substring
'absolute existing directory' — no test flip needed.

GATES (cwd=candy-code, plain pipe, -c phpunit.xml): guard isolated 19T/461A
GREEN (was 19T/4F). MCP dir: 864T/3222A/1S — byte-exact vs review.
Readme|Env|ConfigWriteProducer trio: 62T/39304A — byte-exact. Glob: 13/366.
StdioMcpUserTierCwdTest: 6T green. Mutations 4/4 discriminating, cp+md5
clean: drop-roster-row -> unadjudicated red naming the site; planted
wire-shaped envelope in unrostered closeSocket -> unadjudicated red;
roster row renamed -> unadjudicated red; envelope refactored away while row
kept -> staleness-arm array-diff red.

## FIX ROUND 2 (review-2 delta: 1 MAJOR, 2 NIT — one commit, renames + prose only)

MAJOR CURED — DuplicatedTestHelperDriftTest unrecorded const pairs (lane-created):
renamed per-file, descriptive, ALL five declaration sites now distinct, ZERO roster
rows needed:
- HttpMcpServerGetStreamTest.php: URL -> GET_URL (33,202,273,297), SESSION ->
  GET_SESSION (34,86,97,150,322)
- SseMcpServerServerRequestTest.php: URL -> SSE_URL (34,255)
- HttpMcpServerStreamableHttpTest.php: pre-existing URL/SESSION left as-is (only
  single-file bearers after the renames; no pair).
Guard isolated: RED before (14T/196A/1F naming both drift lines) -> GREEN after
(14T/197A), mutation-free. No other file tree-wide declares either name (grep).

NITs CURED (ReadmeJsonErrorContractDriftTest.php prose): roster docblock
attribution "pre-existing since C-MCP-3.b" -> "C-MCP-3.a" (verified: origin
e71d398aa subject "C-MCP-3.a: answer MCP server requests inline…"); dead cite
"{@see self::JSONRPC_WIRE_ERROR_SITES}" -> "{@see self::JSONRPC_WIRE_ENVELOPES}"
(real const, :148).

REVIEW-2.md landed canonical (prompt_kit/findings/comparison-1026/lanes/C-MCP-3.c/)
as first act of the round.

GATES (cwd=candy-code, plain pipe, -c phpunit.xml): DuplicatedTestHelperDrift
14T GREEN; guard isolated 19T/461A byte-exact; tests/MCP 864T/3222A/1S byte-exact;
ReadmeRosterDrift|EnvRosterDrift|ConfigWriteProducer trio 62T/39304A byte-exact.
php -l clean on all three edited files. No durations/suite-figure/dist touches;
broad-filter run surfaced only the known welder-carried reds (ChatTest Environment
method + ReadmeSuiteFigureDrift staleness pair 25537-vs-27589) — unchanged by this
delta.
