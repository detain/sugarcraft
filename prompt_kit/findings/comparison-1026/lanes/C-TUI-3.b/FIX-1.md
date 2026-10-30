# C-TUI-3.b FIX-1 (round 1) — IN PROGRESS

Base tip: 17354d9cc578346557b489a02f6e2d2a2d2c46dc (chain 3c59d81d5 → 27ace6804 → 17354d9cc, base cb2601f5d).

## Findings cured
- F1 MAJOR: 13 lane-introduced TS2532 sites (2 new specs) → `?.` guards, no assertion weakening.
- F2 MINOR: SessionView.vue rewindRow single-flight guard + non-vacuous double-emit pin.

## Gate baseline (pre-fix, measured at 17354d9cc)
- `node_modules/.bin/vue-tsc --build` (= npm run typecheck, covers tsconfig.vitest.json which includes `__tests__`): **34 errors = 21 foreign + 13 lane**. Note: `vue-tsc --noEmit -p tsconfig.app.json` EXCLUDES `__tests__` (shows only 2 src errors); the non-vacuous gate for specs is `--build`.
- Lane error lines: codeBlockCopy.spec.ts 51,52,65,70,75,76; transcriptActions.spec.ts 153,157,182,202,223,228,293 — matches REVIEW-1.md F1 exactly.
- Foreign 21: contextSources.ts ×4, contextPanel.spec ×8, reducer.spec ×4, statusBar.spec ×2, dormantRoster.spec ×1, contextPanelEdit.spec ×1, contextTrustRouting.spec ×1.

## Results (post-fix, measured)
- `vue-tsc --build` after a COLD rebuild (`rm node_modules/.tmp/*.tsbuildinfo`): **21 errors, all foreign** (contextSources.ts ×4, contextPanel.spec ×8, reducer.spec ×4, statusBar.spec ×2, dormantRoster ×1, contextPanelEdit ×1, contextTrustRouting ×1) — exactly the pre-lane baseline; 0 in any lane file (was 34/13). Gate note for the weld ledger: `-p tsconfig.app.json` excludes `__tests__` and is vacuous for specs; `npm run typecheck` (`vue-tsc --build`, covers tsconfig.vitest.json) is the real gate.
- All 13 sites use `?.` (review-endorsed, same-file `frame?.id` idiom): codeBlockCopy.spec 51,52→`buttons[0]?.classes()/.text()`, 65,70→`await buttons[N]?.trigger('click')`, 75,76→`buttons[N]?.text()`; transcriptActions.spec 153,223→`confirm.mock.calls[N]?.[0]`, 157,182,202,293→`frames[0]?.params`/`wire.sent(...)[0]?.params`, 228→`calls[1]?.[0]`. No assertion deleted or loosened; against `undefined` every expect still fails (toContain throws invalid-args, toBe/toMatchObject mismatch, count asserts unchanged) — same expected values, same failure power.
- F2 guard: `let rewinding` (per-instance — script setup runs per component), re-entry return at the top, armed after the confirm passes, released in `finally` after the FULL settle (exec + its rows-refetch) — steps stay stale-until-refetched, so releasing earlier would not close the hazard.
- New pin: "a second rewind while one is in flight execs exactly once, and the guard releases after settle" — double $emit (r1 then r3, no flush between) ⇒ confirm once, ONE exec `'3 --chat'`; then settle exec+subscribe ⇒ a later rewind execs again (`'2 --chat'`, confirm twice) — proves the guard is single-flight, not a latch.
- Mutations (cp+md5 restore, final md5 cdfe82d711ac10a4faee5af64910267d): M1 remove `if (rewinding) return` ⇒ EXACTLY the new pin red (1 failed | 17 passed). M2 neuter the finally-release ⇒ the new pin + the adapted draft-test red (2 failed | 16 passed) — both are precisely the single-flight-contract tests.
- In-step test adaptation (disclosed): "edit & resend only offers the draft-replacement warning when a draft is held" previously OVERLAPPED two unsettled rewinds — the exact hazard F2 cures — so the first rewind is now settled (`wire.answer('command.exec',{effects:[]})`, rows-less ⇒ no refetch) before the second action; all original assertions kept byte-equal (confirm[0] no-'draft', confirm[1] draft-warning, 2 execs, draft held). Its third (declined) leg now lands guard-suppressed-by-design while the r3 exec is deliberately unsettled to preserve the no-seed-before-settle posture — comment rewritten truthfully; the declined-confirm path proper stays pinned by the two dedicated tests around it.
- Vitest serial (local bin): new specs 2 files 26/26 (25 + new pin); FULL package 91 files / **743 tests passed** (reviewer baseline 742 + 1). eslint on the 3 touched files: 0 problems.
- Diff shape: exactly 3 web files (+87/−15) + this report; **zero `candy-code/**`, zero dist** — client-only law holds.

## Hand-to-closeout (unchanged from REVIEW-1 §4)
- dist rebuild REQUIRED at weld (src-web changed; lane commits none).
- No durations.tsv rows, no suite-figure.json change, no trio/protocol regen, no PHP touched.
- GAP-UI-32 web-half deferral stays intentional. F3 (clipboard raw egress) goes on the host-egress ledger — design-mandated sink, not cured here; C-TUI-3.a owns its own candy-code half. F4 NIT: no action (verified honest).
- Hot files SessionView.vue/TranscriptItem.vue/session.ts — welder keeps wave ordering vs C-TUI-3.a / C-TUI-4.b / C-TUI-2.g.
