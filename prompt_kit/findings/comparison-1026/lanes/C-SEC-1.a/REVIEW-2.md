# C-SEC-1.a round 2 — VERDICT: CLEAN (static-only; shell/php denied — 17/17 gate + %ce/trailer checks stay welder-owed, same as round 1)
Fix commit 7ab00c983 "C-SEC-1.a: review fixes (round 2)" sole commit atop ce0d57b43 (reflog ff10efc43->ce0d57b43->7ab00c983). Unpushed; reflog author literal Joe Huss <detain@interserver.net>.
1. MINOR cured verbatim-as-prescribed: BundleInstallTest.php:296-299 now truthful against the disclosed ELOOP mechanism (MAXSYMLINKS=40 ended the pre-fix descent; refusal unlinks the link as a link and never descends); "crash, not a failure" greps zero tree-wide.
2. Scope: Subcommands.php is_link arms :1963-1967/:1978-1982 and en.php:146 byte-match round-1 confirmed state; only on-disk delta vs round 1 is the prescribed docblock region.
3. REPORT.md:13 fixed in main tree ("4 new test methods + 1 renamed argv pin (+2 re-pinned helpers), no new test FILES").
4. Census consistent: 11 untouched + 4 new + 1 renamed = 15 methods, sentinel provider x3 -> 17 executions; supports 17T/+6T claim.
5. suite-figure.json still 25,537/609,443/3S era pin; staleness pair by design; durations untouched (no new files).
Welder owed: %ce/trailer/%b on 7ab00c983; --filter 'BundleInstall|Subcommands' 17/17; five-guard pair-only (live 25,543).
