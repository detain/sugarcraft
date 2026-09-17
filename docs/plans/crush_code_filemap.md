# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `06f658684` + stamps `3a425d78d` (round-89 wave-1 chain: base `eea60561a`
(r88 filemap) → 20 picks — y1 `9bc98d6b8`; y2 `2fc904421`/`e20ab706b`/`8058799b4`; y3 `f8ceefad3`/
`ba62ac88a`/`e0394c223`/`1c384241c`/`3720d1cd9`/`b3b5c3dc5`/`fafe04ea7`/`d4a55399a`/`ef8b7db66`/
`0dd6fb147`/`02a1b3b4d`/`8ac653b5a`; y4 `85a0a7e29`/`9354aee92`/`ad5c969eb`/`06f658684` — all four
lanes rv-APPROVE 0C/0M, ZERO conflicts, ZERO sugar-crush files) — **FULL REGEN at the round-89
wave-1 close** (supersedes the round-88 `eea60561a`-base cut); regenerate this file at every
round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 2 rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage
section tables (awk strict-prefix survivor recount, exact command + output pasted below and at
`crush_code_RESUME.md` §0-NOW-93 §1; r88 left 4 survivors — the round-89 wave-1 re-cuts CLOSED **§E742**
(y1 canonical xterm palette per operator ruling m0519) and **§E743** (y2 computeBarLayout fold), built
forms cluster-A (y3) and shine 7.1/7.3 (y4) inside the E736 umbrella → post-census **2**) and
cross-confirmed against the picks. **Zero mints, two whole-row closes at this wave.** The
survivor recount returns exactly 2, never chained.

CLOSED rows are PRUNED (E742/E743 at this regen). Round-89 lane letters **y1 y2 y3 y4** — all four
RETIRED (files landed; y1/y2 retired their own rows, y3/y4 landed inside the E736 umbrella which stays
actionable for its single unbuilt row). Retired sets: y (r89w1), x-partial (r88), w (r87), v (r86),
u (r85), t (r84), r/s (r83), q1–q18 (r82), pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77).

**Scheduling warnings (the round-89 wave-2 point):** §E744 is the one real code lane queued — it
TOUCHES `sugar-crush/src/Chat.php` + `src/Renderer.php` → the full census family re-arms
(StderrEmitter/NoRawAnsi/BootstrapLaunchFormat per prior laws) + suite-figure/durations re-pin domain;
cut it ONLY on operator direction, builders commit-EARLY (r88 law — vindicated at r89: 20/20 picks,
zero phantoms). §E736's residual is now exactly ONE unbuilt plan row — shine 7.2 emoji-shortcode map
(net-new feature) — plus the standing MATCHUPS 🟡/🔴 port-completion backlog and the ruling/trigger
classes; none is defect work. Re-derive row anchors at lane base before building (zany-beige-roadrunner
law). Weld briefs must enumerate lane commits via `git log` at authoring time, never pre-baked SHAs
(r88 law).
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson;
§E744's scope hits Chat.php — census trio + DocFigure re-arms mandatory in-brief).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) stays in every
src-touching brief's gate list. The serial census law: r89 wave-1 moved ZERO crush test files — tests
figure EXACT-CARRY 6th round (12,027) — and per the zero-move doctrine NO re-pin was performed; the
green serial read 170,514A (+92 over the pinned 170,422 — live-derivation tree-scan recount family,
slightly over the ±50 band estimate; README/suite-figure.json untouched, guard re-derivation green).
Durations 532 rows HELD (zero new crush test files). Each weld gates the touched libs FULL + targeted
families + five-guard 125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php`.
`scripts/parallel-tests-durations.tsv` is SUGAR-CRUSH-ONLY. `failOnWarning` UNIVERSAL (58/58) —
forms/vt/bits/layout/shine/glow all 0W at this weld. Harness law UNCHANGED: serials run through the
PLAIN BASH-TOOL PIPE — NEVER tmux/PTY. Determinism law (r82-q9) unchanged; forms ×2 and shine ×2
byte-identical prove async hermeticity. WALK-FORM pin law (r87-w2) and STRIPPED-geometry law (r87-w2/rv)
unchanged. Era-correction landed at this weld: the crush shine-family window `Shine|Glamour|Style`
measures **24T/813A** (the 921/59,234 RESUME-era figure was stale). The per-section ledger-edit law
(r77 lc) governed this closeout (backlog: scoped per-section edits, +5/−2; triage: three in-place cell
flips +3/−3; worklog: one inserted section +13/−0; RESUME: banner swap + region replace +15/−20; pointer
1/1; far canaries intact — `### E744`, `### E741`, ROUND-80…88 headings, APPENDIX I–V).
Config-md5 at-rest truth **`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld).
**LINK CENSUS: sugar-crush 19/19 + candy-pty 8/8** — fresh sandboxes verify the census BEFORE briefing.

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-93 roster, normalized to repo-root paths:

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
OPEN  **E736** 
OPEN  **E744** 
```

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E736 | OPEN ⚠y3 ⚠y4 ⚠x4 ⚠x5 ⚠x6 (umbrella — E742/E743 CLOSED at r89w1, cluster-A + shine 7.1/7.3 BUILT inside; single UNBUILT row remains) | shine 7.2 emoji-shortcode map: `candy-shine/src/` (new shortcode table + `emojize()` surface), `candy-shine/tests/`, `docs/` row; layout docblock fold `candy-layout/src/CassowarySolver.php` `Expression` freeze-blocked seam; MATCHUPS 8 🟡 + 2 🔴 ports per add-a-lib checklist (one lib per wave). Ruled-with-trigger classes ride the row: forms Ph5 declined-with-trigger (5.16/5.17 facts), vt 6.2 gated-no-build, layout 1.2 / charts 3.1 / bits 4.3 STOP-class declines | sibling-lib umbrella |
| E744 | OPEN (minted r88 weld — rv-x5 MINOR-1; features BUILT at r88+r89, host half still absent) | `sugar-crush/src/Chat.php` (`delegateToInput` ~:12123 Cmd drop, Ctrl+C pre-empt, `insertString` ~:1586 PasteMsg, input paint ~:4231), zone/mouse forwarding, OSC52 writer, `LoadMoreMsg` consumption, readonly-aware key precedence, view-surface convergence (`TextArea::view` vs Renderer paint) — census family (StderrEmitter/NoRawAnsi) re-arms in-step; FULL re-pin domain (Chat.php/Renderer.php touching) | crush host-seam |
