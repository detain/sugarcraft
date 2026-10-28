# REVIEW-1 — C-TUI-6.k (Web dictation) @ 7de2df023

VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 3 MINOR step-local / 2 NIT)

## Verified
- Diff == exactly the 4 declared files (lib/dictation.ts, Composer.vue hot file, 2 new specs); zero dist/suite-figure/durations touches.
- Single commit; %ae=1, %ce=1 detain@interserver.net (separate greps), zero trailers; porcelain clean after gates.
- Gates (cwd candy-code-web, plain pipe, --no-file-parallelism): dictation.spec + composerDictation.spec **23 passed (23)**; composerFilesImages + composerHistory + components.spec **44 passed (44)**.
- M1 re-reasoned (delete isSecureContext gate): reds = spec "insecure context" it + "refuses when the platform is unsupported" it + composer "hides the mic on insecure page" it → exactly 3. M2 (onFinal→submit): only the never-sends pin asserts emitted('send') → exactly 1. Both disclosed claims honest.
- Pins present: never-auto-sends; ghost-result + post-stop onend suppression; already-running via onError; best-alternative + resultIndex walk; Esc-stops-dictation with no busy-`stop` emit; privacy title; caret insert aXb.
- §6: no ruling touches 6.k (letters map to 6.a/e/f/h/l/m); hard-coded English allowed by design line 893.

## Findings
- MINOR (step-local) `candy-code-web/src-web/lib/dictation.ts:84-85`: onEnd docblock claims delivery on "a user stop"; `stop()` nulls `onend` and the lane's own test pins suppression ("no 'end'"). Fix: "The service ended the session on its own (silence timeout, mic lost); a stop() call deliberately suppresses this."
- MINOR (step-local) `Composer.vue:320-322` onBeforeUnmount stop has no test. Fix: add to composerDictation.spec: plant, mount, click mic, `wrapper.unmount()`, expect `FakeRecognition.last.stops === 1`.
- MINOR (step-local) `composerDictation.spec.ts:112-128`: interim-not-inserted-into-value is unpinned (mutation inserting interim via insertDictated reddens nothing). Fix: after the interim speak add `expect(wrapper.emitted('update:modelValue')).toBeUndefined()`.
- NIT `Composer.vue:317-318`: when start() throws synchronously, listening stays true while the notice shows "already running"; next click recovers. Optional: reset listening in onError for that code.
- NIT REPORT.md test split claimed 12+11; actual 16+7 (total 23 matches).

Step loop: fix the three MINORs (one comment, two one-line test asserts + one small test), re-run both gates, then re-review.
