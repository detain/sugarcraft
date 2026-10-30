VERDICT: CLEAN — weld-ready. 0 CRITICAL / 0 MAJOR / 0 MINOR; 1 NIT (step-local, no action). Empirical gates NOT runnable by this reviewer (permission map denies node — see §Gates); all claims that could be checked statically CONFIRMED, none contradicted.

Tip 7fe07b1f1 (chain 3c59d81d5 → 27ace6804 → 17354d9cc → fix), base cb2601f5d. Fix commit touches exactly 4 files: the 2 specs, SessionView.vue, FIX-1.md — all PASSed r1 surfaces (MessageMarkdown/Transcript/TranscriptItem/session.ts) byte-unchanged.

## F1 · MAJOR — CURED (statically verified)
- All 13 claimed sites carry `?.`: codeBlockCopy.spec.ts :51/:52 (buttons[0]?.classes()/.text()), :65/:70 (await buttons[N]?.trigger), :75/:76 (.text()); transcriptActions.spec.ts :153/:223 (calls[N]?.[0]), :157/:182/:202 (…[0]?.params), :345 (r1's :293 store-test leg, shifted by the added pins), :233 (calls[1]?.[0]). Byte-count matches r1's 6+7 list (line drift +1 from added comments).
- Tree-wide re-scan (`grep -P '\]\.'`, `sent(...)\[`, `calls\[`): zero unguarded indexed member-access remains in either NEW file. `code!.textContent` (:96) is a non-null assertion preceded by a not.toBeNull pin — type-legal and load-bearing.
- No assertion deleted or loosened: `toContain`/`toMatchObject` against `undefined` throws/fails, `toBe('copy')` fails on undefined, count asserts untouched — identical failure power (r1-endorsed semantics-safe idiom, same-file `frame?.id` precedent).
- Type-gate LAW verified on disk: tsconfig.json `"files": []`; tsconfig.app.json excludes `src-web/**/__tests__/**` (vacuous for specs); tsconfig.vitest.json `exclude: []` covers them; package.json `"typecheck": "vue-tsc --build"`. FIX-1.md records the correct COLD-rebuild procedure (rm tsbuildinfo) and claims 34 → exactly 21 foreign (contextSources ×4, contextPanel.spec ×8, reducer.spec ×4, statusBar.spec ×2, dormantRoster ×1, contextPanelEdit ×1, contextTrustRouting ×1), byte-matching the pre-lane baseline, 0 lane errors. I could not re-run it (denied); static completeness corroborates.

## F2 · MINOR — CURED
- SessionView.vue :234 `let rewinding` (script-setup scope ⇒ per-instance), checked at top (:237), armed AFTER `window.confirm` passes (:256) with no event-loop yield between, released in `finally` (:262-263) around the FULL `rewindTo` settle. Rejection path: a rejecting exec propagates catch→finally ⇒ flag CLEARS; a refusal cannot permanently disable rewinding.
- New 18th pin (:289-331): double `$emit` unflushed ⇒ confirm×1, ONE exec `'3 --chat'`; settle exec+subscribe ⇒ re-emit r3 ⇒ confirm×2, exec #2 `'2 --chat'` — proves single-flight, not latch.
- Non-vacuity by mutation-reasoning: M1 (delete :237 check) ⇒ both emits run ⇒ :303/:305 red; every other test re-checked green under M1 (the draft-test's guard-suppressed 3rd emit lands on the declined-confirm path = no exec anyway) ⇒ EXACTLY 1F. M2 (neuter finally) ⇒ third/fourth emits permanently swallowed ⇒ new pin red + draft test red at `calls[1]?.[0]` toContain(undefined); single-action tests unaffected ⇒ EXACTLY 2F. Matches builder claims (1F|17P, 2F|16P), cp+md5 cdfe82d7.
- Draft-warning test adaptation: JUDGED legitimate repair, not weakening. Its old shape embodied the exact over-rewind hazard F2 cures, and under the guard would dead-lock its own confirm×2 assertion. All five original assertions kept byte-equal; the declined-confirm path is NOT lost — dedicated tests exist at :187 (declining the confirm execs nothing) and :247 (a declined edit & resend leaves the draft exactly as it was). Rewritten comment is truthful.

## NIT (step-local, no action) — view-level guard does not survive unmount-mid-exec
`rewinding` is per-instance: navigating away and back while an exec is in flight mints a fresh false flag, so the theoretical window for the stale-snapshot double-rewind survives route churn. Store-level (`session.ts rewindTo`) single-flight would close it; that is C-WEB territory, effect is /redo-recoverable, and the design block mandated the view-level cure. Recorded, not owned.

## Re-confirmed without re-litigation (r1 PASSes)
Branch diff = exactly the 7 design-declared web files + 2 lane-evidence md (REPORT.md, FIX-1.md). Zero `dist`, zero generated artifacts, zero `candy-code/**`, `styles/tokens.css`/ThemeChoice untouched (no C-TUI-4.b collision). `n`-law bijection, seed-after-settle (`draft.value` strictly after awaited `rewindTo`), clipboard secure-context gate, `decorate()`/`closest('.code-block') ?? pre` honesty: untouched code. Visual pin (:125-131) is a genuine source-scan regex pair matching MessageMarkdown.vue:116-117 + :127-128 verbatim — load-bearing, the only jsdom-honest shape.

## Gates
- NOT RUN (my map denied every node invocation: `node -v`, and `node_modules/.bin/vue-tsc --build` in absolute and relative forms — 4 permission.rejected shells; no shell workaround exists). The welder MUST empirically close: (a) cold `vue-tsc --build` = exactly 21 foreign errors, 0 lane; (b) `node_modules/.bin/vitest run --no-file-parallelism` on the 2 specs = 26/26 (18 transcriptActions + 8 codeBlockCopy — pin census confirms 26 exist); (c) full serial = 91 files/743 tests; (d) eslint rc0 on the 3 touched files. Builder measured all four; static review corroborates; my return is honest about non-execution.
- Verified statically: commit %ae/%ce both detain, empty body, zero trailers; worktree clean.
- No PHP touched; in-step guard law (plan §1.8) N/A; no forbidden-file changes.

## §4 weld carries
- REAL vue-tsc --build expectation: 21 (all foreign); bare `vue-tsc --noEmit` is fully vacuous, `-p tsconfig.app.json` vacuous for specs — welder gate is `npm run typecheck` after deleting stale tsbuildinfo.
- dist rebuild REQUIRED at weld (src-web changed; lane committed none).
- NO durations.tsv rows (vitest specs, zero new candy-code/tests files); NO suite-figure change; no trio/protocol regen.
- F3 stays on the host-egress LEDGER (clipboard raw-bytes sink; design-mandated, out of lane scope — unchanged from r1).
- F4 NIT: closed, no action.
- Copy REVIEW-1.md (main-tree-only) and REVIEW-2.md onto branch evidence dir at weld.
- Hot-file serialization: SessionView.vue/TranscriptItem.vue/session.ts [hot] — keep wave ordering vs C-TUI-3.a/C-TUI-4.b/C-TUI-2.g siblings.

NOTE: written to the MAIN TREE (edit map refuses the lane worktree path), same as round 1.
