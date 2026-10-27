# C-SEC-3.a — CLOSED

Commit `e34bd1835` on `cc/C-SEC-3.a` (base 4f657c755, one commit, NEVER pushed). Author/committer `detain@interserver.net` both greps = 1, zero Co-Authored trailers. 13 files, +454/−60.

## Shape
- NEW `src/Commands/ShellProvenance.php` (enum Operator/Repo/Bundle/Mcp + refusesShell()) and `src/Commands/CommandShellPosture.php` (`of(CommandSpec)`; order: mcp tier → Bundle badge (outranks tier) → project tier=Repo → Operator; foreign rows fall through to the tier answer — C-SEC-3.b will give them their own posture).
- `TurnController::refuseCommandShell` + `customCommandMustFork` both consult the table: Mcp/Bundle shell forms refused before trust and before the gate (Lang keys `host.command_shell.mcp_refused` / `bundle_refused`, TurnControllerTest:183-era substrings preserved); project sentence stays byte-identical (ProjectTierRefusalInventory pin); Mcp always forks for the fetch, refused postures never fork on shell account.
- McpPromptCommands TRUST docblock rewritten; docs COMMANDS.md bundle+mcp tier rows, MCP.md refused-outright paragraph (`trustedProjectCommands` pin word kept), PLUGINS.md "the declined surfaces" third bullet.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `CommandShellPostureTest`+`TurnControllerBundleShellTest`: OK 12T/33A.
- TurnControllerTest + McpPromptCommandsTest + CustomCommandShellAndFileFormsTest + ProjectTierRefusalInventoryTest: OK 83T/1342A.
- `--filter 'TurnController|CommandShell|McpPrompt'`: OK 112T/553A.
- DocFigureProseDriftTest alone OK 91T/3894A; 4-way doc filter OK 228T/26612A; doc-adjacent (SlashMenuTierBadge/InertFrontmatter/CommandsTableTakes/RegistryTranslation) OK 22T/1181A; McpStartupOrdering+TrustKey OK 16T/538A; ReadmeRoster|EnvRoster|KeyBinding OK 263T/38255A. StderrEmitterCensus green (zero new emitters). (Runs show "PHPUnit Deprecations: 2" — pre-existing non-public data providers in tests/Config/AccessorDeprecationTest pulled in via @depends; that file untouched by this lane.)
- five-guard: 133T, ONLY red = ReadmeSuiteFigureDrift staleness pair: pinned 25,537 vs live 25,579. Lane adds 13 methods (8+4+1); base was already 29 stale. **MERGE OWES re-pin from a green battery run.**

## Discrimination
Fails-before: mcp trust=true leg asserted null+gate-asked at base (now refused, gate never moved); bundle rows had no refusal leg at all (new pins red at base); DocFigure arms re-anchored to `ShellProvenance::Mcp`/`host.command_shell.mcp_refused` + posture-file tier pin — removals flip each named arm.

## NEW test files (durations rows owed by gate)
- `candy-code/tests/Commands/CommandShellPostureTest.php`
- `candy-code/tests/Host/TurnControllerBundleShellTest.php`

## Deviations/seams
- Harness incident: one `git stash -u` slip mid-verification; popped immediately (stash@{0} dropped by my own pop); foreign stash@{1} `local-wip-before-merge` untouched; post-restore 4-file re-run byte-green. No content lost.
- COMMANDS.md foreign row + "Three tiers" intro left verbatim per §6 ruling (C-SEC-3.b owns both); bundle/mcp refusal sentences call the per-origin opt-in "the planned follow-up" naming C-SEC-3.b.
- PLUGINS.md launch-notice prose (StderrEmitterCensusTest:228 comment) unpinned and unchanged: still two launch notices; the shell refusal is an expansion-time transcript row, as the doc now says.

## Round 2 fixes
- Finding 1 (MINOR, MCP.md:879): trimmed the stale trust-era clause "the transcript
  records the `mcp` tier so the reader knows where the instruction came from" — the
  tier is registry-side (CommandShellPosture reads it, the slash-menu popup badges it;
  Renderer::slashMenu rows only) and a refused body has no tier transcript row. Truthful
  replacement: the notice left in a refused form's place names the wire as the origin
  (matches the en.php `host.command_shell.mcp_refused` sentence). The pinned clause had a
  DocFigure needle ('records the `mcp` tier', DocFigureProseDriftTest:7188) — adjusted
  IN-STEP per §1.8 to the new sentence, with the stale "transcript badge" wording fixed in
  the TIER assertSame message and the arm comment.
- Finding 2 (NIT, McpPromptCommandsTest:333 — cited :331, the polarity-mismatched message
  is the contains-'printf ran-' leg): message text only, "the refusal does not echo the
  command the body carried" -> "the refusal does not name the form it declined — the
  discriminator this leg pins is the declined form in the notice, not its output".
  No behavior change.
- COMMANDS.md untouched per instruction (rows assigned C-SEC-3.b).
- Gates at fix tip: filter McpPrompt|TurnController|CommandShell OK 112T/553A (the 2
  PHPUnit deprecations pre-existing per round-1 report); DocFigureProseDriftTest OK
  91T/3894A; five-guard 133T only-red = allowed ReadmeSuiteFigureDrift staleness pair.
