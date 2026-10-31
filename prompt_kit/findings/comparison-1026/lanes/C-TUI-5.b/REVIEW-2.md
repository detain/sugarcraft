# REVIEW-2 — C-TUI-5.b `candy-code import claude` (W31, round 2 / closure review)

VERDICT: CLEAN — C=0 M=0 MINOR=0 NIT=0 (all three round-1 findings verified closed on the final tree; red set = exactly the two sanctioned foreign riders)

- Lane /home/sites/cc-cmp-C-TUI-5.b branch cc/C-TUI-5.b tip 32f62fe2c, 5 picks off base 364a52876 (merge-base HEAD master == 364a52876, disk-verified; lane does NOT contain C-WEB-2.e's c829e0954). porcelain clean; tip %ae/%ce detain@interserver.net 1/1.
- Fix-pick file sets (git show --stat): af26dd781 = candy-code/tests/Support/ReadPathCensusTest.php only (6+/4−); cf1c1e00f = candy-code/src/Cli/Subcommands.php (13+/1−) + candy-code/tests/Cli/ImportSubcommandTest.php (37+/0−); 32f62fe2c = candy-code/tests/Integration/BinCandyCodeDispatchTest.php (1+/1−). All inside the step's declared files; nothing drifted outside.

## Q1 — MINOR-2 closure (widening_note counts landed allows) — CLOSED
- Fix verified on disk: src/Cli/Subcommands.php:3874-3886 (final tree) replaced `count($report['widenings'])` with a `$landedAllows` loop counting `$report['rules']` rows with `action === 'allow'`; guard `rules !== [] && widenings !== []` unchanged; no new emitter/sprintf (StderrEmitterCensus green confirms).
- Consent-set exactness PROVEN by inspect() trace (src/Config/ClaudeSettingsImport.php:200-239): `rules` is built from `$chosen` (one final-action row per pattern), so a vetoed allow appears ONLY as its stricter row — count(rules, allow) == count(widenings \ dropped) == exactly the allow rules persist() writes. Symmetric: every chosen 'allow' was spelled allow ⇒ in widenings, so no landed allow is ever missed. Pin's fixture (allow git+npm, deny npm): widenings={git,npm}=2, chosen git=allow/npm=deny ⇒ landedAllows=1.
- RED-BEFORE re-derived BY ME, on this final tree, honestly as a derivation (my harness denied `cp -a` and bare `php`, so no live mutation-run: I created the sanctioned scratch worktree /home/sites/rev-5b-mut at 32f62fe2c but could not seed its vendor — pruned, worktree list now clean): (a) the pre-fix line is verbatim `\count($report['widenings'])` at 0cf00ad14 (git show read); (b) the sole src hunk of cf1c1e00f is that expression; (c) trace (above) gives pre-fix note = "2 of these are allow rules that WIDEN" on the pin's exact fixture ⇒ the new pin's leg 1 (contains '1 of these are allow rules that WIDEN') AND leg 2 (assertStringNotContainsString '2 of these are allow rules') BOTH fail pre-fix, pass post-fix (green live at tip). Consistent with FIX-1's measured 1T/5A/1F red-before. No residual gap: the fixer's claim holds.
- No leg of the earlier pins deleted: cf1c1e00f test diff is 37+/0−; earlier mixed-veto pin testYesWritesTheUserTierWithDenyWinningAndForeignKeysNamed (:252) never asserts the note (allow fully vetoed ⇒ old 1 vs new 0 both pass it); single-landed pin testPreviewRefusesToWriteUnattendedAndLeaksNoBytes (:222,:233 '1 of these') agrees under both formulas (old=new=1) ⇒ not silently flipped; grep shows the note string asserted only at :233/:319.
- Edge judged: all-widenings-vetoed case prints "0 of these are allow rules that WIDEN" — truthful (none of the printed rules widens); never under-reports.

## Q2 — MINOR-1 closure (ReadPathCensus rationale prose) — CLOSED
- af26dd781 = tests/Support/ReadPathCensusTest.php only, 6+/4−, entirely inside the two rationale STRINGS of the existing `Config/ProviderKeyStore.php|file_get_contents` key — no roster key added/removed.
- Prose now truthful: reads live in store() (ProviderKeyStore.php:69 merge-read, :95 settle re-read) and login delegates its persist half there (Subcommands.php:664 `ProviderKeyStore::store($type,$key)` inside loginStoreKey); wording says the credit "moved onto these rows when loginStoreKey delegated the persist half to store() (C-TUI-5.b)" — the stale "credited on the Cli/Subcommands.php row" sentence is gone.
- Census count unmoved by prose cure: ReadPathCensusTest 19T/727A — byte-identical to round-1's figure (a membership change would move tests/assertions).

## Q3 — NIT (BinCandyCodeDispatchTest re-spacing) — CLOSED
- 32f62fe2c = 1+/1−, the ONLY delta is spaces before `=>` on the `'import claude'` row (`'import claude'    =>` → `'import claude'   =>`), aligning to the `'completion bash'` column; the row value `[['import', 'claude']]` is token-identical.
- No derived roster perturbed: BinCandyCodeDispatchTest 137T/870A/1F at tip == round-1's pre-NIT figures; sole F is the --worktree foreign arm.

## Q4 — regressions on FINAL tree + §1.8 guard law on fix picks — CLEAN
- Security essentials re-located (not trusted from word) on the final tree, all inside green classes: deny-wins same-doc (ClaudeSettingsImportTest:96 + ImportSubcommandTest:252), cross-doc never-loosens (:119 + ImportSubcommandTest:349 merge-under-deny-wins), every allow-spelled pattern enumerated (rule rows + widened note + vetoed rows; ImportSubcommandTest:303 asserts landed+vetoed visibility), headless no-write (ImportSubcommandTest:222: EXIT_FAILURE + 'preview (nothing written yet)' + 'Refusing to write without confirmation' + config byte-unchanged; tty-no/EXIT_OK and nothing-importable legs present), malformed/symlink/oversized/non-object refusals (ClaudeSettingsImportTest:204/:225/:237/:248; read() guards :312 is_file||is_link, :320 size cap).
- §1.8 in-step law: fix picks added ZERO lang keys, emitters, sprintfs, doc figures or roster rows ⇒ the guard set had nothing to move in-step and every moved-by-lane guard stays green (CliLangCatalogue 8T/220A; StderrEmitterCensus 95T/22806A; DocFigure word-figures 92T/4184A; ReadmeRosterDrift 10T/78A). The +1 test method shifts live suite enumeration → suite-figure/README staleness pair = lead-carried §7 rider (excluded from findings per brief).
- Scope: no fix pick drifted outside the step's declared files (stats above; whole-range diff 364a52876...HEAD = same 17 candy-code files, forbidden intersect empty: suite-figure.json/durations.tsv/dist/protocol/generated.ts = 0 hits).
- Literal hazards: GlobDialectDifferentialTest 13T/366A green (corpus figure unchanged by fix picks); new pin's wildcard literals all `(`-bearing exempt shapes (Bash(git:*)), zero 'Refused:'-shaped literals (grep empty).

## Measured gate figures (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe; exact-class)
- ImportSubcommandTest 15T/70A OK (exact-class filter; combined substring sweep 26T/156A OK additionally swept SessionImportSubcommandTest — all green).
- ClaudeSettingsImportTest 19T/57A OK · ReadPathCensusTest 19T/727A OK
- HelpTest 105T/3964A/1F — sole F = CANDY_CODE_SERVICE_TIER help-screen arm (FOREIGN rider, sanctioned)
- BinCandyCodeDispatchTest 137T/870A/1F — sole F = --worktree completion arm (FOREIGN rider, sanctioned)
- ReadmeRosterDriftTest 10T/78A OK · DocFigureProseDriftTest 92T/4184A OK · GlobDialectDifferentialTest 13T/366A OK · CliLangCatalogueTest 8T/220A OK · StderrEmitterCensusTest 95T/22806A OK
- `Deprecations: 2` standing tree-wide on every run. Red set == exactly the two base-proven foreign families; nothing else red.

## Verdict ledger
- MINOR-1 CLOSED (af26dd781) · MINOR-2 CLOSED (cf1c1e00f, discrimination re-derived) · NIT CLOSED (32f62fe2c) · zero new findings, zero scope drift.
- After-both-MINORs note from REVIEW-1.md:46 honored: step content is weld-ready; §7 riders unchanged (SERVICE_TIER arm, --worktree arm, suite-figure/durations staleness, importer-count doc rider, project-tier/wildcard observations — lead-carried).
