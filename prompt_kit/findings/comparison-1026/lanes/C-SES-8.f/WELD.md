# C-SES-8.f — WELD (2026-10-29, welder: cc-cmp welder subagent)

Base at weld: master ba3c51aab (weld: C-PRV-2.c). Lane cc/C-SES-8.f (old base cf0cfd0be), 2 commits, r2 CLEAN 0C/0M/0m.

## Pick map
- 25c91d6eb → e209a8544 — JSONL transcript writer + jsonl export format (9 files: src/Share/JsonlTranscriptWriter.php NEW, ShareSession/Exporter/ExportCommand, COMMANDS.md, lang, ExportCommandTest +1T, NEW tests/Share/JsonlTranscriptWriterTest.php, ReadPathCensus rows). Zero conflicts.
- f9f079205 → 9a8a96f23 — review fixes (r1: docblock-truth 3 lines + rewind pin +7A). Zero conflicts.
- §4 ✅ mark cites f9f079205 per the lead's explicit batch-2 instruction (pick-map above keeps the master-side hashes).

## Absorption proof
- `git cherry master cc/C-SES-8.f` → `-` for both commits.
- patch-id --stable SAME for both.
- authors 2/2 %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml) — actuals re-derived at merged tree
- `--filter 'JsonlTranscriptWriter|Share'`: **OK 363T/4823A** (lane-tip 362T/4811A; +1T/+12A merged-tree growth, green).
- `--filter 'Writer|ShareSession|Exporter|ExportCommand|ShareCommand'`: **OK 285T/7547A** (wider filter than the reviewer's 115T spelling — 'Writer' matches merged-tree additions; green).
- `--filter 'SymbolCitationDrift'`: **OK 9T/17286A** (tip figure 9T/17233A; re-derived).
- `--filter 'CommandDocsDrift|ReadmeRosterDrift|EnvRosterDrift'`: **OK 53T/38947A** (tip 53T/38846A; re-derived).
- 2 PHPUnit Deprecations per filter = pre-existing foreign AccessorDeprecationTest shape.

## Cures landed inside this weld commit
- DOC-ROW (r1/r2 closeout carry): candy-code/README.md Sessions row (line 639 at weld; lane-era :621) `/export [md|html|json|text]` → `/export [md|html|json|jsonl|text]` — order mirrors the already-landed COMMANDS.md:357. `/share` list correctly unchanged (jsonl refusal pinned by ExportCommandTest). Guard re-run AFTER the flip: CommandDocsDrift|ReadmeRosterDrift|CommandsTableTakesColumn **OK 19T/429A** — no numeral in the touched row shifted; README remains un-guarded but now truthful.

## Bookkeeping
- durations.tsv row ADDED: `tests/Share/JsonlTranscriptWriterTest.php 0.054 9` (after AgentsPaneDockedFooterTest 0.054). Rows 1548→1549, descending-order violations 4==4.
  CORRECTION on record: the brief's "no durations row (+2A inside existing file)" inherited REVIEW-2's "No new files" — true for the r1 FIX commit alone; the lane build commit creates JsonlTranscriptWriterTest.php (disk-proved: created in pick e209a8544). Row minted per §1.5.
- suite-figure carry: +9T new file +1T ExportCommandTest (+ derived getcwd-census row per r1 re-measure) — closeout re-pin authority.

## Carries to closeout (§7)
- r2 evidence-archive note: the lane dir held no REPORT.md/REVIEW-1.md at r2 time (r2 found /tmp scratch); both now canonical under lanes/C-SES-8.f/ and committed with this weld.
- C-SES-8.d adjacency: journal enablement/wiring lives in 8.g launch mirror (unchanged here).
