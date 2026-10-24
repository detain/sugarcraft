<img src="https://raw.githubusercontent.com/detain/sugarcraft/master/media/icons/candy-code-web.png" alt="candy-code-web" width="160" align="right">

# CandyCodeWeb

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![web](https://github.com/detain/sugarcraft/actions/workflows/web.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/web.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-code-web)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-code-web)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

The browser UI for [candy-code](https://github.com/sugarcraft/candy-code)'s
WebSocket server mode: a multi-session dashboard with approvals, live tool
output and settings, built with Vite + Vue 3 + TypeScript + Pinia +
vue-router.

It ships as a Composer package. The pre-built bundle is committed under
`dist/`, and a one-class PHP shim tells `candy-code serve` where it is, so
PHP users get the UI from `composer require` alone and never need Node.

> **Status: multi-session.** Everything a turn
> needs — the sessions sidebar, a virtualised transcript (Markdown, code,
> reasoning folds), tool cards with diffs, permission cards, a composer that
> queues, steers or interrupts, and a status bar — plus tabs and a tiled grid
> of live sessions, a server-wide approvals drawer, the schema-driven settings
> form, and the agent, todo, background, workflow and memory panels.

## Install

```sh
composer require sugarcraft/candy-code-web:@dev
```

The package carries no release tags yet, so the dev-stability suffix is
required: Packagist offers only `dev-master`, which a default
`minimum-stability: stable` project refuses without it.
`candy-code` lists this package under `suggest`, not `require`, so headless
installs stay lean.

## PHP API

```php
use SugarCraft\CodeWeb\Assets;

Assets::distPath();  // absolute, realpath()-resolved dist/ directory
Assets::manifest();  // decoded Vite manifest: entry => {file, css, …}
Assets::version();   // bundle version, stamped into dist/build-info.json
```

`distPath()` throws `RuntimeException` when `dist/index.html` is missing: a
broken install, never something the server should paper over.

## What it does

`candy-code serve` prints a sign-in URL; open it. The page trades the one-time
code for an HttpOnly session cookie, then holds one WebSocket speaking
`candy-code.v1` (the
[`../candy-code/docs/SERVER.md`](https://github.com/sugarcraft/candy-code/blob/master/docs/SERVER.md)
documents the protocol and the UI).

- **Sessions sidebar**: newest first, filter, status, and a badge for every
  question a session is waiting on (also in the tab title, and as an opt-in
  desktop notification).
- **New session in any project** (servers started with `serve
  --allow-dir-browse`): New session opens a folder picker over the server's
  browse root — breadcrumb, Up, child directories with a project badge, show
  hidden, a path box — and starts the session in the directory chosen (a
  workspace host of its own when it is not the server's root). Keyboard:
  ↑/↓, Enter/→ to open, Backspace/← for up, Esc to cancel. Without the flag,
  New session starts on the server's own root, as before.
- **Tabs and a grid**: every session opened stays a tab (a configurable
  chord plus the physical digit key picks one — Alt+1…9 by default, cycled
  from the `tabs: Alt+1` button in the tab strip;
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
- **Composer**: Enter sends; while a turn runs, steer, queue or interrupt —
  offered first is the server's `queueMode` (`server.hello`'s
  `defaults.delivery`; `steer` unless set, as the TUI's Enter does mid-turn);
  Stop or Esc Esc cancels; `/` completes server-runnable commands.
- **Status bar**: activity and step, context used, spend, model, and the
  permission mode (changeable).
- **Reconnects** after 0.5 s doubling to 15 s (±30 % jitter) with a fresh
  ticket, resuming every followed session from its gap-free `seq` cursor. Six
  tries make up the ladder; when it runs out the tab stops retrying and says
  the server is gone — the same verdict `candy-code attach` reaches after its
  exhausted ladder (SERVER.md) — with *Try again* to spend a fresh one.
- **Side panels** (the *panels* button — ten, per the `PANELS` registry in
  `src-web/stores/agents/panels.ts`): **Agents** — the
  session's delegated runs as a live tree, also under each `Task` card, each
  opening into an agent view with the run's own transcript, a box to message it
  (or continue it once finished) and pause / resume / cancel, plus
  *Background* for a run the conversation itself delegated (the TUI's
  `Ctrl+X b`: the run moves to a background session); **Todo** — the
  agent's todo list, live; **Background** — `/bg` sessions with output, stop
  and *send to this session*; **Workflows** — run one, and the session's
  `/workflow` and `Workflow` tool runs; **Memory** — notes per scope, search,
  add, edit, delete; **Rules** — the permission policy in the open (mode and
  its source, allow/ask/deny rules, grants picked up as the session ran;
  read-only — the dials live in the status bar and settings); **Catalog** —
  every agent this server can delegate a run as, with model, provider and
  mode; **Changes** — the workspace's uncommitted files with per-file diffs;
  **Context** — what the next request is built from, as structure rather
  than `/context`'s text, plus the directory-trust prompt; **Generate** —
  the media-generation form and live job surface (shown only when the
  server says it can generate).
- **Command palette** (Ctrl+K / ⌘K): sessions, slash commands, panels, new
  session, theme.
- **Settings** (the *settings* link in the top bar): a form generated from
  `settings.schema` — your tier or a trusted project's local or committed
  shared file, each value's
  provenance, env and flag locks, apply-mode badges — with a diff preview of
  the file before anything is saved (`settings.preview`, then `settings.set`).

- **Security notes**: every untrusted string — model and tool text, server
  paths and reasons — renders as data, never markup (raw HTML off in
  `markdown-it`, then DOMPurify with the html profile only; server-supplied
  panels interpolate with `{{ }}`). One disclosure a reader should know:
  sign-in stores the **login secret in `localStorage`** (key
  `candy-code-web:login-secret`, origin-scoped, shared across tabs) so
  reloads and new tabs re-mint tickets without another sign-in; anything
  that can execute script on this origin — or a shared browser profile —
  can read it for as long as it sits there — the exposure is at-rest
  persistence while signed in. Sign-out clears it (`logout()` deletes the
  key, and any 401 from the server clears it the same way), so until then
  keep the host page's script surface clean and treat browser storage as
  holding a credential.

### Deployment hardening

`candy-code serve` already ships a **Content-Security-Policy** (alongside
`nosniff`, `Referrer-Policy: no-referrer`, frame denial, and same-origin
COOP/CORP): `Responses::SECURITY_HEADERS` is applied by `secured()` to
every response — the static UI bundle and share pages included — except
the WebSocket 101 handshake, whose headers belong to RFC 6455. The shipped
policy is

```
default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'
```

The carve-outs are deliberate: `connect-src 'self'` covers both the
same-origin `/api` calls and the `/ws` socket (CSP3 counts a same-origin
`ws:` as `self`, so the socket needs no wider admission); `data:` and
`blob:` feed pasted images and `media.fetch` bytes rendered through
same-origin object URLs; `'unsafe-inline'` in `style-src` is what inline
theming costs. The sanitising above stays the primary injection defence —
CSP is the net under it.

A deployment wanting tighter can have its front proxy send a stricter
policy on top: browsers intersect multiple `Content-Security-Policy`
headers, so additions only ever narrow the default. Typical wins are
pinning `connect-src` to the exact `https://host` origin, or moving the
bundled styles to fetched files and dropping `'unsafe-inline'`. Do not
widen any directive from the shipped default — in particular `ws:`/`wss:`
generals are unnecessary (the socket already passes) and would admit
cross-origin sockets the app never uses.

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
npm run e2e           # Playwright (Chromium) against a real `candy-code serve`
```

For `npm run dev`, start the server with
`candy-code serve --allowed-origin http://localhost:5173`, so it accepts the
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
              workflows, memory, the ten-panel drawer state)
  components/ SessionSidebar, Transcript (virtualised), TranscriptItem,
              MessageMarkdown, ReasoningFold, ToolCard, DiffView,
              PermissionCard, Composer, QueueStrip, StatusBar,
              ConnectionBanner; grid/ (tabs, tiles), approvals/ (drawer),
              settings/ (SettingsView, fields, preview), agents/ (tree,
              agent view), panels/ (the side-panel drawer — todo, background,
              workflows, memory, rules, changes, context, generate — and the
              command palette; the Agents and Catalog views live under agents/)
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
`scripts/gen-protocol.mjs` from
`../candy-code/docs/protocol/candy-code.v1.schema.json` (itself generated from the PHP
protocol classes). `web.yml` regenerates it and fails on a diff, so a server
protocol change the client was not updated for is caught in CI.

### End-to-end tests

`e2e/fixtures/server.ts` starts `php ../candy-code/bin/candy-code serve
--port 0` over a throwaway git repository and HOME, on the offline echo
provider (no network, no keys; it needs `ext-pcntl`, `ext-posix`, `ext-ffi`).
A prompt of `::tool <Name> <json-object>` lines makes the echo provider call
those tools for real, which is how the suite drives permission questions,
tool cards and diffs. Scenarios: the sign-in link and the token, a spent
code, send and see the echo, markup injection refused, a tool call with its
question and diff, a rejected call, a queued prompt, Stop, `/clear` through
`command.exec`, two tabs on one session (first answer wins), a tab opened
mid-turn, a dropped socket resuming from its cursor, and a server restart.
`PHP_BINARY` and `CANDY_CODE_BIN` override where PHP and the server are.

## Accessor naming (2026-10-24)

The monorepo's bare-accessor convention (no `get*()` wrappers for state that
is already readable) applies to this package's own surface too — and a census
of `src-web/` found it already holds: Pinia setup stores expose plain state and
computed refs, classes use ES `get` accessors, and `defineExpose` surfaces are
verbs (`focus`, `jumpToEnd`). The `getX()` names that remain here are external
contracts, not ours to rename: DOM/Web API methods (`getAsFile`, `getItem`,
`getRandomValues`, `getAttribute`), TanStack Virtual options (`getScrollElement`,
`getItemKey`, `getVirtualItems`, `getTotalSize`), and Pinia internals
(`getActivePinia`). TypeScript has no PHP-style name collision (a `getX()` can
coexist with `x()`), so this side never needed deprecate-and-alias forwarders;
it needs the opposite discipline — do not mint `getFoo()` twins for state a
computed or property already reads, and keep any future rename UI-local: wire
field names mirror `docs/protocol/` and are server-owned truth.

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
