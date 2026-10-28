# C-WEB-4.a FIX-1 — review-1 MINOR (drop.preventDefault polarity) — round 2

Verdict: shipped, amended in place. New tip `bf6331eb03cf459ba0357230feeef76402bb31c3` on `cc/C-WEB-4.a` (base 2a6cd3c1f, ONE commit, parent byte-identical), tree clean, never pushed. %ae and %ce both detain@interserver.net (separate greps = 1/1), zero Co-Authored trailers, no caliber.

## Cure quote (review-1, verbatim)
> candy-code-web/src-web/components/Composer.vue:520 — `@drop.prevent` unconditionally cancels the default of ALL drops, silently breaking the previously-native drag-text-into-composer … Cure: change template to `@drop="onDrop"` and at the TOP of onDrop, synchronously before any await: `if (!event.dataTransfer?.types?.includes('Files')) return; event.preventDefault();` — then add one spec leg asserting defaultPrevented is true for a Files-drop and false for a text/plain drag.

## What landed
- `Composer.vue:527` → `@drop="onDrop"` (was `@drop.prevent="onDrop"`); `@dragover.prevent` kept.
- `Composer.vue` onDrop — guard + `event.preventDefault()` are the first two statements, synchronously before the only await; docblock truth-flipped ("Only a Files drag is ours to claim…").
- `composerDropAndChips.spec.ts` — NEW helper `dragEventTo` (name distinct from every sibling spec; mirrors the VTU drop dispatch: cancelable drop over `composer-root`, but keeps the event handle to read `defaultPrevented`); NEW leg `the component claims only Files drops: defaultPrevented is true for files, false for a text drag`; existing Files-drop fixtures now carry `types: ['Files']` and the text fall-through fixture `types: ['text/plain']` (the shipped guard reads types, so the fixtures must model it honestly).
- Blob-verify at tip: `@drop="onDrop"` count 1; `@drop.prevent` count 0; guard line present before any await; new leg present (grep 1).

## Red-before / green-after proof
Mutation (template reverted to `@drop.prevent="onDrop"` via sed, backup at /tmp/cw4a-Composer.vue.fixed):

```
FAIL src-web/components/__tests__/composerDropAndChips.spec.ts > Composer drag-drop images (C-WEB-4.a) > the component claims only Files drops: defaultPrevented is true for files, false for a text drag
AssertionError: expected true to be false // Object.is equality
 ❯ src-web/components/__tests__/composerDropAndChips.spec.ts:82:39
     82|     expect(textDrop.defaultPrevented).toBe(false)
```

Restore: `cp /tmp/cw4a-Composer.vue.fixed src-web/components/Composer.vue` → `md5sum -c` OK (`e09391d091650656d2a61b7dfbf86dd4`).

## Exact vitest invocations (plain pipe, env -u LINES -u COLUMNS, cwd `candy-code-web`, vitest 5.0.5)
Targeted (post-fix, committed state):
```
npx --no-install vitest run src-web/components/__tests__/composerDropAndChips.spec.ts --no-file-parallelism
→ Test Files 1 passed (1) · Tests 12 passed (12)
```
Touched set (7 files, post-fix, committed state):
```
npx --no-install vitest run src-web/components/__tests__/composerDropAndChips.spec.ts src-web/components/__tests__/composerDictation.spec.ts src-web/components/__tests__/composerFilesImages.spec.ts src-web/components/__tests__/composerHistory.spec.ts src-web/lib/__tests__/paste.spec.ts src-web/stores/__tests__/promptHistory.spec.ts src-web/components/panels/__tests__/paletteClearHistory.spec.ts --no-file-parallelism
→ Test Files 7 passed (7) · Tests 66 passed (66) · zero unhandled
```

## Figure reconciliation (NIT, applied to REPORT.md)
Builder's "65/65" is this lane's 7-file touched set pre-review (65 tests); the post-fix run measures 66 (+1 new leg). Per-file counts of the authoritative set: 12+9+11+8+12+11+3 = 66. The reviewer's "87/7-files" used a different 7-file combination — exact invocation now cited in REPORT.md Gates; the measured truth for THIS lane's touched set is reported, not the reviewer's total.
