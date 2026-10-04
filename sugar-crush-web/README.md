<img src="https://raw.githubusercontent.com/detain/sugarcraft/master/media/icons/sugar-crush-web.png" alt="sugar-crush-web" width="160" align="right">

# SugarCrushWeb

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![web](https://github.com/detain/sugarcraft/actions/workflows/web.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/web.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-crush-web)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-crush-web)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

The browser UI for [sugar-crush](https://github.com/sugarcraft/sugar-crush)'s
WebSocket server mode: a multi-session dashboard with approvals, live tool
output and settings, built with Vite + Vue 3 + TypeScript + Pinia +
vue-router.

It ships as a Composer package. The pre-built bundle is committed under
`dist/`, and a one-class PHP shim tells `sugarcrush serve` where it is, so
PHP users get the UI from `composer require` alone and never need Node.

> **Status: scaffold.** This release carries the build pipeline, the PHP shim
> and an app shell. The protocol client, session views, approvals and settings
> land with the server protocol (`sugarcrush.v1`).

## Install

```sh
composer require sugarcraft/sugar-crush-web
```

`sugar-crush` lists this package under `suggest`, not `require`, so headless
installs stay lean.

## PHP API

```php
use SugarCraft\CrushWeb\Assets;

Assets::distPath();  // absolute, realpath()-resolved dist/ directory
Assets::manifest();  // decoded Vite manifest: entry => {file, css, …}
Assets::version();   // bundle version, stamped into dist/build-info.json
```

`distPath()` throws `RuntimeException` when `dist/index.html` is missing: a
broken install, never something the server should paper over.

## Developing the UI

The TypeScript lives in `src-web/` so it stays out of the PSR-4 `src/` tree.
Node is pinned in `.nvmrc`.

```sh
npm ci
npm run dev        # Vite dev server; proxies /ws and /api to 127.0.0.1:7420
npm run typecheck  # vue-tsc
npm run lint       # eslint
npm test           # vitest (happy-dom)
npm run build      # writes dist/
```

**`dist/` is generated: never hand-edit it.** Rebuild it with `npm run build`
and commit the result together with the source change. The `web` workflow
(`.github/workflows/web.yml`) rebuilds from a clean `npm ci` and fails when
the committed `dist/` differs. The build is deterministic (content-hashed
file names, no timestamps), so a clean rebuild of unchanged source is
byte-identical.

## Tests

```sh
composer install && vendor/bin/phpunit
```

`tests/AssetsTest.php` checks the committed bundle itself: the manifest's
files exist and are content-hashed, `index.html` loads the entry by a
relative URL, the version matches `package.json`, and no source map or
`.env` file leaked into `dist/`.

## License

MIT
