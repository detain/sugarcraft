# C-SES-6.c — REVIEW-2 (round 2, independent)

VERDICT: CLEAN

- Tip: bcb1769f631b8c379230b00feae0e1bb2a902f37 on cc/C-SES-6.c (base cf0cfd0be, follow-up 38ae2f185). All 3 hashes cat-file-true; worktree clean.
- MAJOR-1 CLOSED (verified, not claimed): TreeWideGuardRosterTest run targeted at tip (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml --filter TreeWideGuardRosterTest):
  `Tests: 17, Assertions: 3559, PHPUnit Deprecations: 2.` — 0F/0E, figures exact. The 2 deprecations are pre-existing tree-wide (main-tree run at a foreign lane tip shows the same `PHPUnit Deprecations: 2` on this filter) — not lane-attributed.
  Rows land in WALKS_A_DIRECTORY_THE_TEST_MADE alphabetically between Cli/HelpTest.php and Cli/JsonSchemaEnvelopeTest.php; spellings match the live sites under the guard's whitespace-flattening derivation (guard :4314 `preg_replace('~\s+~','')`) — live `\glob($this->root . '/*')` and `new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)` at HomebrewFormulaTest:50 / InstallScriptTest:483. Classification comments truth-checked: `cc_ses6c_hb_*` prefix real (HomebrewFormulaTest:44), `purgeFixtureTree()` real (InstallScriptTest:477).
- Scope: fix commit touches ONLY candy-code/tests/TreeWideGuardRosterTest.php (+8) and candy-code/tools/install.sh (1 line). Combined cf0cfd0be..bcb1769f6 = the BRIEF's 6 files + the guard roster (in-step §1.8 law); no regression to r1's approved six.
- NIT-1 CLOSED: install.sh:308 now `curl -fsSL -D "$TMP/headers-$page" -- "$next"` — `--` after options, before the header-derived URL; :301 BASE_URL leg untouched and already `--`-guarded; :383 download leg unchanged (`-o "$2" -- "$1"`). Direct `bash -n`/`sh -n` refused by this reviewer's permission map; equivalent proof: InstallScriptTest:76-78 runs `sh -n` on the tip script and passes (I ran both new test files standalone: OK, 20 tests/142 assertions).
- NIT-2 (README:154 "no environment variables" wording): out-of-scope closeout carry per r1 ruling; disclosed in REPORT.md FIX-1 block.
- Phantom law: all hashes real; FIX-1 evidence block present at REPORT.md:45-58 with exact figures.
- Author law: both commits A=C=detain@interserver.net, zero Co-Authored trailers.
- New tests green standalone at tip; InstallScriptTest honors the BRIEF's curl-absence skip (:47).

Findings: none. WELD-READY.
