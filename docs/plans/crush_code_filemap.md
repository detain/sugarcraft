# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-15 @ code tip `3079dbea2` (wave-3b chain `c66df93b9`/`37db286ba`/`b797b6d39`/`fb9f813ac`/
`3d8c0d533`/`fb7ec3ac5` + companions `395bfe60a` + `3079dbea2`) — **FULL REGEN from scratch at the round-82
close** (supersedes the `c489210e6` r81 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — ZERO rows below): ACTIONABLE = 0 BY ROW CENSUS —
PHASE-4 COMPLETE** (OPEN-table 0 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the
four triage section tables (awk strict-prefix survivor recount, exact command + EMPTY output pasted in
`crush_code_RESUME.md` §0-NOW-84 §2(a); pre-census 6 (the closeout-mint rows E714 carried + E724–E728 minted
at `e765b1742`) → post-census 0) and cross-confirmed against the triage **ROUND-82 CLOSE** census paragraph
(round-82 lanes q1–q18, six waves — EIGHTEEN rows CLOSED this round: **E711** q1 `42a46a3e6`→pick
`3336bea5f`, **E712** q2 `35430bfb2`→`05f155145`, **E713** q3 `15cb07ec6`→`2fc01a77e`, **E715**
q4 `044dac54a`→`8df8c26d8`, **E719** q6 `3ca9273ab`→`174a12447` (wave-1, fold `5c3bb3d1c`); **E716** q7
`e49040de3`→`364c43b16`, **E718** q8 `700cbb2fb`→`57a270cb1`, **E720** q5 `d57b34b5e`→`3d053be7b`
(CLOSED-MARKS), **E723**-dash-half q9 `c1a914e64`+`09c58d6c5`→`2dfeaf3b4`+`87c3f5ab4` (repairs `d51bb2a6f` +
fold `269101e30`); **E717** q10 `291b822e0`→`38a284716`, **E721** q11 `17c7bb29b`→`4436e7ebc`, **E722** q12
`d894850d3`+`e59435108`→`4f6637d1c`+`bd07426d2` (wave-3, reword `61d7d7deb`, stamps `6c2faaaac`); **E714**
q13 `2960463a6`→`c66df93b9`, **E724** q14 `955c04f85`→`37db286ba`, **E725** q15 `2b12ead28`→`b797b6d39`,
**E726** q16 `8ce0df6ce`→`fb9f813ac`, **E727** q17 `22734813c`→`3d8c0d533`, **E728** q18 `051675b6e`→
`fb7ec3ac5` (wave-3b, companions `395bfe60a`/`3079dbea2`); ALL reviewed APPROVE-class). **Zero mints at
this close.** The survivor recount returns exactly 0, never chained.

CLOSED rows are PRUNED at this regen (E711–E728 close records live in the triage rows + backlog §-paragraphs
+ worklog ROUND 82 — the map is a scheduling aid, not the history). Round-82 lane letters (**q1**…**q18**)
are RETIRED at this close: every file they touched lands inside a CLOSED row — no `⚠` marks are owed: a
retired lane marks only a file where it landed while the row stayed ACTIONABLE, and none did (the one
disclosed cross-lane file-family overlap — q15's eight CellGrid test rebinds inside q14's candy-vcr, DISJOINT
files AND disjoint hunks — merged ZERO-conflict, exactly as both lanes predicted). pa–pg (r81), ob/oc (r80),
na (r79), ma/mb (r78), la/lc/lb (r77) stay retired.

**Scheduling warnings (the round-83 point):** there are NO schedulable lanes — the audit-derived ledger is
EMPTY end to end across ALL FOUR phases (P1 r61–r76, P2 r77–r80, P3 r81, P4 r82). The operator (m0225)
BANKED the completion; future options are documented-but-unmandated in RESUME §0-NOW-84 §2(c) (~110
leftover-rollout steps; MATCHUPS 8🟡/2🔴; q5 LOW/perf residuals ❗ in `findings/`; deferred FLAGs — vt
dual-Cell/Buffer unification, wish async-core, dash AbstractChart/COMP-2, mosaic sixel-default). Do NOT
mint lanes without that call. Trigger-watch (NOT rows — each names its own mint condition): **NEW r82 —
candy-vcr phpunit.xml lacks `failOnWarning`** (own-measured-lane candidate); **NEW r82 — sugar-dash
Meter.php:89/GaugeCircle.php:170 render clamps** (E726-4 family); **NEW r82 — wish `SHELL=` set-but-empty
direct pin**; **NEW r82 — wish stale README PHP badge in the BADGES managed block**; **NEW r82 — q15
DECSCUSR pass-through note**; **NEW r82 — q10 hand-typed carrier list reflection-arm candidate**;
**pa paste-while-modal behavioral pin**; **ReadPathCensus backslash-prefix blind spot**; **pf
maxOutputTokens threading seam**; **E696-deny-residual** (a `denyPatterns` producer must ship the deny half
ENFORCED); **updateRegistration() redirect-churn** seam (nd §2.2); **E309** DenialKind product question;
**E611** tripwire; **E694/E25** re-severity gate; **E655 VOID** phantom ban; **LspClientDispatchPumpTest**
ambient flake; **Chat.php:8266** beginTurn wiring seam.

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r82
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — r82's FINAL weld is its negative-control demonstration: the whole-tree
serial ran GREEN UNCHANGED (11,991/172,291/1S/exit0) because the round shipped ZERO sugar-crush files, and
q15's `Vt|Terminal|Buffer` filter re-measured byte-identical (288T/20970A). HARNESS LAW unchanged: serials
run through the PLAIN BASH-TOOL PIPE — NEVER tmux/PTY (stdin-pin trio). NEW DETERMINISM LAW (r82-q9,
rv MAJOR): never single-shot `proc_get_status` after a pipe-EOF drain — kernel closes child stdio BEFORE
exit_notify; poll in 10ms ticks (~2s cap) or reap-first via `proc_close`. The per-section ledger-edit law
(r77 lc wipe) governed this closeout's flips (backlog: scoped per-section split + assert-1 + numstat 13/6 +
far-canaries `zero confirmed unescaped`/E723-intact; triage: six per-line anchored swaps 6/6; RESUME:
whole-region replace with the census block restored VERBATIM after a paste mangling was caught by re-run;
root pointer: single-line 1/1 after a truncation slip was caught by numstat and reverted via `git checkout
--`). Sibling-lib rounds touch ZERO sugar-crush figures — `suite-figure.json` (529 durations rows) stands
untouched all round. Config md5 truth `05480c743aff302fd6c06c5a4a4c2210` (start==end at the r82 weld).
Fresh-worktree vendor law (r78) + FULL linked refresh incl candy-pty (r76 lesson, 18/18+7/7 verified at
this weld) stand in the restart recipe.

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the ROUND-82 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-glow`, `sugar-readline`, `sugar-skate`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed
  actionable. NONE owed at this regen (see retirement paragraph).

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| — | (empty — ACTIONABLE = 0) | — | — |

Sum: 0 rows = census 0. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| — | 0 | (none) |

**docs/MCP.md collision cluster: CLOSED (r77–r81) — zero remaining actionable rows cite it.**
`sugar-crush/src/Cli/Bootstrap.php` + `sugar-crush/src/Chat.php` — zero remaining actionable citations
(unchanged since r81; r82 shipped NO sugar-crush files at all).
