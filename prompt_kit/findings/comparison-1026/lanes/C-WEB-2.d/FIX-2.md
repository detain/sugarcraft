# C-WEB-2.d — FIX-2 (round-1 MINOR cure)

Base tip 8d7615e79 (verified via git log before edit).

## Finding cured
MINOR (step-local, REVIEW-1 §Findings): no listing-side pin for a CASE-VARIANT
builtin shadow (skill `Keys` vs builtin `keys`).

## Measurement first (not guessed)
- `CommandMethods::list()` clash is exact-case (`isset($items[$skill->name])`) —
  `Keys` does NOT hit the builtin `keys` entry.
- The veto that removes `Keys` is `SkillSlashInvocation::draft()`'s built-in
  re-check `forSpelling($name) || forSpelling(strtolower($name))`
  (SkillSlashInvocation.php:52-57) — `forSpelling` is an exact-case map
  (BuiltInCommands.php:130-143), so the strtolower leg is the load-bearing half.
- Real behavior pinned: the `Keys` skill casts NO row at all, and the builtin
  `keys` row stays single — matching dispatch, where `/Keys` also stays prose
  through the same draft() gate (list==admission by construction).

## Change
candy-code/tests/Protocol/CommandListSkillRowsTest.php
`testABuiltInSpellingKeepsItsRowAgainstAShadowingSkill`:
- fixture widened `['keys' => 'x', 'Keys' => 'y']`;
- every existing leg byte-identical;
- NEW: `assertArrayNotHasKey('Keys', $rows)` + why-comment (isset is exact-case;
  the gate's case-insensitive built-in re-check is what vetoes the shadow).
- ext4 dirs `keys`/`Keys` are distinct; register() keys by `$skill->name`
  case-sensitively, so both skills genuinely reach the loop (probe-checked).

## Counts
- CommandListSkillRowsTest before: 9T/146A (REVIEW-1 gate) → after: **9T/147A**
  (+1 assertion; test count unchanged — durations carry re-pin: 9T, A 146→147).

## Gates (cwd=candy-code, plain pipe, -u LINES -u COLUMNS)
- `--filter CommandListSkillRowsTest` → 9T/147A, 0F, 2 house deprecations. OK.
- `--filter 'CommandListSkillRows|CommandMethodsMcpPrompts|ServedSkillSlash|SkillSlashInvocation|ProtocolSchemaDrift'`
  → 39T/633A, 1F = ProtocolSchemaDriftTest ONLY (by-design regen carry until
  weld, REVIEW-1 P3 — drift is the +skill enum line, unchanged by this fix).
- `--filter BuiltInCommandsTest` → 9T/559A, 0F. OK.
- Mutation proof (non-vacuity): neutered the `strtolower` leg in
  src/Skills/SkillSlashInvocation.php → exactly the new assertion FAILS
  ("array does not have the key 'Keys'", line :188). Restored, md5
  5e40ba546c60225636945fb4f7ee2912 verified equal, `git status -- src/` clean.

## NITs (REVIEW-1) — both skipped, with reason
- `diskRegistry` name shared with ServedSkillSlashTest:72: guard tolerates it;
  rename is cosmetic churn in a weld-pending lane, not trivial-by-risk.
- inline FQN in CommandMethods: reviewer themself says "consistent, ignore".

## Scope
One file, PHP test only. No web touch → no vitest duty. Protocol trio, dist,
suite-figure.json, durations.tsv untouched (git diff --stat: 1 file).

## Commit
051c71370ade65f8af94b3b0f6aaf2e0d733d662 (`git cat-file -t` = commit) on
cc/C-WEB-2.d atop 8d7615e79; 1 file, +9/−1. Author+committer detain
(%ae, %ce separate greps = 1, 1), no Co-Authored-By (0), tree clean after.
