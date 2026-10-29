# C-SEC-6.d — REVIEW-2 (round-2 verification)

VERDICT: CLEAN

Date: 2026-10-29. Reviewer: fresh r2 lane. Tip bf4155efb (fixer "review fixes (round 1)") over ae2da2543 (build); base e91eb1d47.

## Fixer diff vs round-1 prescriptions — exact match

- `git show bf4155efb`: doc-only, exactly 2 files, +11/−7. Zero executable lines.
  - `candy-code/src/Hooks/BuiltIn/AuditLogReader.php` class docblock: guarantee reworded to the scoped form ("…for every line whose tool input contains no marker of its own") — the exact wording REVIEW-1 MINOR-1 prescribed.
  - `candy-code-web/src-web/components/panels/RulesPanel.vue` `loadAudit` docblock: corrected to match render gating (decisions block lives inside the `v-else-if="loaded"` body, so a rules failure hides it while the fetch+store still runs) — the MINOR-2 prescribed remedy (option (a), comment correction; block-lift declined, acceptable).
- Nothing else changed in the fixer commit (file list + stat verified).

## Producer-truth re-check (independent, AuditHook.php)

- `AuditHook::line()` sprintf emits `[%s] %s %s %s %s` = time, session, tool, **input**, then a tail beginning `' => '` (:401), `'=! DENY'` (:424) or `'=! WITHHELD'` (:439). Input is therefore positioned **before** the verdict marker.
- `AuditHook::field()` (:536+) escapes only control chars (\x00–\x1F, \x7F, NEL/LS/PS); input arrives `mb_strcut`-cut (:515) + `secretFree` scrubbed, and spaces are never escaped — so an input containing `' => '`/`' =! '` would mis-split the parser's first-marker search (`AuditLogReader::parseLine` :142, regex + `strpos`).
- Conclusion: the scoped wording is truthful. The old unqualified guarantee was the defect; it is removed.

## RulesPanel.vue claim-by-claim

All five comment claims verified against the file: audit fetch fires before the rules await; audit failure touches only `auditRows`/`auditUnreadable`; generation stamp invalidates both; decisions hidden under rules failure (template branch order); `auditRows === null` renders the older-server note, not an error. New comment matches.

## Whole-lane diff vs BRIEF.md

- `git diff master...cc/C-SEC-6.d --stat` = 8 files, +1019 (RulesPanel.vue 67, rulesPanelAudit.spec.ts 125, AuditLogReader.php 272, PermissionMethods.php 28, MethodSchemas.php 15, AuditLogReaderTest.php 321, PermissionAuditMethodTest.php 184, ReadPathCensusTest.php 7) — matches the brief's file list; ReadPathCensusTest +7 = in-step §1.8 SELF_LOCATED fopen roster row.
- Generated trio (schema.json / SERVER.md / generated.ts) absent per §1.8 protocol-lane law; no suite-figure.json / durations.tsv / dist touches; no `proc_open` sites; E646-clean (no LLM calls).
- Production reachability: `permission.audit` registered at `Scope::Approve` (PermissionMethods.php:72), `audit()` threads sessionId + clamped limit → `AuditLogReader::tail()`; MethodSchemas.php:339 carries the wire schema; RulesPanel `loadAudit` issues the real `connection.request('permission.audit', …)`.

## Gates (targeted only, plain pipe, env -u LINES -u COLUMNS, cwd=candy-code)

- `php -l` × 6 touched PHP files: all "No syntax errors detected".
- `vendor/bin/phpunit -c phpunit.xml --filter 'AuditLogReader|PermissionAuditMethod'` → **OK (22 tests, 95 assertions, 2 PHPUnit deprecations)**. The deprecations are the disclosed pre-existing AccessorDeprecationTest shape (also present in REVIEW-1/FIX-1 logs).
- 22T vs REVIEW-1's 21T (class-name filter): the substring filter additionally matches the dynamic provider row "No root resolving site falls back to bare getcwd" data set `Hooks/BuiltIn/AuditLogReader.php` in the tree-wide root-resolution census — generated automatically from the new src file, zero test-file edit, green. Not a finding; informational.
- GlobDialect/refused-shape scan: no glob-shaped literals, no `**Refused:**`-shaped literals in lane files.

## Round-1 NIT (weld-carry, non-blocking, unchanged)

- The r1 NIT remains a weld-carry suggestion only (doc-row collection at weld); it is not step-local and does not gate this verdict.

Zero CRITICAL, zero MAJOR, zero step-local MINOR at bf4155efb. Lane CLOSED for review; ready for weld.
