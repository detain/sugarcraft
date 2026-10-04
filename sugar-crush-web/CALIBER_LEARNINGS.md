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
