# C-TOOL-3.d — /shells list/output/stop — LANDED

Commit: ab80609d77ff5b478f1007152a40a7a845610fd0 on cc/C-TOOL-3.d (base 004bc7240), author+committer detain, zero trailers. 12 files, +576/−4.

Delivered: builtin-commands/2905-shells.php (slash-only, no aliases, no paletteAction); src/Host/Commands/ShellsCommand.php (list/output/stop; owner = $context->currentSessionId(); registry resolved via workspace service, never constructed; NO start verb — Shell tool stays the single spawn door); tests/Host/Commands/ShellsCommandTest.php (9T/45A: not-configured, read-only judgement, list own+untagged-hide-foreign, cursor round-trip + tail-wins, foreign==unknown byte-identity on output AND stop, ladder settles, bad-grammar refusals). Chat.php: isReadOnlySafeCommand shells arm beside schedules + handleShellsCommand beside handleSchedulesCommand. Bootstrap workspace(): withService(BackgroundShellRegistry::class, self::backgroundShells($root)) — same memoized instance the Shell tool spawns onto. en.php: cmd.shells.{description,hint} + 18 host.shells.* rows (en-only §6.3-h). README.md + docs/COMMANDS.md regenerated via tools/gen-command-docs.php --write (rows committed; --check rc0). ARCHITECTURE.md dispatch-arms 57→58 (DocFigure arm live-derived, green).

Guards in-step: MenuBarLabelDerivationTest row 'shells' => 'List, read output' (ran the derivation, registry order 2905-before-2910 honored); PaletteSupersetDispatchTest survivor row in derived order. No BuiltInCommandsTest count pin (census-verified).

RED-BEFORE: ShellsCommandTest vs tree with class moved to /tmp → 9 errors (class not found). /tmp cp-harness throughout; never git stash.

Gates (cwd worktree/candy-code, plain pipe, -u LINES -u COLUMNS): ShellsCommand filter 9T/45A OK; guard family ShellsCommand|BuiltInCommands|CommandDocs|ReadmeRoster|MenuBarLabel|PaletteSuperset|DocFigureProse 145T/5014A OK (x2 incl. re-verify post-restore); Chat|SessionHost family 1933T, 8F — PROVEN INHERITED: byte-identical 8-failure set at pristine base (306T window run at base after full file restore) — AutomaticCompaction /clear row-count, ChatHostLangCatalogue 3 chat.* orphan keys (chat.startup.personal/chat.attach.pdf_pages/chat.effort.launch_unshaped — none mine), ChatTest x5 AsyncCmd-instance title-suggestion reds, DreamPass. Owning waves owe these.

Deviations: (1) Cured base-lagging DocFigureProseDriftTest word map with 'thirty-seven'=>37 (C-TOOL-3.c left guard behind docs; dated note in test). (2) 2 PHPUnit deprecations pre-existing base+tip, not lane-attributable.

NEW *Test.php for durations.tsv duty: candy-code/tests/Host/Commands/ShellsCommandTest.php (gate adds row; lane never touches durations.tsv/suite-figure.json/dist).

## CORRECTIONS (FIX-1, 2026-10-28)
- Tip amended in place: ab80609d7 → **1de8e9266c09c7ed0c8ed98d9b138594d5f7f200** (round-1 cures; see FIX-1.md).
- NIT arithmetic: `lang/en.php` is **+19 rows** (2 `cmd.shells.*` + 17 `host.shells.*`), not 20/18; live `--filter ShellsCommand` shape is **10T/51A** (9 lane tests + the derived BinCandyCodeWiring census row) — the 9T/45A above is the file-only decomposition.
- MAJOR cured: helper `registry()` → `shellsRegistry()` in ShellsCommandTest (DuplicatedTestHelperDrift green). MINOR cured: DocFigure dated comment re-attributed to C-AGT-5.a (5cd68af7e).
- Guard filter green at tip: 130T/16141A/0F.
