# C-WEB-2.d — REVIEW-2 (round-2 reviewer)

VERDICT: CLEAN
0 CRITICAL / 0 MAJOR / 0 MINOR

Branch cc/C-WEB-2.d @ 051c71370ade65f8af94b3b0f6aaf2e0d733d662 (parent 8d7615e79 verified; merge-base with master aa5ddf638 = 484f313a9 — the 8-file branch diff vs base is exactly r1's file set, fix commit touches ONE file).

## r1 MINOR cure — VERIFIED (mutation re-derived on landed blobs)
`git show 051c71370:candy-code/tests/Protocol/CommandListSkillRowsTest.php`:
- Fixture widened `['keys'=>'x','Keys'=>'y']`; ALL r1-reviewed legs byte-identical (the 8d7615e79..051c71370 diff is exactly the fixture line + the 6-line why-comment + the new assertion).
- Claim chain re-derived, not trusted: CommandMethods::list veto is `isset($items[$skill->name]) || draft('/'.$name,$skills)===null` (landed CommandMethods.php list()); `SkillRegistry` keys `$this->skills[$skill->name]` case-sensitively (SkillRegistry.php:220/:348) so `Keys` reaches the loop; `isset($items['Keys'])` misses the builtin `keys` row (exact-case); `BuiltInCommands::forSpelling` map is exact-case (BuiltInCommands.php:130-143) and NO builtin spells `Keys` (grep over candy-code/builtin-commands: 'Keys' appears only as handler name `handleKeysCommand` + enum case `ShowKeys`, zero K-initial aliases) → the `strtolower` leg is the sole veto.
- Mutation reasoning: neuter strtolower → draft('/Keys') walks `userInvocable('Keys')` (exact key, found) → returns `$Keys` → row listed → ONLY `assertArrayNotHasKey('Keys',$rows)` reddens; the `keys`-builtin leg still green (isset catches the lowercase skill) and the count leg still green (`$n === 'keys'` excludes 'Keys'). Non-vacuous, single-leg discriminating — matches FIX-2's claimed red at :188.
- Worktree `git status --porcelain` empty: no mutation residue; src blob read via git show equals committed state.

## Gates I RAN (cwd=candy-code, plain pipe — my map allowed the runner this round)
- `--filter CommandListSkillRowsTest` → Tests: 9, Assertions: 147, 0F, 2 house deprecations — EXACT to FIX-2 (9T/147A; r1 state was 146A).
- `--filter 'CommandListSkillRows|BuiltInCommands'` → 19T/712A, 0F, OK.
ProtocolSchemaDriftTest 1F regen carry stands by-design (drift = the +1 `"skill"` enum line; untouched by the fix commit).

## Conventions / laws
Test-only fix; no new test METHODS (counts stable for durations), no doc rows/census/word-figures moved (§1.8 n/a), no glob-shaped or denial-vocabulary literals, PSR-12/strict_types file unchanged. NIT skips (diskRegistry name, inline FQN) accepted — no substance found. E646/proc_open/dist/suite-figure/durations: n/a/deferred per design.

## Welder carries (UPDATED)
1. Figure pair delta now **+9T/+147A** (was +146A) + tree-scan wobble.
2. Protocol regen PAIR in the pick commit unchanged (schema --write → gen:protocol → dist).
3. ARCHITECTURE dispatch-arms stays 65.
