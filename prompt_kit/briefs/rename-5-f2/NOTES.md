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

- Tool run: 42 files, 5 path renames, R1=12 R3=683, rc0; php -l sweep zero. Root lock: `composer update sugarcraft/candy-code-web --no-install` = 1 lock/1 removal/1 path-ref refresh, zero old entries. <!-- @rename-keep -->
- npm quartet at tip: build ok, lint 0, typecheck 0, vitest 211/211 (24 files). gen:protocol output byte-IDENTICAL to committed generated.ts (8A's repo-wide R4 had converged exactly). dist/ rebuilt and committed; CI-shape parity proof at tip: rebuild -> git diff --exit-code clean + porcelain empty (DIST-PARITY-ZERO). <!-- @rename-keep -->
- e2e (Playwright) NOT run locally (chromium install out of gate budget; web.yml owns it). Blindspot fixes: LoginView 3x sugarcrush serve -> candy-code serve; server.ts strip gains CANDY_CODE_ leg (keeps SUGARCRUSH_ legacy leg — live compat, not history); media.spec comment; docs body sugarcrush.v1/serve flips. <!-- @rename-keep -->
- PHP: shim suite 5T/36A EXACT vs baseline row; candy-code --filter 'StaticFiles|Serve' 1011T/9851A green; serve --help prints all-new paths, zero old tokens. <!-- @rename-keep -->
- K=8 (clean out-dir — FIRST run silently RESUMED f1's stale /tmp/parallel-tests done-markers, new law: always pass --out): 22,571T EXACT / 424,974A (+32 wobble vs figure 424,942, disclosed; zero tests added by lane) / 0F 0E 2S / rc0 after one accepted ChatTest async-flake rerun. <!-- @rename-keep -->
- Guards: tools/tests 429/1517 EXACT, gen-docs --check ok (redirect stub docs/lib/sugar-crush-web.html minted via status flip), gen-ansi rc0, path-repos rc0, child-lifetimes rc0, residue --old=sugar-crush-web 0, untagged CrushWeb grep 0 (sole hit = renames.json ledger row, excluded file). <!-- @rename-keep -->
- Chain: 77d43ba2f NOTES+tag -> fb5f25b94 rename (tool+npm+docs+lock+status+stub). Pushed rename/f2. No R2/R8/R9 (lane law). <!-- @rename-keep)
