# C-SEC-5.c — REVIEW-1 (step loop, round 1)

Commit 13818260ebfc97ed6fa9a080360faa135f4023a8 on cc/C-SEC-5.c · tree clean · 1 commit on c36cc1982-era base.

## VERDICT: FINDINGS (2 doc-truth, step-local; zero CRITICAL/MAJOR)

## Gate results (all re-run by reviewer, cwd lane candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'TrustDialogTest'` → Tests: 12, Assertions: 15706 — OK (matches REPORT exactly).
- `--filter 'TrustDialog'` → 13T/15712A OK; the 13th is `Integration\BinCandyCodeWiring` dataset row "Tui/TrustDialog.php" (file-scan guard auto-adopting the new src file, green). "PHPUnit Deprecations: 2" reproduces on foreign filters (TuiLang alone) → pre-existing, not this lane.
- `--filter 'TuiLangTest|LangParityTest'` → 16T/4230A green (all 13 new keys census-reachable as quoted literals).
- five-guard → Tests: 133, Failures: 2 = ONLY the staleness pair (25,537 pinned vs 26,191 live) — expected weld/gate-owned shape.

## Axis checks
1. Scope: diff is exactly the 3 briefed files (src/Tui/TrustDialog.php +444, tests/Tui/TrustDialogTest.php +228, en.php +13). No suite-figure.json / durations.tsv / dist. DUR row for TrustDialogTest.php listed in REPORT. PASS.
2. Immutability/data-only: private ctor, `::new()` factory, all-promoted readonly props, bare accessors, sole `mutate()` copier; `update(KeyMsg)` TEA-style transition; imports only KeyType/KeyMsg/Width/TrustTarget/Lang/Theme/Style — no writer, no store, no fs, no async; `choices()` is the only output. KeyMsg named-param usage verified against sugar-core. PASS.
3. Key arms: dropping each of y/n/a/Esc reddens its pin (y→rows test + keys-after-resolution; n→skip test; a→grant-all exact-choices pin incl. skipped-keeps-answer; Esc→void-answers test) — non-vacuous. Grant-all list shape exact-pinned (census order, skips retain `granted:false`). Enablement prose verified truthful against src/Config/Trust/TrustTarget.php docblocks (hooks may load→run programs; MCP may start servers; McpPin re-records drifted commands via the same door; Commands may run the !-form; Settings contributes the layer with caller-supplied N). No overstatement found. PASS.
4. Frozen-roster line "Takes effect next launch" present (tui.trust.effect) and pinned twice (frame test + byte snapshot). NOTE: plan §6.1 contains no R-PER-11 row and the brief rules "ruling: none" — the design-sec.md sentence is the wording law and it is met. PASS.
5. Width invariant: sweep (cols 3..200 × rows {4,8,11,12,30} × 4 dialog states incl. dismissed/empty-root) asserts count==rows and Width::of(Ansi::strip)==≤cols; border/sided arithmetic hand-traced exact (box=2+2+inner, inner=box−4); box clamped ≤ $cols. Canonical 64x12 byte snapshot (SGR spelled via `~` nowdoc) deterministic under Theme::default(). PASS.
6. All 13 keys are quoted literals; exhaustive `match` per TrustTarget case (fifth-case-proof); zero KeyBindingRegistry touch (modal-local per brief). PASS.
7. §1.9: no glob-shaped or denial-vocabulary literals (space-bearing strings exempt from the glob corpus; GlobDialect green). %ae=%ce=detain@interserver.net (separate greps), 0 Co-Authored, single commit. Dormancy: grep across src/bin/tests finds only the class + its test — zero production callers. PASS.

## Findings
- [MINOR · step-local] `prompt_kit/findings/comparison-1026/lanes/C-SEC-5.c/REPORT.md:4` — claims "en.php +15 tui.trust.* keys"; the commit defines exactly 13 (`grep -c "'tui.trust." lang/en.php` = 13, and all 13 are used). Fix: change "+15" to "+13" (granted/failed belong to 5.d).
- [NIT · step-local] REPORT.md:12 attributes the extra row under `--filter TrustDialog` to "AccessorDeprecationTest"; it is `BinCandyCodeWiring` dataset "Tui/TrustDialog.php" (which also carries the pre-existing `D`). Fix: name the real class.

Neither touches shipped code; lane is weldable once the two report lines are corrected.
