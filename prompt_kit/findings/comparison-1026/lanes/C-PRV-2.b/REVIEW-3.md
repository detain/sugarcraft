# C-PRV-2.b — independent CURE review, round 3 (reviewer of tip be3d3af18)

Date 2026-10-28. Worktree /home/sites/cc-cmp-C-PRV-2.b, branch cc/C-PRV-2.b, tip be3d3af18 (was a65b7302e, amended). Read-only review; nothing edited outside /tmp probe files named below.

## VERDICT: CLEAN

## Phantom-first (all disk-verified)
- `git cat-file -t be3d3af18` → commit; branch `cc/C-PRV-2.b`; `master..HEAD` = 1; porcelain = 0.
- `%ae` = `%ce` = detain@interserver.net (separate greps); message body carries zero trailers (plain round note).
- Diff = exactly 6 files (docs/ADDING_A_PROVIDER.md, Concerns/AnthropicMessagesWire.php, Concerns/OpenAiResponsesWire.php, OpenAIProvider.php, tests/Providers/OpenAiResponsesReasoningTest.php [new, 519L, 12 test methods], tests/Providers/OpenAiResponsesWireTest.php) — inside the step's declared fileset (src/Providers + tests/Providers + docs).
- `shapesEffortFor` count in HEAD blob OpenAiResponsesWire.php = 5 (≥2). Test `testTheIncludeLegRidesTheEffortGateAndNothingElse` present.
- Evidence (welder collect from BOTH dirs): main tree `prompt_kit/findings/comparison-1026/lanes/C-PRV-2.b/` {BRIEF.md, FIX-1.md, gate-guards.log, gate-superset.log, green-file.log, probe.php, probe-public.php, probe-private.php, probe-class.php}; /tmp `cc-cmp/C-PRV-2b-fix1/` {red-before.log, gate-guards.log, gate-superset.log, green-file.log, amend-msg.txt, base-wire.php, fixed-wire.php, HEAD.php, head-test.php, patch2.py, patch_tests.py, mini*.php, probe.php, seg.php, statement.php, two.php, dbg.py, mkmsg.py, orig-msg.txt}; /tmp `cc-cmp-lanes/C-PRV-2.b/` {traitconst.php + reviewer round-3 probes review3-m2probe.php, M2AdjudicationProbeTest.php, M2ClassThroughProbeTest.php}. NOTE: FIX-1.md is NOT under /tmp/opencode/cc-cmp-lanes/C-PRV-2.b as the brief assumed — it lives in the main-tree prompt_kit lane dir.

## M1 (reasoning-replay gate) — CURED, verified
OpenAiResponsesWire.php:204 wraps the replay foreach in `if ($this->shapesEffortFor($request->model))` — same predicate the request body's reasoning/include gate uses (:120) and the same door discipline the Anthropic arm applies (replay only while the live capability hook is on; shapesEffortFor == isReasoningFamily, OpenAIProvider.php:533). Byte-stability holds: `$request->model` is immutable per request. Mid-session switch off ⇒ tagged items stay in the store, off the wire; switch back ⇒ replay returns (both legs pinned: negative test :~331, positive pair at :289/:302-style shapes with o3-mini). Red-before regression genuine: /tmp/opencode/cc-cmp/C-PRV-2b-fix1/red-before.log (base src, tip tests) fails EXACTLY `testReplayStaysOffTheWireAfterAMidSessionSwitchToATextOnlyModel` with `0 => 'message'` vs `[0 => 'reasoning', 1 => 'message']` at :331 — neutering the gate reproduces the failure (mutation-reason, not re-executed). Neutering the replay collection reddens the batch/replay positive pins. The two pre-existing replay tests were re-armed with `model: 'o3-mini'` (provider+request) so they remain positive-door pins under the new gate — honest flip, disclosed in FIX-1.md.

## M2 (trait-const docblocks) — FIXER REJECTION IS CORRECT
Independent microprobe on the same interpreter as the gates (direct `php` invocation is permission-refused in this reviewer; probes run through the gated `vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php` on the identical PHP 8.3.6 runtime — READ-ONLY, files in /tmp above). Verbatim output of my probe (M2AdjudicationProbeTest, trait `ProbePublic { public const K = 'pub-ok'; }`):

    Runtime:       PHP 8.3.6
    1) M2AdjudicationProbeTest::testADirectPublicTraitConstAccess
    Error: Cannot access trait constant ProbePublic::K directly
    2) M2AdjudicationProbeTest::testBCrossTraitDirectAccessInsideMethod
    Error: Cannot access trait constant ProbePublic::K directly

and the class-through form (M2ClassThroughProbeTest, `class ProbeUsesIt { use ProbeConstViaClass; }` → `ProbeUsesIt::MARKER`): `OK (1 test, 1 assertion)`.
So for a PUBLIC trait constant, direct `Trait::CONST` access — top-level AND cross-trait from inside a method — is a fatal on PHP 8.3.6; only the using-class form works. The two docblocks (OpenAIProvider.php:344-346 "a trait constant becomes a class constant of the class that uses the trait, and direct `Trait::CONST` access is a PHP fatal across traits"; OpenAiResponsesWire.php:407-415 "PHP forbids direct access to another trait's constant") are literally TRUE for the repo's required floor. The round-1 reviewer's "false for public const in PHP 8.3" premise was wrong (the 8.2/8.3 RFCs added trait-const *declarations/visibility* and by-name const fetch, not direct trait-name access); the abstract-hook design stands. No change requested.

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd=worktree candy-code, linked; PtyRun never in filter) — ALL REPRODUCED EXACT
- `--filter OpenAiResponsesReasoningTest`: `D...........  12 / 12` → Tests: 12, Assertions: 32 (+ the 2 fleet PHPUnit deprecations, see foreign list) — matches fixer.
- `--filter 'OpenAiResponses|Providers'`: Tests: 2438, Assertions: 9024, OK — matches fixer exactly.
- `--filter Drift`: Tests: 593, Failures: 7 — identity re-listed by me: PermissionsIntroDrift x1, ReadmeJsonErrorContractDrift x4, ReadmeSuiteFigureDrift x2 (25537 pinned vs 27075 live, fleet-wide inherited drift with the lane's +2 inside) — NO new reds.

## Foreign (report-only, welder/closeout owns)
suite-figure staleness pair (live 27075 vs pinned 25537); PermissionsIntroDrift 'Schedule'/pattern-roster row; ReadmeJsonErrorContractDrift x4; the 2 "Data Provider method ... is not public" PHPUnit deprecations (tests/Config/AccessorDeprecationTest.php:168/:202 — fire during ANY --filter run's provider expansion, pre-existing era-style residue); plus standing ChatHostLangCatalogue / ProjectTier 2F / DreamPass / KeyHelpTest / MouseWiringTest / AutomaticCompaction (outside these filters, untouched).

## In-step guard law / project law audit
No suite-figure.json / durations.tsv / UiOnlyWireFilter touches (lane-forbidden files clean). Wire-test byte expectations flipped IN-STEP with the body change (summary:auto + include legs) — not deferred. No proc_open/timer/timeout introduced anywhere in the diff (E646 clean; the only "timeout" grep hit is the docs page restating the law itself). New strings are wire protocol constants, not user prose (no Lang::t obligation). Comments all WHY-shaped.

## Weld duties if absorbed here (per program)
1 durations.tsv row for candy-code/tests/Providers/OpenAiResponsesReasoningTest.php (junit per-file time sum; 12 methods recounted); no riders (fileset src/Providers + tests/Providers + docs only, disjoint from sibling lanes); closeout carries +12T vs master into the suite-figure/README re-pin (lane's net is +2 over r1 tip, +12 over master); collect evidence dirs listed above.
