# patch-claw notes

Counts: 19 cells (1 detail-only: PLAT-05; 18 with new cell text, no glyph changes), 3 new rows, 8 narrative.
`--check` exits 0; scoreboard rows changed: 0 (matches the report: no glyph moves).

Skipped:
- New rows MCP-N2 (MCP Apps) and PA-N1 (delegated phone calls): report rates both low priority; MCP-N2 is already covered by the MCP-06 detail, PA-N1 is not coding-relevant. Orchestrator may add them (claw MCP-N2 ✅, PA-N1 🔌; candy-code and web would be ❌: no `ui://`/voice-call hits in src or web).
- Narrative: §4.1 L551 header (`OpenClaw 2026.9.8` -> `2026.10.7`) and Appendix B `**OpenClaw 2026.9.8.**` are pure renames, left to the `headers` map. The report's "§4.7/§4.12/§1 need no change" entry needs nothing.

Judgment items:
- PLAT-09: report mixes 9.8 measured startup with 10.7 tarball size; the cell says "measured on 9.8" and cites npm package 250 MB. The old "735 MB package dir" figure is dropped. The UNVERIFIED caveat was left out per voice rules.
- INS-01 glyph kept 🟡 (report rationale: consistency with INS-03).
- PLG-N2 kept 🟡 (report notes ❌ is defensible).
- Details were rewritten to current-state voice (dropped "since 2026.10.x", "removed in 10.5", "stale" wording). Report 10.7 version notes are expressed as facts.
- New-row ratings (live source): SKL-N2 cc/web ❌ (`src/Tools/BuiltIn/SkillTool.php:88-107` says it does not search; `src/Skills/SkillMatcher.php` drops past-budget skills to an "N more" line). SH-N2 cc 🟡 (`ShellOutput`/`ShellStop` model tools, no user list), web ❌ (`BackgroundPanel.vue` is `/bg` sessions). SKL-N1 cc/web 🟡 (dream proposals only). Claude/OpenCode columns `· Not assessed`.
- Narrative L11 header replacement includes "installed baseline 2026.9.8" per report.

Renames: all `OpenClaw 2026.9.8` -> `OpenClaw 2026.10.7` (§2/§3 headers, 398 Appendix A labels, §4.1 header, Appendix B bold title).
Scoreboard: no glyph changes, counts unchanged.
