# C-AGT-5.h weld (batch-5 lane 7) — 2026-10-28

Picks: ecda4ca36 -> 676ab6c0f; 5ea83ca14 -> 0ebfdf9fe. Both cherry-pick -x, zero conflicts.
Verdict basis: REVIEW-2 r2 CLEAN (all five r1 MINORs genuinely cured, mutation battery 5/5).

Merged-tree gates (EXACT vs REVIEW-2):
- tools/tests --filter PrCommentBotExampleTest: OK 10 tests, 56 assertions.
- tools/tests dir: OK 499 tests, 2,636 assertions.
  (runner: candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php
   --no-configuration — directive's candy-core/vendor/bin/phpunit path does not
   exist in the main tree; REVIEW-1/2 approved the candy-code-form runner as identical.)
- candy-code --filter 'BootstrapLaunchFormatConstants|DocFigureProseDriftTest':
  Tests: 110, Assertions: 4,337, 2 disclosed base-era deprecations. Tests count EXACT;
  +10A vs reviewer's 4,327 is DocFigure live-derivation wobble after batch-5 doc welds (tolerated).

Carries: NO durations.tsv rows (zero candy-code/tests files touched — class claim true).
NO regeneration duty (workflows example + docs + tools/tests guard only). No figure carry.
Scope proof: pick file sets = 4 files then 3 files, exactly the reviewed diffs
(candy-code/docs/examples/pr-comment-bot.yml, candy-code/docs/CI.md, README.md row,
tools/tests/PrCommentBotExampleTest.php).
