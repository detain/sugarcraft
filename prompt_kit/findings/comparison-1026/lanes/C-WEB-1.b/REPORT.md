# C-WEB-1.b — DONE

## Verdict
Step landed: `pickModel()` in `candy-code-web/src-web/components/StatusBar.vue` writes
`modelOptionValue(currentProvider)` back into the element right after the emit; three
regression pins added to `candy-code-web/src-web/components/__tests__/statusBar.spec.ts`.

## Commit (re-read from `git log` on branch cc/C-WEB-1.b)
- SHA: 07787590f — "C-WEB-1.b: status-bar model select never shows an unapplied pick"
- Author `%ae`: detain@interserver.net · Committer `%ce`: detain@interserver.net (separate greps) · Co-Authored-By: 0
- numstat: `8 1 candy-code-web/src-web/components/StatusBar.vue` · `39 0 candy-code-web/src-web/components/__tests__/statusBar.spec.ts` → 2 files, +47/−1

## Tests (cwd candy-code-web, `npx vitest run --no-file-parallelism src-web/components/__tests__/statusBar.spec.ts`)
- Pre-fix (base StatusBar.vue restored, spec with pins): **2 failed | 8 passed (10)** —
  red exactly on "after picking B the element still shows the summary entry A" and
  "picking A after the apply emits the switch back and re-parks on B". The middle pin
  (summary prop change repaints) passes pre-fix by design: it confirms the Vue binding
  still drives the repaint after the manual DOM reset, as the brief lists it.
- Post-fix: **10 passed (10)**, 1 file, exit 0.
- `npx vue-tsc --noEmit -p tsconfig.json`: rc 0.
- npm-0 confirmed in /tmp/opencode/cc-cmp-npm-C-WEB-1.b.log before any vitest run.

## Files
- Modified: candy-code-web/src-web/components/StatusBar.vue (pickModel only)
- Modified: candy-code-web/src-web/components/__tests__/statusBar.spec.ts (3 tests + helpers inside the WEB-2 describe)
- No new spec files (no durations/gate rows owed); no dist commit (welder rebuilds per §2); no Lang keys; no guards touched beyond vitest.

## Deviations
- None functional. The reset is unconditional after the if (no-emit paths write the same
  value back — behaviorally identical), matching "writes modelOptionValue(currentProvider)
  back into the element" literally.
- Brief risk note honored: all change drives use `setValue` (sets target.value before change).
