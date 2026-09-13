# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `ea61174b5` — **FULL REGEN from scratch at the round-76 close** (supersedes the
`f8eba0f34` r75 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — ZERO rows below): ACTIONABLE = 0 BY ROW CENSUS**
(OPEN-table 0 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount: rows counted in their SECTION's bucket unless the long cell LEADS
with `**CLOSED`, rule r68) and cross-confirmed against the triage **ROUND-76 CLOSE** census paragraph
(two lanes ka/kb, both reviewed before merge — BOTH rows CLOSED-in-place this close: **E611** by kb's DROP
verdict, CLOSED-BY-PRACTICE with a reopen tripwire (`53bf60534` → pick `c27178e0c`), and **E694** by ka's
slice-A (`6b113f67e` → pick `35489b1d1`), steps 2–3 shipped-by-absence/declined-by-ruling per the recorded
dispositions; **zero mints**). The survivor recount returns exactly 0, never chained. This is the PLAN-
COMPLETENESS state — the declaration lives in `crush_code_RESUME.md` §0-NOW-78 §2, and the next move is an
orchestrator decision point, not a lane roster.

CLOSED rows are PRUNED at this regen (E611/E694 close records live in the triage rows + backlog rows +
worklog ROUND 76 — the map is a scheduling aid, not the history). Round-76 lane letters (**ka, kb**) are
RETIRED at this close: ka's files (`sugar-crush/src/Chat.php` memory arms, `src/Context/`,
`tests/Chat/MemoryCommandTest.php`, `docs/MEMORY.md`, DocFigure arm AQ) and kb's files
(`src/Cli/Bootstrap.php` docblocks, backlog/triage docs) all belong to CLOSED rows — no `⚠` marks survive
because no actionable row remains to cite a file.

**Seam dispositions verified at this regen** (from §0-NOW-77's fold list): `README.md:224`/`:233` eleven-family
→ consumed by companion `a001bd9ce` WITH `tests/Config/ReadmeSettingsTierClaimTest.php` moved in-step (rv-kb
MINOR-8 apply-safe pairing); Bootstrap body ELEVEN claims `:6371`/`:6376`/`:6546`/`:6552` → consumed by kb
`fefd95468` + fix-lane `252dea07b` (post-filter/manager-gated truth);
`tests/Integration/ForeignAgentPresetWiringTest.php:273` "six of sixteen" → **judged NO-ACTION** by r76-rv-kb
item 6(a) (dated ROUND-TWO historical narrative beside the 16-field widening — not a stale count); the E686
heading-normalize and "E694 has no ledger row" seams rv-kb carried → both verified OBSOLETE/REFUTED at master.
Disclosed non-seams (no guard, no row): SETTINGS.md :359/:403 "all eleven tools survive" family (brief scoped
README only; sentence not false, un-widened), Bootstrap tool-count BODY docblocks pinned by NO guard (rv-kb
item-10, src-side E686 family), ka's memoryLocate sentinel docblock line unpoliced (rv-ka M5).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-76 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED round-<n> lane letter — marks a file where that lane landed while the row stayed
  actionable (collision history). No marks this cut (zero rows).

## Table (one row per actionable id, ledger order)

_(empty — the actionable queue is closed at round 76; if a future campaign mints rows, this table and its
header census line are regenerated together at that round's close.)_

## Domain index

| domain | n | ids |
|---|---:|---|

Sum = 0 ✓ (equals the table's row count; equals the awk survivor recount's empty output — command + result
pasted in `crush_code_RESUME.md` §0-NOW-78 §2(a)).
