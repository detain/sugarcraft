# C-SES-8.e REVIEW-1 (r1, tip 5133afba9 over base d79502693, master 8defce213)

VERDICT: CLEAN — no lane defects. One MAJOR-class WELD HAZARD recorded (branch is innocent; the welder must not stage the dirty MCP.md). Findings: 0 CRITICAL / 0 MAJOR (lane-committed) / 0 MINOR / 2 NIT.

## TASK 0 — MCP.md contamination audit (headline)
- `git show d79502693:...MCP.md` vs `git show b2e94003e:...MCP.md` (via /tmp/mbase.md, /tmp/m8e.md, `git diff --no-index`): **5+/5− ONLY** — the legitimate numerals (eleven→twelve, `usage [7|30|all]` row, eight→nine, "run is a thirteenth"). NO browser-preset hunk in the commit.
- m6a (c79073e20) vs m8e: 5+/131− → tip does NOT carry C-TOOL-6.a's section. mmaster vs m8e: master→tip lacks only (a) master's C-TOOL-2 paragraph (17−, base-fork artifact, weld picks handle it) and (b) carries the numerals (+5).
- **The +126 "Browser control and computer use" section (C-TOOL-6.a's exact size) survives ONLY as uncommitted worktree dirt**: `git status --porcelain candy-code/` = `M candy-code/docs/MCP.md`; `git diff` of it = the section verbatim at :551, committed blob 142dc2e6d → dirty blob afc0045c6. NOT staged, NOT committed. Verdict: **no out-of-scope committed hunk** — the builder's disclosure is honest.
- Cure: none needed on the branch. Weld MUST cherry-pick the two commits (never tree-copy this worktree — that would silently double-land C-TOOL-6.a's section). Never touch the dirt (standing law; dangling 835d2420e/60103533b/7a33a2d5d recorded, not probed).

## TASK 1 — ARCHITECTURE.md numeral weld carry (explicit)
- Lane writes: :18 and :46 eleven→twelve + `usage [7|30|all]` list item. It does NOT touch :155, where the LANE tree says "dispatch arms for **63**" but master says **64** (`git show 8defce213:... | grep -n 'dispatch arms'` → :155 "for 64").
- Hunk regions disjoint → cherry-pick applies clean; the merged tree must land **both** "twelve subcommands" (lane) and "dispatch arms for 64" (master) — DocFigure live-counts `CommandRegistry::slashCommands()` against that line, so landing 63 would redden the merged gate.
- Required landed-blob proof at weld (`git show <weld>:candy-code/docs/ARCHITECTURE.md`): grep "twelve subcommands" present AND "dispatch arms for 64" present; and `git show <weld>:candy-code/docs/MCP.md` must carry the twelve numerals PLUS master's C-TOOL-2 paragraph AND (once C-TOOL-6.a lands) the browser section — never from a dirty tree.

## TASK 2 — functional review (all verified on disk)
- Registration complete: `ParsedArgs::SUBCOMMANDS` (+usage, alphabet tail kept), `SUBCOMMAND_FLAGS['usage']['--session']=true`, `Subcommands` dispatch match + docblock + `SUBCOMMAND_DESCRIPTIONS`, `lang/en.php` `cli.completion.verb.usage`, help-screen Subcommands block, README Subcommands fence (guard `ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts` exists at :303 and passed). "No ArgvParser touch" PROVEN: ArgvParser membership-checks `in_array($arg, ParsedArgs::SUBCOMMANDS)` at :248 — verbs are never listed there.
- Window contract: `usage()` maps 7→7/30→30/all|none→null and forwards into the SAME `UsageHostCommand::rollupFacts($store,$sessionId,?int $windowDays,$scopeAll)` the welded C-SES-8.a RPC calls at UsageMethods.php:79 (WINDOWS=[7,30], "refused, not folded into the whole ledger"). No second windowing path; junk window = `failUsage` loud refusal naming 7/30/all. Reachability proven: the verb's return IS `renderUsage(rollupFacts(...))` → `UsageCommand::compose()`.
- Money truth: compose() `amount()` prints `≥$` for bounds, `$` only for measured (`UsageCommand.php:172-175`); absent store → days/models null, `cmd.usage.unavailable` names the reason, exit 1. Test asserts both modes never print "By day"/"Totals:" silent zeros and no bare `$` line beside mystery-model. No path prints 0/$0.00 for an absent ledger.
- `resolveSessionTarget`: 4th param `?string $targetOverride = null` positional-tail; `$targetOverride ?? $args->subcommandArgs[1] ?? null` is byte-identical when null — existing session verbs (incl. C-SES-7.b import) pass 3 args, unchanged. No new emitter inside the shared doors.
- Census flips: StderrEmitterCensus 44→46 — the +2 are the verb's two `'candy-code: ' . Lang::t(...)` failUsage envelopes (extra-operand, bad-window), correctly prefixed; the roster is a LIVE re-derive and the class passed. DocFigure arms updated with live derivations (SUBCOMMANDS count, word-maps +twelve/thirteen, regex `eleven|twelve`). Lang insertion sorted: verb.session < verb.usage < verb_argument; cli.truncate.* < cli.usage.* < cli.warn.* (verified positionally).
- Test IS committed: `git show 5133afba9:candy-code/tests/Cli/UsageSubcommandTest.php` resolves (261 lines, 9 methods, in-process door assertions via JSON envelopes). The worktree is NOT showing it untracked at review time (porcelain shows only the MCP.md dirt) — the brief's ?? concern is superseded; the loss/recovery story is disclosed in the commit body.
- M1/M2 non-vacuity (mutation-reasoned, not executed — reviewer read-only): neutering the bad-window door breaks exactly `testABadWindowWordIsAUsageDoorNamingTheContract` (only arm feeding a junk token); turning the store-catch into a silent empty-OK breaks exactly `testAMissingStorePrintsAReasonAndNeverASilentZero` (rc+text+JSON legs; all other arms use a live store). → 1F each, honest.

## Gates reproduced (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, PtyRun excluded by filter shape)
- `--filter 'Usage|ReadmeRosterDrift|DocFigureProseDrift|CliLangCatalogue|StderrEmitterCensus|SessionImport|SessionSubcommands|ServeArgs'` → **703T/29,527A/1F**: only `CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers` — standing red PROVEN at base (`git show d79502693:...en.php` :1055-1056 already swaps serve.tls.missing/mismatch). Builder quoted 282T — their filter string was narrower; failure set identical.
- `--filter 'HelpTest'` → 163T/100,443A/6F = SERVICE_TIER + 5 KeyHelp (chat.* KeyHelpList roster overflow — listed standing, foreign C-AGT era).
- `--filter 'BinCandyCodeDispatch'` → **132T/822A/1F = exactly the `--worktree` standing red**; the new `usage` provider row passes.
- Authors: both commits `%ae`/`%ce` = detain@interserver.net, zero Co-Authored trailers. Diff touches no weld-only files (suite-figure.json/durations/dist absent).

## NITs (non-blocking)
1. b2e94003e body says the shared read was "minted" by **C-SES-9.a**; the RPC is C-SES-8.a's. Prose ID slip, harmless.
2. Commit body calls `resolveSessionTarget`'s doors "ambiguous / not-found" while the not-found door exits 1 typed and ambiguous exits 2 — the test pins this correctly; only the docblock wording could confuse.

## WELD CARRIES (must-do at weld)
1. Cherry-pick only (never tree-copy); leave `candy-code/docs/MCP.md` worktree dirt untouched; C-TOOL-6.a's section lands solely via its own commit.
2. ARCHITECTURE.md landed-blob proof: twelve (lane) + dispatch arms 64 (master) co-present — `git show <weld>:<path>`, dirty-tree gate forbidden. Same blob proof for MCP.md (numerals + master C-TOOL-2 paragraph).
3. durations.tsv row for new file `candy-code/tests/Cli/UsageSubcommandTest.php`.
4. suite-figure re-pin carries approximately +10T/+71A (9 methods + 1 provider row; final green-run is authority).
