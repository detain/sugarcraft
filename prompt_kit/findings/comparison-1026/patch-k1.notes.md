# patch-k1 notes (source: cc-audit-k1.md)

- Counts: 114 cell entries (68 with a new cell, 46 detail-only), 0 new rows, 25 narrative. `--check` exits 0; all 25 narrative `old` strings occur exactly once.
- Detail omitted (glyph unchanged, existing bullet valid): PLAT-06 web, MOD-25 cc, PLAT-09 cc (its report detail was only an UNVERIFIED caveat, so the doc's current bullet text is kept).
- Skipped: nothing. Header/version renames: none requested.
- Judgment items:
  - 18 report `new` cells exceeded 132 chars (and MOD-09 cc and MEM-N1 web contained `|`); I shortened/reworded them, so wording differs slightly from the report.
  - "As X" details in the report were expanded into full bullet bodies (MOD-02..07 web, INS-02..07 web, INS-17/20 web, PRM-12 web, CTX-N2 web, AGT-02..06 web).
  - Static-reading caveats, plan IDs (WEB-n, INT-n) and "UNVERIFIED" notes were dropped from details per the voice rule.
  - Report finding (a)/(b)/(c) (serve never drains the notice sink, no turn-end auto-memory/dream on serve, `/btw` refused mid-turn) are encoded in MOD-14w, MEM-02w, MEM-08w, MEM-10w, CTX-11w and narrative entries only.
- Scoreboard hints: the report counts 26 glyph flips; `--check` shows Total cc ✅224→228 🟡93→90 ❌37→36 and web ✅90→106 🟡157→149 🧪4→2 ❌78→74 ❓7→5 for this fragment alone.
