# C-EXT-1.b — DONE, committed 09a76e8c6 on cc/C-EXT-1.b (base 351726864), never pushed

Verdict: all four fix bullets shipped — prompt/agent/http/mcp_tool types + unknown entry keys
skip-and-record via skippedEntries(); aggregate notice widened to name events, types and keys
(cap census entry stays 1); fromFile($path,$scope) names rows cc:{user|project|local}:{event}:{index}
with hookFileEntries passing the tier; PolicySnapshot gains userClaudeSettings fingerprint feeding
$userChanged, VERSION 1→2 (v1 record reads null = intended one-time reset). docs/HOOKS.md + class
docblocks truth-flipped (INT-4-P3 prose fixed).

Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- targeted 'ClaudeSettings|HookFile|PolicyFreshness|PolicySnapshot|BootstrapLaunch|HookManager|LegacyMigration': OK 227T/1462A (+2 pre-existing PHPUnit deprecations), 24s.
- five-guard: 133T/15335A, FAILURES: 2 = ONLY the staleness pair (pinned 25,537 vs live 25,623 — foreign era drift + this lane's additions; re-pin is gate duty, files forbidden here).
- broad 'Hook|Claude|Script': OK 2511T/17725A.

Files (12, +571/-179): src Hooks/ClaudeSettingsHooks.php, Cli/Bootstrap.php, Config/PolicySnapshot.php;
docs/HOOKS.md; tests ClaudeSettingsHooksTest, BootstrapHookFileTest, ClaudeSettingsHooksSkipRecordTest,
BootstrapLaunchFormatConstantsTest, PolicyFreshnessQuarantineTest, PolicySnapshotTest, DocFigureProseDriftTest,
LegacyMigrationTest. NEW test files: none (no durations row owed).

Deviations:
1. DocFigureProseDriftTest: brief's cc/INT-4 grep was right (no such pin), but the MEMORY.md `'local'`
   carrier roster reddened legitimately (SCOPE_LOCAL = 4th carrier, unrelated vocabulary) — judged IN with
   dated note, memory-scoped claim still true.
2. LegacyMigrationTest joined the 7-file list: its keyless record fixture hit the v2 shape gate; fixed by
   carrying the fingerprint + new post-move continuity assert.
3. Same-tier two-file collision proved UNREACHABLE (one launched root, scopes 1:1 with files) — docs/docblock
   reworded to the reachable form: hooks.yaml CLAIMING a generated cc: name still refuses launch-wide (pinned).
4. Seam: docs/DOCKER.md (~:144) paraphrases the old events-only notice wording; out of claimed files, still
   roughly true — closeout may want a glance.
5. ScriptHookClaudeDialectTest 'cc:PreToolUse:N' literals are fixture-chosen fromConfig names, never parser-
   minted — untouched, green.

Author law verified: %ae and %ce both detain@interserver.net (separate greps), zero Co-Authored trailers.

## round 2 fixes

Commit on cc/C-EXT-1.b atop 09a76e8c6 (message `C-EXT-1.b: review fixes (round 2)`), 6 files.

1. [MINOR step-local] the aggregate's `%d hook group(s)` double-counted a group
   carrying two different skips (SkipRecordTest had enshrined `3` for 2
   contributing groups). Chosen semantics: DISTINCT-GROUP CENSUS — the wording is
   kept, the number is made honest. `ClaudeSettingsHooks` records one identity per
   physical group that contributed a skip (`$skippedEventGroups` for the unmapped-event
   arm, `$skippedEntryGroups` for the entry type/key arm; key `source\0event\0group-index`,
   NUL separator so no path/event spelling can collide), exposed as `skippedGroups()`
   (`+` union, defensive against a group claimed by both records), cleared by the
   matching reset seams. `Bootstrap::reportSkippedClaudeSettingsEvents()` prints
   `count(skippedGroups())` instead of summing the per-name `groups` counters, which
   stay what they always were (a per-name skip diagnostic). Format constant
   byte-unchanged → cap census 1, StderrEmitterCensus green; the in-step flips are the
   method's literal allowlist (`'groups'` no longer appears in the body) plus a
   truth-sentence in the constant's docblock, and docs/HOOKS.md's notice paragraph
   ("group counts" → "number of distinct hook groups", parenthetical law).
   Pins: `ClaudeSettingsHooksSkipRecordTest::testAGroupCarryingTwoDifferentSkipsCountsOnceInTheAggregate`
   (new — mutation-proven: reverting to the per-name sum reddens it AND the flipped leg),
   `::testTheAggregateNamesSkippedEntryTypesAndKeysBesideEvents` (flipped `3`→`2`, dated note),
   `::testTwoGroupsCarryingTheSameSkipStillCountAsTwoGroups` (new — guards the over-correction:
   same-name across groups stays 2, green under both semantics by design).
2. [NIT] tests/Hooks/ScriptHookClaudeDialectTest.php fixtures now teach the shipped
   tier-qualified shape: harness `claudeRow()` `cc:PreToolUse:0` → `cc:user:PreToolUse:0`
   (+ its name-substring assertion), `testFromConfigReadsThePairAndDefaultsToNative`
   `cc:PreToolUse:3` → `cc:project:PreToolUse:3`. Meaning unchanged; a note names why
   (production can no longer mint the bare form).

DOC-ROW for weld carry (untouched here, out of scope): docs/DOCKER.md:144-148 still
paraphrases the notice as events-only and quotes removed wording.

Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- targeted 'ClaudeSettings|HookFile|PolicyFreshness|PolicySnapshot|BootstrapLaunch|HookManager|LegacyMigration|SkipRecord|ScriptHookClaudeDialect':
  OK 242T/1558A (+2 pre-existing PHPUnit deprecations), green.
- StderrEmitterCensus: OK 95T/19853A (cap census entry still 1).
- broad 'Hook|Claude|Script': OK 2513T/17733A (lane's 2511 + my 2 legs).
- five-guard: 133T/15335A, FAILURES: 2 = ONLY the staleness pair (25,537 pinned vs
  25,625 live = the lane's 25,623 + these 2 new tests; re-pin is gate duty).
- mutation: per-name-sum revert → exactly the 2 census legs red; cp+md5 restore clean
  (67ebb22a0867859da4422bd4a2b90a66).

Author law: %ae and %ce verified separately = detain@interserver.net, zero Co-Authored-By; never pushed.
