# C-SEC-6.d FIX-1 (round-1 fixer, doc-only)

Branch cc/C-SEC-6.d: ae2da2543 -> **bf4155efb** `C-SEC-6.d: review fixes (round 1) — two doc-truth corrections`. 2 files, +11/-7, all comment/docblock text; zero executable lines touched. Author+committer verified %ae/%ce each grep-count 1 for detain, zero Co-Authored trailers. No new tests: neither sentence was asserted by any existing test (grep for "construction order"/"blind the audit" in candy-code/tests + web specs: only an unrelated Bootstrap::tools() comment hit).

## Finding 1 — AuditLogReader.php parse guarantee (MINOR, doc-truth) — FIXED
`candy-code/src/Hooks/BuiltIn/AuditLogReader.php` class docblock (~:43): "guarantees for every line it writes" -> "guarantees for every line whose tool input contains no marker of its own" (reviewer's exact prescription), rewrap of the following two lines only. Truth basis re-checked against producer: AuditHook.php:25-26 formats put `<input>` before the ` => `/` =! ` marker and the input is mb_strcut+mb_scrub only (AuditHook.php:463/:509/:538) — never escaped, so a marker-bearing input mis-splits; the scoped clause now says exactly that, and the existing "a hand-edged line can disagree" sentence stays true.

## Finding 2 — RulesPanel.vue loadAudit docblock (MINOR, doc-truth) — FIXED
`candy-code-web/src-web/components/panels/RulesPanel.vue` (~:92-98): "a failed `rules` read must not blind the audit one (and vice versa)" replaced by the render-gating truth: the reads fail independently (audit still fetches+stores rows under a rules failure; audit failure never disturbs the policy view) and the template keeps the decisions block hidden until the policy state loads. Component NOT restructured, per finding prescription — gating verified: 'Recent decisions' (:157) nests inside `<template v-else-if="loaded">` (:125), so the error branch (:123) hides it.

## NIT — NOT fixed (weld-carry only, per brief)

## Gates
- `php -l` AuditLogReader.php: No syntax errors.
- cwd=candy-code, plain pipe, env -u LINES -u COLUMNS, -c phpunit.xml:
  `--filter 'AuditLogReaderTest|PermissionAuditMethodTest'` -> **21T/89A OK** (2 PHPUnit deprecations = disclosed pre-existing AccessorDeprecationTest noise, REVIEW-1 same shape).
  `--filter 'ReadPathCensus|AuditHook'` -> **103T/989A OK** (same 2 pre-existing deprecations; no drift from the docblock edit).
- .vue change comment-only inside <script> docblock: npm/vitest skipped per brief.
- Never run: full suites, PtyRunToolTest keystone, caliber, stash. suite-figure.json/durations.tsv/dist untouched.
