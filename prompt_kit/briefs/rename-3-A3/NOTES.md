# Batch A entry 3: sugar-calendar → sugar-datepicker <!-- @rename-keep -->

2026-10-16, Procedure R, /home/sites/sugarcraft master.

## Chain
- 00e6cbd37 `rename: mark historical campaign-record refs @rename-keep before A3 run`
  (baseline row `| sugar-calendar | 149 | 382 |` tagged; A2 precedent — prompt_kit is the
  only historical surface; CONTEXT/worklog/backlog/findings ride mechanically per A2).
- R2 remote first: `scripts/rename-remote.sh sugar-calendar sugar-datepicker` — <!-- @rename-keep -->
  verified record name=sugar-datepicker, description (EthanEFung/bubble-datepicker port)
  carried, old URL 301.
- R3 tool run exit 0: 74 files changed, 158 paths renamed, R1=38 R2=28 R3=958 R6=1
  (plan dry-run said 75/959 — the tagged baseline line correctly skipped). php -l 39/39 clean;
  zero identifier-position hyphen results.
- R7 `sugar-datepicker: rename from sugar-calendar` (see git log for SHA).

## R4 followups
- README: formerly-notice + namespace-change rows under H1 (both @rename-keep). Also fixed a
  PRE-EXISTING badge typo `packagist/v/sugarcore/...` → `sugarcraft/...` (old README carried it;
  A2's README has sugarcraft spelling).
- composer: description already cites upstream EthanEFung/bubble-datepicker (brief guessed
  lipgloss/calendar — FALSE premise, the port targets bubble-datepicker). keywords keep
  "calendar" deliberately (searchability + upstream naming). No repositories[] added.
- bins: none (library) — no bin legacy shim, no LegacyEnv: tree-wide grep found ZERO
  CALENDAR*/SUGAR_CALENDAR* env reads in the lib. No user-data paths (pure component; no
  HomeDirectory/config file writes) → LegacyPaths: none. Recorded honestly.
- Lang: R6 flipped NAMESPACE 'calendar'→'datepicker' + docblock literal fixed by hand
  ("'calendar' namespace baked in" was mechanically stale). lang/en.php keys are bare
  (domain-agnostic) — no key migration needed. No external domain consumers (core/crush clean).
- ansi: 147 keys + paths rewritten; gen-ansi-readme regen + --check rc=0 (3595 pieces agree).
  114/147 art files still paint the word `Calendar` inside the captured picker UI → recorded
  in prompt_kit/briefs/rename-followups.md (art CONTENT excluded Part C.3).
- VHS: tapes rewritten (R3), vhs.yml all=() :154 + render matrix :225 both updated; GIFs
  re-render in CI.
- Docs: gen-docs 65 files, --check ok (61 pages + 4 stubs at flip time; sugar-calendar.html
  stub mints with the R7 status flip — A2 cadence).

## Deviations / premise corrections
1. CONSUMERS: brief guessed "likely candy-* apps embed calendar widgets" — grep
   `sugarcraft/sugar-calendar` in */composer.json → ONLY the lib's own manifest; zero external
   `SugarCraft\Calendar` users. Root composer.json require+repositories are the only wiring.
   refresh-deps --mode=linked run for the lib only (status: linked 6/6 symlinked).
2. Upstream cited is EthanEFung/bubble-datepicker, not lipgloss/calendar.
3. Brand spelling: R2 derives `SugarDatepicker` (ucwords slug) while the namespace word is
   `DatePicker` (ns_new) — lib now reads brand SugarDatepicker / FQN SugarCraft\DatePicker\DatePicker
   (class DatePicker pre-existed). PROJECT_NAMES rationale rewritten to "sugar + datepicker"
   (A2 hygiene precedent).
4. Foreign oc.7z at root moved to /tmp/oc.7z.a3-hold for the tool's clean-tree preflight
   (rename-lib exit 2 otherwise) — restore at lane end; never staged.

## Gates
- check-rename-residue --old=sugar-calendar: 0 findings.
- sugar-datepicker suite: 149T/382A/0F/0E/0S — EXACT baseline row.
- tools/tests: 426T/1484A — exact floor (no docs-dataset wobble).
- check-path-repos --no-lib-path-repos: rc=0. (Refresh-deps wrapper verified too.)
- gen-docs --check rc=0; gen-ansi-readme --check rc=0.
- Consumers beyond root: none → no consumer suite runs owed. sugar-crush: zero src/tests/manifest
  references to the old slug → not run (no crush files in diff).
- composer.lock root: churn confined to the renamed package block (name/homepage/support/
  autoload/alias/content-hash).
