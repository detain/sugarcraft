# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-14 @ code tip `630ef47ff` — **FULL REGEN from scratch at the round-77 close** (supersedes the
`ea61174b5` r76 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — SIX rows below): ACTIONABLE = 6 BY ROW CENSUS**
(OPEN-table 6 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0 — E696's `**PARTIAL` lead sits physically in
the OPEN table and counts there, rule r68) — re-derived from the four triage section tables (awk strict-prefix
survivor recount, exact command + output pasted in `crush_code_RESUME.md` §0-NOW-79 §2(a)) and
cross-confirmed against the triage **ROUND-77 CLOSE** census paragraph (wave-1 lanes la/lc/lb — three rows
CLOSED-in-place this close: **E695** by la's attachment flagship (picks `eb8318b91`+`4a76fdd40`, drift-fix
`9ea12b968`, rv APPROVE, MINOR-1/2 pinned `e7e25ca17`), **E697** by lc's label-only rename (pick `dc5528bba`),
**E700** by lc's 36-heading stamp sweep (pick `b7e6c2888`) — and **E696** re-stamped [PARTIAL] by lb's
architecture verdict (docs healed, pick `630ef47ff`; wiring deferred to the α/β orchestrator ruling, α
recommended). **Zero mints.** The survivor recount returns exactly 6, never chained. Round-78 lanes ma/mb are
composed in §0-NOW-79 §2(b).

CLOSED rows are PRUNED at this regen (E695/E697/E700 close records live in the triage rows + backlog rows +
worklog ROUND 77 — the map is a scheduling aid, not the history). Round-77 lane letters (**la, lc, lb**) are
RETIRED at this close: la's files (src/MCP/HttpMcpServer.php attach path, tests/MCP/*, MCP.md auth prose) and
lc's files (CommandRegistry palette label + derived pins, backlog heading sweep) belong to CLOSED rows;
lb landed the README.md:1072 / docs/MCP.md honesty edit while E696 stayed actionable → `⚠lb` marks below.

**Scheduling warnings (the round-78 point):** ma RE-ARMS README.md:1072 + docs/MCP.md (flip back to enforced
wording IN-STEP with its DocFigure fold arm) → ma runs ALONE in wave-1. mb touches docs/MCP.md too (E702
table + E698 design note) → mb is wave-2 AFTER ma. E701 + E703 also cite docs/MCP.md — any future lane into
that page collides with both.

**Seam dispositions verified at this regen** (from §0-NOW-78's carry + r77 seams): the r77 weld drift-fix
`9ea12b968` rewrote la's fail-in-catch leak check → **gate law** now: src-touching lane briefs add
`SwallowingCatchCensusTest` to their guard filters (it is NOT in the five-guard family). The r77 lc
ledger-wipe incident is memorialized as the per-section re.split law (E700 closed-notation + §0-NOW-79
restart item 5). Carried disclosed non-seams from r76 UNLESS consumed: SETTINGS.md :359/:403 eleven-family
(not touched by r77, still not false), Bootstrap tool-count BODY docblocks unpinned by any guard, ka's
memoryLocate sentinel docblock line unpoliced. The r76 brief's config-md5 "30230" transcription slip is a
known ERRATUM — truth `05480c743aff302fd6c06c5a4a4c2210` (start==end), stable through the r77 weld.

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-77 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed
  actionable (collision history). `⚠lb` = the r77 honesty edits README.md/MCP.md on E696.

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| **E696** | PARTIAL (α/β ruling → lane **ma**) | sugar-crush/src/Agents/AgentManager.php (α grant-resolution seam: `resolveGrantedTools`/`refuseCallOutsideGrant`), sugar-crush/src/MCP/McpRouter.php, sugar-crush/src/MCP/McpClient.php, sugar-crush/src/Cli/Bootstrap.php :5760-5798 (singleton + recorded ruling) ⚠lb, sugar-crush/README.md:1072 ⚠lb, sugar-crush/docs/MCP.md ⚠lb, sugar-crush/tests/MCP/McpClientTest.php | MCP routing |
| **E698** | OPEN (design-note via lane **mb**) | sugar-crush/src/Cli/Bootstrap.php (`mcpServerInventory()` :5563-5602), sugar-crush/src/Cli/Subcommands.php :347-351 (doctor read-only contract), sugar-crush/docs/MCP.md:142-144 | MCP liveness |
| **E699** | OPEN (OPERATOR ruling pending; keep-as-is recommended) | sugar-crush/src/ClaudeCodeMcpClient.php (:45-62 self-recorded), sugar-crush/tests/ClaudeCodeMcpClientTest.php (unreachability pin) | MCP dormant surface |
| **E701** | OPEN (sequenced behind shipped E695; unassigned) | sugar-crush/src/Commands/McpAuthCommand.php :188-262, sugar-crush/src/MCP/OAuthClientRegistration.php, sugar-crush/docs/MCP.md | MCP auth |
| **E702** | OPEN (small; lane **mb**) | sugar-crush/src/MCP/McpClient.php :172-181 (sse throw), sugar-crush/docs/MCP.md (+ DocFigure arm in-step) | MCP docs/transport |
| **E703** | OPEN (decision pending, size M-L) | sugar-crush/docs/MCP.md:49-55 (frozen read-once decision record), sugar-crush/src/Cli/Bootstrap.php (`registerMcpShutdown` seam) | MCP lifecycle |

Sum: 6 rows = census 6. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| MCP routing | 1 | E696 |
| MCP liveness | 1 | E698 |
| MCP dormant surface | 1 | E699 |
| MCP auth | 1 | E701 |
| MCP docs/transport | 1 | E702 |
| MCP lifecycle | 1 | E703 |

**Shared-file collision cluster: docs/MCP.md** — touched by 5 of 6 rows; ma owns it wave-1 ALONE, mb wave-2,
E701/E703 wait for their rounds. **sugar-crush/src/Cli/Bootstrap.php** — E696/E698/E703 (different hunks:
:5760-5798 / :5563-5602 / shutdown seam).
