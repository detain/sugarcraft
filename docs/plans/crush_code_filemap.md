# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-16 @ code tip `7a3280759` (round-84 W1 weld chain: base `2f770abb1` → picks
`e0bff28cc`(t2)/`5499c50bd`(t3)/`676e597d7`(t4)/`bb2e28cfb`(t6)/`35f327d5b`(t1)/`7a3280759`(t5,
figure-amended pre-ff)) — **FULL REGEN from scratch at the round-84 close** (supersedes the round-83
`c9a0cafff`-base cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 2 rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-86 §2(a); pre-flip 3 → the r84 flip **E735** → post-census **2**: **E731 E736**) and cross-confirmed
against the triage CLOSED flips (round-84 lanes t1–t6, six picks one weld — **E735 CLOSED**: 03.09
`t1 837763041`→`35f327d5b` +3 defect fixes, 06.01 `t5 7e28b3c9e`→`7a3280759` verify-only (amended figure:
`--filter Subscription` 14T/38A, class alone 12T/36A), 10.06 `t2 17e1036cb`→`e0bff28cc` DiffHighlighter,
10.16 `t3 7031e17dc`→`5499c50bd` C1 truth pass, 10.25 `t4 dd1fec6c0`→`676e597d7` GlamourTheme/reload/width,
goldens-cure `t6 39671bedd`→`bb2e28cfb` (68d73f9ba family fully swept, zero stale blues tree-wide); ALL
reviewed APPROVE-class; remaining 13 phase-12 steps PAUSED-BY-USER, 03.13 NOT-ACTIONABLE via the E731
r7 dedup). **Zero mints at this close.** The survivor recount returns exactly 2, never chained.

CLOSED rows are PRUNED at this regen (E735's close record lives in the triage row + backlog §E735 + worklog
ROUND 84 — the map is a scheduling aid, not the history). Round-84 lane letters **t1**…**t6** are RETIRED at
this close: every file they touched lands inside a CLOSED row or a deliberate un-minted note; no `⚠` marks
are owed. t joins the retired sets r/s (r83), q1–q18 (r82), pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78),
la/lc/lb (r77).

**Scheduling warnings (the round-85 point):** the two survivors are BOTH product-completion umbrellas, not
defect work. E731 lives entirely in `sugar-dash/` (largest sibling suite, 5,977T — budget serial time in any
lane brief; the `examples/progressRing.php` broken import rides as a record-only note in backlog §E737 —
cheap companion fix if a dash lane opens). E736 is `docs/MATCHUPS.md` + per-port full-scaffold touchpoints —
**cut ONE lib per wave** (first-cut candidates per plan status not-started/8-phases: **sugar-bits** or
**candy-forms**); the 13 phase-12 rows are PAUSED BY USER — never lane them without a new directive. Before
opening either, take the §0-NOW-86 §2(c) OPERATOR DECISION POINT (port wave vs campaign close). **No lane may
touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`, `scripts/parallel-tests.sh`, or the
figure files without re-arming the census family** (s4's lesson: three of four W3 files there are
census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r84
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — the r84 weld ran the FULL green serial (12,027/172,610/1S/exit0) as the
whole-tree truth AND K=8 conservation green. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php` — any
new MCP-launch test MUST use the trait or it reddens only in-shard. **NEW r84 facts:** (1)
`scripts/parallel-tests-durations.tsv` is SUGAR-CRUSH-ONLY — sibling-lib test files never belong in it; the
manifest holds **532** rows with an empty set-diff both directions at this weld; (2) `failOnWarning` is
UNIVERSAL (58/58) — every lane gate expects ZERO Warnings, a surfaced warning is a finding, not noise; (3)
`CallbackAuthoredRefusalTest` dataset "Refused" (:92) flaked ONE of two full K=8 runs (serial + isolated +
re-run green) — trigger-watch, E655 pollution family. Harness law UNCHANGED: serials run through the PLAIN
BASH-TOOL PIPE — NEVER tmux/PTY (stdin-pin trio). Determinism law (r82-q9) unchanged. The per-section
ledger-edit law (r77 lc wipe) governed this closeout's flips (backlog: scoped per-section split + assert-1 +
numstat 8/2; triage: single anchored line swap 1/1; RESUME: whole-region replace + banner line + verbatim
awk block restored after an escaping slip, re-diffed byte-equal to the house command; root pointer:
single-paragraph 2/1; far canaries intact — `### E740`, ROUND-69 CLOSE, SUPERVISOR DISPOSITIONS all still
present, census command self-verified by re-execution → `E731 E736`). Census-prose errata law (r83-w3):
stamps embed the RECOUNT COMMAND, pick messages are not amended (the t5 figure fix was an exception by
design — folded via `commit --amend` BEFORE ff, the only clean window). Config-md5 at-rest truth
**`d96e124ee7967eb34ef479ef824231ad`** (start==end held across the whole r84 weld, both trees, both
worktrees porcelain-clean at every checkpoint). Fresh-worktree vendor law (r78) + FULL linked refresh incl
candy-pty (r76 lesson, 18/18+7/7 verified at this weld) stand in the restart recipe.

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the ROUND-84 CLOSED census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citing row names a sibling lib explicitly (E731's `src/Plot/Chart/` + `SystemModule.php` are sugar-DASH citations — normalized to the named lib; do not let the default rule mis-bucket sibling rows).
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-stash`, `sugar-spark`, `sugar-glow`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed
  actionable. NONE owed at this regen (see retirement paragraph).

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E731 | OPEN | sugar-dash/src/Plot/Chart/ (35-file family, no shared AbstractChart base), sugar-dash/src/System/SystemModule.php (COMP-2 `/proc` leg :157) | sugar-dash architecture |
| E736 | OPEN | docs/MATCHUPS.md (+ per-port AGENTS adding-a-lib touchpoints: root composer.json, MATCHUPS, PROJECT_NAMES, root README, docs/index.html, docs/_data/<slug>.{json,body.html} + gen-docs, media/icons, vhs.yml, codecov.yml) | port completion |

Sum: 2 rows = census 2. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| sugar-dash architecture | 1 | E731 |
| port completion | 1 | E736 |

**docs/MCP.md collision cluster: CLOSED (r77–r81) — zero remaining actionable rows cite it.**
`sugar-crush/src/Cli/Bootstrap.php` + `sugar-crush/src/Chat.php` — zero remaining actionable citations;
s4 `757327bdd` landed the MCP_DISABLE gate in Bootstrap.php under the full census battery (formats,
stderr emitters, launch constants all rode unflipped) — treat both files as census-guarded for any future
lane.
