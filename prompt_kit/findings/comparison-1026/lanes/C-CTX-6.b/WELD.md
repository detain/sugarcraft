# C-CTX-6.b — weld record (W11)

- Pick: 723418d56 → b72a169f2 (master 3b3e023e5 base). Durations rider: 2d8e06715.
- Conflicts: `candy-code/lang/en.php` only — soft-append, keep-both union (`chat.startup.personal` from C-TUI-7.a + `chat.attach.document_withheld` from the lane). `src/Backend/EngineBackend.php` auto-merged and is byte-identical to the lane post-image (`git diff 723418d56 b72a169f2 -- EngineBackend.php` empty) — the briefed C-PRV-5.c drift did not materialize: 27757dd94 touched Chat/Providers, not EngineBackend.
- Gates (merged tree, cwd candy-code, plain pipe -u LINES -u COLUMNS):
  - `--filter DocumentAggregateBudget` → OK, 11T/44A (expect 11/44 exact).
  - `--filter FileMentions|EngineBackend` → OK, 305T/1865A. Brief said "reviewer-exact 28T/105A" — that reviewer figure is false-to-tree on the merged side; the trio figure confirms: 316T = 11 + 305 exact. 1A over the brief's trio 1908A (1909A measured) = tree assertion drift, tests exact, 0F/0E.
  - `--filter DocumentAggregateBudget|FileMentions|EngineBackend` → OK, 316T/1909A (expect 316T/1908A — +1A drift disclosed).
  - "PHPUnit Deprecations: 2" appears in every run on this box (config-level noise, pre-existing, not a failure).
- Durations: row DocumentAggregateBudgetTest.php 1.986s/11T (junit per-testcase sum), set-equality 1484→1485, desc-invariant 4 pre-existing violations unchanged, suite-figure.json untouched.
- NO regen: pick diff carries zero protocol/dist/generated files (name-only grep rc=1); gen-command-docs/gen-tool-docs/gen-settings-doc `--check` all rc=0 on the merged tree — no drift rider needed.
