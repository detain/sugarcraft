# C-WEB-4.d weld STOP-with-record (batch-3, 2026-10-29, welder @ 72c9aaa36)

STOPPED at the brief's explicit gate "vue-tsc rc0". Not a content conflict; lane held IN FLIGHT (branch cc/C-WEB-4.d + worktree kept; NO §4 flip, NO weld commit).

## What was done and undone
- src-only pick 416fee321 -> 577a5b7f5 + lead-ruled comment cure 0ba2db67b + dist rebuild; then `git reset --hard 72c9aaa36` (sole master writer, nothing landed after). dist untracked build residue cleaned. Master carries ZERO lane-5 content now.

## Gate results at merged tree (before reset)
- vitest targeted (turnTimer+statusBarTimer+reducer+statusBar): 4 files 63/63 PASS.
- vitest full serial: 78 files / 607 tests PASS rc0 (+2 files/+14 tests vs lane-4-era 76/593 — exact lane delta).
- vue-tsc --build --force: rc=2, 21 error lines = 20 base-proven residual at e14c3cc7c (byte-identical set; /tmp/opencode/vue-tsc-cweb4c-base-e14.txt) PLUS **1 NEW lane-attributable error**:
  `src-web/components/__tests__/statusBarTimer.spec.ts(191,47): error TS2554: Expected 1 arguments, but got 2.`
  — `expect(statusOf(wrapper)).toBe('working', 'no ticking without a start')` (2nd arg unsupported by toBe's signature; runtime fine, type red).
- r1 REVIEW-1's "vue-tsc --noEmit: rc=0" gate is VACUOUS as invoked: root tsconfig has references-only (no files), so --noEmit without -p checks nothing; `--noEmit -p tsconfig.app.json` rc=1 even at merged tree (contextSources.ts:176 TS2345 + :187 TS2532, both base-residual).
- Log: /tmp/opencode/vue-tsc-cweb4d-tip.txt.

## Cure wording (comment-only, applied at cure commit 0ba2db67b, preserved for re-weld)
- reducer.ts turnStartedAt docblock: "A server clock ahead of ours (stamp in our future) reads as 0:00 until real time catches up (the display clamps); behind reads inflated by the skew; nothing else follows from it."
- StatusBar.vue elapsedLabel: "Server clock ahead of ours (skew): clamp at 0:00 until real time catches up; behind reads inflated by the skew."

## Re-weld needs (lead/fix-lane)
- Fix statusBarTimer.spec.ts:191 (drop the message arg or use a signature the project type-checks) + owner decision on the standing 20-error residual family (lane-4 record shows it is base, batch start e14c3cc7c).
