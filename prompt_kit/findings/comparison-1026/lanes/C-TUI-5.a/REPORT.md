# C-TUI-5.a — `/doctor` host command + AppArmor userns probe — DONE (2 commits on cc/C-TUI-5.a)

Tip `31ebd16fa8489f46e3fe9ef3bd17653d3ebbf5f9`; base commit `b129f175416b02650ccfad9b3a734d88c6c70ea7`.
Both author+committer `detain@interserver.net` (verified with separate `%ae`/`%ce` greps), zero
Co-Authored-By trailers. Worktree tree clean. Branch never pushed.

## What landed

**The roster moved, once.** `candy-code/src/Cli/DoctorChecks.php` (new) owns the sixteen install
probes: `probes(?string $root)` (moved verbatim out of `Subcommands::doctorProbes(ParsedArgs)` —
only `$args->root` was ever read, so the argument narrowed to the string the design asked for),
`run(?string $root)` (the per-probe try/catch that `doctor()` used to carry inline, so a throwing
probe stays one FAIL row instead of a fatal), and the moved helpers `SESSION_STORE_PROBE_DSN`,
`pdoDriverProbe`, `sqlite3Exercise`, `fts5Probe`, `sandboxProbe`.

**The CLI delegates.** `Subcommands::doctor()` keeps its own half: operand rejection, the
`"%-4s %-{$width}s  %s\n"` rows, the `--output-format json` document, the verdict line, exit 1 on
any FAIL. 4982 → 4460 lines in that hub file, all of it inside the doctor region.

**The session half.** `src/Host/Commands/DoctorHostCommand.php` (new, `final`, ctor-less — the
built-in contract) renders `DoctorChecks::run($context->root)` as a `TranscriptTable`
(Status|Check|Detail) and returns `CommandResult::reply()`: read-only with a host body, so
web/serve get it through `command.exec` with no protocol change. A FAIL is a diagnosis, not a
failed command, so the row stays unflagged on purpose. `builtin-commands/1345-doctor.php` is the
registry row (category app, no paletteAction — `/doctor` is a destination, like `/status`),
`Chat::handleDoctorCommand()` + `'doctor'` in `READ_ONLY_COMMANDS` is the typed half, and
`lang/en.php` gained `cmd.doctor.description` + `host.doctor.{heading,column_status,column_check,column_detail}`.

`/doctor` (install roster) stays distinct from the model-facing `doctor` TOOL
(`src/Tools/BuiltIn/Doctor.php`, capability probe + PNG swatch) — README now says so instead of
claiming no such slash command exists.

## Cross-step ruling honoured (C-TOOL-4.a)

- The `sandbox` / AppArmor-userns probe was **carried, not re-minted**: the row, its closure, its
  `cli.doctor.sandbox_*` keys, `Bubblewrap::appArmorRestrictsUserns()` and its
  `/proc/sys/kernel/apparmor_restrict_unprivileged_userns` ReadPathCensus row all stayed exactly
  as C-TOOL-4.a landed them. My `DoctorChecksTest` pins that the file contains
  `->sandboxRefusal()` and `Bubblewrap::appArmorRestrictsUserns(` once each (no second opinion).
- `docs/TROUBLESHOOTING.md` already carried the AppArmor userns entry, so this step added only the
  `/doctor` pointer (post-fence paragraph + the sandbox row sentence). The pinned headline
  `# sixteen checks; exits 1 if any FAILs` is byte-intact and still a single match — the roster
  size never changed.
- No `PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues` in any filter.

## Guards and docs moved in-step

- `tools/gen-command-docs.php --write` regenerated the README roster and the `docs/COMMANDS.md`
  table; `--check` reports "up to date".
- `docs/COMMANDS.md` read-only-window prose gained `/doctor`; README's doctor paragraphs now name
  `Cli\DoctorChecks` as the single home with two readers.
- Re-pointed (they reflect into the moved privates): `tests/Config/DocFigureProseDriftTest.php`
  (the verdict arm now scrapes span `probes` of `Cli/DoctorChecks.php`; the `new \SQLite3` users
  roster and the `'pdo_sqlite'` arm moved files with the code), `tests/Cli/DoctorExtensionProbesTest.php`,
  `DoctorFts5ProbeTest.php`, `DoctorSandboxProbeTest.php` (reflection target `DoctorChecks`,
  `probes()` now takes a root string not a `ParsedArgs`, source slices read the new file),
  `tests/Integration/BinCandyCodeDispatchTest.php` (`pdoDriverProbe`/DSN reflection + a docblock
  truth flip), `tests/Support/ReadPathCensusTest.php` (count-exact census: the doctor config read
  moved from `Cli/Subcommands.php|file_get_contents` into a new
  `Cli/DoctorChecks.php|file_get_contents` row), `tests/Chat/PaletteSupersetDispatchTest.php`
  (the positional `SLASH_ONLY_SURVIVORS` judged set gained its `doctor` row beside `/status`).

## New test files (welder: these need durations TSV rows)

| file | tests |
| --- | --- |
| `candy-code/tests/Cli/DoctorChecksTest.php` | 6 (roster order/carry, root-only signature, status/detail shape, structural per-probe catch, no-spawn/no-network census on comment-stripped source) |
| `candy-code/tests/Host/Commands/DoctorHostCommandTest.php` | 6 (headless `SessionHost` e2e: one UI-only local report, sixteen labels, operands ignored, plain text within the pane, read-only-window membership, verdict wording shared with `Subcommands`) |

## Gate figures (targeted only, cwd=candy-code, `-c phpunit.xml`, plain pipe; never the full suite)

- Doctor family (checks + sandbox + fts5 + extension + bypass + host command): **37 tests / 298
  assertions / 0 failures** at the checkpoint commit.
- Guard batch `DocFigureProseDrift|CommandDocsDrift|ReadmeRosterDrift|BuiltInCommands|ReadPathCensus|
  DuplicatedTestHelperDrift|NoRawAnsi|CommandRegistry|SlashDispatch`: **200 tests / 6531
  assertions / OK** after the ARCHITECTURE flip.
- Sweep batch `ReadOnlySession|PaletteSuperset|CliLangCatalogue|StderrEmitterCensus|
  TreeWideGuardRoster|KeyBindingDrift`: 397 tests / 29204 assertions / **1 failure**, the
  survivor-set pin (see the standing red below) — everything else green.
- Final combined run on the committed tree `Doctor*|DoctorHostCommand|PaletteSuperset`:
  **44 tests / 548 assertions / 1 failure** (the same standing red; the 43 others green).
- Wide guard re-run on the tip: recorded in §Gates below once finished.

## Standing red this step does NOT own (for the welder/fixer)

`PaletteSupersetDispatchTest::testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet`
still fails by exactly one key: `output-style` is in the derived survivor set but missing from
`SLASH_ONLY_SURVIVORS`. Landed-blob proof it predates this step:
`git show master:candy-code/tests/Chat/PaletteSupersetDispatchTest.php | grep -c output-style` → `0`,
while `git show master:candy-code/builtin-commands/1405-output-style.php` (C-CTX-4.e, `f7d312948`)
names `paletteAction` only in its line-15 comment and sets none — so the row was never judged.
With `doctor` inserted, the diff is `4 => 'output-style'` and nothing else; my own row matches at
index 3. Left alone deliberately (another step's surface, and a one-line fixer item).

## Expected staleness (closeout duty, untouched here)

`ReadmeSuiteFigureDriftTest`'s pinned pair goes stale because two new test files add live
enumeration; `candy-code/tests/Config/Support/suite-figure.json` and
`scripts/parallel-tests-durations.tsv` were never edited, and no `composer`/`refresh-deps`/`npm`
ran in this worktree.

## Deviations

1. `docs/ARCHITECTURE.md` is not in the design block's file list. Its `dispatch arms for 65 built-in
   slash commands` numeral is derived live by `DocFigureProseDriftTest`, so adding `/doctor` made it
   FALSE. Flipped 65 → 66 in the same commit as the guard law requires.
2. The design block's lang line mentioned `cli.doctor.sandbox*` as mine to touch; those keys (and
   the sandbox probe itself) are C-TOOL-4.a's landed work, so this step touched none of them.
3. `tests/` re-pointing (six files, above) is broader than the block's "keep green" list, because the
   extraction moved the privates those pins reflect into. Each was the minimal retarget, no
   assertion weakened; the vacuity arms (label scrape, `assertGreaterThan(0, …)` on the scrape) stayed.

## Seams left (out of this step's file list — honest list, not fixed)

Stale docblock cites that now name a method whose body moved (all comment-only, no behavior, no
guard reads them): `src/Support/SqliteConnect.php:30` (`Cli\Subcommands::pdoDriverProbe()`),
`src/Cli/Bootstrap.php:10129` and `:10222` (`Subcommands::doctorProbes()`), `bin/candy-code:530`
(`Subcommands::doctorProbes()`). Bootstrap is a hub file another lane may hold, and `bin/candy-code`
was never in this block's set — each wants a one-line `DoctorChecks::` retarget by whoever owns them.

## Gates

Wide guard re-run **on the committed tip** `31ebd16fa`, all twelve families together
(`DocFigureProseDrift|CommandDocsDrift|ReadmeRosterDrift|BuiltInCommands|ReadPathCensus|
DuplicatedTestHelperDrift|NoRawAnsiInTranscript|CommandRegistry|SlashDispatch|CliLangCatalogue|
StderrEmitterCensus|TreeWideGuardRoster`):

```
OK, but there were issues!
Tests: 320, Assertions: 33367, PHPUnit Deprecations: 2.
EXIT=0
```

Zero failures; the two deprecations are the pre-existing PHPUnit 10.5 ones the narrower batches
showed too (log `/tmp/opencode/ctui5a-guards.log`).
