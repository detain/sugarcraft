# C-PRV-1.c — weld report (written by welder; builder died pre-REPORT)

- Landed: lane commit 0a93e9a9b cherry-picked clean onto master as 0e25ae5c7 (en.php auto-merge, zero conflicts).
- Files: candy-code/lang/en.php (+1 cli.sub.login.type_mismatch), candy-code/src/Cli/Subcommands.php (provider-block merge, type-clash refusal, echo-off Tty raw read via $loginTty seam, finally restore; $loginKeyRead kept TTY-free), candy-code/tests/Cli/LoginSubcommandTest.php (41 methods: 11 new + 4 seam probes + fork-safety probe).
- Gates at merged tree: --filter 'Login' OK 76T/1500A (2 PHPUnit deprecations pre-existing); five-guard 133T/15341A, ONLY the ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 25,653); php -l clean x3; gen-command/tool/settings-doc --check all "up to date"; no Protocol/web touched.
- Reviewer adjudication: r1 CLEAN — the lead disproved the reviewer's MAJOR/MINOR on disk (production caller Subcommands.php:970 → loginKeyLine():1047-1070 routes through the seam with real openLoginTtyHandle default; type_mismatch key present en.php:1013; "false equivalence" was an over-strict NIT — the pin tests the trimmed+newline round-trip contract). REVIEW-1.md never landed on disk (reviewer file lost); verdict preserved here.
- No new test files → no durations row; suite-figure/README re-pin stays closeout duty.
