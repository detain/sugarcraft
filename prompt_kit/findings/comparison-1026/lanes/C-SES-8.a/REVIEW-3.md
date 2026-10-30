# C-SES-8.a — REVIEW-3 (round 3, fresh, fix chain landed)

VERDICT: CLEAN — ready to weld. 0C/0M/0m. MINOR-1 cure verified real and non-vacuous; MINOR-2 correctly a weld-carry.

## Re-anchor
- /home/sites/cc-cmp-C-SES-8.a, branch cc/C-SES-8.a, HEAD = 2d1463e80 (cat-file: commit); master = 1525b1832 (worktree AND main tree agree); merge-base 574e69f1d (base at cut). Status clean. rev-list = 4 (b95a0e181, 06352783a, 4a1f4643d, 2d1463e80).
- Authors %ae/%ce all detain 4/4 (separate fields), zero trailers.
- Per-commit `git show --stat`: b95a0e181 = 3 PHP (Dispatcher/UsageMethods/MethodSchemas); 06352783a = web 4 + test 1; 4a1f4643d = UsageMethodsTest.php ONLY (+31/−1, src byte-untouched); 2d1463e80 = FIX-3.md only. Forbidden-set grep over full diff (dist/ generated.ts generated.spec.ts schema.json SERVER.md suite-figure durations.tsv lang/) = 0. NO sneaked generated file anywhere. durations.tsv has NO UsageMethodsTest row (weld mints).

## MINOR-1 cure — VERIFIED
- Seed now carries a 400tok/1.50/'older-model' row backdated 40d via raw-PDO UPDATE (EnhancedSessionStore usage_records has ts/session_id/model columns — verified); vacuity guard real: assertTrue(execute) + assertSame(1, rowCount) — zero-hit UPDATE cannot silently re-vacuate.
- New no-window whole-ledger leg at :165 {2,520,2.0}; windowed legs tightened to full totals at :169 {1,120,0.5}. Cited line numbers match HEAD exactly.
- Independent mutation-reason (not run): forward null → windowed legs answer whole ledger {2,520,2.0} ≠ {1,120,0.5} → :169 red; always-forward-7 → no-window leg answers {1,120,0.5} ≠ {2,520,2.0} → :165 red, both window legs green (40d outside 7 AND 30; today inside both) = exactly 1F each, matching FIX-3's M1/M2 proofs. Both directions discriminate. The invalid-window legs (15, '7') gate before forwarding, stay green under both — no cross-talk.
- 40d edge law: cutoff = floor(time()/86400)*86400 − (N−1)*86400 ≥ time() − N*86400 ≥ time() − 30*86400 > row ts = time() − 40*86400 — strictly outside both windows for ANY wall clock, UTC-epoch math, timezone-proof. Floats exact (0.5+1.5=2.0, assertSame-safe).
- Gates reproduced on lane tree: `--filter UsageMethods` → **Tests: 9, Assertions: 54** OK (2 baseline deprecations family); `--filter 'GlobDialect|DuplicatedTestHelperDrift'` → **28T/568A** OK (backdateUsage name-unique, SQL placeholders not glob-shaped).

## MINOR-2 — correct weld-carry
- generated.spec.ts untouched (0 hits in lane diff). On the restored-trio tree the pin `METHOD_SCOPES['usage.rollup']` would RED (key absent from committed generated.ts) — the fix legitimately rides the weld trio-regen. WELD DUTY restated: after gen:protocol regen, add the scope pin per design's "(scope pin)" modify line.

## Residual scan
- Reachability: Dispatcher.php:29+151 register on real roster; MethodSchemas.php:821 row; SidePanels.vue:107 v-else-if guard mount; panels.ts 'usage' appended LAST (PANELS + PANEL_LABELS); UsagePanel.vue:62 real connection.request('usage.rollup'). Round-3 touched none of these.
- §1.8: trio regen-verify-RESTORED (not committed) ✓; no census/figure/doc rows owed in-step; dormant web method has its caller.
- Fixtures: no glob-shaped, no Refused-shaped literals (:165/:169 messages space-bearing plain prose).

## WELD CARRIES (standing)
1. scripts/parallel-tests-durations.tsv row for candy-code/tests/Protocol/UsageMethodsTest.php.
2. suite-figure.json + README figure re-pin (lane is +9T; live-vs-pinned staleness pair is standing §7 duty).
3. Protocol trio regen (schema.json/SERVER.md/generated.ts) + generated.spec.ts scope pin (MINOR-2); vue-tsc's 38 UsagePanel `keyof Methods` staleness errors vanish on regen.
4. vitest re-run at weld on regen'd tree (usagePanel/dormantRoster/generated/panels).
