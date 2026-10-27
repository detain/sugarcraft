# C-CTX-1.a — DONE

Commit: 60772d8e41fff18244ed0c632eef365aa8c9490e on cc/C-CTX-1.a (base e4689c28e), author+committer Joe Huss <detain@interserver.net> (%ae/%ce verified separately, 0 Co-Authored), never pushed.

## What landed (exactly the design block's files; no creates)
- `candy-code/src/Runtime.php`: new public `primeInstructionDiff(App)` — fills the shared memo's `instruction-diffs:<loader id>` slot in the parent, never rechecks (the `seen` advance belongs to the child that sends the row). Slot key now has one spelling: shared private `instructionTracker(App)` used by both `instructionDiff()` and the prime.
- `candy-code/src/Backend/EngineBackend.php`: `beginSessionTurn()` calls `primeInstructionDiff($app)` right after `primeSessionPrompt($app)` inside the same try/catch (hoisted `$runtime`/`$app`); the `summariseAsync()` parent prime (drifted from brief's :4425 to :4457) gains the same call beside it, so a summary fork never captures post-edit bytes. Docblock extended in-step.
- `candy-code/src/Context/InstructionDiffTracker.php`: DECLARED LIMITS capture-window bullet rewritten to the truth: capture rides the parent's pre-fork prime; the fork-case blindness it fixes is named.
- `candy-code/tests/Providers/PromptPrefixByteStabilityTest.php`: fork-path twin `testAMidSessionInstructionEditReachesTheForkedTurnToo` — drives `completeAsync()` twice (skipped without pcntl), asserts turn-2 diff present in the tail row, frozen prefix + all turn-1 wire rows byte-identical. Requests are captured through disk (ScriptedProvider closures run in the child, whose `$requests` never reaches the parent); routing is by request row count because each forked child replays script entry 0 — the parent's provider state does not advance across forks. House `drainUntilSettled` idiom used.

## Evidence the fix bites
- RED pre-fix (src reverted via cp-backup): fails at the turn-context tail-row assertion — the forked turn 2 never appends an updated row (dedup drops it: byte-identical to row 1 because no diff fires). GREEN post-fix: 1 test, 18 assertions.
- Mutation (prime line commented out in beginSessionTurn): exactly this test red, same line; restore md5-clean.

## Gates (cwd candy-code worktree, plain pipe, env -u LINES -u COLUMNS)
- `tests/Providers/PromptPrefixByteStabilityTest.php tests/Context/InstructionDiffTurnContextTest.php tests/Backend/SessionPromptMemoPrimeTest.php`: OK 19 tests, 117 assertions.
- `tests/Support/ReadPathCensusTest.php`: OK 19 tests, 606 assertions.
- Five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`: 133 tests, ONLY the ReadmeSuiteFigureDrift staleness pair red (25,537 pinned vs 25,741 live) — expected, lane-forbidden files untouched.

## New test files for the durations TSV: none (existing file only).
## Deviations
- Brief line cites drifted slightly (:4425→:4457); content matched.
- Spawn prompt parenthetical said "modify EngineBackend.php + InstructionDiffTracker.php only" — the verbatim design block's files.modify also names Runtime.php and its tests bullet names the test file; implemented the design block (serialized region [runtime:primeInstructionDiff (new), instructionDiff] is mine).
- Nothing outside the four owned files; no docs/guards owed (docs/guards: none).
