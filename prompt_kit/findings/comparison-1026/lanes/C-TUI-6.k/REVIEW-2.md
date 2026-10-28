# REVIEW-2 — C-TUI-6.k (Web dictation) @ 2887468af (base c3dfec2a0)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT)

## Phantom-fix check (blob-level, first)
All three cure tokens live in the tip commit: dictation.ts:84-85 reads "The service ended the session on its own (silence timeout, mic lost); a `stop()` call deliberately suppresses this" (matches code: stop() nulls onend :166, active-guard :146); composerDictation.spec.ts:174 unmount it with stops===1 (:181); interim-leg assert `emitted('update:modelValue')).toBeUndefined()` (:132). NIT also cured: Composer.vue onError listening-reset on "already-running" + ThrowingRecognition pin (:184).

## Scope / git law
- Diff == exactly the 4 declared files (lib/dictation.ts, hot Composer.vue within its declared dictation regions, 2 specs); zero dist/, suite-figure, durations, PHP touches. Single commit; %ae=1, %ce=1 detain@interserver.net (separate greps); zero Co-Authored/generated-with trailers. Porcelain clean after gates.

## Gates (re-run by reviewer, cwd candy-code-web, plain pipe, --no-file-parallelism)
- dictation.spec + composerDictation.spec: **25 passed (25)** (16 lib + 9 composer; matches FIX-1).
- composerFilesImages + composerHistory + components.spec: **44 passed (44)** unchanged.
- npx vue-tsc --build: **19 errors** == standing baseline, breakdown contextPanel 8 / contextSources 4 / reducer 4 / statusBar 2 / contextTrustRouting 1; zero in lane files.

## Non-vacuity (each mutation re-reasoned from spec text)
- M-A (drop onBeforeUnmount stop): unmount it's stops===1 reads 0 → exactly that it red (no other test unmounts; toggle test asserts stops on click, untouched).
- M-B (onInterim also insertDictated): only the interim-hint it asserts toBeUndefined (:132); final-caret test never speaks interim → exactly 1 red.
- M-C (remove already-running listening reset): "dies on the spot" it's aria-pressed==='false' flips to true; ThrowingRecognition is planted only there → exactly 1 red.
Reset safety: 'already-running' code is minted only by dictation.ts's own sync-throw catch (:160); service raw codes can never equal it, so the reset cannot false-extinguish a live session.

## Laws
E646: no timers/AbortController anywhere in dictation.ts — pure event passthrough to window.SpeechRecognition. §6.3: none engaged (plan line 34: S-effort early sliver, phase normal, no ruling). Hot-file edits confined to the dictation regions.

## Weld roster
- dist rebuild rides WELD (lane committed no dist/). No phpunit durations.tsv rows (vitest specs never live in the tsv). Re-run 25/25 + 44/44 on merged tree post-pick.
