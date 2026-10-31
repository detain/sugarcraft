# C-TUI-5.b — `candy-code import claude` — LANE REPORT

## Verdict

DONE. Branch `cc/C-TUI-5.b` @ /home/sites/cc-cmp-C-TUI-5.b, base 364a52876, two commits:

- `a754697de` C-TUI-5.b: `candy-code import claude` - Claude settings translation + login persist delegation (16 files, +1448/−67)
- `0cf00ad14` C-TUI-5.b: in-step guard moves - cli.sub.import keys sorted into the catalogue run, Subcommands MESSAGE_SHAPES roster 51->54 (2 files)

Both author+committer `Joe Huss <detain@interserver.net>` (%ae and %ce checked with separate greps in the same shell as the commits; zero Co-Authored-By trailers). suite-figure.json / durations.tsv / dist / protocol trio untouched — never staged. Nothing pushed.

## What shipped

New verb `candy-code import claude [--project] [--yes]`:

- `src/Config/ClaudeSettingsImport.php` (NEW): reads `~/.claude/settings.json` (path via `Bootstrap::trustedConfigDirPath()`, so an unowned home answers null → loud refusal) and, with `--project`, `<root>/.claude/settings.json`. Translates `permissions.allow/deny/ask` through `PermissionRule::fromClaudePrefixDialect()` (`(prefix:*)` → `(prefix *)`), judges every pattern with `PermissionRule::patternRejectionReason()` (rejected = skipped AND named, never guessed), DENY WINS across lists and files (deny > ask > allow; a later file can only raise the restriction), enumerates EVERY widening (incl. the vetoed ones) so the preview shows what the document asked to open. `model` maps when usable; foreign keys are skipped with one-line reasons (`hooks` names the launch loader that already reads it). Hostile input fails loud, whole: symlink/non-plain/oversized >1 MiB → RuntimeException before a byte is consumed; invalid JSON / non-object → InvalidArgumentException; nothing is ever partially applied. `persist()` is the single write door: merges translated rows onto the user tier's existing `permissionRules` (dedupe by pattern+action → re-run is `Wrote 0 new rule(s)`), deny-first order preserved, one `SettingsWriter::write(SettingsTier::You, …)` — the editor re-validates with the launch's strict parser.
- `Subcommands::importClaude` — dispatch arm after `mcp`; three usage doors via `NonInteractive::failUsage` (exit 2); preview printed always; writes ONLY after `--yes` or an interactive confirm; a non-tty stdin without `--yes` refuses with the config byte-unchanged (guarded `is_resource(STDIN) && stream_isatty(STDIN)` probe per the App::firstRunWizardOfferCmd house shape — unguarded stream_isatty TypeErrors under phpunit's fd-0 repair, caught by test, fixed). All import prose is stdout echo; stderr only through the failUsage funnel.
- login persist delegation (design's C-TUI-5.d follow-up): `loginStoreKey`'s merge/write/settle half replaced by `ProviderKeyStore::store($type, $key)` + echo-refusal of the returned sentence; keychain branch unchanged; success line unchanged. Login's own `writeUserConfig` census row retired in the same commit.
- Completion rosters (`SUBCOMMAND_DESCRIPTIONS` + `SUBCOMMAND_ACTIONS` 'import' => ['claude']), `ParsedArgs::SUBCOMMANDS` + `SUBCOMMAND_FLAGS['import']`, lang heredoc help entry `import claude …` + full `cli.sub.import.*` + `cli.completion.verb.import` catalogue run, README Subcommands fence line, `docs/PERMISSIONS.md` (launch-reads paragraph made true + new `### Importing rules from Claude Code` section), `docs/ARCHITECTURE.md` + `docs/MCP.md` thirteen→fourteen subcommands arithmetic (`run` is now the fifteenth spelling).

## New test files (welder: durations rows needed)

- `candy-code/tests/Config/ClaudeSettingsImportTest.php` — 19 tests/57 assertions (dialect translation, deny-wins incl. vetoed-widening enumeration, cross-file precedence never loosens, skipped-key notes, unusable/first-wins model, rejected grammar named, absent files silent, malformed/list-root/symlink/oversized loud refusals, persist merge+dedupe+unrelated-keys, ModelChoice per-provider route, no-vetted-provider throw)
- `candy-code/tests/Cli/ImportSubcommandTest.php` — 13 tests (usage doors via the failUsage JSON envelope + text-mode stdout-silence pin, nothing-to-import exit 0, --project refusal, preview-refuses-unattended with config byte-unchanged pin, `--yes` user-tier write incl. foreign-key skip notes + model-skip on no provider + idempotent re-run, model lands under selected provider keeping other providers, user+project merge deny-wins, malformed JSON loud refusal, symlink refusal, rejected-grammar shown-not-applied)

## Modified tests

- `tests/Cli/LoginSubcommandTest.php` — corrupt-config pin now passes through the store door (byte-identical sentence); NEW structural pin `testThePersistHalfOfTheKeyArmRidesTheSharedStore` (loginStoreKey body contains `ProviderKeyStore::store(`, no `writeUserConfig(`, no providers-merge left). 29T/105A green.
- `tests/Cli/ArgvParserTest.php` — `import claude` parse row + `testImportFlagsAreScopedToTheVerb` (flags bind only after the verb; pre-verb `--project` hits the unknown-flag recorder; `--yes=now` → usageError). 172T/553A green with Login batch.
- `tests/Config/DocFigureProseDriftTest.php`, `tests/Config/Settings/SettingsWriterCensusTest.php` (`'Config/ClaudeSettingsImport.php::persist->write' => 1` added, `Cli/Subcommands.php::loginStoreKey` row removed), `tests/Support/ReadPathCensusTest.php` (ClaudeSettingsImport one CALLER_SUPPLIED sink row; login's two rows removed), `tests/Integration/BinCandyCodeDispatchTest.php` (dispatch 'import claude' + usage rows), `tests/Cli/StderrEmitterCensusTest.php` (Subcommands 51→54).

## Gate figures (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe, targeted only)

- batch guards (DocFigureProseDrift|ReadmeRosterDrift|SettingsWriterCensus|ReadPathCensus): **126T/10049A green**
- batch (CliLangCatalogue|LangParity): 15T/3603A green (after the lang sort fix)
- StderrEmitterCensusTest alone: **95T/22806A green** (after 51→54)
- HelpTest.php file: 105T/3964A, **1 failure = PRE-EXISTING FOREIGN**: `testEveryRosterVariableIsDocumentedOnTheHelpScreenOrExcludedByName('CANDY_CODE_SERVICE_TIER')` — that env was minted by C-PRV-3.a (36cc39ad1) without a help-screen entry; zero occurrences in base lang/en.php (grep-verified at 364a52876), the failing dataset is not an import var. Not chased per lane scope; lead/welder should route it to its owner (same class as the run's memory law (b)).
- my classes: ClaudeSettingsImportTest 19/57; ImportSubcommandTest 13 (25T/147A batch incl. the foreign SessionImport substring); Login+ArgvParser 172/553.
- EnvRosterDrift / GlobDialect / DuplicatedTestHelperDrift / SymbolCitationDrift: green inside the 183T batch (its 2 failures were CliLangCatalogue + StderrEmitterCensus, both cured in `0cf00ad14`).
- BinCandyCodeDispatchTest: **137T/870A, 1 failure = PRE-EXISTING FOREIGN** — `testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions`: `--worktree` (minted by C-AGT-6.c, present in base 364a52876 `src/Cli/ArgvParser.php:844-846`) is missing from the hand-typed completion rosters the guard compares against. This lane touched no ArgvParser/completion source; flagged in the base tree as the standing :1890 red and left for its owner per lane scope. The new `import claude` invocation row + two usage-door rows this lane added pass inside the same run.
- Known-expected red at lane level, welder-owned: ReadmeSuiteFigureDrift staleness pair (two new test files, live suite count moved). Not touched per commit law.

## Deviations / notes

1. The import refuses an unattended write with **exit 1** (not 2) after printing the full preview: exit 2 is reserved for argv-shape refusals (failUsage); the no-`--yes`-and-no-tty case is a legitimate command whose confirmation could not be collected. Documented in the lang sentence itself.
2. `is_resource()` guard on the tty probe (house precedent) — an unguarded `stream_isatty(STDIN)` is a TypeError under the test runner's fd-0 repair, which briefly made the refusal path untestable.
3. MESSAGE_SHAPES roster moved 51→54 in THIS lane's follow-up commit because `failUsage('candy-code: ' . …)` call-sites spell the censused prefix (same arithmetic the lsp/session-import neighbors stamp).
4. Login delegation keeps `loginStoreKey`'s prompt/keychain/success halves; only the persist block (merge → write → settle re-read) moved behind the store. ProviderKeyStore's existing refusals print verbatim through login's echo — the corrupt/type/settle refusal sentences are unchanged text, which the pre-existing corrupt-config pin proves.
5. Docs: `--yes`/`--project`/deny-wins/skip semantics documented in the lang help screen (heredoc = the roster Help.php renders), README fence, PERMISSIONS.md section; ARCHITECTURE/MCP thirteen→fourteen word-figure flips were required in-step by DocFigureProseDrift arms.

## Seams refused

- Foreign `CANDY_CODE_SERVICE_TIER` help-screen red (C-PRV-3.a carry-over) — outside lane files.
- Foreign `--worktree` completion-roster red (C-AGT-6.c carry-over) — outside lane files.
- suite-figure re-pin + durations rows for the two new test files — welder duties.
