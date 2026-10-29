# C-SEC-6.d REVIEW-1 (fresh round-1 reviewer, READ-ONLY)

Target: branch cc/C-SEC-6.d @ ae2da2543 (parent e91eb1d47; both cat-file type=commit verified; ae2da2543 is a single commit on top of parent). Author/committer %ae/%ce each = detain@interserver.net, zero Co-Authored trailers, subject `C-SEC-6.d: permission.audit method + AuditLogReader + RulesPanel view`. Diff = exactly the 8 files the brief names (+1015); trio (schema.json / SERVER.md / generated.ts) carries zero `permission.audit` hits → restored per §1.8 protocol-lane law. No weld-only files (suite-figure.json, durations.tsv, dist/) touched.

## VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 2 MINOR (step-local) / 1 NIT

## Re-derivations (all match builder claims)
- PHP reader+method: `--filter 'AuditLogReaderTest|PermissionAuditMethodTest'` → **21 tests / 89 assertions OK** (2 PHPUnit deprecations are PRE-EXISTING `tests/Config/AccessorDeprecationTest` private-data-provider noise, not lane files).
- ReadPathCensus|AuditHook: **57T/816A** exact; SELF_LOCATED row `Hooks/BuiltIn/AuditLogReader.php|fopen` in-step accepted.
- Protocol band at committed tree: tests/Protocol **151T/1278A + 1F** — the sole failure is ProtocolSchemaDriftTest (compares uncommitted schema.json vs PHP MethodSchemas; §1.8: judged on weld tree, not lane worktree) + ServerProtocolDocumentationDriftTest 6T/59A green = 157T/1337A vs claimed 157T/1338A with trio regen (±1A on the drift success path; structure matches).
- Vitest serial (`--no-file-parallelism`): rulesPanelAudit+rulesPanelSaved **9/9**; dormantRoster+panels **14/14** (dormantRoster green — RulesPanel caller lands same commit, no roster entry owed).
- vue-tsc evidence: builder log 20 errors == baseline 20; all pre-existing shapes (contextSources/specs), zero RulesPanel/audit errors.
- Trio snapshot: /tmp/opencode/cc-cmp-C-SEC-6.d/trio-diffstat.txt = **+76/+1/+20, 0 deletions** (pure additive — welder regen is deterministic append).
- Mutation (e) re-proven: clearstatcache(true,$path) line removed → **exactly 3F** (stat-cache regression pin + 2 double-read method tests); restore via `git checkout -- <file>`, porcelain clean, HEAD run 21T/89A green again. Load-bearing fix confirmed.

## (a) Scope question — RESOLVED, no finding
`Scope::owner()` returns all four scopes incl. approve (Scope.php:20-23); Dispatcher.php:276 grants every live connection owner(); requireScope at Dispatcher.php:342 → wire-level -32003 refusal is unreachable in this build for Approve methods, exactly as for siblings permission.respond (PermissionMethods.php:68), permission.forget (:71), context.trust (ContextMethods.php:55). Audit data IS the owner path (owner == Approve). RulesPanel calls permission.audit in production → feature live, NOT DORMANT, NOT REACHABLE-GAP. Registry-introspection pin of `Dispatcher::methods()->get('permission.audit')` scope is the house idiom (ShellsMethodsTest precedent). The refusal leg the brief asked about is covered honestly: unreadable→`unreadable:true` never throws, pinned at both reader and method level.

## (b) Untrusted-content display — clean
RulesPanel renders every audit field via `{{ }}` interpolation only (no v-html); row shows time/tool/verdict/kind/reason — never the raw input field. HOME/TMPDIR-empty-log note present and truthful (data-testid=rules-audit-note).

## (c)(d) Bounded tail — correct
64KiB window walk-back, `array_pop` torn-line drop, 5MiB rotation `.1` stitch oldest-first, overshoot capped at exactly 200; window-cut + rotation cases pinned by real-AuditHook-produced fixtures. Huge line cannot eat the cap (cap counts complete lines; torn tail discarded before cap).

## Findings
1. **MINOR (step-local, doc-truth)** `candy-code/src/Hooks/BuiltIn/AuditLogReader.php` (~parseLine docblock): claims the first-marker ` => `/` =! ` split is guaranteed "by the producer's own construction order for every line it writes" — FALSE: a DENY line whose tool input itself contains ` => ` (input is only cut+scrubbed, never escaped) misreads as an allow row. Display-only, and the later ambiguity paragraph is honest — but the guarantee sentence lies. **Fix:** reword to "…for every line whose tool input contains no marker of its own" (or drop the guarantee clause; ambiguity note already carries the truth).
2. **MINOR (step-local, doc-truth)** `candy-code-web/src-web/components/panels/RulesPanel.vue` loadAudit docblock: "a failed rules read must not blind the audit one (and vice versa)" overclaims — the 'Recent decisions' block nests inside `<template v-else-if="loaded">`, so a rules failure does hide already-fetched audit rows. **Fix:** correct the comment to match render gating (defensible product choice), or lift the audit block out of the loaded gate; welder fix-now candidate.
3. **NIT (no action):** session-filter narrows only the last `limit` raw lines per file → sparse-match trails may underfill; bounded-by-design tail heuristic, matches cap semantics.

## Weld carries
- durations.tsv rows: AuditLogReaderTest.php (15T), PermissionAuditMethodTest.php (6T) (+ rulesPanelAudit.spec.ts vitest roster if maintained).
- Trio regen (+76/+1/+20, additive) → then ProtocolSchemaDriftTest must go green on weld tree; verify.
- dist rebuild rider for candy-code-web (tracked dist/).
- Suite-figure re-pin +21T (live enum).
- dormantRoster vue-tsc line-shift note: pre-existing dormantRoster.spec.ts TS2532 error moves line 56→59 — not a new error.
