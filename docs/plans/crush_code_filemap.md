# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `ab7f3726a` — **FULL REGEN from scratch at the round-74 close** (supersedes the
`f46c203d6` r73 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 2 rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E611, E686`, both physically in the triage OPEN table) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-74 CLOSE** census paragraph
(one code lane if + zero-commit verdict lane ie — ONE row CLOSED-in-place this close: E25; E686 renoted
with the tranche-11 work list ie minted and r74-rv-ie confirmed 8/9).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686 carries PARTIAL stamp prose but
sits physically in the triage OPEN table and counts there (E686's r68 precedent unchanged);
the `stamp` column below shows each id's backlog-heading disposition, so the map itself reads
2 PARTIAL-stamped rows (E611 is OPEN; E686 is PARTIAL prose in the OPEN table). CLOSED rows are PRUNED
at this regen (E25's close record lives in the triage row + worklog ROUND 74 — the map is a scheduling
aid, not the history). Round-74 lane letters (**ie, if**) are RETIRED at this close (ie never owned a
file — zero-commit verdict lane); `⚠if` marks below name the retired lane that LANDED in a
still-actionable row's file (collision history for round-75 scheduling): if touched
`tests/Config/DocFigureProseDriftTest.php` (the fold-policy arm DISTINCT-scope census widening, pick
`72079870d`) and `sugar-crush/docs/MEMORY.md` (the ProjectMemoryWriter prose + the closeout's loud
asymmetry sentence); its `src/Context/` files need no mark — no surviving row cites them. Round-73 marks
(⚠hc) are dropped — MEMORY.md/DocFigure history lives in the worklog. Round-75 lane
ownership (**ja–jb**) is defined in `crush_code_RESUME.md` §0-NOW-76 §2 — `src/Chat.php` and
`src/Renderer.php` carry no active reservation. Note: **jb is a DESIGN lane** — E694 does not exist yet
(the if seams — project-scope list/search/delete/clear/edit, null-home-store fold, agent memory tool —
ride jb's mint-or-fold decision), so it owns no actionable row and gets no table row here. Tier/lane
analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column
below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-74 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠if` = RETIRED round-74 lane letter — marks a file where that lane landed this round
  while the row stays actionable (see header).

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r74 status → round-75 |
|---|---|---|---|---|---|---|
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); re-derive before launching; r74's ie verdict lane NEVER touched it — the pick-or-drop **SLIPS to THE r75 CLOSE** (honest slip recorded at the backlog row) |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠if;sugar-crush/docs/ARCHITECTURE.md;sugar-crush/docs/AGENTS_AUTHORING.md;sugar-crush/docs/PROMPT_ENGINEERING.md;sugar-crush/docs/COMMANDS.md;sugar-crush/README.md | tests-harness | L | ie's completeness verdict (zero commits, `f46c203d6`) OVERTURNED hc's "docs seed list EMPTY" — **TRANCHE-11 MINTED**, CONFIRMED 8/9 by r74-rv-ie (erratum E1: `prompt_expand.md` IS tracked at the monorepo root — §9.12 cite valid-but-unguarded). Work list per `/home/sites/crush-r61-artifacts/ie/verdicts.md`: **T11-a** ARCHITECTURE (heal 5 bare line anchors + Providers arm derived from `availableTypes()` + absence-guard on BOTH anchor shapes `(line \d+)`/`file.php:\d+`, HOOKS.md:264 self-narrative exempt) · **T11-b** AGENTS_AUTHORING + COMMANDS (2 prose heals — "eleven built-in tools" FALSE since E675 `b636591b6`, "not merely cosmetic" anchor+quote FALSE — + roster arms) · **T11-c** PROMPT_ENGINEERING (cross-page pair arms reusing AP/BE derivations). **ASSIGNED round-75 lane ja**; ~12–16 arms / ~6 prose fixes. Carries: AU/BD keep-dup precedents (check an existing pin before adding an arm), GlobDialect glued-literal law (PathGlob corpus `131,765 = 365×361` byte-untouched), blame-verified gd row-22 erratum. **E686's CLOSE verdict rides ja's success** |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 1 | E686 |
| other | 1 | E611 |

Sum = 2 ✓ (equals the table's row count).
