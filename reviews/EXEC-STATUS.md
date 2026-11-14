# EXEC-STATUS — review→plan→EXEC program final census rollup

Generated 2026-11-14/15 by the FINAL CENSUS + ROLLUP lane (read-only forensics; this file is the lane's only write).
Inputs: `plan_review.queue.tsv` (3,238 data rows, col-2 target), the full commit corpus (18,961 commits, `git log --format='%H%x1f%ad%x1f%s%x1f%b'` plus `--name-only` touched-dirs dump), `reviews/OWNER-GATES.md`, `reviews/P5-GATE-PACKET-2.md` (`reviews/OWNER-GATE-PACKET.md` and `reviews/DECISIONS-PACKET.md` do not exist on disk), per-target `reviews/annotations/*.md` covers/closes/Findings headers, and per-lane dossiers under `/tmp/opencode/*-exec-1113|1114/`.

Method: for every queue row-id token, whole-word subject+body grep (CENSUS-v4) with padded numeric variants, per-target dir-scoring, subject-lead anchoring, annotation finding-alias expansion (covers/closes/Findings, including padded 3-digit finding shapes like `gifview-020`, `tetris-025`, `sugar-dashboard-098`, the `MDV-SNN`↔`MDV-NN` form, the `ssrv-NN`↔`SSHS-NN` dual shapes, and queue-title cover-maps), era floor 2026-11-08 (pre-era hits disclosed separately), then residual-by-residual manual adjudication against dossiers. Known traps screened: substring false positives (`msteams-01` inside `msteams-013`, `RT-01` inside unrelated bodies, `ST-37` inside `PB-TOAST-37`), phantom SHAs in lane prose, subject-mislabels, absorbed multi-row commits.

## TOTALS

| bucket | rows | note |
|---|---|---|
| **queue rows (col-2 target set = 68 targets)** | **3,238** | |
| git-proven landed | 2,838 | incl. 7 pre-era (2026-11-05/06 harvest-wave) landings, 11 dossier content-proven reclassifications below |
| gated / parked (packet + queue owner_gate + dossier parks) | 92 | 10 packet-matched from residual set (incl. tetris 20/21/27/42, flappy 08/21, vterm-09, CH-56, ST-69, SD-S34, D-90) |
| owner-side (candy-code/** = 168+1rdrs, candy-code-web = 31+1, ccweb-parity 18, Windows/macOS legs, host-keyspace fans, closeout WELD-TRIO) | 225 | |
| report-only riders + verified no-ops | 29 | 19 queue RIDERS rows + 10 adjudicated no-op/verified/phantom/absorbed rows below |
| **ACTIONABLE RESIDUAL** | **54** | enumerated in full at the bottom — the program is NOT drained to zero |

## Contradictions (loudly reported)

1. **sugar-dashboard — declared CLOSED queue-zero; disk proves 45 never-landed rows.** The lane chain landed 101 of 165 rows (gated 18 incl. packet D-90 + D3-composer owner-gates + blocked D-111/D-145; D-160 phantom). The 45 open middle rows (D-24…D-99, D-128/135/137/151) cite NO landing: their D-token has zero whole-word hits anywhere in the 18,961-commit corpus AND each row's `covers sugar-dashboard-NNN` finding number has zero hits in ANY commit message repo-wide. The DASH-rung HANDOFF itself classed the middles as "sibling D1/unowned"; no sibling lane ever executed them. These are real work (layout/render-correctness/perf fixes with file:line seats in `reviews/annotations/sugar-dashboard.md`).
2. **candy-mdview — declared FULLY CLOSED 26/26; MDV-S19 + MDV-S20 never landed.** No commit cites either token (all forms checked); `candy-mdview/` has zero commits after `eed4a8936` (2026-11-13, itself a follow-up); the dir log shows S01–S18 + S21–S24 each landed once, S19/S20 absent — and MDV-S05's own body says "S19 owns the final reconciliation", a promise never kept on disk. S20's title marks `(G6)`; if G6 is an owner gate the row is gated, not closed.
3. **sugar-readline — declared CLOSED with remaining=NONE except S-19-G3; S-48 is unlanded.** `sugar-readline/src/Ansi.php` exists, has zero commits in the EXEC era (last touched 2026-11-03), and carries no `SugarCraft\Core` delegation — the row (delegate to sugar-core, covers 072) was never executed.
4. **candy-top — ADOPTION lane CLOSED, but 6 S-X rows remain actionable.** Not a silent contradiction: the lane's own closeout `REPORT.md:37` lists them "Unlanded, non-parked: … S-X13 … S-X16 … S-X17 … S-X18 … S-X19, S-X20 …" and flags "S-X18 needs era-line re-anchor". Disk confirms zero citations. Counted in the residual list.
5. **Phantom-SHA class confirmed again (memory/prose vs object DB):** `51946a856` and `6f998815e` (discord-01/03 booking shas) are ABSENT; the rows are nonetheless LANDED with content proof at `30b8eccba` (approver gate on pairing) and `716f835d8`+`d4075435b` (bundle CI cells). `fb652c759`/`425065af8` (flappy-era) likewise ABSENT from the object DB — the two flappy rows in question are packet-GATED anyway, not landed.
6. **Minor count drifts vs the orchestrator's closed-list notes** (not disk contradictions): queue truth is sugar-gallery 38 rows all accounted (the "S-26 hold" id has no queue row; SG-S33 attribution stamp 922d3b158 present); mcp queue name is sugar-mcp-client (41); mail queue = 69 (harvest-note said 78); candy-codetime (63) appears as a queue target never named in the closed list — 63/63 landed, zero residual.

## Landed-by-dossier reclassifications (mechanical token gap closed with content proof)

| row | proof |
|---|---|
| candy-gifview:gifview-13 | `3cc82b615` copy sweep subject cites `(gifview-020/021/022/045)` compound form |
| candy-kv:KV-S45 | `e42624ab0` "candy-kv: S45 — pt becomes European Portuguese" (bare-suffix lead) |
| sugar-fuzzy:FUZZY-01/02/03 | `3c8189dfc` cures exactly the three named SahilmMatcherTest oracles (#96-#98) |
| repo-tooling:RT-01 | `ecd1ca25f` "ci: kill matrix.lib script-injection in ci.yml run bodies (SEC-01 #92)"; RT dossier records the content-proof |
| sugar-diff:SD-01 | `ff092fe0f` kills the O(k²) array_shift storm in SideBySideFormat (#103); disk `:157` confirms cursor consumption |
| sugar-diff:SD-29 | repo-root rule rewritten by `b9b241e67` (SF-16, same .claude/rules/composer-conventions.md seat) — absorbed |
| candy-discord:discord-01/03 | `30b8eccba` / `716f835d8`+`d4075435b` |
| sugar-metrics:MET-JSONSTREAM | absorbed into `04b268845`, attribution stamp `5d5882da1` proves it |
| sugar-cellbuffer:CB-02/06/07/08/09/10/14 | real pre-era (2026-11-05/06) subject-lead landings (e.g. `f0db6f388` CB-10, `0a215d029` CB-14) |

## Report-only / verified-no-op dispositions (excluded from residual)

- candy-vcr VCR-S46/S56 — `[root seat]` rows, report-only per vcr close; VCR-S48…S52 — Windows legs, owner-side.
- candy-msteams 01/03/13/17 — honest-zero (pre-lane verification, msteams close ledger); 07/09 — host-side (PLUGINS.md / host keyspace; lane REPORT lines 39/41) → owner-side.
- candy-mail CM-59 — guards already tested at HEAD (transport test files carry cancel pins).
- sugar-log SL-20 — PHANTOM finding (sample line never existed; byte forensics per log close).
- sugar-pty PTY-43 — healed-prior (marker leg on disk, `InteractiveShellTestCase.php:54`; pty dossier :38).
- sugar-vterm vterm-35 — Windows-lane CI leg → owner-side (win/macOS exclusion rule).
- sugar-dashboard D-160 — phantom finding (cited files never existed, dash rung-3 adjudication).

## Per-target table

| target | rows | landed | gated/parked | owner-side | report-only | ACTIONABLE |
|---|---|---|---|---|---|---|
| candy-ansi-inspect | 42 | 42 | 0 | 0 | 0 | 0 |
| candy-code | 169 | 0 | 0 | 168 | 1 | 0 |
| candy-code-web | 32 | 0 | 0 | 31 | 1 | 0 |
| candy-codeshot | 35 | 35 | 0 | 0 | 0 | 0 |
| candy-codetime | 63 | 63 | 0 | 0 | 0 | 0 |
| candy-discord | 27 | 27 | 0 | 0 | 0 | 0 |
| candy-files | 55 | 54 | 1 | 0 | 0 | 0 |
| candy-flappy | 90 | 85 | 5 | 0 | 0 | 0 |
| candy-gifview | 26 | 25 | 0 | 0 | 1 | 0 |
| candy-git | 65 | 61 | 4 | 0 | 0 | 0 |
| candy-git-server | 48 | 48 | 0 | 0 | 0 | 0 |
| candy-image | 28 | 28 | 0 | 0 | 0 | 0 |
| candy-kv | 47 | 45 | 1 | 0 | 1 | 0 |
| candy-mail | 69 | 68 | 0 | 0 | 1 | 0 |
| candy-mdview | 25 | 22 | 0 | 0 | 1 | 2 |
| candy-minesweeper | 40 | 40 | 0 | 0 | 0 | 0 |
| candy-msteams | 27 | 21 | 0 | 2 | 4 | 0 |
| candy-scriptui | 85 | 85 | 0 | 0 | 0 | 0 |
| candy-sql | 76 | 75 | 0 | 0 | 1 | 0 |
| candy-ssh-launcher | 43 | 43 | 0 | 0 | 0 | 0 |
| candy-starter | 18 | 17 | 1 | 0 | 0 | 0 |
| candy-tetris | 44 | 39 | 5 | 0 | 0 | 0 |
| candy-top | 105 | 98 | 0 | 0 | 1 | 6 |
| candy-vcr | 76 | 61 | 7 | 5 | 3 | 0 |
| ccweb-parity | 18 | 0 | 0 | 18 | 0 | 0 |
| phar-fleet | 1 | 1 | 0 | 0 | 0 | 0 |
| repo-tooling | 50 | 49 | 1 | 0 | 0 | 0 |
| site-docs | 35 | 34 | 1 | 0 | 0 | 0 |
| sugar-animation | 27 | 18 | 8 | 0 | 1 | 0 |
| sugar-ansi-parser | 22 | 21 | 0 | 0 | 1 | 0 |
| sugar-async | 38 | 37 | 0 | 0 | 1 | 0 |
| sugar-cellbuffer | 37 | 37 | 0 | 0 | 0 | 0 |
| sugar-charts | 59 | 57 | 1 | 0 | 1 | 0 |
| sugar-cli | 31 | 31 | 0 | 0 | 0 | 0 |
| sugar-colorprofile | 56 | 54 | 2 | 0 | 0 | 0 |
| sugar-constraints | 19 | 17 | 2 | 0 | 0 | 0 |
| sugar-core | 45 | 44 | 1 | 0 | 0 | 0 |
| sugar-dashboard | 165 | 101 | 18 | 0 | 1 | 45 |
| sugar-datepicker | 36 | 36 | 0 | 0 | 0 | 0 |
| sugar-diff | 29 | 26 | 3 | 0 | 0 | 0 |
| sugar-flexbox | 51 | 51 | 0 | 0 | 0 | 0 |
| sugar-focus | 17 | 16 | 0 | 0 | 1 | 0 |
| sugar-forms | 57 | 54 | 2 | 0 | 1 | 0 |
| sugar-fuzzy | 31 | 30 | 1 | 0 | 0 | 0 |
| sugar-gallery | 38 | 38 | 0 | 0 | 0 | 0 |
| sugar-image | 55 | 55 | 0 | 0 | 0 | 0 |
| sugar-input | 41 | 41 | 0 | 0 | 0 | 0 |
| sugar-log | 48 | 42 | 5 | 0 | 1 | 0 |
| sugar-markdown | 61 | 60 | 0 | 0 | 1 | 0 |
| sugar-mcp-client | 41 | 41 | 0 | 0 | 0 | 0 |
| sugar-metrics | 39 | 33 | 6 | 0 | 0 | 0 |
| sugar-mouse | 36 | 36 | 0 | 0 | 0 | 0 |
| sugar-navstack | 31 | 26 | 5 | 0 | 0 | 0 |
| sugar-overlay | 38 | 38 | 0 | 0 | 0 | 0 |
| sugar-panels | 36 | 36 | 0 | 0 | 0 | 0 |
| sugar-pty | 55 | 53 | 1 | 0 | 1 | 0 |
| sugar-quickpick | 40 | 40 | 0 | 0 | 0 | 0 |
| sugar-readline | 67 | 66 | 0 | 0 | 0 | 1 |
| sugar-spring | 36 | 36 | 0 | 0 | 0 | 0 |
| sugar-ssh-server | 37 | 36 | 0 | 0 | 1 | 0 |
| sugar-style | 42 | 41 | 1 | 0 | 0 | 0 |
| sugar-table | 79 | 76 | 3 | 0 | 0 | 0 |
| sugar-testing | 27 | 26 | 0 | 0 | 1 | 0 |
| sugar-toast | 34 | 34 | 0 | 0 | 0 | 0 |
| sugar-treelist | 33 | 33 | 0 | 0 | 0 | 0 |
| sugar-video | 76 | 76 | 0 | 0 | 0 | 0 |
| sugar-vterm | 41 | 32 | 7 | 1 | 1 | 0 |
| sugar-widgets | 48 | 47 | 0 | 0 | 1 | 0 |
| **TOTAL** | **3,238** | **2,838** | **92** | **225** | **29** | **54** |

## ACTIONABLE RESIDUAL LIST (54 rows — the successor queue; do-not-execute here: census lane)

### sugar-readline (1)
- `S-48` — Ansi.php: delegate to sugar-core · covers 072. File untouched since 2026-11-03; no Core import on disk.

### candy-mdview (2)
- `MDV-S19` — LOW×16 cluster · README + i18n surface completion (the parity reconciliation S05 deferred to it: "S19 owns the final reconciliation").
- `MDV-S20` — README/body.html/.vhs/vhs-tape/help prose sweep · LAST-WRITER `(G6)` — execute only if G6 proves not an owner gate; adjudicate first.

### candy-top (6 — all listed "Unlanded, non-parked" by the adopt lane's own closeout report)
- `S-X13` — Shell UXD four-pack.
- `S-X16` — Shell SEC trio.
- `S-X17` — Help git deadline escalation + loop-thread IO measure/disclose.
- `S-X18` — Windows data-path trio (report note: needs era-line re-anchor before execution).
- `S-X19` — Shell perf four-pack.
- `S-X20` — Config/data honesty trio.

### sugar-dashboard (45 — file:line seats live in reviews/annotations/sugar-dashboard.md; several rows carry annotated prereq chains incl. owner-gated ones, e.g. D-24 behind D-15(OG), so a successor must serialize D-43→D-47, D-44→D-45 and re-verify prereqs before executing)
- `D-24` covers 031 — RatioGrid :112-115, addShadow :227-255, FlexLayout :370-372 clip, Layout zero-alloc path.
- `D-25` covers 019 — StackLayout :126,143-147.
- `D-31` covers 048 — DrilldownTree :275,326-333.
- `D-32` covers 032 — Stack/StackLayout/VStack/ZStack/Panel size-propagation legs.
- `D-37` covers 030 — 13 render paths (Layout :172-206, Panel :109-188, Sidebar, Window, Frame, Stack).
- `D-41` covers 121(+143) — Boxer :136-161 stale-findNode read, persistState/restoreState 3-copy family.
- `D-43` covers 034(+132,217) — TileLayout :150-177, Resolver :94-99,170 dead-helper leg.
- `D-44` covers 035 — FlexLayout :192-244.
- `D-45` covers 038 — FlexLayout :571-595 match arms + max() on empty.
- `D-47` covers 041 — Resolver :170,77 minSizeFit leg (coordinate lock: D-43 first).
- `D-51` covers 044(+144,148,151) — GridTable :130-134,337-352 dead `$totalFiltered`.
- `D-52` covers 045 — GridTable :68-72 perPage=height-2, chrome emit set.
- `D-53` covers 046 — Support/Calc :24-28,56-60 overflow-lie returns minimums.
- `D-54` covers 047 — DrilldownTree :253-282,301,315,329-333.
- `D-56` covers 050 — Dropdown :86-95 closed trigger, setSize clone no-op :63-66.
- `D-57` covers 051 — Select :24-28,79-84 height stored-never-read; ComboBox leg.
- `D-58` covers 052 — Tabs :129-141.
- `D-61` covers 058 — GridTable :337-391 full refilter per repaint (perf memo seam).
- `D-62` covers 149 — GridTable sort comparator + Sort-era remnants.
- `D-66` covers 060(+166,182) — Modal :131-145,187-196, Drawer :267,283 guarded padders.
- `D-67` covers 061 — Modal :126,170-179 byte str_pad vs cell budget, Width::string discard.
- `D-68` covers 063 — Modal :36,53,357 interior build sites.
- `D-69` covers 064 — ListModal :73-81,127-146,151 (+wither legs from D-18 carry).
- `D-70` covers 069(+173,182) — ConfirmModal/MultiselectModal/ProgressModal contract migration.
- `D-71` covers 062 — Drawer title/footer never rendered, borderColor :147-243.
- `D-77` covers 072 — Popover :124-136 title neither padded nor clipped, width discard.
- `D-80` covers 075(+206) — Clock :34-42,74 12h-vs-docblock lie, time() no seam.
- `D-81` covers 180 — Timer/Stopwatch withElapsed seconds-vs-milliseconds same name.
- `D-82` covers 076(+077) — BrailleMatrix :33-42,74-79 duplicated row-map vs 4-row cells.
- `D-83` covers 078(+193,238,186) — BrailleCanvas :144-165 per-dot double-grid copy.
- `D-84` covers 079 — Braille Canvas :102-108,257-315 setSize stored-never-read, defaultFg/Bg dead.
- `D-85` covers 084 — ScatterPlotTest :227-232 vacuous substr_count pin.
- `D-86` covers 085 — DendrogramTest :1004-1010 45-line fixture asserting '│' presence only.
- `D-87` covers 157 — Dendrogram :240-310 connector geometry computed-never-used.
- `D-88` covers 086 — Bresenham :97-117 octant table ABS-vs-indexing mismatch.
- `D-92` covers 082(+183,184,197,234) — Plot.php :100-371 draw/render near-duplicates.
- `D-93` covers 185 — Plot/Graph range footguns (no min≤max guard; auto-range).
- `D-94` covers 195 — Plot.php :60-66,399-412.
- `D-97` covers 191(+194) — legend/label surfaces Graph.php :226-237, Donut :586-603, Partition :398-400.
- `D-98` covers 192 — Chart.php :194-216 hidden mutable frame/delta-history across renders.
- `D-99` covers 087(+211) — Modules name() hardcoded per type + Module/Registry leg.
- `D-128` covers 125 — Breakpoint :36-45 (+brand-name docblock leg).
- `D-135` covers 135 — Layout/Panel border color TOP-only, wrapText newline flatten.
- `D-137` covers 138 — Foundation/Theme inverted dependency arrows (imports Plot/Components).
- `D-151` covers 170 — Highlight :455-468 merged regex rebuilt per line (~100 alternations).

## Rider / owner-side counts (for the record)
candy-code/** 169 (incl. WELD riders), candy-code-web 32, ccweb-parity 18, plus Windows/macOS legs (candy-vcr×5, sugar-vterm×1, sugar-msteams host-keyspace fans×2) and trio/ci.yml:621 weld rows (closeout:WELD-TRIO). zh-tw copy rows sit inside their target's gated columns (e.g. tetris-27, KV-S45-era waves) or the adopt-adjacent report.

Verdict: the program is 87.6% landed + 2.8% gated + 6.9% owner-side + 0.9% report-only; **54 queue rows (1.7%) remain genuinely actionable**, concentrated in sugar-dashboard's unowned middles, and three CLOSED-claims (dashboard, mdview, readline) are contradicted by disk as detailed above.
