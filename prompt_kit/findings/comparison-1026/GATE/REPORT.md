# §7 Closeout gate — plan_comparison.md

Started 2026-11-01. Base: master == origin == 86695f57d0f6775e146bd9042e33b9f347ef6c1b.
Loop per §1.6: steps 1–4 → fix → re-run from 1 until green; then 5–7.

## Log
- Gate session opened; evidence dir created.

## Fix round 1 (from battery-1 + vitest-1)
- VITEST: 4 failed (2 files) == exactly the banked VITEST-28/4 idempotencyKey family. CURED @ 7c19eb68154c9ec18edba26088862023e1fee4e4 (shellsInput.spec x2 + processesSection.spec x2, six sites: strip stamped key, shape-lock business params, pin 32-hex). Both files 21/21 green serial; vue-tsc rc0.
- Riders: W40/WAVE-REVIEW-1.md records RESTRICTED_TOOL_NAMES 'Script' as LEAD rider OPEN (not a finding); adjudicating against design blocks below. No W40/WELD.md exists (WAVE-REVIEW-1.md is the wave record).

## Fix round 2 — @347d583f0
TrustKeyDocumentationDrift::testTheReadmeNamesEveryKeyNoProjectMaySet cured: README's reasoned
'never taken from a project file' enumeration lacked the five voice.* keys (they sat only in the
tier-summary list above). Added truthful clauses (host+credential routing of takes, model/key
spend, forced-language composer corruption, per-take bill bound). Guard green isolated (100
assertions). skills.pathNudges verified NOT in userTierOnlyKeys (94 live) — clause removed.

## Fix round 2 committed (master 5d7eb2fab, 11 ahead of origin 86695f57d)
- 449ea5302 ChatTest slash-menu /re 8-row re-derive (wrap + ambiguous)
- 4f14ce37b PaletteClick WIDE_UNCLICKABLE_ROWS += /shells row; PaletteSuperset SLASH_ONLY_SURVIVORS += recap
- a922ad16e ShellContrast: Tui reviewDiffRow delegates to canonical Renderer::styleDiffLine (made public), drop unused Color import
- b179f4690 LspLauncherAutoDetect full-manual-shape += 'sandbox'=>true
- 279bd598c DoctorChecks PDO -> SqliteConnect::openDsn; census allowlist re-derive (Subcommands stale row deleted)
- 5d7eb2fab ProcessUniqueTempName six sites -> pid+more-entropy uniqid shape (AudioRecorder take path + 5 fixture names)
All verified green on targeted filters (isolate runs). Continuing TRIAGE grind.

## Fix round 2 committed + lattice (e8ae7a3de)
Master chain now 12 ahead of origin 86695f57d: 7c19eb681 vitest-idempotencyKey · ec86bc080 README voice keys · c8bdf19a6 SelfUpdate 14 verbs · 4755ca05e MemoryPromptWiring client builders · e921cf63e --worktree OPTIONS+12 lang · 449ea5302 ChatTest /re 8-row · 4f14ce37b PaletteClick+/shells, Superset+recap · a922ad16e ShellContrast styleDiffLine delegation · b179f4690 LspLauncher sandbox in manual shape · 279bd598c DoctorChecks PDO→SqliteConnect census · 5d7eb2fab ProcessUniqueTempName six sites pid+entropy · e8ae7a3de ProjectTierRefusal lattice 95/64/35/29 + Bootstrap enumeration (all cat-file verified, %ae/%ce detain 1/1, 0 Co-Authored).
ProjectTierRefusalInventoryTest: 15/15 green isolated (Assertions:1057, PHPUnit Deprecations:2 = standing ambient pair, cross-check battery-2).
Next: ContainedPathInventoryTest 2F, ReadmeJsonErrorContractDriftTest x4, then remaining TRIAGE list.

## Fix round 3 (through 89477c9ea, master 16 ahead of origin)
- f6755b134 JSON error-contract: README schema union/enumerating/exit-1/kebab-four + NonInteractive src type index now FIVE outsiders (import-refused, lsp-install rows) six-of-eleven; ReadmeJsonErrorContractDriftTest 19T/519A green.
- 89477c9ea SwallowingCatchCensus: ClaudeSettingsImportTest::testHostileDocumentsRefuse + VapidKeysTest rawSignature forgery loop rewritten capture-then-assert (fail-in-try was caught: ExpectationFailedException extends RuntimeException via AssertionFailedError); filter SwallowingCatchCensus+both classes 36T green.

## Fix round 4 (through 653d5bb4f, master 19 ahead)
- 160b056d0 RefusalStderrSurface re-measure 676/9 + NonInteractive prose nine-line.
- 653d5bb4f worktree-jail roster + NotebookEdit/ScriptTool; MenuBarLabel + doctor/recap rows.
Both verified red-before (diffs captured from battery-1/isolate1 + direct runs) and green-after; 17T/265A filter. Master tip 653d5bb4f (cat-file ok).
In flight: BootstrapLaunchNoticeCapCensus (userScriptTools fan-out cure) + FrameCapFamily x2 (1_048_576 literal vs ::MAX_FRAME_BYTES; 1048576 vs 67108864 equality leg).
