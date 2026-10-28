# C-AGT-4.a — WELD

- Pick: 1cee96571 → master 093ea6e13. CLEAN (22 files +949/−39; primary-agent core: src/Agents/{PrimaryAgent,AgentMode}.php, preset→main backend carrier, PromptFence 13th tag `agent-instructions`).
- Durations commit: 50bc9cd2b — 1 new row `candy-code/tests/Agents/PrimaryAgentTest.php 0.056 45` (junit file-level recount: 10T/45A — brief anticipated 10–12T, junit truth 10T; the 11th test under `--filter PrimaryAgent` lives in another class matching the name pattern). tsv 1504→1505 (set-equality +1).
- No regen rider: NO_REGEN_SURFACE verified (no src/Protocol/, docs/protocol schema, SERVER.md, generated.ts, candy-code-web in pick).

## Merged gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code)
- `PrimaryAgent` — 11T/51A (file itself 10T/45A per junit; +1 merged name-match).
- `ForeignAgentPresetRegistry` — 49T/158A (+2T merged-row growth vs lane-era 47T; harmless).
- `PrimaryAgent|ForeignAgentPreset` combo — 94T/313A EXACT vs lane disk-truth.
- `FieldCarry|BaseSystemPrompt|PromptSection|FrontmatterKeyAudit` — 104T exact, 777A (+4A from merged EXT-2.e frontmatter rows), green.
- `DocFigureProseDrift` — 91T exact, 3979A (−1 vs lane tree wobble), 1F = STANDING thirty-seven only.
- `LangParity` — 7T exact, 2872A (+20 merged en.php keys incl TOOL-4.a doctor family), green.
- 2 PHPUnit Deprecations throughout = standing AccessorDeprecationTest providers.

## Mandatory carries
- §6.1 / C-AGT-4.b inert-subtask DEFERRAL (REVIEW-2 confirmed): `FrontmatterKeyAudit::INERT['agent']['mode']` joined with leave-it note; AGENTS_AUTHORING single Inert row pinned bidirectionally by InertFrontmatterDocumentationDriftTest; ZERO production callers of `PrimaryAgent::apply()` (grep src/: docblock refs only) — picker/cycle wiring correctly deferred to C-AGT-4.b. This weld lands the core ONLY; no runtime agent-selection behavior change.
- b83a58d4d PHANTOM-HASH history (REVIEW-2 phantom audit): `b83a58d4d`, `testPromptSectionsOrderCarriesNothingAndGuidanceLast`, `testTheAgentInstructionsSlotIsBuiltOncePerSession` = ZERO hits tree-wide — never-landed claims from an earlier report; the real tree stands on 1cee96571 alone. Recorded so no future ledger cites the phantom.
- Lead-roster seams carried unchanged: Agent.php :20/:263 'seventeen fields' + ForeignAgentPresetWiringTest :291/:359 'all seventeen preset fields' prose — still true of the carried set, stale count only; reword at 4.b/closeout. suite-figure +9–10T rides the STANDING staleness pair — closeout §7 re-pin carry.

## Cross-run addendum (lane-1 welder, same session as the 093ea6e13 content check)
- 540b07625 PHANTOM-WELD REPORT history: an earlier closeout claimed this lane was
  welded at `540b07625` — that hash exists nowhere (cat-file fails). The true chain is
  093ea6e13 (pick) → 50bc9cd2b (durations) → 55d66b158 (weld: C-AGT-4.a). Recorded so no
  future ledger cites the phantom, alongside the b83a58d4d phantom-fix tip above.
- Independent absorption proof of 093ea6e13 vs lane tip 1cee96571: sorted +/- lines
  892/892, cmp-equal (identical message body, author/committer detain, zero trailers).
  `git cherry` shows '+' purely from base-context patch-id drift (63f2de5d9 vs applied-on
  b264527ff); the content-equivalence above is the absorption proof.
- Durations row normalized to junit truth (0.152 / 10 testcases) in 15ef514de — the
  rider had carried the assertions count (45) in the testcase-count column.
