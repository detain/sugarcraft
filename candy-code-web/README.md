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
> form, and all twelve side panels: agents, todo, background, workflows,
> memory, rules, catalog, changes, files, context, generate and usage.

## Install

```sh
composer require sugarcraft/candy-code-web:@dev
```

The package carries no release tags yet, so the dev-stability suffix is
required: Packagist offers only `dev-master`, which a default
`minimum-stability: stable` project refuses without it.
`candy-code` lists this package under `suggest`, not `require`, so headless
installs stay lean.

**Where the `require` runs:** in the PHP project that hosts `candy-code` —
the same vendor tree `candy-code serve` resolves at startup. A project-local
install or `composer global require` both qualify; what matters is that the
two packages land in ONE autoloader. The candy-code **phar channel cannot
host this UI**: a packaged phar freezes the archive's own vendor as its only
autoload root and this package is a `suggest`, so a phar install never finds
the web package "beside it". The browser UI is a Composer-channel feature.

## PHP API

```php
use SugarCraft\CodeWeb\Assets;

Assets::distPath();     // absolute, realpath()-resolved dist/ — throws RuntimeException
                        // when dist/index.html is missing (a broken install)
Assets::tryDistPath();  // soft probe: null instead of throwing, for callers that
                        // degrade (a UI-less serve keeps the API + WebSocket up)
Assets::manifest();     // decoded Vite manifest: entry => {file, css, …}
Assets::version();      // bundle version, stamped into dist/build-info.json
Assets::diagnose($packageRoot);
                        // structured {dist, manifest, version, error} verdict for
                        // doctor-style surfaces, never an exception; the package
                        // root is the directory holding src/ and dist/ (for the
                        // package itself, that is just dirname(__DIR__))
```

Users need none of this: run `candy-code serve` and open the sign-in URL it
prints. The class exists so the server can locate the bundle and so a
distributor can verify a build's identity.

## What it does

`candy-code serve` prints a sign-in URL; open it. The page trades the one-time
code for an HttpOnly session cookie, then holds one WebSocket speaking
`candy-code.v1` (the
[`../candy-code/docs/SERVER.md`](https://github.com/sugarcraft/candy-code/blob/master/docs/SERVER.md)
documents the protocol and the UI).

- **Sessions sidebar**: newest first, filter, status, and a badge for every
  question a session is waiting on (also in the tab title, and as an opt-in
  desktop notification). Rows for another workspace carry a "Close
  `<root>/` host" menu item: it asks first, retries with force when the
  server refuses because a turn is running, and reloads the list after. On a
  write-scoped connection each row also carries "Share page…" — it publishes a
  share snapshot and shows the full link with its expiry and a copy button —
  and a revoke item per live link the server lists, so any browser can revoke
  a share it never made; the token is kept in memory only, never in storage.
- **New session in any directory** (servers started with `serve
  --allow-dir-browse`): New session opens a folder picker over the server's
  browse root — breadcrumb, Up, child directories with a project badge, show
  hidden, a path box — and starts the session in the directory chosen (a
  workspace host of its own when it is not the server's root). Keyboard:
  ↑/↓, Enter/→ to open, Backspace/← for up, Home/End to jump to the
  first/last entry, Esc to cancel. Without the flag,
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
- **Device push** (opt-in): a "Notify me on this device" toggle in Settings
  subscribes this browser through a service worker served at `/sw.js`, so a
  question or a finished turn still raises a notification when the page is
  closed. Default payloads are generic catalogue text plus the session id:
  the protocol lets any *other* client subscribe with `name: true` to receive
  the session title in payloads (candy-code SERVER.md), but this UI never
  requests it, and a transcript line never rides a payload. The toggle needs
  a secure context (HTTPS or localhost); switching it off unsubscribes both
  the browser and the server.
- **Client-hosted tools** (when the server opens `server.clientTools`): after
  following a session this tab announces its local tools — the sample
  `browser_context` (language, time zone, viewport, pixel ratio, colour
  scheme, online state; nothing private) — runs the model's `client__…` call
  when the session's `tool.client.call` carries this connection's
  registration, and answers within the call's deadline.
- **Transcript**: virtualised (`@tanstack/vue-virtual`); replies stream in as
  Markdown (`markdown-it`, raw HTML off, then DOMPurify — model and tool text
  are untrusted), fenced code coloured by a small highlighter loaded on first
  use; reasoning folds; a tool card per call with its arguments,
  output (the full text on request when the event was capped) and diff.
  Reaching history above the subscribe window: scrolling to the top edge
  pulls the older page (`session.get` `beforeIndex` paging; a *load more*
  button does the same on click), the viewport keeps its place as rows
  prepend, and a failed pull says so inline — in the server's own words, not
  translated — instead of retrying silently. Diffs render each row through
  one highlight pass and paint at most 500 of them until *load more*, so a
  whole-workspace diff stays operable.
  Two toolbar switches are remembered per browser: *Show thinking* opens
  every settled fold, *Expand tool cards* opens every card until a card is
  toggled by hand; a failure or refusal always opens.
- **Permission cards**: once / always (this session) / always (save) /
  reject / reject & stop, `y` `a` `n` on a focused card. *Always (save)* is
  the only answer that persists a durable config grant, so it is two-step:
  the first click spells out what it saves, the second commits. A card whose
  answer never settles re-arms its buttons after ten seconds — a dead card
  is never the retry story. The first answer from any client wins — which
  means every signed-in browser of one serve is the SAME operator (see
  [Trust model](#trust-model)).
- **Composer**: Enter sends; while a turn runs, steer, queue or interrupt —
  offered first is the server's `queueMode` (`server.hello`'s
  `defaults.delivery`; `steer` unless set, as the TUI's Enter does mid-turn);
  Stop or Esc Esc cancels; `/` completes server-runnable commands, `@`
  completes mentions — files, `@mcp:` resource mentions of connected
  servers, and `@agent:` delegations from the sub-agent roster — and a
  leading `#` is the TUI's todo quick-add. Pasting or
  dragging images files them as chips that ride the send, and PDFs/documents
  ride the same surface within the server's per-message document budget.
  Ctrl+↑/↓ walks the prompt history back and forth and Ctrl+G searches it
  (TUI semantics:
  Enter fills the draft, Esc restores yours); a paste over the TUI's
  collapse thresholds lands as a `[Pasted #n +X lines]` chip that expands at
  submit, so the wire carries the whole text while the draft stays short.
  The mic button on it is dictation — see [Voice input](#voice-input).
- **Status bar**: activity and step — with a live elapsed turn timer while a
  turn runs (`working · 0:42 · step 3/20`; hidden on a mid-turn reload, where
  no start was ever observed) — context used, spend, model, and the
  permission mode (changeable); plus the branch chip, whose PR dot and CI
  status come from the server's workspace facts.
- **Reconnects** after 0.5 s doubling to 15 s (±30 % jitter) with a fresh
  ticket, resuming every followed session from its gap-free `seq` cursor. Six
  tries make up the ladder; a connection held stable for a minute re-arms it,
  so the six tries are per outage stretch; when the ladder runs out the tab
  stops retrying and says
  the server is gone — the same verdict `candy-code attach` reaches after its
  exhausted ladder (SERVER.md) — with *Try again* to spend a fresh one.
- **Side panels** (the *panels* button — twelve, per the `PANELS` registry in
  `src-web/stores/agents/panels.ts`): **Agents** — the
  session's delegated runs as a live tree, also under each `Task` card, each
  opening into an agent view with the run's own transcript, a box to message it
  (or continue it once finished) and pause / resume / cancel, plus
  *Background* for a run the conversation itself delegated (the TUI's
  `Ctrl+X b`: the run moves to a background session); **Todo** — the
  agent's todo list, live; **Background** — `/bg` sessions with output, stop
  and *send to this session*, plus the launch's background shells as a
  Processes section — rows with state, age and recent output (`shells.list`,
  `shells.stop`), where an interactive (pty) shell's row expands into a
  typing pane: a cursor-threaded polled tail (`shells.output`, while the row
  stays expanded) rendered from SGR classes, plus a line and named keys that
  leave as single writes (`shells.input`). This is a POLLING, line-oriented
  door — not PTY-over-WebSocket, and the browser is not a terminal emulator;
  without write scope the pane is watch-only, exactly like Stop;
  **Workflows** — run one, and the session's
  `/workflow` and `Workflow` tool runs; **Memory** — notes per scope, search,
  add, edit, delete, and a history tab listing the home store's commits with a
  confirmed undo (write scope); **Rules** — the permission policy in the open (mode and
  its source, allow/ask/deny rules, grants picked up as the session ran;
  read-only — the dials live in the status bar and settings); **Catalog** —
  every agent this server can delegate a run as, with model, provider and
  mode; **Changes** — the workspace's uncommitted files with per-file diffs,
  each row also opening the read-only viewer; **Files** — workspace file
  search with the same read-only viewer (`fs.search`, `files.read` — plain
  text with line numbers, never markup; binary files answer with a size
  notice, long ones load more by offset); **Context** — what the next
  request is built from, as structure rather
  than `/context`'s text, plus the directory-trust prompt; **Generate** —
  the media-generation form and live job surface (shown only when the
  server says it can generate); **Usage** — the session's spend ledger (the
  `/usage` twin), where every dollar figure carries the ≥ law: an unknown
  rate is a lower bound, never a bare "$".
- **Command palette** (Ctrl+K / ⌘K): sessions, slash commands, panels, new
  session, theme.
- <a id="voice-input"></a>**Voice input**: the composer's mic dictates
  through one of two engines, and the button's title says which is live.
  The *browser* engine hands audio to the browser vendor's speech service
  (Chrome routes through Google, Safari through Apple) and contacts nothing
  on the candy-code side; the *server* engine — chosen whenever the server
  advertises `transcribe` — records locally and sends the audio to the
  server, from where it goes to your own configured voice endpoint. Firefox
  has no browser engine at all, and a plain-http serve on a LAN host is not
  a secure context, so there the button rides the server engine or hides:
  the browser refuses both APIs in an insecure page.
- **Canvas artifacts**: a tool can answer with self-contained HTML, rendered
  as a CanvasCard in a sandboxed frame served from `GET /canvas/{token}`, the
  token minted once by `POST /api/canvas` (the
  containment story is documented in candy-code SERVER.md); generated media
  shows through the matching cards.
- **Keyboard**: `?` opens the help overlay listing the live chords — heard
  when the composer is empty or focus is outside any editable field — and
  three actions are rebindable and persisted per browser: palette, help and
  the tab chord (`src-web/lib/keymap.ts`).
  Chords quoted elsewhere in this README are defaults, not law.
- **Installable, localized**: a PWA manifest plus a service worker
  (`public/manifest.webmanifest` plus a `/sw.js` built from `src-web/sw.ts`
  into the served root — not a `public/` file — the same worker carries push),
  and the UI's strings arrive from the server at runtime (`server.strings`,
  `src-web/stores/locale.ts`), so the bundle follows the server's language
  configuration; the top bar reflows for phone-width viewports (pinned by
  the e2e suite).
- **Settings** (the *settings* link in the top bar): a form generated from
  `settings.schema` — your tier or a trusted project's local or committed
  shared file, each value's
  provenance, env and flag locks, apply-mode badges — with a diff preview of
  the file before anything is saved (`settings.preview`, then `settings.set`).

- **Security notes**: every untrusted string — model and tool text, server
  paths and reasons — renders as data, never markup (raw HTML off in
  `markdown-it`, then DOMPurify with the html profile only; server-supplied
  panels interpolate with `{{ }}`). Three disclosures a reader should know.
  **One:** sign-in keeps the session secret in `localStorage` (key
  `candy-code-web:login-secret`, origin-scoped, shared across tabs) so
  reloads and new tabs re-mint tickets without another sign-in; anything
  that can execute script on this origin — or a shared browser profile —
  can read it for as long as it sits there: the exposure is at-rest
  persistence while signed in. Signing out clears it (`logout()` deletes the
  key, and any 401 from the server clears it the same way), so until then
  keep the host page's script surface clean and treat browser storage as
  holding a credential. **Two:** the composer keeps sent prompts in a local
  ring (`candy-code-web:promptHistory`, capped at 50 entries of at most
  16 Ki characters each, this origin only,
  never sent anywhere beyond the server that answered those very prompts) —
  sign-out clears that key in every open tab of this browser (each tab's
  in-memory copy drops with it, so nothing resurrects on the next send),
  and the command palette's “Clear prompt history” action (the `Actions`
  group, after a confirm) clears it any time without signing out —
  `clearPromptHistory()` in `src-web/stores/promptHistory.ts`. **Three:**
  Unsent composer drafts persist too (`candy-code-web:drafts`) — this is
  user-typed text, which routinely holds file paths, pasted credentials and
  proprietary code. Writes are debounced, each entry is clamped to 16 Ki characters
  (longer text lives in memory for the session, not storage), at most 50
  sessions are kept, and a page-hide flushes before unload; sign-out clears
  the key in every open tab, a session leaving the list drops its own row,
  and a quota refusal flips the store's `storageDegraded` flag — set once,
  never cleared, and not yet surfaced in the UI: the refused write loses
  only reload-survival, while the composer's in-memory copy keeps working.
  Signing out is the whole-browser clear; the 50-session
  window bounds what an endless sign-in could accumulate.

### Browser storage

Everything this UI keeps client-side lives in `localStorage` under the
`candy-code-web:` prefix. A versioned `candy-code-web:v1:` namespace exists in
`src-web/stores/storage.ts` (`VERSIONED_PREFIX`) but is dormant — no
production caller reads or writes under it yet; the live keys are the bare
prefix ones. Default retention is indefinite — state survives
server restarts. Sign-out clears the credential, the transcript caches and
the tab-restore keys (`tabs`, `focused`, `lastSession`); the transcript
caches ride a per-login scope bucket, so the sweep is provably complete
(OD-28). Device preferences survive sign-out.

| Key | Contents | Bound | Cleared by |
| --- | --- | --- | --- |
| `login-secret` | sign-in secret (a credential) | one | sign-out; any server 401 |
| `promptHistory` | recent SENT prompts, newest first | 50 × 16 Ki chars | sign-out (all tabs); palette "Clear prompt history" |
| `drafts` | unsent composer text per session | 50 sessions × 16 Ki chars | sign-out (all tabs); session leaving the index; FIFO eviction |
| `tabs` / `mode` / `focused` / `lastSession` | open tab ids / list vs grid / focused session / last opened id | tabs: 12 | sign-out sweeps tabs/focused/lastSession (OD-28); mode kept (view pref); a vanished session prunes its own rows |
| `theme` / `notify` / `diffStyle` | UI preferences | enum values | kept |
| `push` | device-notification consent | boolean | its own Settings toggle |
| `panels.open` / `panels.active` | side-panel drawer state | enum values | kept |
| `transcriptView.showThinking` / `transcriptView.expandTools` | transcript toolbar toggles | booleans | kept |
| `keymap` / `tab-chord` | rebinding overrides per browser (`tab-chord` is the legacy chord slot, migrated forward on read) | per-chord map | kept |

The table is machine-checked: `src-web/__tests__/storageInventory.spec.ts`
derives every persisted key name from the sources and fails if a key ships
without a row here (or a row outlives its key).

## Trust model

This is a **single-operator surface by design**. Whoever authenticates to
`candy-code serve` gets everything that server can do — the candy-code
server's own words: "Anything that can send a prompt can run code as you"
(SERVER.md). The mechanism this UI exposes is "the first answer from any
client wins": every signed-in browser tab of one serve is the SAME operator
— any of them answers a permission card, any of them can revoke or publish
a share, even one that never created it. Treat extra browsers, coworkers'
tabs and old laptop sessions as the same pair of hands. The server binds
`127.0.0.1` by default and refuses a non-loopback host without
`--allow-remote`; deciding to expose it is governed by SERVER.md's "Remote
access" and "Behind a reverse proxy (TLS)" chapters (`--tls-cert`/`--tls-key`
terminate TLS in the server directly, `--allowed-ips` bounds who may
connect). The share page is the ONLY read-only second-audience door: a
tokenised snapshot link with an expiry, never a driver's seat.

### Deployment hardening

Decide exposure **before** headers: the UI is as public as the server behind
it (loopback default, `--allow-remote`, TLS and IP bounds — see [Trust
model](#trust-model) and candy-code SERVER.md; the Dockerfile's shipped
`serve --host 0.0.0.0 --allow-remote` posture makes that decision for you).
To run a custom build of this bundle, start the server with `--web-root` (or
`CANDY_CODE_SERVER_WEB_ROOT`, candy-code ENVIRONMENT.md) — the only
documented way to serve your own `dist/`. And the server ships `dist/assets`
**uncompressed** (~690 KB cold — measured at fix time, no `.gz`/`.br`
siblings): compression is the front proxy's job, so turn gzip/brotli on for `/assets/` — the file names
are content-hashed and served immutable, so proxy-compressed copies stay
valid across deploys.

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

One route serves under a different policy entirely: `GET /canvas/{token}`
answers with `Responses::CANVAS_HEADERS`, and because an explicit header
always wins the sibling `Router`'s header merge, that replaces the default
rather than narrowing it. It is an opaque-origin sandbox
(`sandbox allow-scripts; default-src 'none'; connect-src 'none';
form-action 'none'`) — the canvas document runs its own script but can
never talk back to the server — and its `frame-ancestors 'self'` is the
one place the general `frame-ancestors 'none'` is deliberately lifted, so
the app can embed its own canvas tiles while outsiders still cannot.

A deployment wanting tighter can have its front proxy send a stricter
policy on top: browsers intersect multiple `Content-Security-Policy`
headers, so additions only ever narrow the default. Typical wins are
pinning `connect-src` to the exact `https://host` origin, or moving the
bundled styles to fetched files and dropping `'unsafe-inline'`. Do not
widen any directive from the shipped default — in particular `ws:`/`wss:`
entries are unnecessary additions (the socket already passes) and would admit
cross-origin sockets the app never uses.

## Accessibility

The UI is keyboard-first: the tab chord, the command palette, the help
overlay and every panel are driven without a mouse (keys in
[What it does](#what-it-does)), and `:focus-visible` rings are set globally
for buttons, links, selects, inputs and textareas
(`src-web/styles/tokens.css`) — they show on keyboard focus, not on click.

Screen readers ride polite `sr-only` `aria-live` regions: a newly arrived
question announces from the app shell with where it came from and what it
offers (`useQuestionAnnouncements`, `src-web/lib/questions.ts`), and a
completed reply is announced once at the transcript tail
(`reply-announcement`, `src-web/views/SessionView.vue`) — nothing with
`aria-live` tracks token deltas, which would spam. A byte-identical repeat
is still announced: the text is cleared before it is re-set, since live
regions announce replacements, not equality
(`src-web/lib/questions.ts`).

Every shipped dialog runs one spine, `src-web/lib/useDialog.ts` — help,
about, history search, the directory picker, the media card, the approvals
drawer, the command palette, the context panel, the sidebar and the mint
dialog: focus enters the panel on open, `Tab` stays trapped inside it, `Esc`
dismisses, and the trigger regains focus on close. A census in
`src-web/lib/__tests__/useDialog.spec.ts` reddens if any `role="dialog"`
ever bypasses the spine.

Diff rows never lean on colour alone: each carries a `+`/`−` sign glyph and
a kind class beside the tint, and old/new line numbers read out per row.
Motion honors `prefers-reduced-motion: reduce` with a global kill-all of
animations and transitions (`src-web/styles/tokens.css`), and the tab title
wears a `(N)` pending-count from the attention model
(`src-web/components/approvals/attention.ts`), so waiting asks are legible
without reading the page at all.

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
npm run check:dist    # CI law: dist/ must be byte-identical to a clean build of the source
npm run gen:protocol  # regenerate src-web/protocol/generated.ts from the wire schema
npm run gen:strings   # regenerate src-web/lib/strings.en.generated.ts from candy-code/docs/protocol/web-strings.en.json (that JSON is the source; the TS file is the mirror)
npm run e2e           # Playwright (Chromium) against a real `candy-code serve`
```

For `npm run dev`, start the server with
`candy-code serve --allowed-origin http://localhost:5173`, so it accepts the
dev page's origin.

The `src-web/` tree, one line per directory (a file-by-file map here rotted
once and would again — the tree IS the inventory):

```text
src-web/
  protocol/   generated schema mirror, the JSON-RPC-over-WS client (hello,
              resume, watchdog, reconnect ladder), the gap-free seq cursor,
              and sign-in/ticket auth (incl. logout's storage sweeping)
  stores/     Pinia state: connection, the session index, per-session
              transcript state (the reducer), approvals, layout (tabs,
              drafts, grid mode), settings (+ the schema-driven form),
              push, prompt history, locale, transcript toolbar view,
              keymap-facing stores, agents/ (delegation tree, todos,
              background, workflows, memory, the twelve-panel drawer)
  components/ transcript and its cards (item, markdown, reasoning fold,
              tool card, diff view, file viewer), composer and queue strip,
              permission cards, session sidebar, status bar, connection
              banner, grid/ (tabs, tiles), panels/ (the twelve side panels
              and the command palette), agents/, approvals/ (the drawer),
              settings/, tokens/ (the /tokens panel); the history search,
              help overlay, about modal, canvas/media cards and the
              directory picker sit flat here — there is no dialogs/
              directory
  lib/        markdown + sanitising pipeline, lazy highlighter, diff model,
              format (incl. the spend ≥ law), dictation + recorder, paste,
              media, questions, tab chord, i18n runtime + the dialog spine
              (useDialog.ts), line diff, ANSI, slow-pending verbs,
              clientTools/ (browser-hosted tool implementations)
  styles/     design tokens and global CSS
  views/      DashboardView, SessionView, LoginView; the /tokens route
              renders components/tokens/TokenPanel.vue directly
  __tests__/  vitest specs (incl. the storage-inventory + i18n-coverage
              guards)
```

**`dist/` is generated: never hand-edit it.** THE LAW: a change to the web
source rebuilds `dist/` with `npm run build` and commits the result in the
SAME commit as the source — never in a follow-up. Enforcement: the `web`
workflow (`.github/workflows/web.yml`, step "Committed dist/ matches the
source") rebuilds from a clean `npm ci` and fails when the committed `dist/`
differs from that build; `npm run check:dist` runs the identical verdict
locally before you push (it judges the committed tree, so commit your source
first). The build is deterministic (content-hashed file names, no
timestamps), so a clean rebuild of unchanged source is byte-identical.

**`src-web/protocol/generated.ts` is generated too**, by
`scripts/gen-protocol.mjs` from
`../candy-code/docs/protocol/candy-code.v1.schema.json` (itself generated from the PHP
protocol classes). `web.yml` regenerates it and fails on a diff, so a server
protocol change the client was not updated for is caught in CI.

### Adding a user-facing string

Every UI sentence is an i18n string, and this package does not own its
English copy: `src-web/lib/strings.en.generated.ts` is a committed MIRROR.
The chain is one direction:

1. add the key in the sibling's catalogue — `candy-code/lang/en.php` **and
   its eleven locale twins** (all-or-none);
2. the sibling regenerates `candy-code/docs/protocol/web-strings.en.json`
   (`gen-web-strings.php`);
3. here, `npm run gen:strings` rewrites the mirror from that JSON, and the
   mirror is committed with the change. The protocol mirror (below) has a CI
   drift leg; this one does not yet, so the regeneration is part of the
   commit ritual, not something CI will forgive you for skipping.

Then call sites use `t('web.<area>.<name>')`. Two laws bite: keys must carry
the `web.` prefix (`setWebStrings()` in `src-web/lib/i18n.ts` refuses any
other prefix from the server too), and minting a key only in this package is
impossible — `i18nCoverage.spec.ts` here reddens on an unknown key and the
sibling's `WebStringsDriftTest` reddens on a one-sided catalogue. At runtime
the server sends its own strings (`server.strings`), so the committed mirror
is the offline fallback — which is why it must never be hand-edited.

### Citation grammar

Comments in `src-web/` carry internal work-record handles — `WEB-1a`,
`C-WEB-4.a`, `C-SEC-5.b`, `ISS-052`, `Appendix O`, bare lane names. They
refer to the monorepo's development archive (plans and reports under the
repository's `docs/`), which does not ship inside this package and is not
linked from here. They are archaeology tags for humans with repo access,
not documentation references: the sentence around one is the comment; read
it and ignore the handle. Shipped USER-facing prose never carries them.

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
mid-turn, a dropped socket resuming from its cursor, and a server restart,
plus specs for the directory picker (browse, open, cancel), the in-graph
highlighter under the served CSP, media cards streaming over `media.fetch`,
the session panels and command palette, the schema-driven settings form, the
phone-width top bar, and the transcript jump pill sitting inside the scroller
clip at every width.
`PHP_BINARY` and `CANDY_CODE_BIN` override where PHP and the server are.

## Accessor naming (2026-10-24)

The monorepo's bare-accessor convention (no `get*()` wrappers for state that
is already readable) applies to this package's own surface too — and a census
of `src-web/` found it already holds: Pinia setup stores expose plain state and
computed refs, classes use ES `get` accessors, and `defineExpose` surfaces are
verbs (`focus`, `jumpToEnd`). The `getX()` names that remain here are external
contracts, not ours to rename: DOM/Web API methods (`getAsFile`, `getItem`,
`getRandomValues`, `getAttribute`, `getData`), the push/voice surfaces that
duck-check browser contracts (`getSubscription`, `getRegistration(s)`,
`getUserMedia`, and the `getTracks` walk over a MediaStream), TanStack Virtual
options (`getScrollElement`,
`getItemKey`, `getVirtualItems`, `getTotalSize`), Pinia internals
(`getActivePinia`), and vitest's own `vi.getTimerCount()` (test files only).
TypeScript has no PHP-style name collision (a `getX()` can
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
