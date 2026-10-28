# REVIEW-1 — lane cc/C-MCP-3.b (step C-MCP-3.b: wire MCP server-initiated requests into turns)

Reviewer: fresh r1 reviewer (no prior-round memory). Date 2026-10-28. READ-ONLY on repo; this file is the sole write.

Lane: worktree /home/sites/cc-cmp-C-MCP-3.b, branch cc/C-MCP-3.b, tip
828b709ea8586eae4bc9e1bea141b16cf01e1690 (parent e4a7052449640e8f2cf411e0218458f6fdd10333).
Salvage lane — extra coherence scrutiny applied (result: clean; see §6).

## VERDICT: FINDINGS

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| MAJOR | 1 |
| MINOR (step-local) | 0 |
| NIT | 5 |

CLEAN bar not met solely because of MAJOR-1, a docs-only wording overclaim with a small
concrete cure. The shipped code itself is correct, gated, and law-clean.

---

## 1. Phantom-first (duty 1) — PASS

- `git -C /home/sites/cc-cmp-C-MCP-3.b branch --show-current` → `cc/C-MCP-3.b`. ✓
- `git log --oneline master..HEAD` → exactly 1 commit (828b709ea). Tree 622c3c9d6 object
  cat-file'd real; author/committer both detain@interserver.net (%ae/%ce separate greps),
  zero trailers; `git status --porcelain` empty.
- Headline features blob-verified in committed content (not just report prose):
  - server-request ask channel: `McpToolBridge::askElicitation` + `perCallDesk` +
    `McpServerRequests` plumbing reachable from `call()`;
  - progress wiring: `McpToolBridge::progressSink`/`progressTail`/`progressPacing` +
    `EngineBackend::turnTools` emitter binding;
  - docs/MCP.md new section "Server requests" (~:627) present in blob.

## 2. Declared-set discipline (duty 2) — PASS

`git diff --stat master...cc/C-MCP-3.b` = 9 files, +1252/−11, byte-matching the BRIEF's
declared list: docs/MCP.md +65, lang/en.php +2, src/Backend/EngineBackend.php +21,
src/Cli/NonInteractive.php +14/−, src/Tools/BuiltIn/AskUserTool.php +21,
src/Tools/BuiltIn/McpCallTool.php +83, src/Tools/McpToolBridge.php +327, NEW
tests/Cli/NonInteractiveMcpToolsHeadlessTest.php, NEW tests/Tools/McpToolProgressTest.php.
No undeclared touches. Weld-only files (suite-figure.json, parallel-tests-durations.tsv,
candy-code-web/dist/, plan_comparison.md) absent from diff. ✓

## 3. Semantics walk (duty 3)

Routing matches §6.1/BRIEF approach: elicitation and permission-ask share ONE channel —
`askElicitation` drives the now-public `AskUserTool` (prompt/typedAnswer/pickedOption/label
opened ~:258/:284/:305/:379) through the turn's `PermissionApprover`; unanswered/rejected →
`McpElicitation::CANCEL`; typed empty + permitting → first option / bool true; required
free-input refusal → CANCEL. Headless: `NonInteractive::withoutInteractiveUser` (~:795-832)
closes elicitations for `McpToolBridge|McpCallTool`, and the close is sticky via TWO legs
(withPermissionApprover early-return + perCallDesk closed-check) — disclosed honestly.
Elicitations never reach the dont-ask auto-Deny path (they are a tool-internal prompt, not a
permission verdict) — consistent with the §6 dont-ask floor.

- E646: clean. No new blanket wall-clock timers. The elicitation deadline-pause is library
  work already vendored (sugar-mcp-client StdioMcpServer:1198-1344 names C-MCP-3), and the
  per-call ceiling shape stays per-request, not per-process.
- Spawn discipline: no new proc_open/fork sites → no roster rows owed; child-lifetimes
  surface untouched.
- StderrEmitterCensus: no new emitters in the diff; census green in-step.
- lang: ONLY `mcp.elicitation.ask` in en.php; lang/ dir has no sibling locales — en-only is
  CORRECT here (§6.3-h: 11-locale strict parity is the LAST wave).
- Library dependency verified on the branch: `McpClient::callTool(..., ?McpServerRequests)`
  (src/MCP/McpClient.php:1023) routes the desk only when the server implements
  `AnswersServerRequests`; `McpServerRequests::handle()` covers ping/roots/list,
  elicitation/create, progress notifications, METHOD_NOT_FOUND default.

## 4. Doc-truth claims (duty 4)

- **"McpCallTool perCallDesk doc-lie fixed" — TRUE.** The docblock now states frames carry
  the `mcp_call` row key; the code passes the call's own `$callId` + `self::NAME` into the
  progress sink, and the ask prompt frames route under the same row identity. Verified.
- **"GlobDialect corpus cured by [+-]{0,1} respell, 304,700 exact" — GREEN.** The integer
  coercion regexes in `coerceElicitationAnswer` are respelled to glob-inert shapes; the
  GlobDialect guard runs green (§5), so the corpus figure is not red.

### MAJOR-1 — docs/MCP.md "Server requests" overclaims the claude-mcp transport

docs/MCP.md (new §, ~:627 region): "On the stdio-family transports (anything that speaks
the line protocol both ways — `stdio` and `claude-mcp`) these are answered in place".
FALSE for `claude-mcp`:

- `src/MCP/ClaudeCodeMcpServer.php:61` implements only `McpServer` — zero occurrences of
  `onServerMessage`/`AnswersServerRequests`/`serverRequests`/`elicitation` in the file.
- `AnswersServerRequests` is implemented ONLY by `src/MCP/StdioMcpServer.php:52`.
- The spawn-time default desk is installed ONLY on McpClient's `'stdio'` arm
  (src/MCP/McpClient.php ~:778-794); the per-call desk is skipped for any server that is
  not `instanceof AnswersServerRequests` (McpClient.php:1023 gate).
- Therefore a claude-mcp-spelled server receives NO answering desk at all, and the §'s
  later "-p … their elicitations land on the spawn-time desk" sentence repeats the same
  overclaim for that transport. (The doc's http statement — no interleaving — is TRUE.)

**Cure (docs-only, fail-loud wording):** restrict the both-ways sentence to `stdio`
alone; add one clause that `claude-mcp` (and `http`) transports do not interleave
server-initiated requests today; scope the -p paragraph to stdio-family servers. ~3-line
edit in docs/MCP.md. No code change — wiring claude-mcp server-requests is future work,
not this step's declared scope.

## 5. Gates reproduced (duty 6) — all green

cwd=/home/sites/cc-cmp-C-MCP-3.b/candy-code, plain pipe, env -u LINES -u COLUMNS,
PtyRunToolTest never in any filter:

- Builder family filter `McpTool|McpCallTool|AskUser|NonInteractive`:
  `Tests: 356, Assertions: 1587, PHPUnit Deprecations: 2` — OK. **Exactly reproduces the
  REPORT's 356T/1587A.** (The duty-list filter `McpToolBridge|McpCallTool|McpToolProgress|NonInteractiveMcpTools|AskUserTool`
  is narrower: 122T/402A OK — the reviewer duty text's filter/figure pairing was off by
  its own construction; builder figures stand.)
- Family + guards combined: `Tests: 707, Assertions: 52627` — OK. Builder said 705T/52611A;
  delta +2T/+26A is filter-superset scope (my regex additionally catches McpStartupOrdering*
  /McpConfig* classes), every leg green.
- Guards alone (GlobDialect|StderrEmitterCensus|TreeWideGuardRoster|LangParity|ReadPathCensus|SymbolCitationDrift|DuplicatedDocBlockLine|DuplicatedTestHelperDrift|TrustKey): 197T/46776A OK — incl. **GlobDialect not-red** and **DuplicatedTestHelperDrift green** on the new test files (duty 8).
- `McpStartupOrderingDoc`: 4T OK (side note: builder's "McpStartupDoc" substring matches
  no class — real name McpStartupOrderingDocDriftTest; green when run correctly).
- Foreign standing reds per the wave roster (ReadmeSuiteFigureDrift staleness pair ~27k vs
  pinned 25,537, PermissionsIntroDrift, ReadmeJsonErrorContractDrift ×4, DreamPass, ChatTest
  AsyncCmd ×5, ChatHostLangCatalogue seam, MouseWiring ctxmeter, AutomaticCompaction,
  KeyHelp) — NOT in any run above because targeted filters excluded them; NOT attributed
  to this lane.
- The 2 PHPUnit Deprecations in every family run trace to
  tests/Config/AccessorDeprecationTest::runtimeEraPairs() provider visibility — pre-existing,
  file not in this diff.

## 6. Salvage coherence + red-before story (duties 5/8)

- Two authors' hunks in one commit read as one design: `askElicitation`/`progressSink`
  are each defined ONCE in McpToolBridge and reused by the McpCallTool dispatcher (no
  duplication, no divergent copies); the perCallDesk spellings differ only in identity
  argument (call-row vs bridge-tool id) — deliberate per notation. No leftover dead code,
  no TODO/stub throws, strict_types preserved, PSR-12 clean.
- Mutation non-vacuity re-reasoned from code (duty 5): M1 (neuter -p close) → both headless
  closed-tests bind a grant and assert accept-never-cancel → 2F; M2 (neuter turnTools
  binding) → turn-binding + plain-arm pins → 2F; M4 (remove BOTH sticky legs → desk
  survives closure) → 2 headless + 1 progress closed-route = 3F; M5 (neuter progress legs
  both files) → 3 progress-tail tests + turn-binding frames = 4F. All consistent with the
  builder's claimed counts; base-swap 14E/3F plausible (new methods absent → Error, plus
  the 3 docs-independent route pins). **Could not physically reproduce:** the reviewer
  permission map denies cp/mkdir/file-write in /tmp (4 attempts rejected), and in-worktree
  mutation is forbidden (foreign-sweep hazard). Reasoned verification only; the pins are
  real assertions on observable frames/verdicts, not vacuous (no glob-shaped or
  '**Refused:'-shaped literals; DuplicatedTestHelperDrift green).
- Sticky-close double-gate disclosed in REPORT as directed — honest; each leg alone would
  survive removal of the other, which is why the builder's M4 mutated both.

## 7. Durations duty (duty 7) — CONFIRMED

Exactly 2 new candy-code *Test.php files. Per-file single runs:
McpToolProgressTest → **15T**/67A ✓; NonInteractiveMcpToolsHeadlessTest → **4T**/14A ✓
(builder's 15/4 recounted correct). Neither file appears in
scripts/parallel-tests-durations.tsv (grep rc=1) → **welder adds 2 rows**; no lane-side
tsv violation.

## 8. Findings

**MAJOR-1** — docs/MCP.md "Server requests" § (~:627): claude-mcp listed as a transport
where server-initiated requests are "answered in place"; no desk exists for it
(ClaudeCodeMcpServer.php:61 implements only McpServer; desk gate = AnswersServerRequests,
StdioMcpServer.php:52 only; McpClient.php:1023/~:778-794). Cure in §4 above.

**NITs (no lane action required; record for closeout):**
1. NonInteractive.php (~:800): `McpToolBridge` used FQ-inline while `McpCallTool` is
   imported — pick one style.
2. src/Tools/RelaysPermissionAsks.php docblock "today only BuiltIn\TaskTool" — stale
   pre-existing lie (AskUser/PlanExit implemented it before this lane). Out-of-scope;
   closeout prose fix.
3. BRIEF-recommended DocFigure arm pinning the new MCP.md capability set against
   McpServerRequests was not added (BRIEF makes it optional; nothing now guards the §'s
   transport list — a guard arm would ALSO have caught MAJOR-1; recommend welding one
   with the MAJOR-1 cure).
4. progressTail truncation is byte-based (`substr(-4096)`) and can split a UTF-8 sequence
   — matches the Bash tail convention; cosmetic only.
5. askElicitation scrubs enum options but McpElicitation::result() revalidates raw enum —
   a forged control-byte in an enum option yields INTERNAL_ERROR (fail-closed);
   adversarial-server-only, acceptable.

## 9. Lead/weld notes

- Weld order: MAJOR-1 docs cure (3-line edit, same lane branch or welder pick) + optional
  DocFigure arm (NIT-3) + durations.tsv 2 rows + closeout suite-figure re-pin (lane rides
  +19T into the ~27k era figure — staleness pair is foreign-era, not this lane's).
- All other step acceptance criteria met: files declared==delivered, family+guards green
  at the builder's own filter, laws clean (E646, spawn, stderr, lang en-only, §6.1 routing).
