# patch-k2 notes (cc-audit-k2: SKL CMD HK PLG MCP TL EDT SH + narrative)

Counts: 117 cell entries (83 with a new cell, 34 detail-only), 0 new rows, 32 narrative replacements. `--check` exits 0 (all `expect` prefixes match, all `old` strings unique and non-overlapping).

## Resolved / adjusted
- `expect` taken from the live doc cell (first 40 chars), not the report.
- Cells containing `|` in the report (`<git-url|path>` in SKL-09, `accept|reject` in SKL-14) rewritten with `/`.
- Detail glyph set to the new glyph (else the current one). "cell unchanged, detail only" entries emit no `new`.
- PLG-08 web and EDT-08 cc: report detail was "unchanged"; no detail emitted (glyph unchanged).
- EDT-N1 web and EDT-N2 cc: report said "unchanged except evidence"; wrote full bullet from the current Appendix text with the new evidence.
- Voice cleanups in details: dropped the "measured 2026-10-26" date (SH-N1 cc), "Static read, no test run" (MCP-11), "code-traced, not run live" (TL-19 web), and the UNVERIFIED caveat in MCP-04 cc (kept doc wording: tokens attach at next launch); "VIS-5" in SKL-06 web replaced by a plain description.
- §4.3 L593/L596/L597/L599 each merged to one replacement per row covering both the cc and web cells; the `|` in the Registry cell uses `/`.
- Narrative merges: §6.5 L820 follows the EDT slice (CRLF fixed, BOM open). §6.5 L815/L816/L820 replace whole rows (cells 2-3 re-purposed as gap / approach, per the report's new text). §6.5 L819 uses two non-overlapping replacements. §1 L64 and L65 each have two non-overlapping span edits; the L65 sandbox edit is anchored on "SSRF on a par with OpenClaw;" for uniqueness. §4.7 L647 keeps "tokens attach at the next launch".

## Skipped
- §2 L84-86 scoreboard hint entry (derived; applier recomputes).
- Not in the report's Narrative list but flagged in its Summary: the dated notes under the scoreboard and the Appendix A lead-in (L851 "dated supersession pointers"/"2026-10-16 baseline") break the voice rule; left for the orchestrator.

## Judgement items
- Many Appendix A bullets in these rows still carry dated "_2026-10-18 remediated:_" tails or stale glyphs; replacing the bullet body removes them only for rows in this patch. Other rows with such tails are untouched.
- HK-07 cc, MCP-11 cc, TL-19 cc, TL-20 cc, TL-27 web downgrade to 🟡; applier scoreboard output (MCP cc ✅9 🟡4, TL cc ✅16 🟡7 ❌5, etc.) is the authority.
- Report claims "§4.6 /hooks claim is TRUE": no narrative change made for it.

## Header renames
None requested.
