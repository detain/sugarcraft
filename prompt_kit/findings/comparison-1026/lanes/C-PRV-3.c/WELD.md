# C-PRV-3.c — WELD (batch-14, W22-run)

Welded 2026-10-28 atop master e91eb1d47, wave position 3/5.

## Picks (zero conflicts, %ae/%ce detain, cherry-pick -x)
- 01c230f8c → aa6e9ecc2 (`ultrathink` raises effort for one turn)
- 1493c8e87 → 385d3face (review fixes; §4 cite)

## Merged-tree gates (cwd=candy-code, plain pipe) — ALL GREEN
- UltrathinkTurnTest → OK 18T/33A (exact)
- `--filter 'TurnRunner|EffortGate|EffortBudget|ThinkingCeiling'` → OK 70T/286A (exact)

## DUR row
- MINTED `candy-code/tests/Host/UltrathinkTurnTest.php 0.159 18`

## Notes
- REVIEW-1 + REVIEW-2 canonical (staged). MINOR-1 carry (REVIEW-2, deferred post-W22): the 3-copy effort-keyword finder predicate (Message.php:941 / SweepCommand.php:134 / TurnRunner.php:1082) awaits consolidation; NIT-1 exotic-fence fail-open folds with that fixer.
- GlobDialect check run LAST across batch-14: `--filter GlobDialectDifferentialTest` → OK 13T/366A; PathGlob.php:51 figure 310,245 holds on merged tree — no ed5fbffed-style re-cure needed.
- `PHPUnit Deprecations: 2` on every filter = pre-existing base family (confirmed in REVIEW-1).
