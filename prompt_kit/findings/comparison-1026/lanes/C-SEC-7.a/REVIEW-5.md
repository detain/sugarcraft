# C-SEC-7.a — REVIEW-5 (fresh round-5 reviewer, tip 35cd18d53, worktree /home/sites/cc-cmp-C-SEC-7.a)

VERDICT: FINDINGS
0 CRITICAL / 0 MAJOR / 5 MINOR (all step-local, one-to-few-line cures) / 2 NIT
Gates reproduced EXACT: guard quintet 137T/40408A OK (2 PHPUnit deprecations); LangParity|DocFigureProseDriftTest 99T/7347A OK. cwd=candy-code, env -u LINES -u COLUMNS, -c phpunit.xml --filter, plain pipe. Neither filter matches PtyRunToolTest (exclusion moot).

## 1. Traceability and scope — PASS with one flag
- diff master...HEAD: 27 files exactly as ledgered. 2e7791673 (r4) = docs/PERMISSIONS.md + docs/TROUBLESHOOTING.md + lang/en.php ONLY — the doc/lang-cure commit introduced ZERO code change (brief item 3 satisfied). %ae/%ce detain, no trailers.
- FIX-1.md 2288B / FIX-2.md 2203B / FIX-3.md 5825B (matches claimed) / REPORT.md 8724B — all on the BRANCH; main-tree copies stale, weld reads branch.
- Fourth-site judgment: PERMISSIONS.md:91-92 doctor parenthetical is IN-BOUNDS (declared file, same paragraph the cure was rewriting, identical reversed-doctor-claim class) — NOT scope creep. TROUBLESHOOTING.md:89 correctly LEFT alone (valid-value vocabulary row, not a default claim).
- FLAG: lang sort violation, finding M-5.

## 2. Reversal residue — FIX-3's grep was forward-only line-based; bidirectional + wrap-tolerant patterns find FIVE surviving current-tense surfaces (its residue grep NOT actually empty):
- M-1 candy-code/docs/DOCKER.md:184-188 "The bypass banner" — claims the banner "prints on every launch that runs without an approver, TUI and `-p` alike" and that "`-p` and `/bg` runs start in bypass precisely because nothing can answer a prompt". Post-lane FALSE (both have approvers; -p starts asking). Line-wrap hid it from the fixer's pattern. Cure: rewrite to the split (only daemon/server-context runs start in bypass; TUI and -p ask, banner fires on bypass launches only).
- M-2 candy-code/docs/SERVER.md:1352 — "Server sessions start in `default` (they ask), as the TUI does — not in the `bypass-permissions` that `-p` keeps." Reverse word-order hid the `-p`-keeps-bypass claim. Cure: "...that `/bg` daemons keep".
- M-3 candy-code/docs/SERVER.md:1354-1357 — "a local TUI's `/bg` is not a server spawn and keeps the documented headless default": after the split, /bg keeps the DAEMON (background-surface) bypass default; "headless default" now names the ASKING default — terminology inverted. Same mislabel at SERVER.md:856 "rather than the headless `bypass-permissions` default" (r4 adjudicated :856's substance true — server-spawn bypasses; fix the label in the same pass).
- M-4 candy-code/src/Background/BackgroundSessionRunner.php:692-693 (docblock only) — "A daemon's gate is the launch's headless one (`-p`'s, `bypass-permissions` unless configured otherwise)". Broken by the split: the daemon's gate is the BACKGROUND-surface default (BypassPermissions, Bootstrap.php:228/:7231-7232), NOT `-p`'s headless default (now Default). Cure: comment-only reword "headless one (`-p`'s)" → "background-surface one". Undisposed by FIX-3's adjudication list.
- M-5 candy-code/lang/en.php:898 — `cli.headless.permission_hint` inserted UNsorted (cli.noninteractive.json_schema_not_object → cli.headless.permission_hint breaks the one-sorted-run block law). Derived: base 410f5ac58 block fully sorted (584 keys, zero violations); master has exactly ONE foreign violation (serve.tls.missing→mismatch = the registered standing red); HEAD adds a SECOND — lane-introduced. No new red (same test, CliLangCatalogue :196, already red), but the weld/closeout must re-derive one roster; §1.8 in-step law says fix it here. Cure: move the key line to directly after `cli.doctor.unknown`.
- Cured sites confirmed matching the model wording (README.md:2632 / ENVIRONMENT.md:46): README :1861-1868 + :2601 + :2632, PERMISSIONS.md :25-34/:72-92, TROUBLESHOOTING.md :265-270, SETTINGS.md:1228 defaultText, en.php heredoc :491-494. Historical/narrative sites (ProtectFilesHook.php:35/:204, ConfirmRemoveHook.php:29, WebSearch.php:75 — "used to be"/"then" tense) read as honest history = same class as the already-dispositioned ArgvParser:1303 NIT → closeout ledger, not findings.

## 3. Code truth (re-verified at HEAD)
- `-p` path: NonInteractive.php:2150 passes PERMISSION_SURFACE_HEADLESS → Bootstrap.php:7231-7232 maps headless→PermissionMode::Default (asking/deny). Daemon path: permissionGate default param :7170 = BACKGROUND surface → :228/:7231-7232 keep BypassPermissions (the `??=`-style surface default). Subcommands.php:561 sole mode_split consumer, 3-slot (tui/headless/background). HeadlessPermissionPrompt.php:523 appends the new hint key. Prose now matches code.
- en.php: php -l clean; heredoc line widths 74/74/75/44 (≤ file max 78), no trailing-space/width break — carriage-pin risk nil.

## 4. NITs
- N-1 docs/PERMISSIONS.md:1179 "(then the TUI's default, still the console paths')" — incident-era narrative but "still" is present-tense stale vs the lane's own :72-79 rewrite; weld-minor row.
- N-2 "(C-SEC-7.a)" campaign-ID jargon inside user-facing lang string cli.headless.permission_hint (+README/PERMISSIONS prose cites) — house precedent exists (ServerConfig.php:582 AUT-6 cite); cosmetic, prior rounds accepted.

## 5. Already-dispositioned out-of-scope (restate only, not findings)
docs/HOOKS.md:1156; src/Cli/ArgvParser.php:1303 docblock; ENVIRONMENT.md:46 tail clause.

## 6. §4 WELD CARRIES (restated)
- durations.tsv: repo-root scripts/parallel-tests-durations.tsv has NO row for tests/Cli/HeadlessDefaultPermissionModeTest.php (verified 0 matches; lane-forbidden file — weld mints the row or it runs in zero shards).
- Suite-figure/README pair: net +10T from the new file (r3 ledger); staleness pair is the registered standing red — re-pin at weld per §1.8.
- Lang rulings carried: mode_split 3-slot re-key (tui/headless/background); new key cli.headless.permission_hint; appLaunch.notice.headless_default; en-only-locale decision. If M-5 cured, the key's final sorted position is after cli.doctor.unknown.
- Corrected REPORT.md + FIX-1/FIX-2/FIX-3 (+ this REVIEW-5, delivered to main tree because the reviewer write-permission map refuses worktree paths) are canonical on BRANCH cc/C-SEC-7.a for the FIX files; weld copies REVIEW-5.md onto the branch path.
- Foreign stand-ins re-proven at HEAD (fixer's stash proofs): CliLangCatalogue :196, PermissionsIntroDrift :59, HelpTest 6F — all base-red, zero NEW reds from the lane (M-5 rides an already-red arm).
