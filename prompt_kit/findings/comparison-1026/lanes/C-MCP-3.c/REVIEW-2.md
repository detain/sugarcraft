# C-MCP-3.c REVIEW-2 (delta dbd11a501..be1d3e425) — verdict: FINDINGS (1 MAJOR, 0 CRITICAL, 2 NIT)

## MAJOR — lane tree ships red five-guard (not in delta; missed by both r1 and fixer gate lists)
- `tests/Support/DuplicatedTestHelperDriftTest::testNoDuplicatedConstIsUnrecordedInEitherPolity` FAILS isolated at be1d3e425 (14T/196A/1F).
- Lane files `tests/MCP/HttpMcpServerGetStreamTest.php` + `SseMcpServerServerRequestTest.php` (new) vs pre-existing-but-touched `HttpMcpServerStreamableHttpTest.php` declare same-named `const URL` (3 files, within drift bound) and `const SESSION` (2 files) unrostered. At base only StreamableHttpTest declared either name → pair is lane-created. K=24 battery will catch it.
- Cure: rename per-file (e.g. GET_URL/GET_SESSION) or consolidate byte-identically or roster ACCEPTED_CONST_DRIFT with reason; re-run guard isolated + durations note unaffected (no new file).

## Core delta verification — PASS
- Guard cure non-vacuous, fail-closed: type-less code+message envelope skipped ONLY when `enclosingFunctionKey` ∈ JSONRPC_WIRE_ENVELOPES (innermost named fn; closures → `{closure}`, top-level → `::<top-level>`, unrosterable → loud); unrostered JSON-RPC site → self::fail; non-JSON-RPC type-less → old assertNotNull path. Staleness arm `roster == array_unique(seen)` in shippedTypes; green run PROVES 4 rows live + exactly 4 tree-wide skip sites.
- Static mutation traces (cp/git-grep-rev shell-denied → predicate-trace precedent): row-deletion → unadjudicated-fail red; new-row → moved-apart red; site-refactor (type added or envelope removed) → key vanishes from seen → moved-apart red; planted 5th site → unadjudicated-fail red.
- 4-site roster scope-honest: HttpMcpServer:542 + SseMcpServer:910 (this lane) + McpServerRequests handle:134/elicitation:150/:159 verified byte-identical at base 74acd2472 (envelopes born in e71d398aa creation); §1.8 guard-roster move in same commit, adjudication docblock present. FIXING r1's undercount was correct (walk aborted alphabetically at HttpMcpServer).
- Wire untouched in delta: `git diff dbd11a501..be1d3e425 -- src/MCP/` = McpClient.php refusal message reword ONLY; pin StdioMcpUserTierCwdTest:224 asserts surviving substring 'absolute existing directory' → no flip, MCP band green.
- Re-derived gates cwd=candy-code plain-pipe, all EXACT: ReadmeJsonErrorContract 19T/461A; tests/MCP 864/3222/1S; trio (Readme+Env+ConfigWriteProducer) 62/39304; GlobDialect 13/366; SymbolCitation green.

## NITs (fix-delta prose truth)
- `ReadmeJsonErrorContractDriftTest.php:157` docblock: "pre-existing since C-MCP-3.b" — actual origin commit e71d398aa is **C-MCP-3.a**; letter wrong, substance right.
- `:85` `{@see self::JSONRPC_WIRE_ERROR_SITES}` names a nonexistent const (actual: JSONRPC_WIRE_ENVELOPES); SymbolCitation doesn't police same-file const refs, so silent.

## Welder carries — UNCHANGED (DUR x2 GetStream+SseServerRequest, figure, ChatTest standing) + ADD: DuplicatedTestHelperDrift cure lands in lane durations/figure set anyway (no new file).
