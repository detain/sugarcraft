# CALIBER_LEARNINGS — sugar-crush-web

## Patterns

- **`dist/` is committed and generated.** Rebuild with `npm run build` and commit
  it in the same change as the `src-web/` edit. `web.yml` rebuilds from `npm ci`
  and runs `git diff --exit-code dist/`, so a stale or hand-edited bundle goes red.
- **The build must stay byte-deterministic.** No timestamps, build ids or
  absolute paths in emitted files: the build-info plugin in `vite.config.ts`
  writes only name + version. Measured: a clean `npm ci && npm run build` in a
  different directory reproduces `dist/` exactly.
- **The version travels inside `dist/`.** `package.json` is excluded from the
  Packagist archive (`archive.exclude`), so `Assets::version()` reads
  `dist/build-info.json`, never `package.json`.
- **Hash history in the router.** `sugarcrush serve` serves `dist/` as static
  files; a history-mode deep link would reach the server as a path with no
  file. The one-time login code also rides in the fragment.
- **TypeScript stays out of `src/`.** `src/` is the PSR-4 root, checked by
  `tools/check-one-type-per-file.php`; the UI lives in `src-web/`.
- **typescript-eslint caps TypeScript below 6.1**, so `typescript` is pinned
  `~6.0` (TypeScript 7 is the native port). `@vue/eslint-config-typescript`
  was dropped for plain `typescript-eslint` + `eslint-plugin-vue`: it pulled a
  `fast-glob` → `braces` chain with an open high-severity advisory.
- **`src-web/protocol/generated.ts` is generated** by `scripts/gen-protocol.mjs`
  from `../sugar-crush/docs/protocol/sugarcrush.v1.schema.json`; `web.yml`
  regenerates and diffs it, and `generated.spec.ts` does the same locally
  (skipped in a split-repo clone, where the schema is absent).
- **DOMPurify is unreliable under happy-dom** (its parser mangles the walk:
  links vanish, `<script>` text survives). Unit tests pin `markdownToHtml()`
  (markdown-it with `html: false` already escapes raw HTML and refuses
  `javascript:` links); the sanitiser itself is covered by the e2e suite in
  Chromium.
- **Tool-call ids repeat across turns** (EchoProvider and the DSML/MiniMax
  parsers restart at `*_call_1`), so tool rows key on `turnId` + `toolCallId`,
  and a finish without an exact match pairs with the newest running row.
- **A running call in a snapshot is a `system`-role placeholder row** carrying
  `pendingToolCallId` / `pendingToolName` / `pendingToolArguments`: check that
  before the role.
- **`command.exec` refuses an empty `args`** (`invalid_params`) — omit it. A
  command rewrites the transcript without events (`/clear` answers
  `effects: ["clear-transcript"]`), so the session store re-subscribes for a
  fresh snapshot after one.
- **Drive tools without a model with `::tool <Name> <json>`** (EchoProvider's
  scripted mode). Not `!tool`: a prompt starting with `!` is BangShell, the
  user's own shell command.
- **`@playwright/test` is pinned exactly** (1.63.0 ↔ chromium revision 1243);
  bump it together with `npx playwright install chromium`.
