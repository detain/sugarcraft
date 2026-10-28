# FIX-1 — C-TUI-6.k round-1 fixes

Amended 7de2df023 → **2887468af** on cc/C-TUI-6.k (base c3dfec2a0, still one commit, tree clean, not pushed). %ae=1, %ce=1 detain@interserver.net (separate greps), zero Co-Authored trailers. Commit message preserved + fix note appended. Files touched: still exactly the 4 declared (dictation.ts comment, Composer.vue, composerDictation.spec.ts; dictation.spec.ts untouched).

## Findings → changes

1. MINOR onEnd docblock (dictation.ts:84): now "The service ended the session on its own (silence timeout, mic lost); a `stop()` call deliberately suppresses this." Comment-only, no pin possible (verified vs the existing suppression pin in dictation.spec lifecycle test).
2. MINOR unmount stop untested: new it 'unmounting the composer stops a live session (no orphaned recognition)' — plant, mount, mic click, wrapper.unmount(), expect rec.stops===1. Red-before M-A: cp-harness neutered the onBeforeUnmount stop → exactly this it red (1F/8P), restore cmp-clean.
3. MINOR interim leg: interim-hint test gains expect(wrapper.emitted('update:modelValue')).toBeUndefined() after the interim speak. Red-before M-B: mutation making onInterim also insertDictated(text) → exactly that it red (1F/8P), restore clean.
4. NIT taken (one-liner): Composer.vue onError resets listening=false on 'already-running' (a sync-thrown start left the mic lit while the notice said "already running"). Pinned by new it 'a start that dies on the spot leaves the mic unlit with the notice' (ThrowingRecognition subclass); M-C removing the reset → exactly that it red (1F/8P).
5. REPORT.md test-split erratum corrected in place: 12+11 → 16+7 (both per-file lines + the gate line; total 23 unchanged).

## Gates (cwd candy-code-web, plain pipe, --no-file-parallelism)

- Touched specs: dictation.spec + composerDictation.spec → **25 passed (25)** (23 + unmount + already-running pins; the brief's 24 anticipated skipping the NIT pin).
- Composer trio (composerFilesImages + composerHistory + components.spec): **44 passed (44)** unchanged — new cases joined composerDictation.spec, not a trio file (the conditional "45" leg N/A).
- ESLint on all touched files: clean. vue-tsc --build --force: 19 errors == master's standing baseline (an initial 20th was my subclass missing the override modifier — fixed in-lane, back to 19).
- No dist/, no suite-figure/durations, nothing outside the 4 files.
