# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-16 @ code tip `c9a0cafff` (round-83 wave-3 chain: upstream base `e2818fd15` → picks
`f45b52a21`(s2)/`a04c947c5`(s3)/`7d5b03f9d`(s1)/`757327bdd`(s4) + companion `99727c5f6` + sextet re-pin
`c9a0cafff`) — **FULL REGEN from scratch at the round-83 close** (supersedes the round-82 `3079dbea2`-base
cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 3 rows below): ACTIONABLE = 3 BY ROW CENSUS**
(OPEN-table 3 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-85 §2(a); pre-flip 7 → the four r83-w3 flips E737/E738/E739/E740 → post-census **3**: **E731 E735
E736**) and cross-confirmed against the triage CLOSED flips (round-83 lanes r1–r11 + s1–s4, three waves —
SIXTEEN rows CLOSED this round: **E732** r3 `5ea8bdc94`→`3acd2e76d`, **E733** r1 `5e979e696`→`141d5e227` +
completion r5 `e4f6e7e23`→`76218c66e`, **E734** r2 `c093c58bd`→`06cc8a7e9`, **E737** r4 `8725ea5ec`→
`5c815306c` + r11 `16249b740`→`30760dae6` + s4 `12470eb32`→`757327bdd`, **E738** r9 `c58168027`→`59ec5a6e3`
+ r10 `14c73a0fb`→`d4e4df464` + s1/s2/s3 (→58/58), **E739** s1 `e86525c4a`→`7d5b03f9d`, **E740** s2
`056366f62`→`f45b52a21`; plus **E729** CLOSED-declined r8→`4545142c7` (design doc) and **E730** CLOSED r6
`176a15725`→`40646bf86`; ALL reviewed APPROVE-class). **Zero mints at this close.** The survivor recount
returns exactly 3, never chained.

CLOSED rows are PRUNED at this regen (E729–E732, E734, E737–E740 close records live in the triage rows +
backlog §-paragraphs + worklog ROUND 83 — the map is a scheduling aid, not the history). Round-83 lane
letters (**r1**…**r11**, **s1**…**s4**) are RETIRED at this close: every file they touched lands inside a
CLOSED row or a deliberate un-minted note; no `⚠` marks are owed. r (W1/W2) and s (W3) joins the retired
sets pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77), q1–q18 (r82).

**Scheduling warnings (the round-84 point):** the three survivors are UMBRELLAS — E731 lives entirely in
`sugar-dash/` (largest sibling suite, 5964T — budget serial time in any lane brief); E735 spans
`docs/plans/leftover/` + four DIFFERENT libs (its four build steps are file-disjoint BY DESIGN — 03.09
dash, 10.06 stash, 10.16 spark, 10.25 glow can run as parallel t* lanes; the 13 phase-12 rows are PAUSED
BY USER — never lane them without a new directive); E736 is `docs/MATCHUPS.md` + per-port full-scaffold
touchpoints (one lib per wave). **No lane may touch `sugar-crush/src/Cli/Bootstrap.php`,
`sugar-crush/src/Chat.php`, `scripts/parallel-tests.sh`, or the figure files without re-arming the census
family** (s4's lesson: three of four W3 files there are census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r83
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — r83's wave-3 weld ran the FULL green serial (12,027/172,610/1S/exit0) as
the re-pin truth AND ran K=8 conservation +0 on the final tree. NEW SHARD GATE (s4/E737): shards launch
with `SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php`
— any new MCP-launch test MUST use the trait or it reddens only in-shard (37-test collision was the s4
headline lesson). Harness law UNCHANGED: serials run through the PLAIN BASH-TOOL PIPE — NEVER tmux/PTY
(stdin-pin trio). Determinism law (r82-q9) unchanged. The per-section ledger-edit law (r77 lc wipe)
governed this closeout's flips (backlog: scoped per-section split + assert-1 + numstat 11/3; triage: four
per-line anchored swaps 4/4; RESUME: whole-region replace + banner line; root pointer: single-line 1/1;
far canaries intact — `zero confirmed unescaped` count unchanged, census command self-verified by
re-execution). Census-prose errata law (r83-w3): stamps embed the RECOUNT COMMAND, pick messages are not
amended. Config-md5 at-rest truth **`d96e124ee7967eb34ef479ef824231ad`** (operator `e2818fd15` superseded
`05480c74…2210`; start==end held across the whole weld, both trees). Fresh-worktree vendor law (r78) +
FULL linked refresh incl candy-pty (r76 lesson, 18/18+7/7 verified at this weld) stand in the restart
recipe. Durations manifest is **532** rows (531→532 at this weld, set-diff exactly
+`sugar-crush/tests/Cli/BootstrapMcpDisableEnvTest.php`).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the ROUND-83 CLOSED census paragraph, normalized to repo-root paths:

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
| E735 | OPEN | docs/plans/leftover/ (step-03-09/step-06-01/step-10-06/step-10-16/step-10-25 + PAUSED phase-12 ×13 + not-actionable step-03-13), docs/plans/leftover/updates.md, findings/candy-mosaic.md (stale §1 re-mark folded in), sugar-dash/src/ + sugar-stash/src/ + sugar-spark/src/ + sugar-glow/src/ (the four DO-builds, file-disjoint per lane) | leftover rollout |
| E736 | OPEN | docs/MATCHUPS.md (+ per-port AGENTS adding-a-lib touchpoints: root composer.json, MATCHUPS, PROJECT_NAMES, root README, docs/index.html, docs/_data/<slug>.{json,body.html} + gen-docs, media/icons, vhs.yml, codecov.yml) | port completion |

Sum: 3 rows = census 3. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| sugar-dash architecture | 1 | E731 |
| leftover rollout | 1 | E735 |
| port completion | 1 | E736 |

**docs/MCP.md collision cluster: CLOSED (r77–r81) — zero remaining actionable rows cite it.**
`sugar-crush/src/Cli/Bootstrap.php` + `sugar-crush/src/Chat.php` — zero remaining actionable citations;
s4 `757327bdd` landed the MCP_DISABLE gate in Bootstrap.php under the full census battery (formats,
stderr emitters, launch constants all rode unflipped) — treat both files as census-guarded for any future
lane.
