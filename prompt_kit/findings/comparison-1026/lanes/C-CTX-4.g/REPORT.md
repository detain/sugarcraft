# C-CTX-4.g — Headless --append-system-prompt (fenced <user-rules> splice)

**LANDED: commit cbdc0102f** on branch `cc/C-CTX-4.g` (base 21732353d), 15 files, +861/−6. NEVER pushed.

## STOPPED-THEN-LEAD-AMENDED (provenance)
The design block omitted `src/Backend/EngineBackend.php` although `newRuntime()` is the SOLE production
construction site of Runtime (AUT-4/C-AGT-4.a carrier precedent) and `lang/en.php` although every refusal
string must be a catalogue key. The lane STOP-reported the gap (zero-commit report at this path earlier);
the lead ruled it a PLAN GAP and authorized both files, serializing `[hot:candy-code/src/Backend/EngineBackend.php]`
(hot region free: C-PRV-3.a is welded INTO this base). A process-global static hand-off was rejected: it
would leak the operator's rules across sessions in serve/ACP hosts that call `NonInteractive::run()` in-process.

## Phantom-write recovery note
An early /tmp write window silently no-op'd (dir absent): the three test files were REWRITTEN from the
recovery brief afterward; src/lang/bin survived md5-exact and were cp-swapped to base and back (STASH BAN
honored) to produce the fail-before evidence.

## Executed shape
- **ArgvParser**: `--append-system-prompt` strict-value flag, both spellings (spaced + `=`); refusals ride
  the `usage` error family: `.missing` / `.flag {option}` / `.empty` / `.requires_prompt` (headless-only gate
  twin of the AUT-4 `!$promptRequested` arm, ArgvParser.php:972). Parser stores the RAW token; no fs reads.
- **bin/candy-code**: `--append-system-prompt` added to `$strictValueFlags` + docblock enumeration.
- **NonInteractive::decodeAppendSystemPrompt()** (private): null passthrough; `@path` → `is_file` + `is_readable`
  + `file_get_contents` (false-arm included), bounded at `InstructionFileLoader::MAX_DOCUMENT_BYTES` = 61 440;
  whitespace-only refuses. Honesty gate: non-EngineBackend carrier refuses via existing `failUsage` →
  `usage` type, `EXIT_CONFIG` 2 (no new error type — spawn contract kept).
- **EngineBackend**: readonly nullable `appendedSystemPrompt` ctor prop (mutate() is get_object_vars-spread),
  `withAppendedSystemPrompt()` wither + bare accessor, read as a named arg inside the one `new Runtime(` site.
- **Runtime**: `APPENDED_SYSTEM_PROMPT_PREAMBLE` const (wording guard-safe: ASCII, no fence-tag spellings);
  append leg between the `<available-skills>` push and the plan-mode block — ONE `Stability::PerSession`
  section `<user-rules>\n{preamble}\n\n{PromptFence::escape(trim(rules))}\n</user-rules>`; `<env>` stays LAST,
  plan-mode stays directly ahead of it; flag-absent = zero new sections (byte-identity pinned).
- **Docs/guards in-step**: README CLI fence row; PROMPT_ENGINEERING.md rule 5 flipped (override ban KEPT,
  §6 ruling recorded, plan_comparison.md:4132); en.php 10 keys + cli.help.screen page block (sorted run fixed);
  Subcommands OPTIONS row + completion option key; StderrEmitterCensusTest (ArgvParser 37→41, NonInteractive
  11→12); ReadPathCensusTest second verdict for the new `file_get_contents` site;
  EngineBackendWitherPreservesStateTest census row + carrier pin.

## Measured gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- AppendSystemPromptTest: **18 tests / 145 assertions OK** (fail-before at base src: 18T with the filter 19T/Errors+Failures 19 — cp-swap, `/tmp/opencode/C-CTX-4g/fail-before-real.txt`). WELD-ERRATUM (lead ruling at weld, batch-13): the class holds 18 test methods; the 19 seen at the lane was the `--filter AppendSystemPrompt` shape (file 18 + ArgvParserTest recognizer). Merged-tree re-measure: file alone 18T/145A OK; filter 19T/147A OK.
- `LaunchFlagsTest|ArgvParserTest|EngineBackendWitherPreservesStateTest`: 280T/2091A OK (now +recognizer/+census/+carrier = 282-shape confirmed inside the 497T battery).
- Battery `Completion|LangCatalogue|HelpTest|AppendSystemPrompt`: 263T / **9F = the 9 base-proven reds, identical names** (base-swap run `helptest-base.txt`/`completion-base.txt` reproduced them with all my files at base).
- Battery `DocFigure|ReadmeRoster|ArchAssemblyOrder|PromptSnapshot|PrimaryAgent|BaseSystemPrompt|Stderr|ReadPath`: **259T/27290A, 0F**. WELD-DISCLOSURE: the 259T figure was not reproducible at weld or review; the closest true band on the merged tree measures 393T/2F (ChildStderrCapture, base-owned) — battery-quote corrected to actuals; the merged-tree guard run GlobDialect|TreeWideGuardRoster|StderrEmitterCensus|ReadPathCensus|DocFigureProseDrift = 237T/31028A OK.
- Battery `AppendSystemPrompt|LaunchFlags|ArgvParser|Wither|NonInteractive|BinGuard|JsonErrorContract`: 497T/**16F all base-proven** (NonInteractive trio 12F+3W byte-identical at base NonInteractive.php; JsonErrorContract 4F = McpServerRequests foreign shape at base).
- ReadmeSuiteFigureDrift staleness pair NOT re-run here — welder re-pin carries the figure.

## Mutations (cp+md5-restore, all five discriminating, restores verified)
M1 escape→raw: forgery pin red (1F). M2 cap→false: ceiling pin red (1F). M3 newRuntime read dropped:
carrier + whole-chain pins red (2F). M4 requires_prompt gate neutered: refusal pin red (1F) (wrong-form
variant `&& !prompt` removed → 5F, disclosed). M5 append pushed after `<env>`: order pin red (1F).

## Welder owes
- durations.tsv row: `AppendSystemPromptTest.php` (measured **18** tests, 0.52s — weld-minted).
- suite-figure/README re-pin carry: **+21 tests** (WELD-ERRATUM: 18 new file, not 19 + 1 ArgvParserTest recognizer + 1 WitherFeeds
  method + 1 withers() sweep data-set).
- Pre-existing standing reds re-confirmed at this base (NOT this lane): NonInteractive trio 12, JsonErrorContract 4,
  KeyHelp 5, SERVICE_TIER, CliLangCatalogue marketplace sort, ChatHost catalogue, BinCandyCode verb.
