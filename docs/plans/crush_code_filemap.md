# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-14 @ code tip `a594f073b` — **FULL REGEN from scratch at the round-78 close** (supersedes the
`630ef47ff` r77 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — TWO rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-80 §2(a)) and cross-confirmed against the triage **ROUND-78 CLOSE** census paragraph (round-78 lanes
ma/mb — FOUR rows CLOSED-in-place this close: **E696** by ma's α grant-resolution narrowing (lane
`c4d08c169` → pick `f64399c82`, wave-1 weld `41ad0bc93` floor 11,756/169,439, rv APPROVE 0C/0M/4MINOR-info;
DENY-pattern residual lives under CLOSED-notation — `denyPatterns` config producer still absent, trigger-watch
minted), **E698** by mb's never-launching liveness readout (picks `4854c86a3`+`15d57cc84`+fix `4eca19378`,
byte-stable-when-cold with negative pins), **E702** by mb's supported-transports table (pick `4ed2924a8`),
**E703** by mb's α config-digest line (pick `1f9e6fec8`; β reload REJECTED — relaunch re-arms the
prompt-injection→`proc_open` threat). r78-rv-mb APPROVE-WITH-FIX 0C/0M/2MINOR, both healed `4d1672b75`.
**Zero mints.** The survivor recount returns exactly 2, never chained. Round-79 lane na (E701 PKCE) is
composed in §0-NOW-80 §2(c); E699 is an OPERATOR DECISION GATE, not a lane.

CLOSED rows are PRUNED at this regen (E696/E698/E702/E703 close records live in the triage rows + backlog rows
+ worklog ROUND 78 — the map is a scheduling aid, not the history). Round-78 lane letters (**ma, mb**) are
RETIRED at this close: every file they touched lands inside a row that CLOSED this round (ma: AgentManager/MCP
routing + README:1072/MCP.md re-arm; mb: McpClient `startedSnapshot`, panel suffixes, MCP.md transports +
liveness + digest lines, two new test files) — so no `⚠` marks are owed: a retired lane marks only a file
where it landed while the row stayed ACTIONABLE, and none did.

**Scheduling warnings (the round-79 point):** na (E701) touches `sugar-crush/docs/MCP.md` — its ONLY remaining
co-citation is E699's own page-free surface, so na is collision-free. `McpAuthCommand.php` gained r77-la's
`tokenUrl`/`registrationUrl` carries (AuthEntry) — the PKCE flow must extend, not re-litigate, that shape
(rv-la MINOR-1 is the pinned precedent for what happens to UNPINNED carries). If the operator ever rules
E699 = delete, that is STOP-class and needs NO lane.

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through both r78
weld serials — keep it in every src-touching brief's gate list. The per-section ledger-edit law (r77 lc wipe)
governed this closeout's backlog flips (scoped re.split + assert-1 + numstat + far-canary). Fresh-worktree
vendor law (r78 merge): a merge worktree may be vendor-cloned from the finished lane when the post-pick tree
is content-identical — verify the path-repo symlinks are RELATIVE first (18/18 + 7/7 census after).
`scripts/parallel-tests.sh` conservation only prints its verdict with `--against-json`/baseline junit — a bare
run just aggregates shard tails (r78 note, RESUME §1b). Carried disclosed non-seams from r76/r77 UNLESS
consumed: SETTINGS.md :359/:403 eleven-family (still not false), Bootstrap tool-count BODY docblocks unpinned,
ka sentinel docblock line unpoliced, `ForeignAgentPresetWiringTest:273` stale 'six' comment (test-code,
r75-ja seam). Config md5 truth `05480c743aff302fd6c06c5a4a4c2210` (start==end at the r78 weld).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-78 CLOSE census paragraph, normalized to repo-root paths:

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
| **E699** | OPEN (OPERATOR ruling pending; keep-as-is recommended ON RECORD; delete = STOP-class, no lane) | sugar-crush/src/ClaudeCodeMcpClient.php (:45-62 self-recorded), sugar-crush/tests/ClaudeCodeMcpClientTest.php (unreachability pin) | MCP dormant surface |
| **E701** | OPEN (sequenced behind shipped E695 + E696-α; round-79 lane **na**) | sugar-crush/src/Commands/McpAuthCommand.php :188-262, sugar-crush/src/MCP/OAuthClientRegistration.php, sugar-crush/src/MCP/McpAuthStore.php (AuthEntry carry shape from la), sugar-crush/docs/MCP.md (auth prose + DocFigure arm IN-STEP) | MCP auth |

Sum: 2 rows = census 2. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| MCP dormant surface | 1 | E699 |
| MCP auth | 1 | E701 |

**docs/MCP.md collision cluster HAS CLOSED: 5 of 6 → 1 of 2 rows** (only E701 cites it now; ma/mb consumed the
rest). `sugar-crush/src/Cli/Bootstrap.php` — zero remaining actionable citations (E696/E698/E703 all closed).
