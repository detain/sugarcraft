# C-TUI-3.a — `/copy [n]` in the TUI (W26, gap, effort M)

Status: DONE. Tip below; all claims verified against the committed tree.

## What landed

- `src/Commands/CopyCommand.php` (NEW): pure `select(list<Message> $history, string $argument): {status,text,blocks}`.
  Bare = last assistant reply (reverse walk skipping uiOnly + blank-body rows);
  `n` = nth fenced code block of that reply counted FROM THE END (1 = newest).
  Statuses ok/no-reply/bad-arg/no-block/out-of-range.
- `builtin-commands/1910-copy.php` (NEW): session category, hint `[n]`,
  `withHandler('handleCopyCommand')`, NO host command (client-only ON PURPOSE —
  SessionHost maps the missing body to `clientOnly`, the /keys + /editor refusal
  the design block demands), NO paletteAction with the per-row judgement recorded
  in the spec file (PaletteSuperset law).
- `src/Chat.php`: `handleCopyCommand()` beside the editor handler — refusals are
  uiOnly notices only (`Cmd` null); success appends `chat.copy.copied {chars}`
  and raises the SAME two legs the drag-release rides:
  `Cmd::batch(relayWidgetCmd(Cmd::setClipboard($text)), static fn → SystemClipboard::copy($text))`.
  `READ_ONLY_COMMANDS` += `'copy'` (row 1, after share/export).
- `lang/en.php`: 7 keys in sorted position (`cmd.copy.description/hint` after
  `cmd.context.unit.ui-rows`; `chat.copy.bad_arg/copied/no_block/no_reply/out_of_range`
  after `chat.compact.idle_folded`).
- Generated docs (lane-committed): `README.md` roster fence + `docs/COMMANDS.md`
  regenerated via `php tools/gen-command-docs.php --write`; `--check` reports
  "up to date" post-commit.
- `docs/ARCHITECTURE.md`: "dispatch arms for 64" → 65, in-step (guard-forced
  widening beyond the declared region: `DocFigureProseDriftTest` LIVE-COUNTS
  `CommandRegistry::slashCommands()`; base was 64, my row makes 65 — verified by
  direct php probe after the spec landed, and by DocFigureProseDriftTest green).
  Correct AT MERGE (+1 vs base; no sibling step in this wave adds a slash row).
- Guard rosters carried in-step (test-file edits, disclosed widening):
  `MenuBarLabelDerivationTest::DERIVED_LABELS` += `copy => 'Copy the last'`
  (registry position 20, between editor and compact);
  `PaletteSupersetDispatchTest::SLASH_ONLY_SURVIVORS` += copy (after pane).
  Both classes remain red ONLY on the FOREIGN `output-style` staleness that made
  them brief-listed standing reds at the cut base — my share is clean; the
  foreign row is untouched (another step's).

## Tests (NEW files — welder owes durations.tsv rows for BOTH)

- `candy-code/tests/Commands/CopyCommandTest.php` — 15T/48A: selection ladder,
  fences (info string inside = content; short run cannot close long fence; tilde
  not a fence; unterminated runs to end; empty pair not a candidate), argument
  coercion edges (`x`, `-1`, `0`, `1.5`, untrimmed, non-ASCII digit), spec
  wiring (handler name, `instantiateHostCommand()` null), and
  `testCopyIsAReadOnlySafeCommand` — reflection pin over the production
  `READ_ONLY_COMMANDS` constant (`assertContains('copy', …)`), mirroring the
  Hooks/Insights house shape.
- `candy-code/tests/Chat/CopySlashCommandTest.php` — 5T/28A: real submitted
  drafts through `update(KeyMsg Enter)`; asserts the exact OSC 52 bytes
  (`Ansi::setClipboard`), the SystemClipboard leg via the stub seam, refusals
  raise NO write and touch no clipboard tool, notices uiOnly, draft consumed.

## Gates (targeted only, cwd candy-code)

- New tests: 20T/76A green first run (the 2 "PHPUnit Deprecations" are the
  baseline shape — HooksCommandTest alone reports exactly 2).
- Battery (17 guard/dispatch classes + ReadOnly family): 946T/52,727A, 2
  failures — only the two foreign-`output-style` standing reds (names verified).
- Mutations on CopyCommand (cp+md5, restore md5-OK): newest-first index flip →
  exactly 2F; fence-opener neuter → 8F; close-run guard → loud errors (never
  falsely green).
- `php -l` clean everywhere; `gen-command-docs --check` clean.

## Deviations / disclosures

- Tilde fences (`~~~`) not recognized — house recognizer parity
  (MarkupScanner `FENCE` is ``` too); stated in the class docblock.
- Empty fence pairs are not copy candidates (no empty clipboard writes).
- `/copy 0` → BAD_ARG (positional grammar error), not OUT_OF_RANGE; pinned.
- OSC 52 silently dropped by some terminals: covered by the SystemClipboard leg
  exactly as the drag path does (design's own risk note).
- No protocol trio touched (no wire/schema surface changed).

## Commit ledger

- `eb4bbbfc1448b82a0092f2be86b2cb9f63bcb528` — C-TUI-3.a: /copy [n] — keyboard
  copy of the last reply or its nth newest code block (12 files, +776/-9;
  %ae=%ce detain@interserver.net, zero trailers).
- (this report's closeout commit follows; cat-file'd tip in the lane return.)
