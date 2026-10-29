# C-SEC-8.f — REVIEW-2 (round-2, fix-r1 re-check)

**VERDICT: CLEAN** — 0 CRITICAL / 0 MAJOR / 0 step-local MINOR / 2 NIT (disclosure-truth notes for lead visibility; zero repo impact).

- Branch `cc/C-SEC-8.f`, tip `69567f9dc`, chain ba842a702 (skeleton, 21-line declared test) + f491d8187 (build) + 69567f9dc (fix-r1). (Brief cited skeleton 20c1845ad — not on disk; actual ba842a702. Brief-side erratum, chain intact.)
- merge-base(master,HEAD) = 6d0c79851; diff = 8 files +795/−20, exactly the design-declared set (README.md, lang/en.php, src/Cli/{ParsedArgs,Subcommands}.php, src/Support/Keychain.php, tests/Cli/LoginKeychainTest.php new, tests/Cli/StderrEmitterCensusTest.php, tests/Support/DescriptorInheritanceGuardTest.php). No hub-region escapes; no weld-only files touched.
- Authors: all 3 commits `%ae` and `%ce` = detain@interserver.net (separate greps), zero trailers. ✓
- Worktree clean at review time.

## r1 step-MAJOR — CURED

StderrEmitterCensusTest roster flipped IN-STEP in f491d8187 (same commit that added the emitter at Subcommands.php:1019): `EXPECTED_EMITTED['candy-code/src/Cli/Subcommands.php'] 8→9` + `EXPECTED_ALL['Cli/Subcommands.php'] 40→41`, with the dated C-SEC-8.f comment naming what reddens. `forty-one` pre-exists in NUMBER_WORDS (:1020). Independent count: tip grep of `'candy-code: '` literals in Subcommands.php = 41 (base = 40, +1). Census GREEN in the gate run below. ✓

## r1 MINOR — CURED

MAX_SECRET_BYTES=8192 (Keychain.php:144) refused at the store() input gate (:175) BEFORE locateOnPath/spawn, with the why-comment (:171-174) explaining the preserved blocking fwrite (:386): a bounded input can drain into a dead child's 64 KiB pipe; `>=` binds the accept edge so an 8191-byte secret passes (:363-372 pair pin). 'refused' verdict arm lands in loginStoreKey (Subcommands.php:1070-1076) naming the bound via its own Lang key cli.login.keychain.refused — FIX-1's deviation from the r1 prescription (dispatch-level literal) is ACCEPTABLE: the arm is honest (Keychain::store still refuses oversized at the seam, dispatch cannot reach spawn with a >8 KiB secret regardless of arm), and the dedicated arm + own leaf + own pin is better i18n shape than jamming the bound sentence onto the default failed arm. Pins: dispatch-level oversized refusal (:320, names bound, no retry sentence, zero spawns) + store-level (:357, name/empty/runaway, zero spawns) + bound-pair (:370, 8192 refused / 8191 accepted, zero dials).

**What reddens if the guard is neutered (`>= PHP_INT_MAX`), reasoned:** (a) `testStoreRefusesMalformedNamesAndSecretsWithoutSpawning` — the 8192-row sail to the armed recorder → `assertSame('refused')` + zero-spawn count red (one method); (b) `testTheSecretBoundRefusesWithoutDialingAndAcceptsTheByteBelowIt` — bound-sized store answers 'ok' from the recorder → refused-assert + no-dial-assert red; (c) `testAnOversizedPasteIsRefusedAtTheDispatchAndNamesTheBound` — dispatch proceeds (recorder 'ok') → the store happens → bound-name + no-write asserts red. 3 methods, matching FIX-1's M1 claim. Removing the 'refused' display arm alone: falls to the default failed sentence → only (c) reddens (its notContains('try again') + bound-name legs), 1 method — FIX-1's M2 claim consistent.

## Security law re-check at tip — PASS

- **Secret never in argv**: stdin-echo shim (`printf '#!/bin/sh\necho "$*" > argv.txt; cat >/dev/null'`) records the CHILD's own argv (the /proc-style independent view); real-path pins (:420/:443/:468/:481/:491) assert the secret is absent from argv.txt, verdict rides the exit code, and the read path shares the same spawn() so the store pin cannot drift from the lookup's spawn shape. argv shape pinned at the seam for both spellings (Linux `--store --label`, Darwin replacing `-U` :169).
- **apiKey-only preserve**: spread-merge keeps the provider block's siblings and the top-level keys; multi-provider leg keeps other blocks byte-identical (:203-240, :305+ leg). Whole-block replacement remains the PRE-EXISTING C-PRV-4-P1 defect — correctly left to C-PRV-4.d, disclosed in the loginStoreKey docblock.
- **Refuses pinned**: empty paste before spawn (:320); passthrough arm refuses before reading/spawning (:272, seam-never-consumed + exit-2 + zero writes); ambient arm (:295); not_key door at Subcommands.php:1015-1021 keyed on LOGIN_KEY_ENVS (:913 — exactly the five storable providers; door and match arm agree, no provider can reach a store arm without the door passing); `=`-form refused at the parser (:138, verb-scoped switch → usage error) and pre-verb form lands in unknownFlags (:131).
- **Orphan entry on refusal disclosed**: docblock (Subcommands.php:1060-1066: harmless, env wins, re-run heals) + the type-mismatch-after-store pin (:255) pinning the config byte untouched in exactly that scenario.
- **Spawn hygiene**: Keychain::spawn routes through `ProcessContainment::spawnSpec` (:371-373); DescriptorInheritanceGuardTest row flipped IN-STEP in f491d8187 (71→72 count + guard-rationale docblock + timeout legs). No new timers on LLM paths (E646 clean — the 5s wall bounds the keychain child only). No glob-shaped / '**Refused:**'-shaped fixtures (GlobDialect side green in the second gate family).

## Completion-roster refutation — HOLDS (verified structurally)

The OPTIONS census (BinCandyCodeDispatchTest:1649-1684) enumerates a flag only when the parser literally branches `$arg === '--<flag>'`; `--keychain` has no such literal (it routes through the verb-scoped `ParsedArgs::SUBCOMMAND_FLAGS` lookup at ArgvParser.php:822, matched only under `$subcommand !== null`), so no completion row can name it. A row ADDED anyway would redden the standalone-parse leg `ArgvParser::parse(['candy-code','--keychain','value'])` (:1676) — it expects the flag in unknownFlags, and the roster lookup cannot fire with no verb open. Precedent confirmed on disk: `--url`/`--children`/`--archived` carry SUBCOMMAND_FLAGS rows and zero OPTIONS rows. The design's "completion roster" instruction is therefore correctly satisfied by the ParsedArgs::SUBCOMMAND_FLAGS['login'] row + this guard.

## Standing reds — NOT this lane

- `testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions`: '--worktree is accepted by the parser but offered by no completion' (:1888). PROVEN base-red: `git show 6d0c79851:...Subcommands.php` OPTIONS contains zero worktree rows (grep count 0); the lane touches neither OPTIONS, the completion script, nor ArgvParser. Identical at base and tip.
- Second family (BootstrapLaunchFormatConstants|LangParityTest|GlobDialect|DocFigureProseDrift): sole red = `testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster` (roster expects README+SETTINGS quoting PROJECT_TIER_TOOL_REMOVAL_FORMAT; neither carries the raw format at base or tip — README grep 0/0, docs/SETTINGS.md only the expanded '34 tools' prose which doesn't match). The lane's README change is +3 pure insertions in the login table (zero deletions), so the sweep's inputs are unchanged by this branch; the red is inherited external drift. FIX-1 disclosed it; r1's gate never ran this family, so this confirms the disclosure honestly.

## Gates (reviewer-run, cwd=candy-code, linked worktree, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'LoginKeychain|Keychain|StderrEmitterCensus|BinCandyCodeDispatch|ReadmeRosterDrift'` → **Tests: 287, Assertions: 23415, Failures: 1** — the single failure is the --worktree standing base-red above. StderrEmitterCensus GREEN; ReadmeRosterDrift GREEN; all 23 LoginKeychain methods GREEN; 0 Errors; 2 PHPUnit Deprecations (pre-existing family).
- `--filter 'BootstrapLaunchFormatConstants|LangParityTest|GlobDialect|DocFigureProseDrift'` → **Tests: 131, Assertions: 7933, Failures: 1** — inherited DOCKER.md-family roster drift only (external, disclosed).

## README / lang rows — PRESENT

README.md login section +3 (f491d8187): flags column gains `--keychain`, plus the storage paragraph (secret-tool store / -U replace, ${keychain:<provider>} reference, env precedence, `--keychain=yes`/pre-verb refuses) — ReadmeRosterDrift green. lang/en.php gains `cli.login.keychain.{stored,refused,not_key}`; all three have production call sites (Subcommands.php:1019/:1060/:1066/:1074); no unused leaves.

## NITs (disclosure-truth; no code change required)

1. **FIX-1.md durations claim is false on disk.** `lanes/C-SEC-8.f/FIX-1.md:121-124`: "the scripts/parallel-tests-durations.tsv row for the new test file landed alongside the skeleton commit" — skeleton ba842a702 is single-file (the declared test); durations.tsv carries zero LoginKeychainTest rows at tip AND base; the file is not in the branch diff. Harmless in outcome (the lane law forbids committing that file; §6.1 closeout carries "durations.tsv row + suite-figure re-pin" for the welder either way) — but the report sentence should not describe an uncommitted row as landed. Lead/welder: the durations row remains a closeout duty, not a absorbed one.
2. **FIX-1.md NUMBER_WORDS cite drifted by one line** (:115 cites :1014; disk has `forty-one => 41` at :1020). Cosmetic.

## Ledger

r1 MAJOR: cured, in-step, gate-green. r1 MINOR: cured with an acceptable documented deviation (dedicated 'refused' arm + Lang leaf); blocking fwrite kept; guard refusal pinned at three layers; neuter-impact reasoned independently (3 methods). Security law: PASS at tip. Scope/concurrency: clean (8 declared files, hub edits inside declared login/SUBCOMMAND_FLAGS regions). No fabricated success paths. **CLEAN — ship-ready for the wave welder (who owes the durations row + suite-figure/README staleness re-pin at merge).**
