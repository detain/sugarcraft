# W11 wave review — round 1

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR / 3 NIT (wave ships; minors → wave-fixer/closeout)

Tip judged: 7e97c9e4f (C-TUI-6.k weld rode ahead mid-review; 13974e4b3 C-CTX-6.b weld is the last W11 commit — all 10 steps merged below tip).

## Step ledger — 10/10 genuine
C-MCP-2.a 51812d155 · C-CTX-6.b b72a169f2 · C-EXT-2.d fa14b82c6 · C-PRV-5.d e07d05447 · C-SEC-5.b f90523394 · C-SES-1.b 46d64b18a · C-SES-9.b b2b049b5b · C-TOOL-3.b 1999f4114 · C-TOOL-7.b 08e91c647 · C-TOOL-7.d 15ec4b791.
All cat-file OK, all ancestors of tip, %ae=%ce=detain@interserver.net, zero trailers — on all 10 lanes + all 16 weld/durations/support commits (incl. amended 622fce834 for f90523394). §4 marks: full hashes present on every W11 step (no stamp gap; C-TOOL-7.b mark rode plan bookkeeping b4b4404c5).

## Cross-lane interactions (clean)
- SessionHost.php union: C-MCP-2.a mcpProvider/McpPromptCommands surface (:24-1199) + C-SES-9.b live-spend (:457-480) coexist; SES-1 unpriced companion bit (:478) coherent with cmd.status.spend.untracked (StatusHostCommand:82, en.php:1499-1500).
- NonInteractive.php union: C-MCP-2.a @mcp reader leg (:455-459) + C-TOOL-7.d encodeDocument escaping (:1491-1495) stacked; weld queued NIT: refusal literal shared const :462 vs TurnController:1098 (closeout).
- Chat.php: C-TOOL-3.b three fold hunks + C-SES-9.b weld cure auto-merged (weld message + merged-file read confirm).
- lang/en.php: C-CTX-6.b/C-EXT-2.d/C-TOOL-3.b hunks all live (lines 1903, cmd.skills.*/host.skills.*, tui.tool.tail_more:2949); dup-key scan across all quoted top-level keys: zero duplicates.
- en Façade/wire: C-TOOL-3.b ToolTailSlots 'NOT YET WIRED'→'WIRED' flip + weld LEAK-LAW truth-cure live; C-MCP-2.a, C-EXT-2.d (SkillManager enable/disable now called: App.php:5028/5050, SkillsHostCommand:349-355) and C-TOOL-3.b all WIRE dormant code (E-laws: dormant code wired not deleted — exemplary). E646: zero new wall-clock expiry/timers (TrustAsks re-approval event-driven; grep of f90523394 hunks: no ttl/expir/timeout). Fork/child: no new spawn sites.

## Merged-tree gates (all run at tip, cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDrift|KeyBindingDrift → 281T/40,262A OK.
- LangParity|GlobDialect|DocFigureProseDrift → 112T/7,189A OK.
- DescriptorInheritanceGuard|TreeWideGuardRoster → 32T/8,551A OK.
- ServerProtocolDocumentationDrift|SessionMethods|ContextMethods → 7T/50A OK.
- Generators --check: gen-command-docs / gen-settings-doc / gen-tool-docs / scripts/gen-protocol-schema → all rc0 "up to date" (no regen owed; C-SEC-5.b protocol regen rode weld d93e9b0b6: schema.json+SERVER.md+generated.ts+dist bundle swap).
- Lane-targeted: batch1 (MCP surface/mentions/turn-seam/aggregate-budget/file-mentions/skills/census) 91T/6,311A OK; batch2 (Gemini×2/trust targets-prompt-sources-gateway-routing/background-announced/web-two-host) 92T/550A OK; batch3 (ToolTailFold/RunningToolTail/Ls/EncodeDocumentEscaping/StreamJsonEmitter) 268T/1,257A OK.
- durations.tsv (root scripts/parallel-tests-durations.tsv): set-equality for every NEW *Test.php in W11 — DocumentAggregateBudget:162, SessionHostMcpSurface:224, ContextTrustTargets:373, GeminiResponseSchema:653, SkillsEnableDisable:785, RunningToolTail:833, ToolTailFold:977, EncodeDocumentEscaping:1203 → 8/8 rows present; C-TOOL-7.b/C-SES-1.b/C-SES-9.b added no new files.

## Hygiene
- No cc-cmp worktree/cc branch left for any W11 step (8 live ones belong to in-flight other-wave lanes — report-only).
- suite-figure.json untouched by W11 welds; ReadmeSuiteFigureDrift staleness pair remains standing (§7 closeout carry, NOT W11).
- Standing-red roster unchanged and not attributed: ChatHostLangCatalogue launch_unshaped (C-PRV-1.b seam), ProjectTierRefusalInventory 2F, SwallowingCatchSrcCensus SkillTool, web typecheck 19.

## Findings
1. MINOR — candy-code/src/Attachments/FileMentions.php:335-342 (C-CTX-6.b): §4/design-brief promised lang key chat.attach.pdf_pages; it never landed — page refusal is a hardcoded English sprintf (document_withheld sibling did land). Substance (refusal + notice + MEDIA.md:206 docs) is live, so not MAJOR. Cure: route the sprintf through Lang::t('chat.attach.pdf_pages') + all 16 locale files (LangParity flips in-step), or apply a dated erratum on plan §4 C-CTX-6.b docs/guards line. Assign: wave-fixer.
2. NIT — weld d95f2486c (C-TOOL-7.b): empty body + no WELD.md/gate disclosure, unlike all 9 sibling welds (its gates verified green in this review independently). Cure: closeout ledger note backfilling the record. Assign: wave-fixer/closeout.
3. NIT — fa14b82c6 (C-EXT-2.d lane) committed its own lanes/C-EXT-2.d/REPORT.md (evidence normally rides the weld); record complete, zero duplication. No cure; note convention for future lanes.
4. NIT (carry, disclosed) — durations row SkillsEnableDisableTest 0.051s vs ~0.224 fresh measure — re-measure candidate at §7 closeout.

## Disclosed limitation
Root tools/gen-docs.php --check and tools/gen-ansi-readme.php --check refused by this reviewer's bash permission map (repeated permission.rejected; candy-code generators ran fine). W11 touched no docs/_data/<slug>.* nor ansi/ surface in any of the 10 diffs, so drift risk nil; §7 closeout battery (welder, full map) must run both --check to close the hole.
