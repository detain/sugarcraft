# C-CTX-3.c REVIEW-2 (round 2, fresh reviewer)

Scope: cc/C-CTX-3.c tip 93c08ab87 (fix commit on c33e189af, base 86f80fef8), worktree clean. Fix diff = 5 files (+129/−12): ContextMethods.php, MethodSchemas.php, ContextInstructionWriteTest.php, Composer.vue, composerQuickAdd.spec.ts — exactly the three findings' files, no creep (SessionHost.php and other live-builder regions untouched: full-range name-only grep 0 hits on Host/Commands/*, TranscriptProjector, Chat.php, PathJail, trio). Author %ae/%ce = detain (separate greps), 0 trailers.

VERDICT: CLEAN

## Verification per mandate

1. **MAJOR-1 cured.** `personalWriteTarget()` eager-create block removed on disk (diff + file read); it resolves (owned home, PrivateDir, is_link refusals) and returns without writing. Latch at ContextMethods.php:197 is exactly the prescribed `is_file ? filesize : 0`. Creation rides the success path only: `$currentBytes > 0 ? fileperms : ($isPersonal ? 0o600 : null)` fed to AtomicFileWriter::write. Docblocks (:231-239) state the law. Pin `testARefusedSentinelSaveOnTheStandInRowMintsNothingAndKeepsTheStandIn` is behavioral (real fixture client, temp HOME, 31-B stand-in): refusal −32009 + `assertFalse(is_file AGENTS.md, 'the refusal minted no primary')` + stand-in intact + re-list unchanged + deferred 0600 mint on the later success. **Discrimination reason:** if create moves back, the latch still refuses (fresh 0 ≠ 31), so every other assertion stays green and exactly `the refusal minted no primary` reddens — fixer's M1 (eager-create restore → 1F on that assert, md5-clean restore) is consistent with this analysis. Zero `latchedBytes` remain in src (aborted over-reach attempt fully reverted).
2. **MINOR-2 cured.** MethodSchemas.php:7 import now load-bearing: both instructionWrite caps spell `InstructionFileLoader::MAX_DOCUMENT_BYTES`; const = 61_440 (InstructionFileLoader.php:105); `Schema::string(?int $maxLength)` takes the same int → emitted JSON identical by construction (no 61440/61_440 literal survives anywhere in candy-code src/tests; grep 0). Trio RESTORE honored: full-range diff contains NO docs/protocol/*.json, docs/SERVER.md, or generated.ts (committed byte-identical to base per §1/§1.8; weld regenerates — ProtocolSchemaDriftTest red is that documented rider, not a lane defect).
3. **MINOR-3 cured.** `answerQuickAdd()` re-derives `quickAddLine(props.modelValue)` before confirming: re-seats `quickAdd.value = {line: fresh}` when the draft still is a `#` line; on shape loss calls `cancelQuickAdd()` and returns false so line 365's caller proceeds to a plain send. Both new vitest pins read real (edited-draft appends '- second, better thought\n'; '## prose heading now' → chip retired, 0 instructionWrite, send emitted) and discriminate (without the hunk the stale '- first thought' append and the live chip would fail them).

## Gates (my own runs, linked worktree, plain pipe)

- phpunit `-c phpunit.xml --filter 'ContextMethods|ContextPanel|ContextInstruction|ContainedPathInventory|ReadPathCensus|Protocol'`: **299T/2612A/4F/2 deprecations**. The 4F = exactly the expected foreign/by-design set: ProtocolSchemaDriftTest (trio rider, item 2) + ContainedPathInventory x2 + ReadPathCensus `Chat.php|file_get_contents 2→3`.
- **Base attribution reproduced by disk proof:** the FIX commit touches none of Chat.php, ReadPathCensusTest, ContainedPathInventoryTest, or any src file named in the failing arms (Host/Commands/*, TranscriptProjector, EnvironmentBlock, PathJail); the full lane range likewise names none of those except ContainedPathInventoryTest's intentional in-step roster row (accepted r1, contract item). The ReadPathCensus arm's inputs (Chat.php + the test) are untouched across the whole range → red identical at base; r1 had already run both filters at pristine base with identical reds (REVIEW-1:24). Not lane findings; census/inventory rows ride the wave's W20-cure family closeout ledger.
- phpunit `--filter 'ContextInstructionWrite|ContextMethods'`: **12T/60A OK** (2 deprecations = the disclosed fixture pair).
- vitest SERIAL (`--no-file-parallelism`): composerQuickAdd + contextPanelEdit = **13/13 pass**.
- php -l clean on both src files.

## Notes (no action)

- Hand-trace normal case: first unlatched sentinel quick-add → latch skipped, currentBytes 0 → AtomicFileWriter mints 0600; refusal case leaves zero residue (PrivateDir::ensure of `.candy-code` dir on a doomed save creates a directory only — masks nothing, matches the TUI open-arm, not a finding).
- FIX-1.md's zombie-twin-writer hazard: worktree is clean at tip now; lead should still confirm no twin session before weld.
- r1 scope unchanged elsewhere: hot-file disjointness vs C-MCP-2.c, MEMORY.md doc row, §6 (no ruling touches this step), E646 N/A — nothing regressed by the fix.
