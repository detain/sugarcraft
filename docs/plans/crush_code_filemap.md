# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-14 @ code tip `ca9aac147` — **FULL REGEN from scratch at the round-79 close** (supersedes the
`a594f073b` r78 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — ONE row below): ACTIONABLE = 1 BY ROW CENSUS**
(OPEN-table 1 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-81 §2(a); pre-census 2 → post-census 1) and cross-confirmed against the triage **ROUND-79 CLOSE**
census paragraph (round-79 lane na — ONE row CLOSED-in-place this close: **E701** by na's interactive OAuth
authorization-code + PKCE flow (lane `a14f613ec`+`9d19bd2b5`+`44adf5fe0` → picks `8ed8ada1d`+`7b83a6454`+
`1b8cc7ce8` ALL CLEAN, merge drift-fix `669c9bd93` (ProjectTier NOT_A_TIER roster + TreeWideGuardRoster 3
temp-dir licensés), weld `ca9aac147` floor 11,837/170,424, rv APPROVE-WITH-FIX 0C/0M/3MINOR all healed
`44adf5fe0`; nd design-first caught the §4 `refresh_token=''` buffer-window defect pre-code).
**Zero mints.** The survivor recount returns exactly 1, never chained. **The ONE survivor is the E699
OPERATOR DECISION GATE — it is a ruling, not a lane: ACTIONABLE = 0 LANES + 1 operator decision.**

CLOSED rows are PRUNED at this regen (E701's close record lives in the triage row + backlog §E701 + worklog
ROUND 79 — the map is a scheduling aid, not the history). Round-79 lane letter (**na**) is RETIRED at this
close: every file it touched lands inside the row that CLOSED this round (McpAuthCommand login verb, new
src/MCP/OAuth{Pkce,AuthorizeUrl,AuthorizationCodeExchange,LoopbackFlow}.php, McpAuthStore carry, MCP.md auth
prose + DocFigure BI/BJ/BK, 7 new test files) — so no `⚠` marks are owed: a retired lane marks only a file
where it landed while the row stayed ACTIONABLE, and none did. ma/mb (r78) and la/lc/lb (r77) stay retired.

**Scheduling warnings (the round-80 point):** there are NO schedulable lanes — the queue terminates at the
E699 ruling (keep-as-is ON RECORD recommended; delete = STOP-class, needs no lane either). If fresh work is
desired instead, the options are a fresh audit sweep or banking. Trigger-watch (NOT rows — each names its own
mint condition): **E696-deny-residual** (if a `denyPatterns` config producer is ever minted, the deny half
ships ENFORCED in the same change), **updateRegistration() redirect-churn** seam (nd §2.2 — servers rejecting
per-login redirect re-registration), **tempnam hygiene** candidate (`/tmp/crush-mcp-auth-*` — 1,878 leaked
files from CommandTableRenderingTest observed at r79; a bounded-cleanup test-hygiene fix, not a product
defect).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r79's
serial2 weld (add it to every src-touching brief's gate list). The per-section ledger-edit law (r77 lc wipe)
governed this closeout's E701 flips (scoped re.split + assert-1 + numstat + far-canary E475/E643/E702/E695).
Fresh-worktree vendor law (r78 merge) + FULL linked refresh incl candy-pty (r76 lesson) stand in the restart
recipe. `scripts/parallel-tests.sh` conservation only prints its verdict with `--against-json`/baseline junit
(RESUME §1b). Durations regen via `--manifest` mode is run-free (r79: `--junit <serial> --out <dir>
--manifest` → cp; set-diff must equal exactly the new test files). Carried disclosed non-seams UNLESS
consumed: SETTINGS.md :359/:403 eleven-family (still not false), Bootstrap tool-count BODY docblocks
unpinned, ka sentinel docblock line unpoliced, `ForeignAgentPresetWiringTest:273` stale 'six' comment
(r75-ja seam), Chat.php:8266 `RuntimeNoticeSink::beginTurn()` wiring seam (r69-fn, opt-in by design). Config
md5 truth `05480c743aff302fd6c06c5a4a4c2210` (start==end at the r79 weld).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-79 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed
  actionable. NONE owed at this regen (see retirement paragraph).

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| **E699** | DECISION-GATE (OPEN-table survivor — OPERATOR ruling pending; keep-as-is recommended ON RECORD; delete = STOP-class, no lane either way) | sugar-crush/src/ClaudeCodeMcpClient.php (:45-62 self-recorded), sugar-crush/tests/ClaudeCodeMcpClientTest.php (unreachability pin) | MCP dormant surface |

Sum: 1 row = census 1 (of which 0 lanes + 1 operator decision). ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| MCP dormant surface | 1 | E699 |

**docs/MCP.md collision cluster FULLY CLOSED: 1 of 2 → 0 of 1 rows** (na consumed the last cite; E699 cites no
docs page). `sugar-crush/src/Cli/Bootstrap.php` — zero remaining actionable citations (unchanged from r78).
`sugar-crush/src/Commands/McpAuthCommand.php` — zero remaining actionable citations (E701 closed with the
login verb landed on la's carry shape, rv-la MINOR-1 precedent honored: both endpoint carries pinned).
