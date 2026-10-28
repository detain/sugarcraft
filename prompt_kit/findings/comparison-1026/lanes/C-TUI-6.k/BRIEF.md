# C-TUI-6.k — Web dictation via the Web Speech API

- kind: gap · effort S · phase: normal

- area: tui · item: C-TUI-6 (sources: GAP-WEB-13, GAP-MOD-23)

- deps: none

- serialized: [hot:candy-code-web/src-web/components/Composer.vue]

- ruling: none

- risks: C-WEB-4 and C-CTX-3 also edit `Composer.vue`, so keep this step out of their wave. Firefox has no SpeechRecognition, so the button is simply absent there.

## Approach


- **Why normal, not LATE.** This is the cheap, independent sliver the brief allows. It is S effort, client-only, needs no ruling, touches no protocol or PHP, and has no dependency.
- `lib/dictation.ts`:
- `dictationSupported(win)` requires `SpeechRecognition` or `webkitSpeechRecognition` AND `window.isSecureContext`. Plain-http serve on a LAN host is not a secure context, so the button stays hidden.
- `createDictation({lang, onInterim, onFinal, onError})` returns `{start, stop}` with `continuous` and `interimResults` on.
- `Composer.vue`:
- adds a mic toggle, which shows only when supported;
- shows interim text as a dim hint under the textarea;
- inserts final text at the caret and never auto-sends;
- Esc stops dictation;
- maps `not-allowed` / `no-speech` errors to a short inline notice.
- **Privacy.** The button's title discloses that the browser vendor's speech service hears the audio. Nothing reaches the candy-code server.
- **Strings.** They stay hard-coded English like the rest of today's chrome. C-TUI-6.g migrates them later.

## Files — create

`candy-code-web/src-web/lib/dictation.ts`

## Files — modify

`candy-code-web/src-web/components/Composer.vue` (toolbar button, interim hint, caret insert)

## Tests

create `candy-code-web/src-web/lib/__tests__/dictation.spec.ts` (feature detection including an insecure context, lifecycle against a fake recognition class, error mapping) and `candy-code-web/src-web/components/__tests__/composerDictation.spec.ts` (hidden when unsupported; the toggle; final text inserted at the caret and not sent)

## Docs/guards

vitest; dist rebuild at weld