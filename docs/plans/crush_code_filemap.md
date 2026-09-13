# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `f46c203d6` — **FULL REGEN from scratch at the round-73 close** (supersedes the
`3deab0b6f` r72 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 3 rows below): ACTIONABLE = 3 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 1 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E611, E686` OPEN-table + `E25` PARTIAL-table) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-73 CLOSE** census paragraph
(single code lane hc + zero-commit verdict lane hd — ZERO rows CLOSED-in-place this close; E686 renoted,
stays — tranche-10 landed, docs seed list DECLARED empty pending ie's completeness verdict).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686 carries PARTIAL stamp prose but
sits physically in the triage OPEN table and counts there (E686's r68 precedent unchanged);
the `stamp` column below shows each id's backlog-heading disposition, so the map itself reads
2 PARTIAL (E25, E686) + 1 OPEN (E611). CLOSED rows are PRUNED at this regen (their closeout record
lives in the triage rows + worklog rounds — the map is a scheduling aid, not the history). Round-73 lane
letters (**hc, hd**) are RETIRED at this close (hd never owned a file — zero-commit verdict lane);
`⚠hc` marks below name the retired lane that LANDED in a still-actionable row's file (collision history
for round-74 scheduling). Earlier retired-wave marks (⚠ha, ⚠hb, ⚠gf–⚠gi, ⚠gg2) are dropped; their
collision history lives in the worklog. Round-74 lane
ownership (**ie–if**) is defined in `crush_code_RESUME.md` §0-NOW-75 §2 — `src/Chat.php` and
`src/Renderer.php` carry no active reservation. Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a file-cluster
bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-73 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠hc` = RETIRED round-73 lane letter — marks a file where that lane landed this round
  while the row stays actionable (hc on `DocFigureProseDriftTest.php` — tranche-10 arms BB–BH + the
  review-fix `c616d5738` sentence-shape pin, and on `sugar-crush/docs/MEMORY.md` — the two healed FALSE
  sentences). Round-72 marks (⚠ha on `src/Backend.php`) are dropped — Backend.php prose is byte-stable
  since ha; history in the worklog.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r73 status → round-74 |
|---|---|---|---|---|---|---|
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 verified r68/ec; p2 PROJECT-scope writer = **ASSIGNED round-74 lane if** — DESIGN-FIRST brief: read the E25 backlog row + `MemoryBlock.php` piece-1 state, enumerate censuses/ownership BEFORE touching code; owns `src/Context/` + Runtime seams + tests; DTO-enumeration rule; no wall-clock timeouts (E646 ban) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); re-derive before launching; **pick-or-drop AT THE r74 CLOSE** per the standing carry |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠hc;sugar-crush/src/Backend.php;sugar-crush/tests/;sugar-crush/docs/⚠hc;sugar-crush/README.md | tests-harness | L | hc (`41834f960`+review-fix `c616d5738`) shipped tranche-10: arms **BB–BH** on `docs/MEMORY.md` — the LAST un-armed docs page — +7T/+198A, DocFigure 56→**63**; TWO FALSE healed in-step (containment five→six call sites naming `loadAncestorRoots` per canonical `ContainedPathInventoryTest::ROUTED_CALL_SITES`; threading list += `Grep` — exactly five `instructionLoader:` sites); review r73-rv-hc APPROVE 0C/0M/2MINOR (BD 512-anchor leg KEPT per AU precedent; dead `$memo[2]` capture healed → literal sentence-shape pin, M8 reddens EXACTLY BC). **The campaign's docs seed list is now DECLARED EMPTY — round-74 lane ie re-derives that emptiness INDEPENDENTLY (all docs pages + gd/gg/ha/hc measures ledgers + guard families) and RULES: CLOSED or mint tranche-11; do not close blind.** Carry to ie: hc's 8 HELD rows (`/home/sites/crush-r61-artifacts/hc/measures.md`); ⚠ GlobDialect corpus law: PathGlob `131,765 = 365×361` byte-untouched (hc stayed clean — no glob-shaped literals needed this tranche) |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 1 | E686 |
| other | 2 | E25, E611 |

Sum = 3 ✓ (equals the table's row count).
