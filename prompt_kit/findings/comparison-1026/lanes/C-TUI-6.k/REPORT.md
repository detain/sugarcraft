# C-TUI-6.k — Web dictation via the Web Speech API — DONE

Verdict: COMPLETE. Single commit `7de2df023` on branch `cc/C-TUI-6.k` (worktree `/home/sites/cc-cmp-C-TUI-6.k`, base `c3dfec2a0`). Author+committer `detain@interserver.net` (verified via separate `%ae`/`%ce` greps), zero Co-Authored trailers, tree clean, not pushed.

## Files touched (exactly 4 — web-only scope honored)

- `candy-code-web/src-web/lib/dictation.ts` (new) — `dictationSupported(win)`: `(SpeechRecognition || webkitSpeechRecognition)` AND `win.isSecureContext`; `createDictation(handlers, win)`: one recognition instance, `continuous`+`interimResults` on, `lang` passthrough, active-flag gates (post-stop ghost results and self-triggered `onend` suppressed), best-alternative (`result[0]`) collection walking from `resultIndex`, re-`start()` → `onError('already-running')`, ctor-throw/unsupported → returns `null`; `dictationNoticeFor(code)` maps error codes to inline notices (aborted → silent). Returns `{start, stop}` per brief.
- `candy-code-web/src-web/components/Composer.vue` (modified, hot region respected) — mic toggle `v-if="canDictate"` as first `.controls` child; `.live` state + `aria-pressed`; button title discloses browser-vendor speech service hears audio, nothing reaches the candy-code server; interim transcript as dim `aria-live` hint under the textarea; final text inserted at the caret via `selectionStart/End` on `props.modelValue` (append fallback), emits `update:modelValue` only — never auto-sends; Esc stops dictation before the busy Esc-Esc branch; `not-allowed`/`no-speech`/etc. → short inline notice; `onBeforeUnmount` stops. Strings hard-coded English pending C-TUI-6.g (per brief).
- `candy-code-web/src-web/lib/__tests__/dictation.spec.ts` (new) — 16 tests: detection matrix (chrome/firefox-webkit/insecure/undefined win), refuse-when-unsupported with zero constructions, ctor-throws → null, full lifecycle (start/props/interim→final/stop suppresses onend/ghost results ignored), service-side end fires once + deactivates, best-alternative + resultIndex walk, error codes reach handler, double-start no-op, busy-start → already-running, notice mapping ×4.
- `candy-code-web/src-web/components/__tests__/composerDictation.spec.ts` (new) — 7 tests: mic hidden when unsupported, hidden when secure-context missing, toggle start→stop with fresh instance per toggle, Esc stops without emitting the busy `stop` action, interim hint shows/clears, final inserted at caret and never sent (`update:modelValue` = `aXb`, no send emit), `not-allowed` notice, privacy title, service-end resets pressed state.

## Test/gate results (targeted only, `npx vitest run --no-file-parallelism`)

- New specs: 23/23 pass (16 + 7).
- Composer regression: `composerFilesImages` + `composerHistory` + `components.spec` → 44/44 pass.
- Typecheck `vue-tsc --build --force`: exit 2, exactly 19 errors, `diff` vs pristine-worktree baseline → byte-identical (no new errors; baseline is master's standing 19).
- ESLint on the 4 files: clean.
- Mutations (cp-harness under `/tmp/opencode/cc-cmp-C-TUI-6k/`, restores `cmp`-verified byte-clean): M1 delete insecure-context gate → exactly 3 reds; M2 wire `onFinal` → `submit()` → exactly 1 red (the never-sends pin).

## Deviations (disclosed)

1. `Handlers` carries an optional `onEnd` beyond the brief's four — the component needs the service-side end (mic auto-stops when the browser ends the session) without leaking lifecycle into `onError`.
2. `dictationNoticeFor()` exported from the lib so error→notice mapping is unit-tested without mounting the component.
3. Component calls `createDictation(handlers, window)` passing `window` explicitly (vitest `globalThis` ≠ `window` gotcha); default param remains `globalThis`.

## For the gate

- NEW spec files needing `scripts/parallel-tests-durations.tsv` rows: none (TSV is PHP-suite-only; vitest files are not tracked there).
- NO `dist/` rebuild (weld's duty), NO `suite-figure.json`, NO durations edits, NO generated-protocol files touched — lane commit contains exactly the 4 files above.
