# P-CTX.b — `/context --raw [path]` prompt dump (GAP-PRM-15)

Status: DONE. Branch `cc/P-CTX.b` @ /home/sites/cc-cmp-P-CTX.b, base 5f740e62a,
tip e34dd4938 (increment 1 amended to 9a6ebd207 to carry the COMMANDS.md row in
the same commit as its argumentHint flip, per §1.8).

## Commits
- 9a6ebd207 — src + command + lang + builtin-commands row + generated COMMANDS.md row
- e34dd4938 — ContextRawDumpTest (7 pins) + PROMPT_ENGINEERING how-to-dump section

## Shape landed (matches BRIEF verbatim)
- NEW `Runtime::assembledSystemPrompt(App): string` public, one line, beside
  `buildSystemPrompt()` (which stays as the delegating private — many tests reach
  it via ReflectionMethod).
- NEW opt-in capability `src/Backend/DumpsSystemPrompt.php` (NOT a Backend
  interface method; docblock mirrors the ReportsPromptSections/ReportsContextWindow
  third-party-extension-point reasoning). `dumpSystemPrompt(): array{prompt, tools}`.
- `EngineBackend implements … DumpsSystemPrompt …`: dump builds
  `sessionApp()->withTools($this->promptTools())` and folds
  `assembledSystemPrompt()` through the same hook-resolved runtime as
  `promptSectionSizes()` — one fold behind panel and dump; tools rows carry
  name/description/input_schema in wire key order.
- `ContextHostCommand` parses `--raw [path]`: bare form byte-identical; jail +
  naming copy the `/export` rules (project-root `PathJail::resolveForCreate`,
  home-shelf fallback under `.candy-code/exports`, `prompt-` prefix default name,
  `~` expansion, fillable palette tokens date/time/job_timestamp only, other
  palette names literal + disclosed in the reply). Writes `base.json` +
  `base.txt` via AtomicFileWriter at 0600. Reply = paths + byte sizes only —
  the prompt body never enters the transcript (RISK honored, pinned).
  Non-dumping backend → named errorReply refusal; >1 arg → usage refusal.
- TUI free via `Chat::handleContextCommand` — **zero Chat.php edits** (the live
  P-CTX.d lane's regions untouched). Headless/serve run the same class.
- `builtin-commands/1350-context.php` argumentHint; lang `cmd.context.hint` +
  7 `cmd.context.raw.*` keys in en.php (alphabetical); COMMANDS.md row via
  `gen-command-docs.php --write` — the rewrite touched EXACTLY my row (no
  foreign re-ordering; C-SEC-3.b hazard did not materialize); PROMPT_ENGINEERING.md
  new "## Dumping the exact bytes" section (no bare anchors, no un-live numerals).

## Regression proof
`ContextRawDumpTest` run against the base tree (4 src files reverted): 7/7 FAIL.
Restored via `git checkout HEAD --`, `git status src/` clean.

## Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter ContextRawDump|ContextHost|ContextCommand|CommandDocsDrift`:
  Tests: 35, Assertions: 181, OK (PHPUnit Deprecations: 2 — same shape as
  ContextCommandTest at base, pre-existing).
- `php tools/gen-command-docs.php --check`: "command docs: up to date", rc 0.
- `--filter TrustKey|DocFigure`: Tests: 109, Assertions: 4,464, OK.
- five-guard `ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|
  ChildWallClockBudget|DuplicatedTestHelperDrift`: Tests: 133, **Failures: 2 —
  ONLY the allowed staleness pair** (artifact 25,537 vs live 25,835 = base 25,828
  + 7 new; re-pin is gate duty).

## NEW test files (gate must add durations.tsv rows)
- `candy-code/tests/Host/Commands/ContextRawDumpTest.php` (7T/35A)

## Not staged (per law)
suite-figure.json, durations.tsv, dist/, generated protocol files — untouched;
no web side in this step.

## Deviations / notes
1. Increment 1 was amended once (COMMANDS.md row moved into it) so no commit in
   the range carries a CommandDocsDrift-red tree; final SHAs are 9a6ebd207 /
   e34dd4938 (earlier-reported 9c5cfe872 superseded by the amend).
2. Test seeds one anonymous Tool via `withTools()` — a bare EngineBackend has an
   empty tool list, so the tools-wire-shape pin would have been vacuous; the
   byte-equality pin stays sound because panel and dump ride the same seeded
   promptTools().
3. Only lang/en.php exists in this package (LangParityTest census is en-only;
   locale mirror loop vacuous) — the step's lang scope honored.
4. `--raw one two` refusal wording kept as "at most one file name" (pinned).
