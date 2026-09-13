# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `3deab0b6f` — **FULL REGEN from scratch at the round-72 close** (supersedes the
`328b14d91` r71 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 3 rows below): ACTIONABLE = 3 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 1 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E611, E686` OPEN-table + `E25` PARTIAL-table) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-72 CLOSE** census paragraph
(two lanes ha–hb, BOTH reviewed BEFORE merge — r72-rv-{ha,hb} APPROVE; minus 1 CLOSED-in-place row:
E693 (hb `179f0b37e`); E686 renoted, stays — tranche-9 landed).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686 carries PARTIAL stamp prose but
sits physically in the triage OPEN table and counts there (E686's r68 precedent unchanged);
the `stamp` column below shows each id's backlog-heading disposition, so the map itself reads
2 PARTIAL (E25, E686) + 1 OPEN (E611). CLOSED rows are PRUNED at this regen (their closeout record
lives in the triage rows + worklog rounds — the map is a scheduling aid, not the history). Round-72 lane
letters (**ha, hb**) are RETIRED at this close; `⚠` marks below name the retired lane that
LANDED in a still-actionable row's file (collision history for round-73 scheduling). Round-73 lane
ownership (**hc–hd**) is defined in `crush_code_RESUME.md` §0-NOW-74 §2 — `src/Chat.php` and
`src/Renderer.php` carry no active reservation. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a file-cluster
bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-72 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠ha` (+`⚠hb`) = RETIRED round-72 lane letter — marks a file where that lane landed this round
  while the row stays actionable (ha on `DocFigureProseDriftTest.php` — tranche-9 arms AS–BA + the AV
  vacuity fix `d75ce3af7`, and on `src/Backend.php` — the MCP.md/BP prose lines + the BA `$onEvent`
  live-arm). Earlier retired-wave marks (⚠gf–⚠gi, ⚠gg2, ⚠fa–⚠fn, ⚠ea, ⚠da..⚠ks) are
  dropped; their collision history lives in the worklog.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r72 status → round-73 |
|---|---|---|---|---|---|---|
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 verified r68/ec; p2 PROJECT-scope writer = DESIGN CARRY — unowned (§0-NOW-74 carry; pick or drop at the r73 close) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); re-derive before launching; pick-or-drop at the next close per the r72 carry |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠ha;sugar-crush/src/Backend.php;sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md | tests-harness | L | ha (`868221189`+`d75ce3af7`) shipped tranche-9: arms AS–BA (+9T/+244A, DocFigure 47→**56**) — the brief's PROVIDERS.md/ANTHROPICS.md/TOOLS.md roster measured **PHANTOM** (files do not exist; docs/ = 13 pages), so the tranche worked SKILLS.md + MCP.md + `src/Backend.php`: MCP.md M13 line-number FALSE anchor healed + pinned in AZ, the `$onEvent` roster is now the BA live-arm (docblock cites ↔ `complete()` `@param` ↔ `encodeEvent` param ↔ `decodeEvent` return, four sets bidirectional, import-verified + `class_exists`), AV digit asserts bound to captured doc figures (vacuity mutation-caught → fix pick); AU MINOR dup with `PathsGlobDocumentationTest` — SUPERVISOR DISPOSITION KEEP as-is (unique `paths:` verbatim-quote leg). **tranche-10 = remaining docs pages per ha/measures.md carry → hc**; hc MUST check for an existing pin before adding an arm (AU-duplication precedent); ⚠ GlobDialect corpus law: PathGlob `131,765 = 365×361` byte-untouched, re-shape glob-shaped literals before any re-pin (ha re-proved it live: 368×364 → chr(42)/concat fix) |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 1 | E686 |
| other | 2 | E25, E611 |

Sum = 3 ✓ (equals the table's row count).
