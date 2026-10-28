# C-TUI-6.k — weld record (W13)

- Pick: 2887468af → 7e97c9e4f (base master 13974e4b3). ZERO conflicts — Composer.vue collision zone clear: C-WEB-7.b landed at c69f190b1 before this lane's base c3dfec2a0 (its Composer hunks are in the pre-image), and C-WEB-3.f remains unwelded in flight.
- Gates (merged tree, cwd candy-code-web, plain pipe, vitest always --no-file-parallelism):
  - dictation pair `src-web/lib/__tests__/dictation.spec.ts` + `components/__tests__/composerDictation.spec.ts` → 25 passed (25) — 16 lib + 9 composer, exact.
  - composer regression trio `composerFilesImages` + `composerHistory` + `components.spec` → 44 passed (44), exact.
  - `npx vue-tsc --build --force` → exactly 19 errors, breakdown identical to REVIEW-2 baseline (contextPanel 8 / contextSources 4 / reducer 4 / statusBar 2 / contextTrustRouting 1), zero in lane files.
- dist regen outcome: CHANGED — separate rider 0e55e657c (`weld: C-TUI-6.k regenerate`, npm run build): bundle swap index-mLZ5ITt6.js→index-BoMiRCkM.js, index-DeW6LKiW.css→index-DPvz5jdK.css, index.html + manifest.json refs follow (rename-detected 57%/75%).
- NIT carries: NONE — REVIEW-2 came CLEAN 0C/0M/0minor/0nit; round-1 NIT (Composer.vue onError already-running listening reset) was cured in FIX-1 with a ThrowingRecognition pin. Disclosed forward-carries (not defects): strings hard-coded English pending C-TUI-6.g locale migration (lane REPORT); dictation feature is browser-side only, zero server surface.
- Durations: no phpunit rows (vitest specs never live in the TSV); suite-figure.json untouched.
