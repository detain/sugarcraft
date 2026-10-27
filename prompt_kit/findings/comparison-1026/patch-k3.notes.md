# patch-k3 notes

Counts: 68 cell entries (39 with a new cell, of which 3 keep their existing detail; 29 detail-only), 0 new rows, 19 narrative.
Merged check (k1+k2+claude+claw+k3) exits 0: 353 cells, 89 narrative.

## Cross-fragment edits (narrative only)
- patch-k1.json: deleted "Linux-first, no Windows; Composer-only install" (folded into k3; wording: Composer, release PHAR (`self-update`) or serve Docker image, no npm/brew/curl installer).
- patch-k1.json: deleted the §6 "most formerly unmoved protocol methods ... " entry (folded into k3; same 7-method list).
- patch-k2.json: deleted "- `/theme`, `/keys` and `/sessions` are terminal-only ..." (k3 carries a merged version: 16 refused, named, plus 41 server-run).
- patch-k2.json: deleted "- No panel shows loaded instructions ..." (k3 carries k2's fuller Context-panel text verbatim).
- patch-k2.json: edited the surviving "opt-in LLM reviewer fed every call" text to "fed flagged calls and every unflagged non-read-only shell call" (live code: ShellSubjectTools only).
- patch-claw.json: deleted the "Control UI side panels" entry (k3 carries both: Files, Terminal, Review, Subagents and Processes; Changes panel partly covers Review).

## Skipped / judgment
- Gaps and Dormant code sections ignored as instructed. AUT-09 web detail-only line ("see cell change above") skipped.
- WEB-02 cc, WEB-10 cc, CFG-04 cc: cell-only changes, existing details kept (WEB-10 SHA is only in the cell, now removed).
- UI-19 web and UI-26 web: report gave no detail text; I wrote one. OBS-05 web: report detail was empty; I wrote one from the evidence.
- Five cc cells (PER-09, UI-09, UI-10, UI-25, UI-33) were shortened to fit 130 chars; web paths rewritten to `web:` style.
- Dropped "UNVERIFIED" caveats (UI-11 cc, CFG-06 cc evidence); UI-07 cc detail is the existing text minus the SHA/wave tag.
- §6.7: the "Still open" item is numbered 8 and Ideas renumbered 9 (the report said "9 before Ideas").
- §4.10: "But:" header removed, sibling bullets de-glyphed, candy-code-web bullet added.

## Headers / versions
None requested.

## Scoreboard hints
Report gives none beyond the counts in its summary (39 cell changes, 20 glyph changes); applier recomputes.
