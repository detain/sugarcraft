# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `4da36922a` — **FULL REGEN from scratch at the round-70 close** (supersedes the
`5e3e1ce83` r69 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 8 rows below): ACTIONABLE = 8 BY ROW CENSUS**
(OPEN-table 5 / PARTIAL-table 3 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E204, E325, E493, E611, E686` + `E25, E134, E390`) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-70 CLOSE** census paragraph
(five lanes across two waves — ga/gb/gd/ge wave-1, gc wave-2 — minus 6 CLOSED-in-place rows: E10, E172,
E199, E309, E353, E616; renoted still actionable: E375 DECIDED-confirmation, E390→PARTIAL law-pin,
E493→PARTIAL providers-half; rulings E616 DENIAL_SHAPE-bytes-unchanged, E10 keep-lowercase,
E309 CLOSED-VERDICT open-by-design, E353 CLOSED-BY-FOLD).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E493 and E686 carry PARTIAL stamp prose
but sit physically in the triage OPEN table and count there (E686's r68 precedent; E204/E325 likewise);
the `stamp` column below shows each id's backlog-heading disposition, so the map itself reads
7 PARTIAL + 1 OPEN. CLOSED rows are PRUNED at this regen (their closeout record lives in the triage rows +
worklog rounds — the map is a scheduling aid, not the history). Round-70 lane letters
(**ga, gb, gc, gd, ge**) are RETIRED at this close; `⚠` marks below name the retired lane that LANDED in a
still-actionable row's file (collision history for round-71 scheduling). Round-71 lane ownership
(**gf–gh**) is defined in `crush_code_RESUME.md` §0-NOW-72 §2 — `src/Chat.php` is FREE this round (ga's
E199 wiring landed; no active reservation). Tier/lane analysis lives in
`docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain` column below is a file-cluster
bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-70 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠ga`–`⚠ge` = RETIRED round-70 lane letter — marks a file where that lane landed this round while the row
  stays actionable (gb on `ChildWallClockBudgetTest.php`, gc on the `src/Providers/` heartbeat set, gd on
  `DocFigureProseDriftTest.php`). Earlier retired-wave marks (⚠fa–⚠fn, ⚠ea, ⚠da..⚠ks) are
  dropped; their collision history lives in the worklog.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r70 status → round-71 |
|---|---|---|---|---|---|---|
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 verified r68/ec; p2 PROJECT-scope writer = DESIGN CARRY — unowned (§0-NOW-72 carry; pick or drop at next close) |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S | SUPERVISOR-PENDING — judged again at r70 close, no new fact; supervisor owns disposition |
| E204 | PARTIAL | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M | SUPERVISOR-PENDING — closeout-docs own the actionable half; supervisor judges |
| E325 | PARTIAL | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php;sugar-crush/tests/Cli/HelpTest.php;sugar-crush/tests/VhsTapeContractTest.php;sugar-crush/tests/Support/SlicesDeclaredMethodsTrait.php | tests-harness | M | BOTH prescribed directions shipped fd (r69); remainder = 12 inline-slice readers, unchecked-by-design today — no §2 lane, carry |
| E390 | PARTIAL | HIGH | sugar-crush/tests/Support/ChildWallClockBudgetTest.php⚠gb;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php;sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php | tests-harness | M | gb (`fdeddc1f3`, salvage path) pinned the same-file-literal-only law + tripwire arm `testTheResolverRefusesCrossFileLiteralShapesToKeepTheSameFileLaw`; licensé drop BLOCKED on the byte-identical pair → **gf** as ONE motion (dedupe pair → drop licensé → flip roster trio in-step; ⚠ the tripwire reddens any order separating widening from drop) |
| E493 | PARTIAL | HIGH | sugar-crush/src/Backend/EngineBackend.php;sugar-crush/src/Runtime.php;sugar-crush/src/Providers/⚠gc;sugar-crush/src/Backend.php | providers | M | gc (`d7be5733f`) shipped PROVIDERS-half: `CompleteRequest::$onHeartbeat` + `heartbeatOptions()` (Sglang/Custom, 1/s throttled progress, fail-soft) + `ProviderHeartbeatProgressTest` 8T; consumer-threading (EngineBackend/Runtime from the child frame-writer) + `src/Backend.php` `$onEvent` docblock drift → **gh**; ⚠ enumerate every census on touched files BEFORE the brief (bd/-67 lesson) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); re-derive before launching |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠gd;sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md | tests-harness | M | gd (`8b639e092`) shipped tranche-7: arms AC–AH (32→38), 22 claims judged ALL TRUE zero FALSE (docs byte-untouched), E353 HOOKS.md CLOSED-BY-FOLD; tranche-8 = ~9.5 HELD figures per `/home/sites/crush-r61-artifacts/gd/measures.md` carry-dispositions (9 carry rows + 3 labeled-external HOOKS holds) → **gg**; ⚠ GlobDialect corpus law: re-shape glob-shaped literals before any re-pin (gd's M7) |

## Domain index

| domain | n | ids |
|---|---:|---|
| tests-harness | 3 | E325, E390, E686 |
| docs | 2 | E134, E204 |
| other | 2 | E25, E611 |
| providers | 1 | E493 |

Sum = 8 ✓ (equals the table's row count).

## Cross-file collision clusters (≥3 actionable ids sharing a file)

**None at this cut** — the residual 8 rows are thin enough that no file carries 3 actionable ids
(r69's Bootstrap/Chat clusters closed with ga/gc). Two-way overlaps and single-owner notes below:

- **`sugar-crush/tests/Support/`** — E390 (→ **gf**, owns ChildWallClockBudgetTest + DuplicatedTestHelperDriftTest + the two literal-pair files) and E325 (carry) both cite `tests/Support/`; gf's edit set is named-file-specific, no live collision unless E325 gets a lane.
- **`sugar-crush/src/Backend/EngineBackend.php` + `src/Runtime.php`** — E493 (→ **gh**) is the only live row; gc's providers-half landed in `src/Providers/` beneath the same `heartbeatOptions()` shape — gh threads FROM the shipped carrier, do not re-design it.
- **gf ↔ gg ↔ gh are mutually file-disjoint** as §2 scopes them (gf = the E390 four-file set; gg = DocFigureProseDriftTest + docs; gh = Backend/EngineBackend/Runtime + Providers call sites as needed).

**Notes (single-owner guards, new files, and carried seams — §0-NOW-72 §2 is the assignment authority):**

- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — single-owner guard file (di's r67 polity, then gb's roster edit); **gf** takes it next for the E390 licensé deletion — one lane at a time.
- **`sugar-crush/tests/Cli/StderrEmitterCensusTest.php`** — ga flipped the `:55` ELEVEN→TWELVE quote IN-STEP with the Chat.php wiring (`109293485`); the fe-era stale-quote seam is CLOSED.
- **`sugar-crush/tests/Providers/ProviderHeartbeatProgressTest.php`** — NEW from gc (r70, E493 providers-half, 8T; the +1 durations row `504` total). Consumer-side pins ride **gh** there.
- **Seams carried (from the r70 lane reports):** `tests/Backend/AwaitPromiseDiagnosticArmTest.php:525` private `matching()` copy (fourth copy — fold onto canonical `TokenFunctionRanges::matching()`; ga's remaining seam, small, rides any lane entering tests/) · E611 design-carry (supervisor-harness scope, not a code lane) · E25 piece-2 design-carry (PROJECT-scope importer) · `sugar-crush/src/Backend.php` `$onEvent` docblock names 2 event classes (r67 carry) → folded into **gh** · `MultiAgentRefactorTest:423` tokenless `throwing-` team ids (fe-era residue; any lane entering tests/Agents).
- **Unowned rows:** E25 (p2), E134, E204, E325, E611 — §0-NOW-72 carries them verbatim ("pick or drop at the next close — re-derive before launching"); E134/E204 are supervisor-owned-file dispositions, E325/E25p2/E611 are design/trigger carries.

*Derived from `docs/plans/crush_code_backlog_triage.md` (four open-family section tables, row census) + `docs/plans/crush_code_hardening_backlog.md` headings + `docs/plans/crush_code_RESUME.md` §0-NOW-72 §2 at `4da36922a`; round-70 closeout.*
