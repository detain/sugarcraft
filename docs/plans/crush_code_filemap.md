# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-12 @ tip `5e3e1ce83` — **FULL REGEN from scratch at the round-69 close** (supersedes the
`378cb9fa6` r68 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 14 rows below): ACTIONABLE = 14 BY ROW CENSUS**
(OPEN-table 10 / PARTIAL-table 4 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E10, E199, E204, E309, E325, E353, E493, E611, E616, E686` + `E25, E134, E172, E390`) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-69 CLOSE** header paragraph
(13 lanes across two waves; r68's 49 minus 35 rows stamped CLOSED-in-place; ten rows renoted still
actionable; rulings E375→B-QUALIFIER, E199→PER-TURN, E611→design-carry, fj's Chat.php breach ACCEPTED).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686 carries PARTIAL stamp prose but sits
physically in the triage OPEN table and counts there; the `stamp` column below shows each id's backlog-heading
disposition, so the map itself reads 9 PARTIAL + 4 OPEN + 1 DEFERRED. CLOSED rows are PRUNED at this regen
(their closeout record lives in the triage rows + worklog rounds — the map is a scheduling aid, not the
history). Round-69 lane letters (**fa, fb, fc, fd, fe, ff, fg, fh, fi, fj, fk, fl, fn**) are RETIRED at this
close; `⚠` marks below name the retired lane that LANDED in a still-actionable row's file (collision history
for round-70 scheduling). Round-70 lane ownership (**ga–ge**) is defined in `crush_code_RESUME.md`
§0-NOW-71 §2 — `src/Chat.php` is RESERVED for **ga** (fj's r69 breach is CLOSED — do not repeat it: gc owns
Bootstrap, NOT Chat). Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated
here; the `domain` column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-69 CLOSE header, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `size`: S = 1 path, M = 2–4 paths, L = 5+ paths or cross-domain.
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠fa`–`⚠fn` = RETIRED round-69 lane letter — marks a file where that lane landed this round while the row
  stays actionable (fb on `tests/DenialPrefixRosterTest.php`, fc on the Doctor/CommandLoader pins, fd on the
  slice-reader set, fj on `src/Chat.php`, fk on the E390 pair, fl on `DocFigureProseDriftTest.php`, fn on
  `RuntimeNoticeSink.php`, fa on the E493 record sites). Earlier retired-wave marks (⚠ea, ⚠da..⚠ks) are
  dropped; their collision history lives in the worklog.

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r69 status → round-70 |
|---|---|---|---|---|---|---|
| E10 | PARTIAL | HIGH | sugar-crush/src/Tools/BuiltIn/Doctor.php⚠fc;sugar-crush/tests/Tools/BuiltInToolTest.php⚠fc | tools-skills | M | record branch pinned fc; rename half → **gc** (tool-schema owner) |
| E25 | PARTIAL | MED | sugar-crush/src/Context/MemoryBlock.php;sugar-crush/tests/Context/MemoryBlockTest.php | other | M | p1 verified r68/ec; p2 PROJECT-scope writer = design item — unowned carry (§0-NOW-71) |
| E134 | PARTIAL | MED | docs/plans/crush_code_worklog.md | docs | S | UNOWNED (fh-brief-listed, not in fh's landed scope) — supervisor assignment; re-derive before launching |
| E172 | PARTIAL | HIGH | sugar-crush/src/Commands/CommandLoader.php⚠fc;sugar-crush/src/Cli/Bootstrap.php;sugar-crush/tests/Cli/BootstrapLaunchFormatConstantsTest.php | tools-skills | M | feeder verified fc; drain half → **gc** (owns Bootstrap); ⚠ sprintf/format census IN-STEP (lane-bd lesson) |
| E199 | PARTIAL | HIGH | sugar-crush/src/Diagnostics/RuntimeNoticeSink.php⚠fn;sugar-crush/src/Chat.php⚠fj | cli-config | M | fn shipped the PER-TURN ruling (row's evidence cell pre-dates the CLOSE-header renote); Chat.php:8266 `beginTurn()` wiring left OPT-IN → **ga** (owns Chat.php) |
| E204 | OPEN | MED | docs/plans/crush_code_RESUME.md;docs/plans/crush_code_hardening_backlog.md | docs | M | UNOWNED (fh-brief-listed, not in fh's landed scope) — supervisor owns closeout; actionable half only |
| E309 | OPEN | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php⚠fb;sugar-crush/src/Tools/ToolResult.php | tools-skills | M | tree-wide factory refused with evidence (fb); measure-and-verdict ONLY → **ge** (reads src/Tools broadly, writes no fb/fn files) |
| E325 | PARTIAL | HIGH | sugar-crush/tests/Support/ReflectionLineSliceReaderCensusTest.php⚠fd;sugar-crush/tests/Cli/HelpTest.php⚠fd;sugar-crush/tests/VhsTapeContractTest.php⚠fd;sugar-crush/tests/Support/SlicesDeclaredMethodsTrait.php⚠fd | tests-harness | M | BOTH prescribed directions shipped fd (declaring-file pin + fold + cross-file census rule); remainder = 12 inline-slice readers carry — no §2 lane, pick-or-drop at next close |
| E353 | OPEN | MED | sugar-crush/docs/HOOKS.md | docs | S | UNOWNED (fh-brief-listed, not in fh's landed scope) — §2 table does NOT list it under gd; follow the table: supervisor assignment, re-derive before launching |
| E390 | DEFERRED | HIGH | sugar-crush/tests/Support/RequirementDirectiveProvenanceTest.php⚠fk;sugar-crush/tests/Cli/BootstrapSkillSkipsTest.php⚠fk;sugar-crush/tests/Support/ChildWallClockBudgetTest.php;sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php | tests-harness | M | DEFERRED on dual-guard evidence (fk); post-merge both blockers unowned → **gb** UNBLOCK as ONE motion (ceiling-resolver widening + licensé deletion — same-file edits must land together) |
| E493 | PARTIAL | HIGH | sugar-crush/src/Backend/EngineBackend.php⚠fa;sugar-crush/src/Runtime.php⚠fa;sugar-crush/src/Providers/ | providers | M | record re-verified + extended with E524 measurements (fa); fix = HTTP progress-callback heartbeat seam → **gc** (owns src/Providers) |
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | design-carry RENOTE (fh seam, backlog :18927) — supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope; re-derive before launching |
| E616 | PARTIAL | HIGH | sugar-crush/tests/DenialPrefixRosterTest.php⚠fb | tools-skills | S | 3 tail-frame fixtures pinned REPORTED (fb); `-'` lookbehind decision (measured all-three-NOWHERE) → **gb** (owns the file) |
| E686 | PARTIAL | MED | sugar-crush/tests/Config/DocFigureProseDriftTest.php⚠fl;sugar-crush/tests/;sugar-crush/docs/;sugar-crush/README.md | tests-harness | M | tranche-6 landed fl (arms Z/AA/AB, total 32, zero FALSE); tranche-7 = ~50 figure-file remainder + fl's 7 HELDs → **gd**; ⚠ GlobDialect corpus: re-shape glob-shaped literals before any re-pin |

## Domain index

| domain | n | ids |
|---|---:|---|
| tools-skills | 4 | E10, E172, E309, E616 |
| tests-harness | 3 | E325, E390, E686 |
| docs | 3 | E134, E204, E353 |
| other | 2 | E25, E611 |
| cli-config | 1 | E199 |
| providers | 1 | E493 |

Sum = 14 ✓ (equals the table's row count).

## Cross-file collision clusters (≥3 actionable ids sharing a file)

**None at this cut** — the residual 14 rows are thin enough that no file carries 3 actionable ids
(r68's Renderer/Runtime/DenialPrefixRoster clusters closed or split below the threshold). Two-way overlaps
and single-owner notes below:

- **`sugar-crush/tests/DenialPrefixRosterTest.php`** — E309 (→ ge, READS only — §2 bars it from fb-owned files) + E616 (→ gb, OWNS the file): the two lanes must not co-write; ge's verdict lands as a backlog renote, not an edit here.
- **`sugar-crush/src/Cli/Bootstrap.php`** — E172 (→ gc) is the only live row, but gc ALSO owns `src/Providers/` (E493) and Doctor (E10): one lane, three seams — sequence inside gc's own wave slot.
- **ga reservation**: `src/Chat.php` (E199 seam) is ga's per §2; fj's r69 Chat.php breach is CLOSED — do not repeat the collision pattern with gc.

**Notes (single-owner guards, new files, and carried seams — §0-NOW-71 §2 is the assignment authority):**

- **`sugar-crush/tests/Support/DuplicatedTestHelperDriftTest.php`** — single-owner guard file (di's r67 polity work landed there); **gb** takes it next for the E390 licensé deletion — one lane at a time.
- **`sugar-crush/tests/Support/FixtureLifetimeCensusTest.php`** — NEW file from fg (r69, E505 guard, 3T/15A, 8-row bidirectional roster); E505 is CLOSED — the guard stands alone; roster edits are future one-lane work (census-sleeps-on-itself law recorded in-file).
- **`sugar-crush/tests/Cli/StderrEmitterCensusTest.php`** — now post-ff (carries the E228 zero-fixture sweep arm); holds the stale `'is ELEVEN'` quote seam at **:55** (task-lore cites :53 — the tree says 55; fe's E158 row and §2 ga both cite :55) → **ga**.
- **Seams carried (from the r69 lane reports):** `Chat.php:8266` `beginTurn()` wiring → **ga** (E199 activation); `ProcessUniqueTempNameTest` third `matching()` copy → **ga** (fd's E208 carry — the fold closed but this named copy stayed outside); `MultiAgentRefactorTest:423` tokenless `throwing-` team ids → **ga** (fe's E281 carry); `StderrEmitterCensusTest:55` ELEVEN quote → **ga** (fe's seam); `RuntimeNoticeSink.php:778` reset()-docblock staleness → **ga** (fd's E194 seam, three callers now); `DenialKind` class docblock DECIDED-stamp renote → **gb** (fb recorded the question's home; fn's B-QUALIFIER ruling shipped — the docblock still reads undecided); E611 design-carry (supervisor-harness scope, not a code lane); E25 piece-2 design-carry (PROJECT-scope importer).
- **Unowned rows:** E134, E204, E353 — fh-brief-listed but NOT in fh's landed scope (report covered E9/E564/E611/E633; E291 rode fg). §0-NOW-71 lists them as UNOWNED CARRY ("pick or drop at the next close — re-derive before launching"); a round-70 proposal suggested E353 to gd, but the §2 table does not carry it — follow the table: supervisor assignment.

*Derived from `docs/plans/crush_code_backlog_triage.md` (four open-family section tables, row census) + `docs/plans/crush_code_hardening_backlog.md` headings + `docs/plans/crush_code_RESUME.md` §0-NOW-71 §2 at `5e3e1ce83`; round-69 closeout.*
