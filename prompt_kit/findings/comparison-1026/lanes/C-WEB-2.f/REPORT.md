# C-WEB-2.f — /effort host body over a shared effort dial

VERDICT: DONE. Tip `5ea346d6bece8da7d6a5b359e5d1ef79d7603d20` (cat-file'd: commit; on
`cc/C-WEB-2.f`, cut base `5f8717874`). Two commits:
- `8c48257c0e4be4a4da119db0ffe3d495a6f2da91` — src: EffortDial + EffortHostCommand + Chat delegation + 0350 host binding
- `5ea346d6bece8da7d6a5b359e5d1ef79d7603d20` — tests + SettingsWriterCensus row + SETTINGS.md door line
Both author+committer `detain@interserver.net` (separate %ae/%ce greps, 1 each), zero Co-Authored-By.
Master moved twice under the lane: observed `eee747273` then `a0578545f` at final commit (welder live).

## Shared-dial contract
`SugarCraft\Code\Commands\EffortDial::decide(array $args, ?EngineBackend $engine, \Closure $persist): [string, ?EngineBackend]`
owns the whole judgement (bare status, usage, auto/clear, level validation, C-PRV-1.b shape
refusal — verbatim lift). Each surface owns only its save door and row mount:
- terminal: `Chat::handleEffortCommand` delegates, `$persist` = `persistEffort` (workspace SettingsWriter), row via `effortRow` — `EffortCommandTest` 13 pins green UNMODIFIED = byte-shape proof.
- served: `EffortHostCommand` delegates, `$persist` = its own private writer path, applies via existing `CommandEffect::setModel($provider,$model,$engine->withReasoningEffort(...))` (no new effect kind; pair names ride unchanged, FastHostCommand shape).
Both surfaces persist the tier (set→`reasoningEffort=level`, auto/clear→delete key) — deliberate
contrast with `/fast`'s session-only doctrine, per design block. Read-only refuse only on a turn
(words non-empty); bare `/effort` read is allowed read-only. `runsIn` derives server-side
automatically (CommandMethods reads `hostCommand !== null`) — zero protocol src change.
Web needs no client change (sessions.ts already renders the effort cell).

## Files
NEW `candy-code/src/Commands/EffortDial.php`, `candy-code/src/Host/Commands/EffortHostCommand.php`,
`candy-code/tests/Commands/EffortDialTest.php` (13T), `candy-code/tests/Host/Commands/EffortHostCommandTest.php` (10T).
MOD `candy-code/src/Chat.php` (handleEffortCommand → 4-line delegation; effortRow/persistEffort stay),
`candy-code/builtin-commands/0350-effort.php` (`->withHostCommand(...)` + header truth),
`candy-code/tests/Config/Settings/SettingsWriterCensusTest.php` (roster row
`'Host/Commands/EffortHostCommand.php::persist->write' => 2` + header docblock line),
`candy-code/docs/SETTINGS.md` ("Saving from the settings view" enumeration names the /effort
terminal+served doors — hand prose outside generated markers).
Lang: ZERO new keys (`chat.effort.*` reused). No env vars added → EnvRosterDrift N/A.

## Gates (all plain-pipe, cwd candy-code, PHP 8.3.6)
- php -l: all touched files OK.
- `--filter 'EffortDialTest|EffortHostCommandTest|EffortCommandTest|SettingsWriterCensusTest|BuiltInCommandsTest'` → 50T OK, 5729A.
- `--filter 'CommandDocsDriftTest|CommandsTableTakesColumnDriftTest|ConformanceTest|AttachRemoteCommandsTest|SessionHostCommandsTest|SessionHostTest|WebTwoHostCommandsTest|CommandMethodsMcpPromptsTest'` → 99T OK, 848A.
- `--filter 'DocFigureProseDriftTest|GlobDialectDifferentialTest|LangParityTest|ReadmeRosterDriftTest|SettingsSchemaDocDriftTest'` → 141T OK, 8057A.
- `--filter 'ConfigWriteProducerDocumentationDriftTest|TrustKeyDocumentationDriftTest|ReadmeSettingsTierClaimTest|EnvRosterDriftTest|LayeredSettingsTest'` → 169T OK, 41319A.
- `gen-command-docs.php --check` rc0 ("up to date" — COMMANDS.md table has no host-body column; no row flip owed).
- `gen-settings-doc.php --check` rc0.
- Mutation pins: M1 (persist called unconditionally on refusal) → 3F (2 dial + 1 host refusal pins); M2 (drop `->withEffect`) → 1F (served-effect pin). Both cp-restored, md5sum -c OK; post-restore `EffortDialTest|EffortHostCommandTest|EffortCommandTest` → 36T OK.
- Every gate carries the same pre-existing noise: "PHPUnit Deprecations: 2" = foreign
  AccessorDeprecationTest private data providers (rulings-1024 era) — NOT lane-attributable.
- PtyRunToolTest never in any filter; no full/serial suite run.

## vue-tsc REAL counts (lane touched ZERO web files)
- `vue-tsc --noEmit -p tsconfig.app.json`: rc=1, **7 errors** (ChangesPanel.vue ×5, contextSources.ts ×2).
- cold `vue-tsc --build` (tsbuildinfo deleted): rc=2, **37 errors** (ChangesPanel 10, contextPanel.spec 8, changesPanel.spec 6, reducer.spec 4, contextSources 4, statusBar.spec 2, dormantRoster.spec 1, +7 folded). Foreign baseline grew past the ~21 note after C-TUI-2.g (`815ba17a0`) landed; 0 errors mention effort or any file this lane touched → lane-attributable **0** (file-set proof, not re-derived at base).
- Vitest: NOT run — zero web spec/source changes in this lane.

## PROTOCOL TRIO carries (welder duties)
1. `gen-protocol-schema.php --check` is RED at this lane's tree AND at cut base — drift is
   FOREIGN C-TUI-2.g diff/checkpoint schema text (`815ba17a0`, ancestor of base) whose JSON was
   never regenerated; regenerated diff = 25+/3− entirely in `diff.*` methods, **zero effort/runsIn
   entries**. Lane verified then RESTORED the JSON (never committed).
2. WELDER: `php candy-code/tools/gen-protocol-schema.php --write` **MUST** pair with
   `npm run gen:protocol` (web) **in the same commit**, then rebuild `candy-code-web/dist` —
   one without the other breaks `src-web/lib/__tests__/generated.spec.ts`.
3. NEW test files needing `scripts/parallel-tests-durations.tsv` rows: `candy-code/tests/Commands/EffortDialTest.php`, `candy-code/tests/Host/Commands/EffortHostCommandTest.php` (23T total).
4. suite-figure.json / README re-pin belongs to closeout (lane never touched them).

## Disclosures / deviations
- `CommandText::words` splits raw whitespace after the command (quoted `'high'` arrives with
  quotes → usage row) unlike the TUI's unquoted parser tokens — inherited sibling-wide
  (FastHostCommand same shape); disclosed, not a defect of this step.
- EffortDial refusal passes BOTH `{provider,model}` params to Lang::t even for the key lacking
  `{model}` — strtr ignores extras, rows byte-identical (terminal pins confirm).
- C-PRV-1 reuse check: EffortGate is the GATE, no dial existed → creates stand (design block anticipated the alternative; not applicable).
- No collision hit: no edits to docs/MCP.md, no conflict observed with C-WEB-2.c surfaces
  (skill invocation untouched); SETTINGS.md edit is hand-prose outside the generated markers so
  the C-SEC-7.a/C-CTX-4.e regen zone is unaffected (`gen-settings-doc --check` rc0 proves it).
- SessionHost hub not touched (CommandContext already carries backend+workspace).
- Evidence lives untracked at this path in the lane worktree (per program convention).
