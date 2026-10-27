# C-AGT-2.c round 1 — VERDICT: FINDINGS (static-only; phpunit denied in reviewer sandbox — lead/fixer must execute gates)
Scope OK: exactly the 5 declared files (test at root tools/tests/ = BRIEF path; ci.yml:216 runs whole dir; no durations row).
Commit law OK: %ae=%ce=detain@interserver.net, zero trailers.
Premises code-verified: Bootstrap.php:3477 default->offline EchoProvider; composer.json minimum-stability:dev + 16 @dev siblings make global-require unresolvable as documented; bin/candy-code 100755 autoload candidate works from create-project target; NonInteractive.php:1919 result=message content.
runBodies extractor hand-traced: 3 bodies, block/one-line/dedent/EOF paths sound; heredoc content stays inside bodies (interpolation arm cannot heredoc-false-pass); env-indirection arm correct.
- [MINOR out-of-scope] docs/GETTING-STARTED.md:140 still teaches `composer global require sugarcraft/candy-code` — the broken form this step kills; root doc outside brief file list; DOC-ROW carry to closeout.
- [NIT step-local] interpolation regex /\$\{\{\s*inputs\./ is case-sensitive; GitHub context names resolve case-insensitively -> ${{ INPUTS.prompt }} evades guard; add `i`.
- UNVERIFIED: gates ActionYmlInstall 4T/20A, tools/tests 478T, five-guard pair-only, YAML safe_load — reviewed static-clean but must be executed post-fix.
