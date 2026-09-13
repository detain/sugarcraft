# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `328b14d91` — **FULL REGEN from scratch at the round-71 close** (supersedes the
`4da36922a` r70 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 4 rows below): ACTIONABLE = 4 BY ROW CENSUS**
(OPEN-table 3 / PARTIAL-table 1 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E611, E686, E693` OPEN-table + `E25` PARTIAL-table) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-71 CLOSE** census paragraph
(four lanes gf/gi/gg/gh merged by a session that died before its reviews — the successor's
review-after-merge salvage, ADDENDUM -73: every review APPROVE, one MAJOR healed by the gg2 review-fix
pick `00bab5d21`; minus 5 CLOSED-in-place rows: E134/E204 (CLOSED-BY-PRACTICE `913d6f5b5`), E325 (gi
`571dcf85d`), E390 (gf `0c6820f39`), E493 (gh `9d05c5e9f`); minted: E693 from gi's residue).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686 carries PARTIAL stamp prose but
sits physically in the triage OPEN table and counts there (E686's r68 precedent unchanged);
the `stamp` column below shows each id's backlog-heading disposition, so the map itself reads
2 PARTIAL (E25, E686) + 2 OPEN (E611, E693). CLOSED rows are PRUNED at this regen (their closeout record
lives in the triage rows + worklog rounds — the map is a scheduling aid, not the history). Round-71 lane
letters (**gf, gg, gg2, gh, gi**) are RETIRED at this close; `⚠` marks below name the retired lane that
LANDED in a still-actionable row's file (collision history for round-72 scheduling). Round-72 lane
ownership (**ha–hb**) is defined in `crush_code_RESUME.md` §0-NOW-73 §2 — `src/Chat.php` and
`src/Renderer.php` carry no active reservation. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a file-cluster
bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-71 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠gf`–`⚠gi` (+`⚠gg2`) = RETIRED round-71 lane letter — marks a file where that lane landed this round
  while the row stays actionable (gg/gg2 on `DocFigureProseDriftTest.php` — tranche-8 arms + the arm-AP
  review-fix; gi on `tests/SuiteSkipRosterTest.php` — the E325 fold left the double-`getMethod` residue
  that minted E693). Earlier retired-wave marks (⚠ga–⚠ge, ⚠fa–⚠fn, ⚠ea, ⚠da..⚠ks) are
  dropped; their collision history lives in the worklog.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r71 status → round-72 |
|---|---|---|---|---|---|---|
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 verified r68/ec; p2 PROJECT-scope writer = DESIGN CARRY — unowned (§0-NOW-73 carry; pick or drop at the r72 close) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); re-derive before launching |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠gg⚠gg2;sugar-crush/src/Backend.php;sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md | tests-harness | L | gg (`7924075c7`) shipped tranche-8: arms AI–AR (AQ skipped), 2 ARCHITECTURE.md FALSE fixes guarded by re-introduction mutations; the successor review caught arm AP's hand-typed layer roster (MAJOR, M10 `RuleLoader`→`RuleReader` survived) and the gg2 fix `00bab5d21` made the arm DERIVE its roster from the doc text it polices (DocFigure 47T/1449A); tranche-9 = per-arm figures in PROVIDERS.md / ANTHROPICS.md / TOOLS.md / SKILLS.md / MCP.md + gd/gg `measures.md` HELD carry → **ha**; ALSO ha absorbs the `src/Backend.php` `$onEvent` roster LIVE-ARM (gh rewrote the docblock truthfully, claim unpinned — M4; moved from hb to keep the lanes DocFigure-disjoint) ; ⚠ GlobDialect corpus law: PathGlob `131,765 = 365×361` byte-untouched, re-shape glob-shaped literals before any re-pin (gd's M7) |
| E693 | OPEN | LOW | sugar-crush/tests/SuiteSkipRosterTest.php⚠gi | tests-harness | S | MINTED at the r71 close from gi's E325 residue — the rostered-skip body indexes `getMethod($method)` twice by hand (`:513-514`) where the canonical `SlicesDeclaredMethodsTrait` (`571dcf85d`) exists → **hb** fold |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 2 | E686, E693 |
| other | 2 | E25, E611 |

Sum = 4 ✓ (equals the table's row count).

## Cross-file collision clusters (≥3 actionable ids sharing a file)

**None at this cut** — the residual 4 rows are thin; no file carries 3 actionable ids. Two-way overlap and
single-owner notes below:

- **`sugar-crush/tests/Config/DocFigureProseDriftTest.php`** — E686 (→ **ha**) is the only live owner. The
  COLLISION the regen caught: hb's `$onEvent` live-arm was scoped as a DocFigure-style arm, which would
  have put ha and hb in the same file — RESOLVED by moving the live-arm INTO ha (§0-NOW-73 §2); hb keeps
  only the two test-side items and is now disjoint from ha.
- **ha ↔ hb are mutually file-disjoint** as §0-NOW-73 §2 scopes them (ha = DocFigureProseDriftTest + the
  five docs pages + the Backend.php docblock; hb = SuiteSkipRosterTest + MultiAgentRefactorTest).

**Notes (single-owner guards, new files, and carried seams — §0-NOW-73 §2 is the assignment authority):**

- **New files this round:** `sugar-crush/tests/Support/RunsWallClockBoundedChildTrait.php` (gf, E390
  completion — owns BOTH byte-identical const pairs `CHILD_WALL_CLOCK_BUDGET_SECONDS` 20/20 +
  `KILLED_BY_THE_BUDGET` 137/137 and the sole `timeout -s KILL` wrapper; DuplicatedTestHelperDrift
  ACCEPTED_CONST_DUPLICATION licensés 23→21, documented deviation) ·
  `sugar-crush/tests/Backend/EngineBackendHeartbeatThreadingTest.php` (gh, E493 consumer, 4T/13A fork
  pins; the +1 durations row → 505 total).
- **`sugar-crush/tests/Config/DocFigureProseDriftTest.php`** — single-owner guard file; **ha** takes it
  next (tranche-9 + `$onEvent` live-arm). No other lane may enter it this round.
- **Seams carried (from the r71 lane reports + reviews):** `tests/Backend/AwaitPromiseDiagnosticArmTest.php:525`
  private `matching()` copy — **DONE via gh** (folded onto `TokenFunctionRanges::matching()`) ·
  `sugar-crush/src/Backend.php` `$onEvent` docblock — truth-rewrite **DONE via gh**, its ROSTER LIVE-ARM
  → **ha** · gi's E325 residue — **MINTED as E693** → **hb** · `MultiAgentRefactorTest:423` tokenless
  `throwing-` team ids (fe-era residue) → **hb** · E611 design-carry (supervisor-harness scope, not a
  code lane) · E25 piece-2 design-carry (PROJECT-scope importer) · E309 open-by-design trigger watch
  (ge's r70 verdict).
- **Unowned rows:** E25 (p2), E611 — §0-NOW-73 carries them verbatim ("pick or drop at the r72 close —
  re-derive before launching"); both are design carries, not code lanes.

*Derived from `docs/plans/crush_code_backlog_triage.md` (four open-family section tables, row census) + `docs/plans/crush_code_hardening_backlog.md` headings + `docs/plans/crush_code_RESUME.md` §0-NOW-73 §2 at `328b14d91`; round-71 closeout.*
