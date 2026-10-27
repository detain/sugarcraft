# P-CTX.g — REVIEW-1 (static; reviewer shell denied — verdict-first)

VERDICT: CLEAN (4 NIT, zero CRITICAL/MAJOR/MINOR)

Verified on disk: scope = exactly the 5 briefed files (test-id string sweep finds zero
foreign files, zero conflict markers; generated.ts untouched); field spellings
key/label/tokens/share/sub{label,tokens,bytes,personal,note} match committed generated.ts:140-159;
bar apportions measured rows only, widths sum 100 (float, pinned — a Math.round-per-row
impl lands 102 and reddens; null buffer row holds no cell, pinned); cachePct mirrors
src/Renderer.php:3075-3123 cacheIndicator exactly (three-bucket identity, OpenAI
creation-null fallback, any-leg-null hides, measured-0 renders) and reducer producers
(turn.started nulls :424, turn.finished :491, usage.updated replaces :591 with unpriced
latch) confirm no-silent-zero; panels key 'context' is real (panels.ts:5); reload fires
own-session turn.completed only (both polarities pinned, :360-378); zero v-html in the
5 files (only pre-existing sanitized MessageMarkdown).
- [NIT] statusBar.spec.ts:260/265/282 — cache fixtures set totalTokens == prompt sum, so a
  denominator-is-totalTokens impl passes the 90/86 pins (only the null-input pin :270 catches
  it). Fix: add outputTokens (e.g. total 1700) so the identity itself is discriminated.
- [NIT] ContextPanel cache math has NO pin for the two TUI-pinned edges: prompt==0 (guard
  StatusBar.vue:105 untested) and measured 0%% rendering. Fix: +2 mounts ({read:0,input:0}
  → hidden; {read:0,input:100} → 'cache 0%%'). step-local.
- [NIT] REPORT cites "Tui Renderer.php:3090-3119"; formula lives in candy-code/src/Renderer.php
  :3075-3123 (src/Tui/Renderer.php has 2,519 lines). Report-only wording.
- [NIT] contextPanel.spec.ts:263 fixture label "Instruction files" vs server lang
  "Memory files" (en.php:1132); no assertion reads it. Fixture realism only.
Seams (report-only, out-of-scope): TS2554 six foreign lines CONFIRMED pre-existing at base on
main-tree disk (reducer.spec.ts:307/312/317/320 + statusBar 200/265 == committed 199/208,
+1 by the new import) — disclosed, not blocking; welder carries the two git proofs
(`git show 7f85a5ab6 --stat` = exactly 5 files; worktree porcelain clean) and the vitest
re-run (static test count 17+17=34 == claim; builder's statusBar "+6" is +7, totals consistent).
No new files → no durations/vitest-list duty. Stash-pop incident: recovered state is
self-consistent with disk (no residue found); foreign stash entry untouched by this review.

[lead note: reviewer's statusBar line cite "200/265" is a transcription of 200/209; the two
step-local NITs (denominator fixture, +2 cache-edge mounts) are offered as weld-carry
test-hardening at P-CTX.g's weld — welder discretion, disclose either way.]
