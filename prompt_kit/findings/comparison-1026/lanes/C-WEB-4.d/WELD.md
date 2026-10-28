# C-WEB-4.d — WELD record (batch-5 step 4, 2026-10-28)

## Picks (cherry-pick -x, src+spec ONLY per brief — lane dist hunks stripped)
- 416fee321 → bbf4a95b9. The pick conflicted ONLY in candy-code-web/dist
  (rename/rename + content vs later welds' rebuilt bundle). Resolved by taking
  HEAD's dist wholesale (git rm --cached + checkout HEAD -- dist), so the pick
  carries README/StatusBar.vue/reducer.ts + 2 new specs only (5 files).
- 4935cce08 → 074ffb281 (review fixes: drops the toBe message arg that caused the
  batch-3 TS2554 STOP + lands REVIEW-1 MINOR-1 skew-comment polarity, lead-ruled
  wording per STOP-1.md cure block). Clean apply.

## dist (plan §2: welder-owned)
`npm run build` on the merged tree at pick time: fresh bundle index-B5Pshpu5.js +
index-qeLIClEt.css (replaces index-NcaNumyb.js/index-Bqln88tI.css), index.html +
manifest.json updated, build-info.json byte-stable. dist rides THIS weld commit.

## Gates on merged tree (cwd=candy-code-web)
- `npx vue-tsc --build --force` → rc=2, 20 error lines; sorted `error TS` set
  diff vs /tmp/opencode/vue-tsc-cweb4c-base-e14.txt = EMPTY (same base-residual
  family; batch-3's new TS2554 at statusBarTimer.spec.ts:191 cured — STOP-1.md
  discharged). Log: /tmp/opencode/vue-tsc-cweb4d-merged.txt.
- vitest serial (--no-file-parallelism): statusBarTimer.spec.ts +
  sessionView.spec.ts (views/__tests__) → 2 files / 9 tests PASS; first combo run
  (statusBarTimer+turnTimer+sessionView) 3 files / 16 PASS. REVIEW-1 targeted
  44/44 family legs green.
- No PHP touched → no php gates, no durations rows (no candy-code/tests files).

## Figure carry (note only — §7 closeout re-pins)
+0T php; +14 vitest tests (2 new spec files, 8+6 per REVIEW-1 full-serial era).

## Escalations discharged
- Batch-3 STOP-1.md (this lane's file): TS2554 cured by 4935cce08, skew comments
  land lead's ruling; standing 20-error residual family is base-proven at
  e14c3cc7c (owner-gated, disclosed in that record).

## Absorption proof (post-weld)
git cherry master cc/C-WEB-4.d → 4935cce08 '-' (patch-id SAME for 074ffb281);
416fee321 '+' BY DESIGN — the pick strips the lane's dist hunks (welder-owned per
plan §2). Content proof: change-lines of pick vs lane tip identical ignoring dist
paths (diff of `^[+-]` sets empty; only README hunk-offset context + -x trailer
differ). Worktree ../cc-cmp-C-WEB-4.d + branch cc/C-WEB-4.d pruned after proof.
