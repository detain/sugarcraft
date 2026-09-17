# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `b89059713` + this closeout (round-89 WAVE-2 = campaign-final chain: base `0c83384df`
(§0-NOW-93 dispatch) → y6 pick `82aec515c` (E736 7.2 emoji map, lib-local) → y5 picks `4e04ec035` `e25a8b564`
(E744 crush-host widget adoption, RE-PIN domain) → re-pin `cf456a306` (floor 12,061T/170,810A/0F/0E/1S exit0,
K=8 conservation +0/+0) → ledger stamps `b89059713` — both lanes rv-APPROVE 0C/0M) — **FULL REGEN at the
round-89 wave-2 close = CAMPAIGN CLOSE** (supersedes the round-89 wave-1 `064430d85`-base cut); regenerate
this file at every round-close — there will be none unless the operator opens a new plan.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 0 rows below): ACTIONABLE = 0 BY ROW CENSUS**
(OPEN-table 0 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage
section tables (awk strict-prefix survivor recount, exact command + verbatim empty output pasted below and at
`crush_code_RESUME.md` §0-NOW-94 §1; wave-1 left 2 survivors — round-89 wave-2 CLOSED **§E736**
(y6 shipped its single unbuilt row — shine 7.2 emoji-shortcode parity via new `candy-shine/src/GithubEmoji.php`
1,837-code MAP, house-39 left-wins over 34 collisions, ' headphones' dead-key fixed; 8.1 measured
ALREADY-LANDED) and **§E744** (y5 shipped all six crush-host workstreams — lazy widget-Cmd relay with
OSC52_MAX_CHARS=65,536 cap + transcript notice, Ctrl+C copy-over-quit precedence, PasteMsg/MouseMsg zone
routing, LoadMoreMsg top-N paging, InputProjection view-convergence ruling) → post-census **0**) and
cross-confirmed against the picks (`git cherry`: every lane commit marked '-' = patch-equivalent on master).

CLOSED rows are PRUNED (E736/E744 at this regen — the last two). Round-89 lane letters **y1 y2 y3 y4 y5 y6** —
all six RETIRED (files landed; y5/y6 retired the two surviving rows outright). Retired sets: y (r89 w1+w2),
x-partial (r88), w (r87), v (r86), u (r85), t (r84), r/s (r83), q1–q18 (r82), pa–pg (r81), ob/oc (r80),
na (r79), ma/mb (r78), la/lc/lb (r77).

**Campaign-closure note (the definition-of-done met):** the row census prints NOTHING — the sugar-crush
hardening plan is COMPLETE. The MATCHUPS 🟡/🔴 port-completion backlog rides the MATCHUPS board itself (add-a-lib
checklist per lib, one lib per wave) — it was never a ledger row and stays out of this map. The standing
STOP list (no push / no removal of unfinished code / no prompt_*.md / no blanket-LLM-timeout / E639-class
decisions ask first) and the trigger-watch roster survive verbatim at §0-NOW-94 §2/§3. The harness laws stay
in force for any future operator-opened work: serials PLAIN BASH PIPE (NEVER tmux/setsid/PTY); re-pin crush
figures ONLY when sugar-crush files move (r89w2 re-proved the re-pin shape: +34T serial → README/suite-figure
hand-bump → green serial → refresh-suite-figure, at `cf456a306`); `scripts/parallel-tests-durations.tsv` is
SUGAR-CRUSH-ONLY (now 536 rows — 4 y5 files landed in-step); `failOnWarning` UNIVERSAL (58/58); the
SwallowingCatch gate law stays in every src-touching brief's gate list; SHARD GATE (s4/E737): shards launch
with `SUGARCRUSH_MCP_DISABLE=1`, launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php`;
no lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `scripts/parallel-tests.sh`, or the figure files
without re-arming the census family. ERA-CORRECTION stamped at this close: five-guard is **45T/4,768A**
(the 125T/7,362A cited through the RESUME tables was stale era); DocFigure-alone 83T/2,685A; crush windows
re-derived by junit classname grep at the weld: `Form|Input|Select|Cursor` 712/4,516 (633/3,943 pre-y5),
`Vt|Terminal|Buffer` 305/21,034, `Shine|Glamour|Style` 24/813. Config-md5 at-rest truth
**`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld). **LINK CENSUS: sugar-crush 19/19 + candy-pty 8/8.**

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-94 seam/watch roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation names a sibling lib.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-bits`, …) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠`-free note: `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed actionable.

## Row census (exact command + verbatim output, re-run at the filemap regen)

```
$ awk -F'|' '/^## `OPEN`/{sec="OPEN"} /^## `PARTIAL`/{sec="PARTIAL"}
    /^## `STALE-CITATION`/{sec="STALE"} /^## `UNCERTAIN`/{sec="UNCERTAIN"}
    /^## `SUPERSEDED`/{sec=""} /^\| \*\*E/{gsub(/^ +| +$/,"",$4);
    if (sec!="" && $4 !~ /^\*\*CLOSED/) print sec" "$2}' docs/plans/crush_code_backlog_triage.md
$   # NOTHING — ACTIONABLE = 0. PLAN COMPLETE.
```

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| —  | — | — | — |

**ZERO rows.** Both wave-1 survivors closed at wave-2: E736 by y6 (last unbuilt plan row shipped), E744 by y5
(six workstreams shipped + re-pinned). See §0-NOW-94 for the final state table and §2 for the discretionary
seam ledger (y5: chat.quit description clip / picker `'/'`-filter ruling / painted-chrome pixel-stability /
InputProjection convergence-by-ruling; y6: headphones-pin vacuity note / :phone: prose nuance) — seams and
watches are NOT rows.
