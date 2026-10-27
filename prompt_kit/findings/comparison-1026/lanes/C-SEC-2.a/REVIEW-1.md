# C-SEC-2.a — Review round 1 (base ff10efc43, tip 4e313722f)

VERDICT: CLEAN

Scope: exactly the 5 declared files (lineDiff.ts, PermissionCard.vue, lineDiff.spec.ts, permissionCardExtras.spec.ts, new permissionCardDiff.spec.ts). No dist/, no generated protocol, no PHP. One cohesive commit. %ae/%ce detain@interserver.net, no Co-Authored-By, worktree clean.

Correctness (hand-traced vs Edit::editList, Edit.php:441-471):
- [top-level, ...edits] in execution order; top-level pair first; absent/null/empty edits = single edit. Matches editList (null/[] short-circuit; JSON arrays are lists both sides).
- Fail-closed to null on everything editList refuses: missing/non-string top-level side, non-array edits, non-object entry, missing side in entry, empty old_string in edits. Empty new_string still previewed (editList allows it). Verified each JS branch against the PHP branch.
- replaceAllTruthy mirrors PHP (bool) for every JSON value that editList can receive (null/false/0/''/'0'/[] falsy; 'false' truthy). One display-only divergence: value = JSON object `{}` decodes to PHP [] (false) but JS reports true -> card would over-state "replace all occurrences". Safe direction (never under-reports a replacement). NIT, not required.
- Summary label carries (snippet-relative)/(N edits, snippet-relative); per-pair header gains (replace all occurrences). unifiedDiff @@ grammar untouched; lib/diff.ts:35/:43 skips --- /+++ header lines, so suffixed paths parse fine (components/diffSection/approvals/picker suites re-run green).
- PermissionCard: raw arguments details now unconditional beside ask-diff (data-testid ask-arguments), collapsed by default; no dangling v-else; docblock truth-flipped. Root causes 1 and 2 removed as named.

Tests: targeted trio re-run by reviewer, serial: 24/24 passed (matches report). Adjacent components.spec + diffSection.spec: 29/29 passed (my run); approvals + picker: 13/13 (my run).

Pre-fix red: reviewer sandbox denies scratch-tree writes (mkdir/ln/printf/node blocked; only git-prefixed commands land files), so the base-source run could not be reproduced here. Derivation from the actual base file (git show ff10efc43 lineDiff.ts, on disk): base returns ONLY the top-level pair for {old_string,new_string,edits:[...]}, has no replaceAll field or label, no snippet-relative marker, and returns pairs (not null) when a valid top pair masks a malformed/edits-only payload - each of which fails at least one new/re-written assertion (batched case, fail-closed set incl. edits:{}, edits:[{c}], empty edits entry, replace-all label, PHP-truthiness, snippet marker, both re-armed label pins, plus both mount assertions and the flipped extras pin). >=10 reds forced; report's 11 is consistent. Regression-test law (§1.5) holds.

Laws: §1.9 - no glob-shaped or **Refused:**-shaped literals in the new fixtures. No new *Test.php (web spec only; listed in report for the gate). §6: no ruling touches this step (brief: ruling none). Dist: not committed (§2 weld-only). e646/dormant/fork law: n/a.

Notes: vue-tsc rc=0 claim not reproducible here (npx vue-tsc denied); vitest transforms and weld dist rebuild will surface any type-level break. MINOR-free.

Review by cc-cmp step reviewer, 2026-10-27.
