# rename-5-f2 NOTES — sugar-crush-web → candy-code-web <!-- @rename-keep -->

Lane: f2 (batch-F line 58, kind=rename, ns CrushWeb→CodeWeb, type=lib, bins {}, no protect — nothing starts with `sugar-crush-web-`). Branch `rename/f2` off f1 tip `b54726b7a`, worktree `/home/sites/sugarcraft-f2`. NEVER write master; push ONLY rename/f2. No R2/R8/R9 (brief law — f3 serial after weld owns remotes/packagist). 8A left this lane 3 `sugarcraft/sugar-crush-web` refs inside candy-code (StaticFiles×2, Serve docblock×1) + the web dist/vitest reds — all mine to close. <!-- @rename-keep -->

## Census at base b54726b7a <!-- @rename-keep -->

- remaining old-token files (excl. package-lock): web dir own surface (composer.json name/homepage/support, package.json name, lock name, src/Assets.php ns+messages, tests/AssetsTest.php ns, README badges/paths, CALIBER_LEARNINGS H1, vite.config docblock+plugin name, client.ts client name, storage.ts PREFIX `sugar-crush-web:`, DashboardView version stamp, server.hello.json fixture client name, StaticFilesTest pins, web.yml paths/job-names/error strings, root composer require+repositories, codecov.yml flag, docs/_data + docs/lib + index.html + MATCHUPS + PROJECT_NAMES + root README rows, candy-code 3 refs + docs ENVIRONMENT/SERVER + lang/en.php + DescriptorInheritanceGuardTest comment). <!-- @rename-keep -->
- 8A already flipped (repo-wide R4): SUBPROTOCOL `candy-code.v1`, `candy-code.auth.` in rpc.ts, `SugarCrushWeb`→`CandyCodeWeb` Pascal mentions, protocol doc refs — verified on disk at base. <!-- @rename-keep -->
- node v24.21.0 / npm 12.0.2 on devbox; `.nvmrc`=24; engines `^20.19.0 || >=22.12.0` OK. `gen:protocol` script exists in package.json. <!-- @rename-keep -->

## Blindspots (R-rule vocabulary misses — manual in this lane) <!-- @rename-keep -->

- `LoginView.vue` `<code>sugarcrush serve</code>` ×3: 8A R4 lookbehind excludes a preceding `>` (HTML tag close) → survived. Live product copy naming the OLD bin → flip to `candy-code serve` (bin/sugarcrush is gone). <!-- @rename-keep -->
- `e2e/fixtures/server.ts:75` `key.startsWith('SUGARCRUSH_')`: R5 needs `[A-Z0-9]` AFTER the prefix → bare-prefix string literals survive R5. The strip must now cover the ACTIVE prefix `CANDY_CODE_`; keep `SUGARCRUSH_` (legacy env still read via LegacyEnv → must stay stripped from the e2e child). media.spec.ts:35 comment same shape. <!-- @rename-keep -->
- `protocol.spec.ts` `/crush/` URL path ×2: arbitrary base-path in a pure-function test, bare English word → KEEP (brief law). <!-- @rename-keep -->

## Steps (procedure R, per plan 8B)

1. NOTES skeleton commit + tag `rename-baseline.md:72` (historical pre-rename census row). <!-- @rename-keep -->
2. R1 dry-run → read. R3 tool run `php tools/rename-lib.php --old=sugar-crush-web`. <!-- @rename-keep -->
3. php -l sweep; blindspot manual fixes; web.yml YAML validate.
4. npm: `npm install --package-lock-only && npm ci && npm run gen:protocol && npm run build && lint && typecheck && test`. Commit rebuilt dist/ + regenerated generated.ts + lock.
5. R5 wire: root composer update of the new slug entry only, gen-docs (+ old-name redirect stub), gen-ansi --check, refresh-deps linked.
6. Gates: residue 0, CrushWeb grep 0 (untagged), shim suite, StaticFiles/serve tests, K=8 22,571T exact, tools/tests 429/1517, path-repos, child-lifetimes, dist parity rebuild-diff zero. <!-- @rename-keep -->
7. renames.json:58 pending→renamed (own-line sed). Push rename/f2.

## Progress log (append as work lands) <!-- @rename-keep -->

(append)
