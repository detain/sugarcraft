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

> **Status: multi-session (roadmap O-5b, O-6a/b/c).** Everything a turn
> needs — the sessions sidebar, a virtualised transcript (Markdown, code,
> reasoning folds), tool cards with diffs, permission cards, a composer that
> queues, steers or interrupts, and a status bar — plus tabs and a tiled grid
> of live sessions, a server-wide approvals drawer, the schema-driven settings
> form, and the agent, todo, background, workflow and memory panels.

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

## What it does

`sugarcrush serve` prints a sign-in URL; open it. The page trades the one-time
code for an HttpOnly session cookie, then holds one WebSocket speaking
`sugarcrush.v1` (sugar-crush's
[`docs/SERVER.md`](https://github.com/sugarcraft/sugar-crush/blob/master/docs/SERVER.md)
documents the protocol and the UI).

- **Sessions sidebar**: newest first, filter, status, and a badge for every
  question a session is waiting on (also in the tab title, and as an opt-in
  desktop notification).
- **Tabs and a grid**: every session opened stays a tab (Alt+1…9 picks one;
  each shows its status and open questions). The grid tiles the open tabs, up
  to 3×3, each live — status, step, spend, the last few events, its questions
  and a line to prompt or steer it. The focused tile streams in full; the
  others are narrated by the server (a tail every 2 s) and catch up at once
  when focused. The layout is remembered per browser.
- **Approvals drawer** (the ⚠ count in the top bar): every question open in
  any session of the server, oldest first, grouped by session and answerable
  in place. A question arrives the moment it is put, whether or not this page
  follows its session; opt-in desktop notifications also say when a turn
  finishes out of sight.
- **Transcript**: virtualised (`@tanstack/vue-virtual`); replies stream in as
  Markdown (`markdown-it`, raw HTML off, then DOMPurify — model and tool text
  are untrusted), fenced code coloured by a small highlighter loaded on first
  use; reasoning folds; a tool card per call with its arguments,
  output (the full text on request when the event was capped) and diff.
- **Permission cards**: once / always (this session) / reject / reject & stop,
  `y` `a` `n` on a focused card. The first answer from any client wins.
- **Composer**: Enter sends; while a turn runs, queue (default), steer or
  interrupt; Stop or Esc Esc cancels; `/` completes server-runnable commands.
- **Status bar**: activity and step, context used, spend, model, and the
  permission mode (changeable).
- **Reconnects** after 0.5 s doubling to 15 s (±30 % jitter) with a fresh
  ticket, resuming every followed session from its gap-free `seq` cursor.
- **Side panels** (roadmap O-6c; the *panels* button): **Agents** — the
  session's delegated runs as a live tree, also under each `Task` card, each
  opening into an agent view with the run's own transcript, a box to message it
  (or continue it once finished) and pause / resume / cancel; **Todo** — the
  agent's todo list, live; **Background** — `/bg` sessions with output, stop
  and *send to this session*; **Workflows** — run one, and the session's
  `/workflow` and `Workflow` tool runs; **Memory** — notes per scope, search,
  add, edit, delete.
- **Command palette** (Ctrl+K / ⌘K): sessions, slash commands, panels, new
  session, theme.
- **Settings** (the *settings* link in the top bar): a form generated from
  `settings.schema` — your tier or a trusted project's, each value's
  provenance, env and flag locks, apply-mode badges — with a diff preview of
  the file before anything is saved (`settings.preview`, then `settings.set`).

## Developing the UI

The TypeScript lives in `src-web/` so it stays out of the PSR-4 `src/` tree.
Node is pinned in `.nvmrc`.

```sh
npm ci
npm run dev           # Vite dev server; proxies /ws and /api to 127.0.0.1:7420
npm run typecheck     # vue-tsc
npm run lint          # eslint
npm test              # vitest (happy-dom): protocol client, reducer, stores, components
npm run build         # writes dist/
npm run gen:protocol  # regenerate src-web/protocol/generated.ts
npm run e2e           # Playwright (Chromium) against a real `sugarcrush serve`
```

For `npm run dev`, start the server with
`sugarcrush serve --allowed-origin http://localhost:5173`, so it accepts the
dev page's origin.

```text
src-web/
  protocol/   generated.ts (from the schema), client.ts (JSON-RPC over WS,
              hello + resume, watchdog, reconnect), reconnect.ts (backoff),
              cursor.ts (gap-free seq cursor), auth.ts (login, tickets)
  stores/     connection, sessions, session (one per session id, the
              reducer in reducer.ts), approvals, layout, settings (theme,
              notifications), settings/ (the settings form: fields,
              serverSettings), agents/ (agent tree, todos, background,
              workflows, memory, panels)
  components/ SessionSidebar, Transcript (virtualised), TranscriptItem,
              MessageMarkdown, ReasoningFold, ToolCard, DiffView,
              PermissionCard, Composer, QueueStrip, StatusBar,
              ConnectionBanner; grid/ (tabs, tiles), approvals/ (drawer),
              settings/ (SettingsView, fields, preview), agents/ (tree,
              agent view), panels/ (side panels, command palette)
  lib/        markdown, diff, format, highlight (lazy)
  views/      DashboardView, SessionView, LoginView
```

**`dist/` is generated: never hand-edit it.** Rebuild it with `npm run build`
and commit the result together with the source change. The `web` workflow
(`.github/workflows/web.yml`) rebuilds from a clean `npm ci` and fails when
the committed `dist/` differs. The build is deterministic (content-hashed
file names, no timestamps), so a clean rebuild of unchanged source is
byte-identical.

**`src-web/protocol/generated.ts` is generated too**, by
`scripts/gen-protocol.mjs` from sugar-crush's
`docs/protocol/sugarcrush.v1.schema.json` (itself generated from the PHP
protocol classes). `web.yml` regenerates it and fails on a diff, so a server
protocol change the client was not updated for is caught in CI.

### End-to-end tests

`e2e/fixtures/server.ts` starts `php ../sugar-crush/bin/sugarcrush serve
--port 0` over a throwaway git repository and HOME, on the offline echo
provider (no network, no keys; it needs `ext-pcntl`, `ext-posix`, `ext-ffi`).
A prompt of `::tool <Name> <json-object>` lines makes the echo provider call
those tools for real, which is how the suite drives permission questions,
tool cards and diffs. Scenarios: the sign-in link and the token, a spent
code, send and see the echo, markup injection refused, a tool call with its
question and diff, a rejected call, a queued prompt, Stop, `/clear` through
`command.exec`, two tabs on one session (first answer wins), a tab opened
mid-turn, a dropped socket resuming from its cursor, and a server restart.
`PHP_BINARY` and `SUGARCRUSH_BIN` override where PHP and the server are.

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
