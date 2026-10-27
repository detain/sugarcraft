# C-SEC-2.a — DONE · commit 4e313722f (branch cc/C-SEC-2.a, base ff10efc43)

C-SEC-2 (WEB-5-P1 + WEB-5-P4): the web Edit permission card now shows every
edit and the raw arguments.

## What landed
- `candy-code-web/src-web/lib/lineDiff.ts`
  - `diffPairsFor()` mirrors `Edit::editList()`: pair list is
    `[top-level, ...edits]` in execution order (edit 1 = top-level pair).
  - Fails closed to null (raw dump) on anything editList itself refuses:
    missing/non-string top-level side, non-list `edits`, malformed entry,
    empty `old_string` in an `edits` entry. Absent/null/empty `edits` = the
    plain single edit.
  - `replace_all` read with PHP `(bool)` truthiness (null/false/0/''/'0'/[]
    falsy; string 'false' truthy) — top-level and per entry — surfaced as a
    `(replace all occurrences)` label on that pair's hunk headers only.
  - Edit summary marked `(snippet-relative)` (single) / `(N edits,
    snippet-relative)` (batched) — WEB-5-P4 — without touching the
    `@@ -N,M +N,M @@` grammar `parseUnifiedDiff` matches.
- `candy-code-web/src-web/components/PermissionCard.vue`
  - raw `arguments` `<details>` renders always (was `v-else`), beside the
    `ask-diff` preview; new `data-testid="ask-arguments"`; collapsed by
    default so card height is unchanged (step risk note honored).
  - preview docblock truth-flipped in-step (fall-back wording → rides-beside).

## Tests (all serial, `npx vitest run ... --no-file-parallelism`)
- lineDiff.spec.ts modified: batched case rewritten to the real
  `{old_string,new_string,edits:[…]}` shape (asserts every pair's lines incl
  `exfiltrate()`, summed counts 3/3, editList order, three `@@` headers);
  NEW cases: malformed-extra → null (fail-closed set), replace_all label
  per pair (no inheritance), PHP-truthiness of replace_all, snippet-relative
  summary marker; two exact-label pins re-armed for the new label.
- permissionCardExtras.spec.ts:38 flipped (pinned the defect "no other
  details beside the diff") → asserts arguments details exist with payload.
- NEW permissionCardDiff.spec.ts: arguments details next to ask-diff; the
  injected-batch scenario end-to-end (both hunks visible); raw survives the
  no-preview path.
- Results: base 17/17 (pre-change green) → fixed 24/24 green → pre-fix proof
  11 failing on HEAD sources with the new specs (every regression pin red) →
  restored (md5-verified) 24/24 green. Adjacent: components.spec +
  diffSection.spec 29/29 green; `npx vue-tsc --build` rc=0.

## Guards/never-committed
- No PHP touched → no phpunit, no suite-figure/durations impact.
- New web spec file (not PHP): listed here for the gate — permissionCardDiff.spec.ts.
- `candy-code-web/dist/` NOT rebuilt/committed (§2 weld duty). No generated
  protocol files touched. No lang keys (web strings are hardcoded card copy).

## Deviations
- None from the design block. Summary marker is label text
  `(snippet-relative)`, not an `@@ snippet @@` grammar change — the design
  requires the grammar stay, the finding's "or" alternative was the
  forbidden side.
