# C-TOOL-6.e — FIX-1 (round 1)

Grounding: lane /home/sites/cc-cmp-C-TOOL-6.e, branch cc/C-TOOL-6.e, base d1cd13055; reviewed pick fcf16d15e untouched; fix pick e9a76e057fec83d012bfd7e7ab487780f51289f3 (cat-file'd same shell), picks=2, porcelain 0, %ae/%ce separate counts = 1/1 detain, Co-Authored-By 0. REVIEW-1.md authority read in full: 10,527 B @17:16:23, `VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR (1 step-local, 1 foreign-inherited) / 2 NIT. WELD-ELIGIBLE: YES.`

## §1 MINOR-1 (step-local) — disposition: DOCS-ALIGNED-TO-CODE, no behavior change

REVIEW-1 line 27 verbatim: "MINOR (step-local) design-vs-code: design says \"Args are validated against required and scalar types\"; implementation enforces required + rejects array/object but never checks declared JSON-schema TYPES (int supplied for a string property renders). Null→'' documented deviation (k). If the type-check is intended, add it in UserScriptTool::renderArgv; if not, the design cell should read \"scalar values only\"."

Decision: the review's own alternative ("if not, the design cell should read 'scalar values only'") was taken. Reasoning: the lane's CODE is already honest at the exact site — `UserScriptTool::renderArgv`'s docblock (src/Tools/UserTools/UserScriptTool.php:194-197) reads "Scalar values only: an array or object has no one-token shell reading..." and the refusal message names the real boundary ("must be a string or number; array and object argument values are not supported"). The class docblock makes no type-fidelity claim. Scalar-type fidelity is defense-in-depth beyond the security spine: every value rides `escapeshellarg()` before the join (REVIEW-1 Q2 PASS), so a bool/float/int supplied for a declared-string property renders as one quoted argv word — never an injection surface. Adding the declared-type check would be an invented behavior change (forbidden by the fix brief) for no safety gain. The only false sentence tree-wide was the design cell; grep confirmed no lane-owned docs row (PLUGINS.md/PERMISSIONS.md/SETTINGS.md/ENVIRONMENT.md) carries the claim.

## §2 Exact edits made (one file, prose-only)

`prompt_kit/findings/comparison-1026/design-tool.md:949` — 1 insertion / 1 deletion, nothing else:
- OLD: "    - Args are validated against `required` and scalar types."
- NEW: "    - Args are validated against `required`; scalar values only (an array or object value is refused before anything spawns). Declared schema types are not re-checked — an int supplied for a string property renders as one `escapeshellarg`-quoted word, which is the deliberate decision (round-1 review: docs aligned to code, no type-fidelity check added)."

Zero src, zero test, zero lang, zero candy-code/docs files touched. Commit: e9a76e057 "C-TOOL-6.e: review fixes (round 1)".

## §3 MINOR-2 (FOREIGN — restated, not cured) + §7 restatement for the welder

REVIEW-1 line 28 verbatim: "MINOR (out-of-scope, foreign-inherited, NEW ledger row): LaunchContextDelegatesTest[\"tools\"] red at base via df106a84c egressProxy() — welder should carry it to the 6.j-style debt list; do NOT pin-cure in this pick." Status: restated per lead — FOREIGN-DEBT-LaunchContextDelegates-t1014 ledger, report-only carry. Lane touched nothing about it.

§7 figures at r1 (UNCHANGED by this fix — no file any figure counts moved): durations.tsv THREE rows owed at battery, NEVER minted in-lane: UserToolLoaderTest.php **10T/78A** + UserScriptToolTest.php **11T/37A** (real-spawn family, slow bucket) + UserToolPermissionTest.php **6T/20A** = 27T/135A. suite-figure +27T-class carry as REVIEW-1 §7; lane never touched suite-figure.json/durations.tsv. Welder WARN restated: re-derive en.php span (1289-1299), seam census 37, roster 49, PROSE_SITES union BY TEXT on the merged blob; LaunchContextDelegates + PtyRelay×2 + BootstrapLaunchFormat doc-leg + GlobDialect 1F stay foreign debt, must not be absorbed.

## §4 NIT dispositions (verbatim-credited, recorded — zero churn)

REVIEW-1 line 29 verbatim: "NIT: UserScriptTool.php:137-143 probe-refusal prose is a third copy of Bash.php:618's wording (acceptable; consider a shared const later). NIT: builder's report cites the refusal-classes set as 8 + 3 I/O rows — matches 11 keys exactly (no action)." Disposition: NIT-1 accepted-as-is per the review's own "(acceptable...)"; a shared-const refactor is beyond a review fix round — recorded, no churn. NIT-2 is "(no action)" by the review's own words.

## §5 Gates run (post-commit, lane tree, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)

- `--filter 'TreeWideGuardRosterTest::'` → `Tests: 17, Assertions: 3939` OK (era-exact; 2 PHPUnit Deprecations = standing noise).
- `--filter 'DuplicatedTestHelperDriftTest::'` → `Tests: 14, Assertions: 204` OK (era-exact).

No other guard family applies: the changed file is a tracked prompt_kit design doc that no test scans (verified: only two tests cite prompt_kit paths and both are comment references, no content scan). No figure moved; no en.php touch ⇒ zero lang gates; zero glob-shaped literals added (the edit is markdown prose, no new `*` token).

background=true confirmed. REPORT.md/REVIEW-1.md byte-untouched.
