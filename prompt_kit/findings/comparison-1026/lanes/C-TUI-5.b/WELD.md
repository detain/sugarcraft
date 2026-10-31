# C-TUI-5.b weld (W31) — `candy-code import claude`

Welder: cc-cmp-welder. Date: 2026-10-30. Master pre-weld tip: `874324d35` (C-TUI-3.g review fixes r1).
Lane: `/home/sites/cc-cmp-C-TUI-5.b`, branch `cc/C-TUI-5.b`, merge-base `364a52876` (verified by `git merge-base`).
Step loop exit condition verified from disk before welding: `lanes/C-TUI-5.b/REVIEW-2.md` = 7592 B, verdict CLEAN 0C/0M/0minor/0nit (r1 FINDINGS 2 MINOR + 1 NIT, cured in picks 3/4/5).

## Step 1 — cherry-picks (five explicit shas, never a range)

| # | lane sha | master sha | subject |
|---|---|---|---|
| 1 | `a754697de` | `117c948bb` | main implementation (16 files) |
| 2 | `0cf00ad14` | `0d1f432af` | in-step guard moves (lang keys sorted + StderrEmitterCensus MESSAGE_SHAPES 51→54) |
| 3 | `af26dd781` | `7161fb454` | MINOR-1 ReadPathCensus rationale prose (roster count unmoved) |
| 4 | `cf1c1e00f` | `37b4bfff6` | MINOR-2 landed-only widening count + red-before pin |
| 5 | `32f62fe2c` | `66a999831` | NIT whitespace alignment in BinCandyCodeDispatchTest |

Zero conflicts: all five auto-merged (`README.md`, `docs/ARCHITECTURE.md`, `lang/en.php` auto-merged disjointly).
`git range-diff 364a52876..cc/C-TUI-5.b 874324d35..66a999831` → all five pairs report `=` (content-EQUAL), so there is no
conflict-resolution `+` to re-verify for absorption; `git cherry` proof still run at step 6.

Author law per landed commit: `%ae` grep-c detain = 1 and `%ce` grep-c detain = 1 on 5/5; `Co-Authored` grep-ci = 0 on 5/5.
No `caliber refresh` run; no pre-commit hook staged agent configs (`.opencode/agents/cc-cmp-lead.md` foreign lead edit left untouched and unstaged).

## Step 1b — merged-tree en.php union check (pre-gate)

All three in-scope families live in the merged `candy-code/lang/en.php` (base `364a52876` had 0 of each):
- C-WEB-2.e: `host.help.*` 2, `host.budget.*` 2
- C-TUI-3.g: `cmd.recap.*` 1, `settings.sessions.awayRecap.*` 2, `chat.recap.*` 1
- this lane: `cli.sub.import.*` 35
`php -l` clean.

## Discrepancy reported to lead (not cured here)

The dispatch brief said "this weld introduces NO new test files". Disk says otherwise: pick 1 adds
`candy-code/tests/Cli/ImportSubcommandTest.php` and `candy-code/tests/Config/ClaudeSettingsImportTest.php`
(`git diff --name-status 364a52876..cc/C-TUI-5.b` → both `A`; both ABSENT on pre-weld master).
Per §1.5 these need `scripts/parallel-tests-durations.tsv` rows — §7 GATE duty, welder forbidden to edit that file.

## Halt + resume note

Mid-gate the lead issued a BOUNDED HALT (its own t626/t628 hold predating the 3.g review close); the owner then ruled
"i dont mind the welding tell it to resume it" and the record half completed. No rewind was performed or needed —
master is append-only and the five picks were already legitimate. Nothing was written to master during the halt window
(the only actions between halt and lift were read-only ground-truth probes + the step-1 report).

## Step 2 — merged-tree gates (all 12 classes, anchored exact-class `/\\Class::/`)

Shape: cwd `candy-code`, `-c phpunit.xml`, plain pipe, `env -u LINES -u COLUMNS`. Anchoring is load-bearing —
`SessionImportSubcommandTest` contains `ImportSubcommandTest`, and `KeyHelpTest` ends in `HelpTest`; a loose filter
sweeps both siblings. `tests/Tools/PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues` was never selected
because no filter named its class (no exclusion flag required).

| class | merged tree | lane truth | verdict |
|---|---|---|---|
| ImportSubcommandTest | 15T/70A OK | 15T/70A | EXACT |
| ClaudeSettingsImportTest | 19T/57A OK | 19T/57A | EXACT |
| ReadPathCensusTest | 19T/727A OK | 19T/727A | EXACT |
| ReadmeRosterDriftTest | 10T/78A OK | 10T/78A | EXACT |
| GlobDialectDifferentialTest | 13T/366A OK | 13T/366A | EXACT |
| CliLangCatalogueTest | 8T/220A OK | 8T/220A | EXACT |
| DocFigureProseDriftTest | 92T/**4189A** OK | 92T/4184A | +5A merged-tree wobble, green |
| StderrEmitterCensusTest | 95T/**22860A** OK | 95T/22806A | +54A merged-tree wobble, green |
| HelpTest | 105T/**3973A**/1F | 105T/3964A/1F | +9A; rider SAME shape (below) |
| BinCandyCodeDispatchTest | 137T/870A/1F | 137T/870A/1F | EXACT, rider confirmed |
| KeyHelpTest | 63T/96505A/**5F+1R** | foreign 5F+1R | baseline unchanged |
| ApplySettingsTest | 14T/168A/1F | foreign 1F | rider confirmed |

Accepted foreign riders, each identified BY MESSAGE (not just count):
- `HelpTest::testEveryRosterVariableIsDocumentedOnTheHelpScreenOrExcludedByName` data set `CANDY_CODE_SERVICE_TIER`
- `BinCandyCodeDispatchTest::testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions` — "`--worktree` is accepted by
  the parser but offered by no completion" at `:1893`
- `ApplySettingsTest::testEveryLiveKeyTheViewCanSaveHasAnApplyArm` — "`promptCache.keepWarm` is badged live but nothing applies it"
- `KeyHelpTest` 5F + 1R (`testItStaysWithinTheTerminalWhileScrolledToTheEnd`)
Zero new red families. "PHPUnit Deprecations: 2" is the tree-wide standing shape. `ReadmeSuiteFigureDrift`/README staleness
pair was never in these filters (§7 duty). Nothing cured.

## Step 3 — regenerate

All four generators `--check` at the merged tree, cwd `candy-code`: `tools/gen-command-docs.php` → "command docs: up to date"
rc0 · `tools/gen-settings-doc.php` → "settings docs: up to date" rc0 · `scripts/gen-protocol-schema.php` → "protocol schema:
up to date" rc0 · `tools/gen-tool-docs.php` → "tool docs: up to date" rc0. **ZERO drift → no `weld: W31 regenerate` commit**
(the lead's "drift is plausible because a subcommand landed" hypothesis did not materialize: `docs/COMMANDS.md` enumerates
slash commands, and the new `import claude` bin subcommand rides the README roster guarded by `ReadmeRosterDriftTest`, which
is green). Protocol trio + `docs/SERVER.md` verified byte-unchanged (`git status --porcelain` empty for those paths);
the lane touches **0** `candy-code-web` files (`git diff --name-only | grep -c` = 0), so no `npm`/`dist` work — verified, not assumed.

## Step 4 — §4 mark + record commit

`plan_comparison.md` line 3522 flipped to `#### ✅ 117c948bb,0d1f432af,7161fb454,37b4bfff6,66a999831 C-TUI-5.b — ...`
(1 insertion / 1 deletion = mark-only). C-TUI-3.g's mark lines (3409/3521) and the §3 W31 row left untouched — §3 is the
wave loop's, not the welder's. Proof taken from the LANDED BLOB, not editor state (see below).
Record commit carries: the mark, `lanes/C-TUI-5.b/{REPORT,REVIEW-1,FIX-1,REVIEW-2}.md`, this `WELD.md`, and
`lanes/run-state.json` committed VERBATIM as the lead folded it (uncommitted `note_t612` + the
`STRAY-NOTIFICATION-C-WEB-2f-t616`, `TWIN-REVIEW-C-TUI-5b-t624`, `TWIN-ADJUDICATOR-3g-t640` and
`WELDER-DISPATCH-VIOLATED-LETTER-OF-OWNER-HOLD-t640` anomalies all reach master). Welder edited nothing in it.
`.opencode/agents/cc-cmp-lead.md` (foreign live edit), `AGENT_LAW_SNIPPET.md`, `LEAD_LAWS.md`, root `./run-state.json`,
`./escalated`, the junk `./[input removed due to failed tool call]` and the `.bak-*` rotations were all left unstaged.
No `caliber refresh` run.

