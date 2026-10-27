# patch-opencode notes

Counts: 42 cells (33 oc2, 9 oc1; all with cell + detail, 0 detail-only), 4 new rows (MCP-N3, PER-N3, OBS-N2, UI-N7), 16 narrative. `--check` exits 0.

Skipped:
- All §5 narrative fixes (the §5 L733-765 replacement, plus anything touching §5), per brief.
- Header/label renames, left to the orchestrator: "OpenCode 1.18.35" -> "OpenCode 1.19.4" and "OpenCode 2.0.31" -> "OpenCode 2.1.4" (§2/§3 headers, Appendix A labels), the Legend L26 line `Δ | (OpenCode 2.0.31 column) Changed from 1.18.35`, the Contents L31 §5 link/anchor, §2 L75 and §3 L118 header rows, and the §2 footnote line the report proposes.
- Report's "Changelog digest", "Proposed §5", "What's new" sections.

Judgment items:
- Row IDs: report's proposals were PLG-N3/MCP-N2 (client-hosted tools), OBS-N1 (heap dump), PER-N3, UI-N7. PLG-N3, MCP-N2 and OBS-N1 are taken by the Claude fragment with different features, so these became MCP-N3, OBS-N2 (PER-N3 and UI-N7 unchanged; both free in §3). No candidate duplicates a Claude/OpenClaw row, so no merges needed.
- New rows: cc/web rated from live source (PER-N3 cc/web 🟡 read-only `permission.rules` / Rules panel; others ❌). claude/claw `· Not assessed`; oc1 rated only where the report has data (MCP-N3 ❌ and PER-N3 ❌, OBS-N2/UI-N7 `· Not assessed`). The MCP-N3 oc1 ❌ and cc/web evidence are absence-based.
- The report's existing oc2 detail prefixes ("1.18.35 (✅): … 2.0.31 (❌): …") remain in oc2 bullets I did not touch; the header rename should cover them. For cells I changed I wrote new bodies without that prefix.
- Dropped for voice: "UNVERIFIED", dates, "now", "Since 2.1.x", history lists. SES-09 oc2 dropped the Δ and I added a one-clause remark that v1 also dropped sharing.
- AUT-08 oc2 stays ❓ per report (no change).
- The report's narrative for §1 L65 uses "tui_write_terminal"; I wrote `write_terminal` to match the tool name in the TUI source.

Possible overlaps with §1/§2/§6 web/protocol text or other fragments:
- §1 L62/L65/L67/L69 (OpenCode statements in the §1 paragraph) and §6.5/§6.7 items (elicitation, phone-access) might be touched by the web/protocol audit (cc-audit-k3) or Claude/OpenClaw reports.
- §4 L656 (MCP paragraph) and §4 L689/L705/L728 may also be edited by k1/k2 fragments.

Scoreboard hints (not acted on): oc1 Total ✅199 🟡82 🔌23 ❌51 -> ✅198 🟡82 🔌23 ❌52; oc2 Total ✅199 🟡80 🔌12 ❌59 ❓5 -> ✅201 🟡81 🔌15 ❌57 ❓1 (before new rows and before the AUT-08 etc.).
