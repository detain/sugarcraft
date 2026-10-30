# C-SES-8.e — `candy-code usage` CLI verb — lane REPORT (incremental)

Worktree /home/sites/cc-cmp-C-SES-8.e, branch cc/C-SES-8.e, base d79502693 (C-SES-9.a 98b862e9f ancestor — re-verified live).

## Status: IN PROGRESS (design settled below; code landing next)

## Design (final, re-derived live from master):
- `usage [7|30|all] [--session <target>] [--output-format json]`; window from subcommandArgs[0]
  (default 'all' -> windowDays null), mapped onto UsageHostCommand::WINDOWS contract; junk = usage
  exit 2 (cli.usage.bad_window), 2nd operand = usage exit 2 (reuses cli.sub.unexpected_operand).
- --session resolved via resolveSessionTarget with a new optional ?string $targetOverride param
  (1-line signature widen; session verbs byte-identical) -> zero new 'candy-code: ' literals /
  fwrite sites for the ambiguous (exit 2) + not-found (exit 1) legs.
- store opened with Bootstrap::sessionStore(prune: false) (diagnostic must not prune, per the
  doctorProbes docblock law); ctor throw (e.g. garbage session.db) caught -> facts unavailable
  (days/models null + reason) -> compose() "unavailable" page / JSON facts map, EXIT_FAILURE.
- text: echo UsageCommand::compose($facts) . "\n" (compose returns no trailing newline). JSON:
  self::emitDocument(['result' => ['usage' => $facts]]) — the facts map, both modes carry the
  unpriced `≥` framing via the rollup rows themselves.
- verb registration: ParsedArgs::SUBCOMMANDS (+usage end), SUBCOMMAND_FLAGS ['usage' =>
  ['--session' => true]], Subcommands dispatch match, SUBCOMMAND_DESCRIPTIONS row
  (cli.completion.verb.usage), cli.help.screen Subcommands block row (first word `usage`),
  README Subcommands fence line.
- census-trio in-step: DocFigureProseDriftTest ARCHITECTURE arm (eleven->twelve + map), MCP arm
  (count eleven->twelve, prose eleven->twelve, 'eight answer'->'nine', run-is-a-twelfth->thirteenth
  + map thirteen), docs/ARCHITECTURE.md (2 lines), docs/MCP.md (verb list + counts);
  StderrEmitterCensusTest MESSAGE_SHAPES src/Cli/Subcommands.php 44->46 (the two new usage-door
  failUsage literals; DIRECT_SITES unchanged), roster-note comment same commit.

## Commits: (pending)
## Gates: (pending)

## LANE PROGRESS (incremental, 2026-10-30)
- COMMIT 1: b2e94003ee5818a414793b3c3c52e1ed26ae5c2b (cat-file VERIFIED, %ae/%ce detain both greps, no trailer).
  8 files: ParsedArgs (SUBCOMMANDS +usage, SUBCOMMAND_FLAGS usage=>--session), Subcommands (dispatch arm,
  usage()+renderUsage(), resolveSessionTarget targetOverride seam, SUBCOMMAND_DESCRIPTIONS row, docblock),
  lang/en.php (help row + cli.completion.verb.usage + cli.usage.bad_window/cli.usage.usage sorted),
  README fence, ARCHITECTURE eleven->twelve x2 + list entry, MCP.md eleven->twelve/eight->nine/twelfth->thirteenth
  + list entry, DocFigure arms (both word maps, regexes, counts, messages), StderrEmitterCensus MESSAGE_SHAPES
  44->46 in-step w/ dated roster comment.
- SMOKE PROVEN on disk: full-ledger page rc0; `usage monday` exit2 w/ hint; json `usage --output-format json 7`
  -> {"schema":1,"result":{"usage":{scopeAll,windowDays:7,days:[],models:[],reason:""}}} rc0;
  `usage --session nope-xyz` -> stderr not-found rc1.
- CAUGHT PHANTOM: compressed note claimed SUBCOMMANDS const edit landed — it had NOT (flags had). Re-derived
  live via ReflectionClass(in_array) before trusting; fixed. Lesson: verify every prior-session claim on disk.
- NEXT: BinCandyCodeDispatchTest provider row, tests/Cli/UsageSubcommandTest.php, guard gates, commit 2.

## STATUS: DONE (2026-10-30)

Commits (branch cc/C-SES-8.e, base d79502693):
- b2e94003e  verb + registration + docs + in-step guard flips (8 files; StderrEmitterCensus
             MESSAGE_SHAPES Subcommands 44→46; DocFigure ARCH/MCP arms eleven→twelve/thirteen)
- 5133afba9  tests/Cli/UsageSubcommandTest.php (NEW, 9T/71A) + BinCandyCodeDispatchTest
             provider 'usage' row. Expected live-enum delta for welder re-pin: +10T (9 methods
             + 1 provider case), assertions ~+71 + dispatch-row churn.

NEW test file for durations TSV: candy-code/tests/Cli/UsageSubcommandTest.php
(UsageSubcommandTest runs ~1.2s; the dispatch provider row rides an existing file.)

Gates (plain pipe, cwd=candy-code): UsageSubcommandTest 9/71 green ×3 (incl. post-restore);
battery Usage|ReadmeRosterDrift|DocFigureProseDrift|CliLangCatalogue|StderrEmitterCensus|
SessionImport|SessionSubcommands|ServeArgs = 282T, only-red = standing cli.serve.tls sort
(self-check: my keys SORT_STRING-correct, only the two known serve.tls keys out of position);
Cli\HelpTest 100T only-red CANDY_CODE_SERVICE_TIER (base-proven); BinCandyCodeDispatchTest
132T only-red --worktree (see NEW standing red).

Mutations: M1 bad-window door neuter → exactly 1F; M2 store-throw silent-zero (days/models
→[], reason '') → exactly 1F (the reason-page pin). cp+md5 restore each time.

Disclosures / incidents:
1. STASH COLLISION: this worktree carried FOREIGN uncommitted hunks at seed time
   (docs/PERMISSIONS.md, docs/TROUBLESHOOTING.md, lang/en.php --permission-mode text =
   a -p-asks doc flip; docs/MCP.md browser-control section) plus foreign stash entries.
   Two `git stash -u/pop` cycles I used for base-red proofs collided with them and
   briefly lost my uncommitted test file. RECOVERED byte-exact from dangling untracked
   tree 835d2420e (blob 1c718bac3); both foreign sets still recoverable: foreign 3-file
   hunks in dangling WIP commit 7a33a2d5d (cycle-2), originals also in cycle-1 60103533b.
   Foreign MCP.md hunk is still dirty in my worktree UNTOUCHED (never staged).
   LESSON: never stash in a seeded worktree; base-proofs via `git checkout <base> -- <files>`
   are safe only because my files were committed — do this per-file checkout dance, not stash.
2. NEW inherited base red (NOT mine, welder/gate roster): BinCandyCodeDispatchTest::
   testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions fails at base d79502693 —
   `--worktree` (C-AGT-6.c) is parsed but offered by no completion table. Proven by
   exact-reverting all 8 of my commit-1 files to base: same 6 Help/KeyHelp reds + this one
   pattern independent of my diff. Needs a completion-row or roster decision by its owner lane.
3. KeyHelpTest ×5 + HelpTest SERVICE_TIER reds are base-inherited (proven); standing
   ReadmeSuiteFigureDrift pair left for welder re-pin; AccessorDeprecationTest carries 2
   tree-wide PHPUnit provider deprecations (pre-existing, fires on every filtered run).
4. Deviations as planned: resolveSessionTarget widened with a 4th `?string $targetOverride`
   (usage --session shares the ambiguous/not-found doors; session verbs byte-unchanged);
   en.php-only locale edit per C-SES-7.b precedent; no ArgvParser touch (verbs come from
   SUBCOMMANDS; --output-format is global); help-screen usage row keeps leaf-row pin at 11.
