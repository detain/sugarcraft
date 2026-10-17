# sugar-crush & sugar-crush-web vs Claude Code, OpenCode and OpenClaw

_A feature-by-feature comparison, compiled 2026-10-16. Uncommitted working document. Issues found along the way are in [`../sugar_issues.md`](../sugar_issues.md)._

| Product | Version compared | How it was examined |
|---|---|---|
| **sugar-crush** (PHP TUI agent) | monorepo `master` @ `10f121d49` (2026-10-16) | Five code audits traced from `bin/sugarcrush`, plus live runs in tmux and headless. Every audit-reported issue was re-checked by an independent verifier; five later single-pass findings are marked PLAUSIBLE |
| **sugar-crush-web** (Vue 3 browser UI for `sugarcrush serve`) | 0.1.0 (committed `dist/` verified byte-identical to a fresh build) | Code audit + typecheck/lint/211 unit tests + live probes of a throwaway server (curl, WebSocket, headless Chromium) |
| **Claude Code** | 2.1.301 (installed here) | All 228 official doc pages, the changelog, `--help` for every subcommand, and a read-only look at `~/.claude` |
| **OpenCode** | **1.18.35** (installed here) and **2.0.31** (latest major) | Source at both tags, shipped docs, CLI help, local configs and installed plugins. v1 latest is 1.19.3, so the installed copy is 4 releases behind |
| **OpenClaw** | 2026.9.8 (installed here) | The installed package's ~1,300 doc pages, `dist/` source, CLI help, the `clawhub` CLI, and GitHub changelogs to 2026.10.4 |

**Legend** (one code per cell):

| Code | Meaning |
|---|---|
| ✅ | Full native support |
| 🟡 | Partial or limited (the cell says what's missing) |
| 🔌 | Only via a named plugin or extension |
| 🧪 | Code exists but is dormant, i.e. not reachable from the live app (sugar-crush and sugar-crush-web columns only) |
| ❌ | Absent |
| ➖ | Not applicable |
| ❓ | Could not verify |
| · | Not assessed |
| 🆕 | A row added during research for a feature outside the original 356-row taxonomy |
| Δ | (OpenCode 2.0.31 column) Changed from 1.18.35 |

> **Read the scoreboard with one caveat.** The sugar-crush and sugar-crush-web columns come from *adversarial code audits*: a feature with a real bug or an unreachable path was marked 🟡 or 🧪. The competitor columns come from each product's docs, source and CLI help, and their bugs were only counted when the researchers happened to find one (e.g. OpenCode's never-fired `permission.ask` hook). Raw ✅ counts therefore flatter the competitors relative to sugar-crush. Read the cells, not just the totals.


**Contents:** [1. Bottom line](#1-bottom-line) · [2. Scoreboard](#2-scoreboard-356-taxonomy-rows-per-product) · [3. Full feature matrix](#3-full-feature-matrix) · [4. How and when context is surfaced](#4-how-and-when-each-tool-surfaces-context-to-the-model-and-to-the-user) · [5. OpenCode 1.18.35 → 2.0.31](#5-opencode-11835--2031-what-changes-in-the-comparison) · [6. How sugar-crush can improve](#6-how-sugar-crush-and-sugar-crush-web-can-improve-prioritised) · [Appendix A: per-feature detail](#appendix-a--per-feature-detail-how-when-where-evidence) · [Appendix B: method and sources](#appendix-b--method-and-sources)

---

## 1. Bottom line

**sugar-crush's engine is genuinely competitive.**
- **Where it matches the best of the three:**
  - native memory with hybrid recall and consolidation (on a par with OpenClaw, ahead of Claude Code and OpenCode);
  - DNS-pinned SSRF protection (on a par with OpenClaw);
  - live sub-agent control (it edges out Claude Code with pause, broadcast and a shared batch board).
- **Where it is ahead:**
  - prompt-injection fencing of instruction files;
  - model-callable context-pruning tools;
  - git-versioned memory history;
  - a settings view that shows where each value comes from.
- It **trails on four fronts:**
  - **Discoverability.** It rarely tells the user what it loaded or what exists.
  - **Breadth.** Providers, MCP features, a plugin ecosystem, surfaces (IDE/desktop/mobile/chat) and scheduling.
  - **Real safety bugs.** One P0 (`PtyRun` keystrokes bypass every permission layer) plus several P1s that undercut an otherwise strong safety design.
  - **TUI rough edges.** No startup summary, approvals without diffs, typo'd slash commands sent to the model, and readline keys that type letters.

**sugar-crush-web is a strong multi-session cockpit**, with a careful sign-in design and lossless reconnect, but it **exposes only part of the engine**:
- 17 of 62 protocol methods are unused.
- `/model`, `/compact` and `/init` are terminal-only.
- No panel shows loaded instructions, skills, MCP or hooks; only text commands (`/context`, `/mcp`, `/notices`) hint at them.
- It has one P1: background sessions skip approval even on a server that forbids bypass.

| | Where sugar-crush **leads** | Where it is **at parity** | Where it **trails** |
|---|---|---|---|
| Context & memory | Instruction-file fencing/escaping with provenance preambles (unique; Claude Code scrubs only MEMORY.md); named budget overflow; native `Prune`/`Compress`/`Recall` tools (OpenCode needs the DCP plugin; Claude Code and OpenClaw have none); git-versioned memory with `/memory log`/`restore` (OpenClaw only git-inits its workspace); repo-shareable memory; summaries pre-fetched ahead of need (TUI only; not in `serve`, ISS-064) | Hybrid memory recall + consolidation + pre-compaction flush (as OpenClaw); compaction with structured state; `/context` breakdown; `/btw`; checkpoints/rewind | Compaction tiers count only message text, so they fire late (ISS-015); nothing names the loaded instruction files; no CLAUDE.local.md / `~/.claude/CLAUDE.md` / `.claude/rules`; no prompt caching on the `anthropic` provider (ISS-023) |
| Agents | Finest live run control: **pause**, **broadcast** and a shared per-batch board (BoardPost/BoardRead), on top of type-into-child, cancel, background, 7-day resume and worktree isolation (which Claude Code also has) | Parallel + nested + background sub-agents and teams (as Claude Code and OpenClaw) | The model sees agent names only, and no TUI view lists the roster with descriptions (only `/agent <name>` for one) (ISS-032, ISS-034); no `@agent`; no agent wizard |
| Extensibility | Reads foreign **agents** live (`.claude/agents`, `.opencode/agents`; no competitor does) as well as foreign skills (`.claude`, `.agents`, `.opencode`); MCP server fingerprint pinning | Skill gating by bins/env/os (shared only with OpenClaw); fail-closed shell hooks by default that parse Claude Code's JSON verdict envelope (but Claude Code scripts get no stdin and can fail open, ISS-038); custom commands with `$ARGUMENTS`/`` !`cmd` ``/`@file` | No plugin system or marketplace; MCP is tools-only (no resources, prompts, SSE, user scope, deferred loading, server mode); 12 hook events vs Claude Code's 33; `.claude/commands` not read |
| Tools & safety | Built-in apply-patch for every model and LSP diagnostics fed back after edits (Claude Code: no apply-patch, LSP via plugin; OpenClaw: no plan mode or LSP feedback; only OpenCode 1.x matches); scripted PTY driver built in (OpenClaw's `exec pty` + `process send-keys` is comparable; OpenCode 1.x needs the opencode-pty plugin; Claude Code has none); shell-aware rule engine; SSRF guard (DNS-pinned, full special-range blocklist) far beyond Claude Code's and OpenCode's | Plan mode, ask-user, todo; six-stage edit matcher requiring a unique match per stage (Claude Code is exact-only; OpenCode 1.x and OpenClaw also match fuzzily); content-hash stale-file refusal (Claude Code enforces read-before-edit instead; OpenCode and OpenClaw have neither); policy self-grant floor (only Claude Code matches); SSRF on a par with OpenClaw; opt-in bubblewrap sandbox for Bash that fails closed by default (Claude Code's sandbox is broader, with Seatbelt and a network allowlist, but fails closed only with `failIfUnavailable`) | **P0 PtyRun bypass** (ISS-001/002); no diff in approvals (ISS-003); auto mode is a regex denylist, where Claude Code uses a classifier (ISS-013); no background shell; no auto-format; no LSP auto-detect; read-only commands roam the whole disk unasked (ISS-010) |
| Providers | Deepest self-hosted (SGLang) support; explicit no-total-timeout engineering; mid-stream resume of a dropped stream | Bedrock, Vertex, OpenAI-compatible endpoints (Claude Code has no OpenAI-compatible endpoint; OpenClaw's Bedrock is a plugin); fallback models (as Claude Code and OpenClaw) | `openai` provider breaks reasoning models (ISS-005); `anthropic` is the OpenAI-compat endpoint (no caching/thinking/PDF); retries ignore Retry-After and have no jitter (ISS-074); no model catalog; no subscription login |
| Sessions & automation | Typed JSON error document on every CLI failure with test-pinned exit codes (OpenClaw's `agent exec` is similar) | Single-writer session locking, background daemons, `/goal` judge loop (as Claude Code and OpenClaw; OpenCode 1.18.35 has no lock and `/goal` only via a plugin); resume/continue/branch/fork/export | `-p` spend cap not enforced (ISS-006); no stream-json / `--max-turns`; `-p` runs not persisted; `-c` not project-scoped (ISS-026); no cron/`/loop`/webhooks; share links are a stub |
| UX | Generated, drift-tested keyboard reference; contrast-aware themes (unique); settings view with source + apply mode; dockable panes; inline images with kitty/iTerm2/sixel/chafa/half-block fallbacks (OpenClaw: Kitty/iTerm2/Ghostty thumbnails only) | Status bar, permission modal depth, steering (Enter/Tab/Esc) | No startup banner; paste not collapsed; `/` popup overflows; Ctrl+U/E/B/F type letters; panes auto-dock and squeeze chat; static spinner; no vim / keybinding config / history search |
| Surfaces | Self-hostable browser cockpit (multi-session tabs + 3×3 grid, server-wide approvals) with gap-free seq-cursor reconnect in sugar-crush-web | One engine behind TUI, web, `attach` and ACP clients (as OpenCode's server and OpenClaw's Gateway); lossless reconnect (OpenClaw and OpenCode 2.0.31 replay too; `sugarcrush attach` never reconnects); ACP for Zed/JetBrains/Neovim | No VS Code extension or native JetBrains plugin (Claude Code has both; OpenCode has the VS Code extension); no desktop, mobile, chat channels or cloud execution; Linux-first, no Windows; Composer-only install |

---

## 2. Scoreboard (356 taxonomy rows per product)

| Category | sugar-crush | sugar-crush-web | Claude Code 2.1.301 | OpenCode 1.18.35 | OpenCode 2.0.31 | OpenClaw 2026.9.8 |
|---|---|---|---|---|---|---|
| PLAT — Platform & distribution | ✅5 🟡4 ❌1 | ✅7 🟡2 ❌1 | ✅6 🟡2 ❌2 | ✅7 🟡3 | ✅8 🟡2 | ✅9 🟡1 |
| MOD — Models & providers | ✅9 🟡12 🧪1 ❌3 | ✅5 🟡11 🧪1 ❌6 ➖1 ❓1 | ✅17 🟡5 ❌3 | ✅21 🟡2 ❌2 | ✅18 🟡4 ❌3 | ✅24 🔌1 |
| INS — Project instruction / rules files | ✅9 🟡6 ❌5 | 🟡15 ❌4 ❓1 | ✅16 🟡4 | ✅8 🟡4 ❌8 | ✅8 🟡4 ❌8 | ✅8 🟡3 ❌9 |
| MEM — Memory | ✅9 🟡5 ❌1 | ✅4 🟡8 ❌3 | ✅8 🟡5 ❌2 | 🟡2 🔌11 ❌2 | 🟡2 🔌10 ❌2 ❓1 | ✅12 🟡3 |
| PRM — System prompt & per-request context assembly | ✅9 🟡6 | 🟡11 ❌3 ➖1 | ✅14 ❌1 | ✅7 🟡4 🔌1 ❌3 | ✅7 🟡5 ❌3 | ✅10 🟡3 ❌2 |
| CTX — Context-window management | ✅12 🟡1 | ✅3 🟡9 ❌1 | ✅12 ❌1 | ✅8 🟡3 🔌1 ❌1 | ✅9 🟡2 ❌2 | ✅12 ❌1 |
| SES — Sessions | ✅9 🟡6 ❌2 | ✅7 🟡6 🧪2 ❌2 | ✅13 🟡4 | ✅12 🟡5 | ✅13 🟡3 ❌1 | ✅17 |
| AGT — Agents & sub-agents | ✅15 🟡4 ❌2 ➖1 | ✅9 🟡9 ❌3 ➖1 | ✅18 🟡3 ❌1 | ✅15 🟡4 🔌1 ❌1 ➖1 | ✅15 🟡3 ❌2 ➖1 ❓1 | ✅18 🟡3 ❌1 |
| SKL — Skills | ✅7 🟡5 🧪1 ❌2 | 🟡11 ❌3 ❓1 | ✅13 🟡2 | ✅8 🟡3 ❌4 | ✅8 🟡4 ❌3 | ✅12 🟡1 ❌2 |
| CMD — Slash commands / custom commands | ✅7 🟡2 ❌3 | ✅5 🟡4 ❌2 ➖1 | ✅10 🟡2 | ✅9 🟡3 | ✅10 🟡1 ❌1 | ✅5 🟡3 ❌4 |
| HK — Hooks | ✅5 🟡3 ❌2 | 🟡9 ❌1 | ✅9 🟡1 | ✅3 🟡6 ❌1 | ✅3 🟡6 🔌1 | ✅6 🟡3 ❌1 |
| PLG — Plugins & extensibility | ✅3 🟡3 ❌3 ➖1 | ✅2 🟡2 ❌3 ➖3 | ✅7 🟡1 ❌2 | ✅10 | ✅9 🟡1 | ✅10 |
| MCP — Model Context Protocol | ✅2 🟡7 ❌6 | 🟡9 ❌3 ➖1 ❓2 | ✅14 🟡1 | ✅10 🟡2 ❌3 | ✅10 🟡2 ❌3 | ✅11 🟡4 |
| TL — Built-in tools | ✅13 🟡7 ❌8 | ✅12 🟡8 ❌7 ❓1 | ✅18 🟡4 🔌2 ❌4 | ✅13 🟡6 🔌4 ❌5 | ✅14 🟡4 ❌8 ❓2 | ✅22 🟡2 🔌2 ❌2 |
| EDT — Editing & code intelligence | ✅4 🟡4 ❌2 | 🟡9 ❓1 | ✅3 🟡4 🔌1 ❌2 | ✅6 🟡2 ❌2 | ✅3 🟡2 ❌5 | ✅1 🟡2 ❌7 |
| SH — Shell execution | ✅4 🟡4 ❌2 | ✅1 🟡7 ❌2 | ✅7 🟡1 ❌2 | ✅3 🟡2 ❌5 | ✅4 🟡1 ❌5 | ✅9 ❌1 |
| PER — Permissions & safety | ✅9 🟡5 ❌1 | ✅6 🟡6 🧪1 ❌2 | ✅13 🟡2 | ✅6 🟡6 ❌3 | ✅7 🟡5 ❌3 | ✅12 🟡2 ❌1 |
| GIT — Git, checkpoints, review | ✅5 🟡4 ❌3 | 🟡5 🧪1 ❌4 ➖2 | ✅11 🟡1 | ✅7 🟡3 ❌2 | ✅5 🟡5 ❌2 | ✅4 🟡5 🔌1 ❌2 |
| UI — TUI / GUI UX | ✅18 🟡12 ❌4 | ✅12 🟡10 ❌11 ➖1 | ✅30 🟡4 | ✅26 🟡6 ❌2 | ✅24 🟡8 ❌2 | ✅27 🟡5 ❌2 |
| WEB — Web, remote, multi-surface | ✅6 🟡2 ❌6 | ✅5 🟡1 ❌5 ➖3 | ✅11 🟡3 | ✅8 🟡3 🔌1 ❌2 | ✅10 🟡3 ❌1 | ✅12 ❌2 |
| AUT — Automation / headless | ✅3 🟡6 ❌3 | ✅1 🟡1 ❌4 ➖6 | ✅12 | ✅4 🟡5 🔌3 | ✅3 🟡7 ❌1 ❓1 | ✅7 🟡4 ❌1 |
| CFG — Configuration | ✅4 🟡4 | ✅4 🟡2 ❌2 | ✅8 | ✅4 🟡4 | ✅7 🟡1 | ✅7 🟡1 |
| OBS — Observability | ✅1 🟡3 ❌2 | 🟡3 ❌3 | ✅6 | ✅4 🟡2 | ✅4 🟡2 | ✅4 🟡1 🔌1 |
| PA — Personal-assistant / always-on features | 🟡2 ❌6 | 🟡2 ❌6 | 🟡4 🔌3 ❌1 | 🟡2 🔌1 ❌5 | 🟡3 🔌1 ❌4 | ✅8 |
| **Total (356 taxonomy rows)** | **✅168 🟡117 🧪2 ❌67 ➖2** | **✅83 🟡160 🧪5 ❌81 ➖20 ❓7** | **✅276 🟡53 🔌6 ❌21** | **✅199 🟡82 🔌23 ❌51 ➖1** | **✅199 🟡80 🔌12 ❌59 ➖1 ❓5** | **✅267 🟡46 🔌5 ❌38** |


## 3. Full feature matrix

The matrix has **398 rows**: the 356-row taxonomy plus 42 🆕 rows for features found during research, each with **6 product columns**.
- **Cells:** each holds a status code and a summary of at most 130 characters. The full *how / when / where shown* detail and the evidence (file:line, doc URL) for every cell is in [Appendix A](#appendix-a--per-feature-detail-how-when-where-evidence).
- **OpenCode 2.0.31 column:** `Δ` marks a cell that changed from 1.18.35; an unmarked cell is unchanged.
- **Category rows:** bold rows start each of the 25 categories.
- **Corrections:** one sugar-crush cell (INS-08) has been corrected from the verification passes; its detail says so.

| ID | Feature | sugar-crush | sugar-crush-web | Claude Code 2.1.301 | OpenCode 1.18.35 | OpenCode 2.0.31 | OpenClaw 2026.9.8 |
|---|---|---|---|---|---|---|---|
| **PLAT** | **Platform & distribution** |  |  |  |  |  |  |
| PLAT-01 | Implementation language / runtime | ✅ PHP 8.3+ (pure PHP, ReactPHP loop, pcntl fork per turn); ext-pdo_sqlite, mbstring, intl required; pcntl strongly recommended | ✅ Vue 3.5 + TypeScript 6 + Pinia 4 + vue-router 5 SPA built by Vite 8; server side is PHP 8.3 on ReactPHP | ✅ TypeScript app shipped as a native single-file executable (Bun-compiled, ~256 MB); npm package runs on Node | ✅ TypeScript on Bun compiled to one ~186 MB binary; Effect-TS core, OpenTUI+SolidJS TUI, Electron desktop | ✅ Δ Same TS/Effect stack, re-cut as core/server/cli/tui/app packages; native `@opencode/ai` provider layer; ~212 MB binary | ✅ TypeScript on Node 24.16+/26 (Bun opt-in); native Swift/Kotlin/WinUI companion apps; Control UI is Vite + Lit |
| PLAT-02 | License / open source | 🟡 MIT (composer.json, README badge), but no LICENSE file in sugar-crush/ or any lib dir; split package ships no license text | ✅ MIT | ❌ Proprietary (Anthropic Consumer/Commercial Terms); public repo has changelog, issues, some built-in mods, not CLI source | ✅ MIT-licensed, fully open source (anomalyco/opencode, formerly sst/opencode) | ✅ MIT-licensed, fully open source (anomalyco/opencode, formerly sst/opencode) | ✅ MIT; stewarded by the OpenClaw Foundation (501(c)(3)); no paid tier, hosted service or token |
| PLAT-03 | Install methods (npm, brew, curl script, composer, binary, docker) | 🟡 Composer only (require, global require, git checkout); no phar, brew, npm, curl script, binary or Docker image | ✅ `composer require sugarcraft/sugar-crush-web`: prebuilt dist/ committed, so PHP users never need Node; a sugar-crush suggest | ✅ Native curl/irm installer (auto-updating), Homebrew casks, WinGet, apt/dnf/apk, npm, dev-container feature; `claude install <ver>` | ✅ curl script, npm/bun/pnpm/yarn, Homebrew tap, pacman/AUR, choco, scoop, mise, Docker image, release binaries | ✅ Δ v2: curl /v2/install, npm @opencode/cli, brew opencode-v2, AUR opencode-beta, mise≥2026.10.9, WinGet, Docker, zips | ✅ curl/PowerShell installer (provisions Node), npm/pnpm/bun, Docker/Podman, Nix, Ansible, K8s, source, desktop apps |
| PLAT-04 | OS support (Linux / macOS / Windows native / WSL) | 🟡 Linux primary/fully tested; macOS best-effort; Windows explicitly unsupported (WSL presumably as Linux, unverified) | 🟡 Any modern browser (desktop or phone); the server it needs runs on Linux/macOS/WSL only (pcntl + posix + FFI required) | ✅ macOS 13+, Windows 10 1809+ native (Git Bash or PowerShell tool), Ubuntu 20.04+/Debian 10+/Alpine 3.19+, WSL 1/2 | ✅ Linux, macOS, Windows native (pwsh/cmd/Git Bash shells; WSL recommended) | ✅ Δ Linux (glibc+musl), macOS, Windows native incl. ARM64 builds; WSL still supported | ✅ macOS, Linux, Windows native (CLI + Windows Hub), WSL2, ChromeOS; iOS/Android/watchOS as nodes |
| PLAT-05 | Self-update / release channels | ❌ No self-update or release channels; `--version` reads Composer metadata; update = `composer (global) update` | 🟡 No self-update; when the server comes back as a different version a banner offers "Reload" | ✅ Background auto-update for native installs; `latest` (default) or `stable` channel via `autoUpdatesChannel`; `claude update` | ✅ `autoupdate` true/false/notify on startup; `opencode upgrade [ver] --method`; separate v2 channel (@opencode/cli) | ✅ Δ `update` auto/notify/disable; `opencode upgrade` (curl/npm/pnpm/bun/yarn/vp/brew/winget); /update; release highlights | ✅ `openclaw update` with stable / extended-stable / beta / dev channels, auto-updates, rollback, `/update` from chat |
| PLAT-06 | Interfaces offered (TUI / web / desktop / IDE / mobile / chat apps) | ✅ TUI, browser web UI (serve), remote TUI attach, ACP editor agent, headless -p; no desktop, mobile or chat apps | ✅ Browser UI (desktop + responsive layout) over `sugarcrush serve`; sibling clients are `sugarcrush attach` (TUI) and ACP editors | ✅ CLI TUI, Desktop (Code tab), VS Code, JetBrains, web (claude.ai/code), iOS/Android, Slack, Chrome, GitHub Actions, SDK | ✅ TUI (+--mini inline), web UI, Electron desktop, VS Code ext, ACP editors, GitHub/GitLab bots; no mobile app | ✅ Δ TUI + `opencode mini`, web (pair, installable PWA), Electron desktop, iOS app pairing, VS Code ext, ACP; GitHub cmd gone | ✅ TUI, web Control UI, desktop apps, iOS/Android, 20+ chat apps, ACP (Zed), MCP serve, OpenAI-compatible HTTP |
| PLAT-07 | Telemetry & opt-out | ✅ No telemetry code; only non-LLM network call is a 24 h LiteLLM price-DB refresh (opt-out env var) | ✅ No telemetry at all: no third-party requests; the server CSP (`default-src 'self'`) would block any | ✅ Redacted usage metrics + error reports; opt out via DISABLE_TELEMETRY / DISABLE_ERROR_REPORTING / NONESSENTIAL_TRAFFIC | 🟡 No product analytics in CLI source; desktop/web builds init Sentry if DSN baked in; models.dev + update checks can be disabled | 🟡 No product analytics in CLI source; desktop/web builds init Sentry if DSN baked in; models.dev + update checks can be disabled | ✅ Daily update check on by default (version/OS/Node/arch); anonymous feature stats opt-in; one switch disables both |
| PLAT-08 | Offline / air-gapped operation (local models only) | ✅ Fully offline-capable: zero-config default is the offline EchoProvider; local SGLang/vLLM/Ollama supported | ✅ Bundle is fully self-contained, so the web UI works air-gapped against a server on local models | ❌ Needs internet and Claude models; no offline/local-model mode (routing to non-Claude models through any gateway is unsupported) | ✅ Local models via Ollama/LM Studio/llama.cpp/any OpenAI-compatible; flags disable models.dev fetch, autoupdate, LSP downloads | ✅ Δ Local/OpenAI-compatible providers still supported (aisdk: packages or native protocols); offline flags kept | ✅ Fully local possible: Ollama/vLLM/SGLang/LM Studio included, managed llama.cpp plugin, local embeddings + TTS |
| PLAT-09 | Startup time / footprint | ✅ `--help` 0.14–0.31 s; `-p` 0.41–0.46 s / 81 MB RSS; TUI first frame ≈1.0 s; vendor 126 MB | ✅ 388 KB JS (144 KB gzip) + 42 KB CSS; syntax highlighter is a lazy 5 KB chunk; server sign-in line ~10 s after launch (warm) | 🟡 Heavy install (~256 MB binary); `--bare` / `--safe-mode` trim startup; no published startup benchmarks | 🟡 `opencode --version` measured 1.1 s / ~200 MB RSS; 186 MB binary; `opencode debug startup` prints timing | ✅ Δ `opencode --version` measured 0.15 s / ~89 MB RSS (v1: 1.1 s / 200 MB); 212 MB binary | 🟡 Fast CLI cold start (0.14 s, ~68 MB RSS measured) but heavy install (735 MB package dir); Gateway is a daemon |
| PLAT-10 | Localization / i18n of the UI | 🟡 i18n plumbing (Lang::t, locale from LC_ALL/LANG, ~2,100 externalised strings) but only English ships | ❌ Web UI strings are hard-coded English; sugar-crush's Lang/locale catalogue is not used by the browser | 🟡 UI strings English-only; `language` setting makes Claude reply in any language and sets dictation language | 🟡 TUI English-only; desktop/web app ships ~60 locales; docs translated into ~17 languages | 🟡 Δ TUI still English-only; desktop/web app ~67 locales; READMEs in 22 languages | ✅ Control UI localized to 21 locales (auto-detected); docs translated; CLI/TUI English-only |
| **MOD** | **Models & providers** |  |  |  |  |  |  |
| MOD-01 | Anthropic API | 🟡 anthropic type uses the OpenAI-compat /v1/chat/completions endpoint, not native Messages (no caching/thinking/PDF) | 🟡 Inherited: works when the server's configured provider is anthropic/claude-code; no provider picker in the web | ✅ Native Anthropic Messages API via claude.ai subscription, Console login or API key; `ANTHROPIC_BASE_URL` for proxies | ✅ Anthropic API via @ai-sdk/anthropic; cache breakpoints; high/max thinking variants | ✅ Anthropic API via @ai-sdk/anthropic; cache breakpoints; high/max thinking variants | ✅ Anthropic API key + "Claude CLI" route reusing a Claude Code login; caching, server-side tool clearing, Admin cost |
| MOD-02 | OpenAI API (Chat Completions / Responses) | 🟡 Chat Completions via openai-php; no Responses API; hard-coded temperature 0.7 + max_tokens breaks gpt-5/o-series | 🟡 Inherited (server `openai` provider); not selectable from the web | ❌ No OpenAI Chat Completions/Responses; gateways must speak Anthropic Messages, Bedrock or Agent Platform formats | ✅ OpenAI (Responses via @ai-sdk/openai); GPT models get apply_patch instead of edit/write; codex/gpt prompts | ✅ Δ OpenAI via native @opencode/ai protocols (Responses, ChatGPT OAuth w/ zstd); GPT gets `patch`; gpt/astra prompts | ✅ OpenAI API (Responses + Chat Completions) and Codex runtime; GPT-5-family prompt contribution |
| MOD-03 | Google Gemini / Vertex AI | 🟡 Gemini only via vertex (ADC, generateContent + streaming, thinkingBudget); no Gemini API-key type | 🟡 Inherited (server `vertex` provider); not selectable from the web | 🟡 Claude on Google Cloud's Agent Platform (ex-Vertex) fully supported incl. `/setup-vertex` wizard; Gemini models not supported | ✅ Google Gemini + Vertex AI providers; gemini.txt base prompt; low/high variants | ✅ Δ Gemini + Vertex (ADC/gcloud creds in /connect); split Gemini responses auto-continued; no Gemini-specific prompt | ✅ Google Gemini included (dynamic thinking); Gemini CLI runtime optional; Claude-on-Vertex via official plugin |
| MOD-04 | AWS Bedrock | ✅ AWS Bedrock via aws-sdk-php Converse/ConverseStream, ambient AWS credential chain, cachePoint prompt caching, per-model pricing | 🟡 Inherited (server `bedrock` provider); not selectable from the web | ✅ Amazon Bedrock (`CLAUDE_CODE_USE_BEDROCK`, `/setup-bedrock` wizard, inference-profile ARNs, Mantle) and Claude Platform on AWS | ✅ Amazon Bedrock: region/profile/VPC endpoint, bearer token; cachePoint caching | ✅ Amazon Bedrock: region/profile/VPC endpoint, bearer token; cachePoint caching | 🔌 Bedrock via official on-demand plugin `@openclaw/amazon-bedrock-provider` (also Bedrock Mantle) |
| MOD-05 | Azure OpenAI / Foundry | 🟡 No Azure OpenAI/Foundry type; only by pointing openai/custom at Azure v1 OpenAI-compat URL (undocumented) | 🟡 Only through the server's custom/OpenAI-compatible config; nothing in the web | 🟡 Microsoft Foundry Claude deployments supported natively; Azure OpenAI (GPT) models are not | ✅ Azure OpenAI + Azure Cognitive Services providers (useCompletionUrls option) | ✅ Δ Azure (cognitive-services merged into `azure`); asks for resource name instead of querying Azure at startup | ✅ Azure OpenAI and Microsoft Foundry (included plugin) |
| MOD-06 | OpenRouter / aggregators | 🟡 OpenRouter/gateways via generic `custom` type or `OPENAI_BASE_URL`; no first-class aggregator type or model list | 🟡 Inherited via the server's `custom` provider config; not selectable from the web | 🟡 LLM gateways (LiteLLM etc.) in Anthropic format via ANTHROPIC_BASE_URL + Anthropic's Claude apps gateway; Claude-only | ✅ OpenRouter, Vercel AI Gateway, Cloudflare AI Gateway, Helicone, LLM Gateway, ZenMux, OpenCode Zen/Go | ✅ OpenRouter, Vercel AI Gateway, Cloudflare AI Gateway, Helicone, LLM Gateway, ZenMux, OpenCode Zen/Go | ✅ OpenRouter included (browser sign-in), plus LiteLLM, Vercel/Cloudflare AI Gateway, ClawRouter, Kilocode, OpenCode |
| MOD-07 | Local models (Ollama, LM Studio, llama.cpp, vLLM, SGLang) | ✅ sglang type is first-class (server-info discovery, reasoningEffort, tool parsers); custom covers Ollama/vLLM/LM Studio | 🟡 Inherited (server `sglang`/custom local endpoints); not selectable from the web | ❌ No local-model support (Ollama, LM Studio, llama.cpp, vLLM, SGLang), not even via gateway | ✅ Ollama, Ollama Cloud, LM Studio, llama.cpp documented; vLLM/SGLang via openai-compatible (used here for SGLang) | ✅ Ollama, Ollama Cloud, LM Studio, llama.cpp documented; vLLM/SGLang via openai-compatible (used here for SGLang) | ✅ Ollama (local+cloud), SGLang, vLLM, LM Studio included; managed llama.cpp plugin; llmman; ds4 |
| MOD-08 | Arbitrary OpenAI-compatible endpoint | ✅ custom type = any OpenAI-compatible endpoint, plus named provider blocks with ${VAR} interpolation | 🟡 Inherited (server `custom` provider); not configurable from the web | ❌ No arbitrary OpenAI-compatible endpoint; a custom base URL must speak Anthropic Messages (or Bedrock/Vertex) format | ✅ Custom provider = any AI-SDK npm pkg (e.g. @ai-sdk/openai-compatible) + baseURL, models, limits, variants | ✅ Δ Custom provider: `providers.<id>.package` (aisdk:… or native), `settings.baseURL`, headers, body, compatibility | ✅ Arbitrary endpoints via `models.providers.<id>` custom providers with API adapters + compat metadata |
| MOD-09 | Subscription OAuth login (Claude Pro/Max, ChatGPT, Copilot, etc.) | ❌ No subscription OAuth; claude-code runs `claude --bare`, which never reads OAuth/keychain (API key only) | ❌ No subscription/OAuth model login from the browser; only the server account's own provider credentials apply | ✅ Claude Pro/Max/Team/Enterprise login (browser OAuth, SSO, `claude setup-token`); no ChatGPT or Copilot login | 🟡 Native OAuth for ChatGPT Plus/Pro, GitHub Copilot, GitLab Duo; Claude Pro/Max unbundled since 1.3.0 (3rd-party plugin) | 🟡 Δ ChatGPT/Copilot/GitLab-style OAuth plus multi-account management (`auth switch/export/import`); Claude Pro/Max still unbundled | ✅ ChatGPT/Codex OAuth, Claude CLI login reuse (Pro/Max), GitHub Copilot, OpenRouter sign-in; multi-account profiles |
| MOD-10 | Provider count / model catalog source (e.g. models.dev) | 🟡 7 provider types + echo + named blocks; no model catalog (picker shows current/persisted/default ids only) | 🧪 `server.info` (providers, agents, tools, commands, modes) exists with generated TS types, but the web never calls it | 🟡 Claude-only catalog via aliases (default, best, fable, opus, sonnet, haiku, [1m], opusplan) over ~6 provider routes; no models.dev | ✅ 75+ providers from models.dev catalog (models.opencode.ai, cached); `opencode models --refresh/--verbose` | ✅ 75+ providers from models.dev catalog (models.opencode.ai, cached); `opencode models --refresh/--verbose` | ✅ 64 providers in the directory + hosted catalog refreshed from models.dev; `openclaw models list/refresh/scan` |
| MOD-11 | Mid-session model switching (/model) | ✅ /model [provider [model]], Ctrl+P Switch model, --model, SUGARCRUSH_MODEL; live switch, persisted globally | ❌ `/model` is client-only: `command.exec` answers -32030 `ui_only` (verified); status bar shows the model read-only | ✅ `/model` picker (Alt+P; `s` = this session only); applies to the next request even mid-turn, after a cache warning | ✅ /models (<leader>m), F2 cycles recent, favorites; per-message model recorded so switches are first-class | ✅ /models (<leader>m), F2 cycles recent, favorites; per-message model recorded so switches are first-class | ✅ `/model` with session/agent/global scope; TUI Ctrl+L and Control UI/Discord/Telegram pickers; mid-run switch applied at next retry |
| MOD-12 | Per-agent / per-subagent model selection | ✅ Per-agent model: Task `model` arg → preset `model:` → `subagentModel` setting → session model; provider is always the session's | 🟡 Per-agent models apply server-side; the Agents panel shows each run's step/tokens/cost, not its model | ✅ Per-subagent `model` frontmatter, per-call Agent `model`/`effort`, CLAUDE_CODE_SUBAGENT_MODEL, skill `model`, workflow-agent model | ✅ Agent `model`/`variant`; subagents inherit invoking agent's model unless set; commands can set model | ✅ Δ Agent `model: provider/model#variant`; subagent tool accepts a `model` override chosen via a `models` tool | ✅ Per-agent primary/fallbacks; sub-agent model override; separate compaction, flush, reviewer, utility models |
| MOD-13 | Small/fast model for auxiliary tasks (titles, summaries, classifiers) | 🟡 titleModel/summaryModel slots exist but default to the main model; prompt suggestions add a call per turn | ✅ Server titles sessions itself; titles appear live in sidebar, tabs and palette via `session.updated` | ✅ Haiku-class small/fast model for titles, resume summaries, agent-view summaries (ANTHROPIC_DEFAULT_HAIKU_MODEL) | ✅ `small_model` (auto-picks cheap model) for title/summary agents; plugin hook experimental.provider.small_model | 🟡 Δ `small_model` now only sets the `title` agent model; compaction always uses the session model | ✅ `agents.defaults.utilityModel` for titles, thread/topic names, progress narration, Activity recaps |
| MOD-14 | Fallback model on overload/error | ✅ fallbackModels per provider: switch on transient failure or overflow, 60 s pin, transcript notice (same provider) | ❓ Any server-side fallback is invisible; no event or notice names a model change | ✅ `--fallback-model a,b` / `fallbackModel` chain (max 3) on overload/unavailable; plus safety-classifier content fallback | ❌ No fallback model on overload/error; only retries with backoff | ❌ Δ Still no fallback model; v2 now preserves an unavailable saved model instead of silently substituting one | ✅ Same-model recovery → auth-profile rotation → `model.fallbacks`; fallback is turn-local; user picks are strict |
| MOD-15 | Reasoning/thinking control (effort levels, budgets, keywords) | 🟡 Effort only for sglang (reasoningEffort) and Gemini (thinkingBudget) plus per-agent effort:; no mid-session UI | ❌ No effort/thinking control; e.g. `thinkingBudget` is risk class `spend`, which `settings.set` refuses remotely | ✅ Effort low/medium/high/xhigh/max (`/effort` slider, `--effort`, env, per-model settings), `ultrathink` keyword, `ultracode` | ✅ Model variants (e.g. high/max, low..xhigh) cycled with ctrl+t, `--variant`, agent `variant`, custom variants | ✅ Δ Variants are an array with `id`; selected as `#variant` in model refs; ctrl+t / /variants | ✅ `/think off/minimal/low/medium/high/xhigh/adaptive/max/ultra`; provider-profile menus; `ultra` adds orchestration |
| MOD-16 | Thinking/reasoning display in UI | ✅ Thinking streams live, then folds into a collapsed "💭 Thought" row; click or Ctrl+O toggles | ✅ `reasoning.delta` streams into a live fold; settled reasoning sits in collapsible folds per reply and per tool call | ✅ Thinking collapsed by default; Ctrl+O transcript shows it in gray italics; `showThinkingSummaries` for full summaries | ✅ Reasoning parts rendered; /thinking toggles visibility; `run --thinking` | ✅ Δ Reasoning shown/hidden by default via cli.json `session.thinking` (and `mini.thinking`); `run --thinking` | ✅ `/reasoning on/off/stream`, TUI Ctrl+T, Control UI inline commentary with "Keep commentary" |
| MOD-17 | Streaming responses | ✅ All real providers stream; TUI repaints per token via forked-child frames; -p prints only the final answer | ✅ `assistant.delta` with byte offsets → gap-detecting reducer → live Markdown; durable `assistant.completed` repairs gaps | ✅ Streaming in all modes; `--include-partial-messages` for stream-json; stalled streams aborted and re-sent once | ✅ Streaming text/reasoning deltas persisted per part and broadcast over SSE to all clients | ✅ Streaming text/reasoning deltas persisted per part and broadcast over SSE to all clients | ✅ Token streaming in TUI, Control UI, ACP; block/progress streaming to chat channels |
| MOD-18 | Retries / backoff / rate-limit handling | 🟡 3 retries (max 5), 0.5 s/1 s backoff, mid-stream resume, no total timeout; Retry-After ignored, no jitter | 🟡 Model retries happen server-side and are not surfaced; the web's own socket reconnects 0.5 s→15 s jittered | ✅ 10 retries w/ exponential backoff, stall/sleep detection, max_tokens shrink, unattended retry watchdog, limit auto-continue | ✅ Up to 5 retries, 2 s exponential backoff w/ jitter, honors retry-after(-ms); retry status shown in UI | ✅ Δ 1 + max 4 retries w/ jittered backoff; 15 min chunk timeout; output-limit auto-continuation; `retry` plugin hook | ✅ Rate limits ≤10 attempts, transient 8 retries in 90 s window, jittered backoff, retry-after honored; resumes transcript |
| MOD-19 | Prompt caching (provider cache breakpoints) | 🟡 cache_control on Claude-on-Vertex, cachePoints on Bedrock; the `anthropic` type (OpenAI-compat) gets NO caching | 🟡 Inherited; cache tokens are not shown (status bar has context tokens and $ only) | ✅ Automatic prompt caching with a stable prefix (system → project context → conversation); 5m/1h TTL; `/usage` cache stats | ✅ Auto cache breakpoints on first 2 system + last 2 msgs; promptCacheKey=sessionID; x-session-affinity header | ✅ Δ Provider `compatibility.cacheFormat`/`supportsPromptCacheKey`; frozen instruction baseline; opt-in cache `warming` | ✅ Explicit system-prompt cache boundary, runtime-context carriers, cache-TTL-aware pruning, cacheRead/Write in /status |
| MOD-20 | Long-context (1M) support / context-window detection | 🟡 Window from setting (3 types), LiteLLM DB, tables; SGLang discovered; Bedrock 1M table; Vertex fixed at 200k | ✅ Context meter = `contextTokens / contextWindow` from the server (window detected server-side), % + bar | ✅ 1M window by default on Fable, Sonnet 5+, Haiku 5.5, Opus 4.7+; `[1m]` variants for Opus/Sonnet 4.6; gateway window correction | ✅ Context/input/output limits from models.dev or config `limit`; usable window derived for compaction | ✅ Context/input/output limits from models.dev or config `limit`; usable window derived for compaction | ✅ Context window from catalog metadata; window-scaled tool-result caps; 1M models supported |
| MOD-21 | Vision input (images to model) | ✅ PNG/JPEG/GIF/WebP via @mention, drag-drop, Ctrl+V; native image parts; non-vision models get placeholder + notice | ❌ No image attach/paste/drop: `session.send` carries `text` only (schema has no attachments) | ✅ Images via Ctrl+V/Alt+V paste ([Image #N] chips), dragged paths, Read tool, Remote Control attachments | ✅ Images via paste, @file, read tool; auto-resize >2000px or >5 MB base64 (attachment.image) | ✅ Δ Images via composer, read tool (≤20 MiB source) and `media.image` normalization (renamed from `attachment`) | ✅ Images from channels/Control UI (paste, drop, camera) and `view_image` tool; model-aware resize |
| MOD-22 | PDF / document input | ❌ No PDF/document input: a binary non-image file is refused with a notice; no PDF document blocks on any provider | ❌ No PDF/document upload; only `@path` mentions of files already on the server are resolved | ✅ PDFs read whole (≤10 pages) or by `pages` ranges (≤20 per call; ranges need poppler `pdftoppm`) | ✅ PDFs attached as file parts via @file or read tool (provider must support PDF input) | 🟡 Δ read tool still passes PDFs, but PDF prompt attachments are NOT sent to the model in v2 | ✅ `pdf` tool (PDF-capable model) + included document-extract plugin for attachments |
| MOD-23 | Audio / voice input or output | ❌ No audio/voice input or output | ❌ No audio input or output | 🟡 Voice dictation input only (`/voice` hold/tap; claude.ai accounts; streamed to Anthropic STT); no spoken output | ❌ No voice/audio input or speech output (TUI audio is only notification sounds) | ❌ No voice/audio input or speech output (TUI audio is only notification sounds) | ✅ Transcription in, TTS out, realtime Talk mode, voice wake, phone calls (plugin) |
| MOD-24 | Structured output / JSON-schema mode | 🧪 CompleteRequest::$jsonSchema maps to SGLang response_format, but nothing in the app sets it; no CLI flag | ➖ Not a client concern; nothing exposed | ✅ `--json-schema` validated structured output (print mode); SDK structured outputs | ✅ API/SDK `format: {type:'json_schema'}` forces a StructuredOutput tool; result in info.structured_output | ❌ Δ Structured output (`format: json_schema` / StructuredOutput tool) not present in the v2 API | ✅ JSON-Schema-validated output in Swarm collectors (`structured_output` tool) and the included `llm-task` plugin tool |
| MOD-25 | Credential storage (keychain, env, config file, auth.json) | 🟡 Env vars or plaintext apiKey in ~/.sugar-crush/config.json (0600, ${VAR} interpolation); no OS keychain | ✅ Browser never holds the token: one-time code → HttpOnly SameSite=Strict cookie → 30 s ticket; provider secrets masked | ✅ macOS Keychain; `~/.claude/.credentials.json` (0600) on Linux/Windows; env vars; `apiKeyHelper` scripts; long-lived tokens | 🟡 auth.json (~/.local/share/opencode, 0600), env vars, {env:}/{file:} config substitution; no OS keychain | 🟡 Δ Credentials per integration with multiple accounts; `auth export` prints secrets as JSON, `auth import` restores | ✅ Per-agent SQLite auth profiles, env vars, config keys, SecretRefs (1Password/Bitwarden/Vault/pass/sops), secret store |
| MOD-N1 | 🆕 Advisor / second-opinion model the agent can consult mid-task | 🟡 No advisor tool; the agent can only delegate to a sub-agent (e.g. reviewer) on another model via Task `model` (same provider) | 🟡 Inherited Task `model` arg (sub-agent on another model, same provider); the run shows in the Agents tree without its model | ✅ `/advisor` lets Claude consult a stronger second model server-side at key moments (experimental) | 🟡 No advisor model; a custom subagent with its own `model` can be delegated to as a second opinion | 🟡 No advisor; subagent tool can run a child on another model (`model` param, models lookup tool) when asked | 🟡 No free-form advisor; `decision_evaluate` (decision model), `llm-task` model override, or a different-model sub-agent |
| MOD-N2 | 🆕 Fast mode / priority service tier toggle | ❌ No fast-mode / priority service-tier toggle; only a manual `extraBody` field on `custom` providers could send `service_tier` | ❌ No fast mode / service tier; the web has no model controls at all (status bar shows provider · model read-only) | ✅ `/fast` (Alt+O) runs Opus up to 2.5x faster at higher price (research preview; Opus 5.5/5/4.8) | 🟡 No fast-mode toggle; provider options (e.g. serviceTier) can be bundled in a variant and cycled with ctrl+t | 🟡 No fast-mode toggle; variants/body overlays carry service_tier or speed settings (docs show a 'fast' variant) | ✅ `/fast status/auto/on/off/default` maps to provider service tiers per session |
| MOD-N3 | 🆕 Cache warming (keep-alive requests that preserve provider prompt caches while idle) | ❌ No cache warming or keep-alive pings; cache marks use the default 5-min TTL and idle gaps are just treated as evictions | ❌ No cache warming (none in the engine); the web shows no cache readout at all | 🟡 No keep-alive pings; caches preserved via 1h TTL (`promptCacheTtl`, default on subscriptions) and idle compaction pre-expiry | ❌ No cache-warming / keep-alive requests in 1.18.35 | ✅ Δ optional periodic keep-alive requests preserve provider prompt caches while idle | 🟡 Keep-warm only via heartbeat turns (e.g. `heartbeat.every: "55m"` against a 1 h cache); no dedicated keep-alive pings |
| **INS** | **Project instruction / rules files** |  |  |  |  |  |  |
| INS-01 | CLAUDE.md read | ✅ CLAUDE.md at repo root and in monorepo ancestors goes into the system prompt; nested ones arrive on touch | 🟡 Engine loads CLAUDE.md for served sessions; the web never lists or shows it — only `/context` reports the section size | ✅ CLAUDE.md or .claude/CLAUDE.md in cwd and every ancestor loaded at launch; subdirectory files load lazily | 🟡 CLAUDE.md is a fallback only: used if no AGENTS.md found from cwd up to git root; ~/.claude/CLAUDE.md if no global AGENTS.md | ❌ Δ CLAUDE.md is NOT read at all in v2 (no project or ~/.claude/CLAUDE.md fallback) | 🟡 Native runtime does NOT read CLAUDE.md; only via the claude-cli backend (Claude Code itself) or one-time `openclaw migrate claude` |
| INS-02 | AGENTS.md read | ✅ AGENTS.md is read at the same tiers as CLAUDE.md (second in precedence); `/init` writes AGENTS.md | 🟡 AGENTS.md: same — applied server-side, invisible in the web except as a `/context` section size | ✅ AGENTS.md read natively (v2.1.277+, built-in mod `cc-plugin-agents-md`) when no CLAUDE.md/CLAUDE.local.md is on the path | ✅ AGENTS.md from cwd up to git root (all ancestor copies) + global ~/.config/opencode/AGENTS.md, re-read every step | ✅ Δ Global + every AGENTS.md from cwd up to $HOME (or project root if outside home); file-watched; delta updates | ✅ Workspace AGENTS.md injected into the system prompt every run; execution-folder AGENTS.md appended; only file sub-agents get |
| INS-03 | Other vendor rule files (.cursorrules, .cursor/rules, GEMINI.md, copilot-instructions.md, CONVENTIONS.md, .windsurfrules) | 🟡 Also reads GEMINI.md, .cursorrules, .clinerules file; not .cursor/rules, copilot-instructions, .windsurfrules, .claude/rules | 🟡 Whatever vendor rule files the engine reads apply; the web lists none | 🟡 Not read live; `/init` migrates Cursor/Copilot rules; `/import` copies Codex/Gemini/Cursor config once | 🟡 No auto-read of .cursorrules/GEMINI.md/copilot files; add via `instructions` globs; /init cites existing Cursor/Copilot rules | 🟡 No auto-read of .cursorrules/GEMINI.md/copilot files; add via `instructions` globs; /init cites existing Cursor/Copilot rules | ❌ No .cursorrules / .cursor/rules / GEMINI.md / copilot-instructions / CONVENTIONS.md support |
| INS-04 | User-global instructions file (~/.claude/CLAUDE.md, ~/.config/opencode/AGENTS.md, ...) | ✅ Personal ~/.sugar-crush/AGENTS.md in every project, framed <user-rules>; ~/.claude/CLAUDE.md is not read | 🟡 The server account's user-global instructions apply; not visible in the web | ✅ `~/.claude/CLAUDE.md` plus `~/.claude/rules/**/*.md` apply to every project | ✅ ~/.config/opencode/AGENTS.md (else ~/.claude/CLAUDE.md) loaded into every session's system prompt | ✅ Δ ~/.config/opencode/AGENTS.md only (no ~/.claude/CLAUDE.md fallback) | ✅ The agent workspace (default ~/.openclaw/workspace) is the cwd-independent global layer; USER.md = user model |
| INS-05 | Project-local private override (CLAUDE.local.md or equivalent) | 🟡 No CLAUDE.local.md or other private per-project file; workaround is a user-tier `instructions` glob | 🟡 Inherited if the engine supports a local override; not visible in the web | ✅ `./CLAUDE.local.md` (personal, gitignore it) appended after CLAUDE.md at each directory level | ❌ No CLAUDE.local.md/AGENTS.local.md equivalent; only per-user global file or uncommitted `instructions` entries | ❌ No CLAUDE.local.md/AGENTS.local.md equivalent; only per-user global file or uncommitted `instructions` entries | ❌ No CLAUDE.local.md-style private per-project override |
| INS-06 | Managed / enterprise policy instructions | ❌ No managed or enterprise instruction file | ❓ Managed/enterprise instruction support is an engine question; the web surfaces nothing | ✅ Managed CLAUDE.md (`/etc/claude-code/CLAUDE.md`, macOS/Windows equivalents) or `claudeMd` key in managed settings; not excludable | 🟡 No dedicated managed-instructions file; admins can push `instructions` via managed config (/etc/opencode, MDM) or .well-known | 🟡 Δ Managed config/MDM still can set `instructions`; v2 adds Console-delivered org policies (not instructions) | ❌ No managed/enterprise instruction file; the Policy plugin checks config conformance, it does not inject instructions |
| INS-07 | Discovery walks up parent dirs from cwd | 🟡 Walks up only when cwd is not a git root, stopping at the nearest `.git` ancestor and never reading $HOME | 🟡 Inherited: discovery runs from the served root (or the workspace host's root); not shown | ✅ Walks from cwd up to the filesystem root at launch, loading CLAUDE.md + CLAUDE.local.md at each level | ✅ findUp from cwd to worktree (git root) for AGENTS.md→CLAUDE.md→CONTEXT.md; relative `instructions` globbed upward too | ✅ Δ Walks from cwd up to home (inside home) or project root; relative `instructions` globbed cwd→project root | ❌ No parent-directory walk; only the configured workspace plus the session's single execution folder |
| INS-08 | Nested subdirectory instruction files loaded lazily when files there are touched | 🟡 Nested CLAUDE.md/AGENTS.md surfaced in Read/Edit/Write/ApplyPatch/Glob/Grep output on touch; unfenced (ISS-017), re-sent every forked turn (ISS-018) | 🟡 Inherited; lazily loaded nested files are not announced in the web | ✅ Subdirectory CLAUDE.md/CLAUDE.local.md load the first time Claude reads, writes or edits a file there (also `cat`/`head` reads) | ✅ Read (and @file) walks up from file dir to cwd, attaching unseen AGENTS.md once in <system-reminder>; TUI shows ↳ Loaded | ✅ Δ read OR directory listing loads AGENTS.md between target and workspace as a synthetic message 'Loaded <path>' | ❌ No lazy loading of nested AGENTS.md when files in subdirectories are touched |
| INS-09 | @path imports inside instruction files | ✅ `@path.md` imports, max depth 4, .md only, confined to the importing file's boundary; `~/` imports always blocked | 🟡 Inherited; not shown | ✅ `@path` imports (relative/absolute/~), up to 4 hops, code spans ignored; external imports need one-time approval | ❌ @path imports are NOT parsed (docs suggest telling the model to Read them, or using `instructions`) | ❌ @path imports are NOT parsed (docs suggest telling the model to Read them, or using `instructions`) | ❌ No @path import syntax inside instruction files |
| INS-10 | Path-scoped rules (.claude/rules/*.md with globs, etc.) | ✅ Path-scoped rules: `.sugar-crush/rules/*.md` with `paths:` frontmatter, delivered at tool time as a `<system-reminder>` | 🟡 `/rules` runs server-side (command.exec): lists rule packs or toggles one for the session, as text rows | ✅ `.claude/rules/**/*.md`: unscoped rules load at launch; `paths:` glob rules load when a matching file is read/edited | ❌ No path-scoped/glob-conditional rules; nested AGENTS.md lazy loading is the only path-sensitive mechanism | ❌ No path-scoped/glob-conditional rules; nested AGENTS.md lazy loading is the only path-sensitive mechanism | ❌ No glob/path-scoped conditional rules |
| INS-11 | Extra instruction files via config (opencode `instructions` array, globs, URLs) | 🟡 User-tier `instructions` globs force files every session; repo-relative only, no URLs, absolute/~ silently dropped | 🟡 Extra instruction sources are `prompt`-class settings: shown read-only in the Settings page, not writable remotely | 🟡 No `instructions` array/globs/URLs; extras via --add-dir + env var, managed `claudeMd`, --append-system-prompt | ✅ `instructions` array: files, globs, ~/, absolute paths, http(s) URLs (5 s timeout); merged+deduped across config layers | ✅ Δ `instructions`: files/globs/~/abs/URLs; URLs fetched at config load (5 s) and keep last good copy; files watched | 🟡 Extra files via bundled `bootstrap-extra-files` hook (workspace globs); per-channel `systemPrompt`; `agent:bootstrap` hook |
| INS-12 | WHEN loaded (session start / every turn / on demand) and re-read on change | ✅ Read once per session at first prompt build; frozen until /clear, compaction, rewind or session switch; nested on touch | 🟡 Loaded per server session by the engine; the browser gets no "instructions changed/reloaded" notice | ✅ Root/user files read once at start and frozen until /clear, /compact or restart; nested files and path rules lazily | ✅ Rebuilt from disk on EVERY LLM step (no cache): edits apply next step; remote URLs re-fetched each step | ✅ Δ Frozen per-session baseline; before each model attempt sources are hashed and changes injected as update messages | ✅ Re-read and injected on every run (edits apply next turn); `continuation-skip`/`never` modes; AGENTS.md refreshed after compaction |
| INS-13 | HOW injected (system prompt vs user-message reminder) and precedence order | ✅ Fenced system-prompt sections, general to specific: user rules, personal, ancestors, root, forced, project rules | 🟡 Inherited; injection/precedence invisible | ✅ System-reminder user message after the system prompt ("…OVERRIDE any default behavior"); managed→user→project→local | ✅ Joined into the single system message after base/agent prompt + <env>; each prefixed 'Instructions from: <path>' | ✅ Δ Order: agent/provider prompt → Code Mode guidance → MCP/reference/skill guidance → AGENTS.md → config → date/env → API | ✅ System prompt "Project Context" (above cache boundary), order AGENTS/SOUL/IDENTITY/USER/BOOTSTRAP/MEMORY then exec-folder AGENTS |
| INS-14 | Shown to the user at startup (banner / count / list)? | ❌ Nothing at startup says which instruction files loaded; only refusals and deferrals become launch notices | ❌ No startup banner or list of loaded files; the dashboard shows only the served root and versions | 🟡 No startup list of loaded files; only size warnings, the AGENTS.md "loaded" line and the external-import dialog | ❌ No startup banner/count of loaded rule files; only lazily-loaded nested files show '↳ Loaded <path>' under Read | 🟡 Δ No startup list, but timeline rows show 'Instructions updated' and grouped 'Loaded <file>' instruction loads | ❌ Not shown at startup; TUI header = gateway URL/agent/session only; loaded files visible on demand via `/context` |
| INS-15 | Inspect loaded instructions in the UI (/memory, /context, panel) | 🟡 `/context` shows the instruction layer's token size and section count (e.g. `project-instructions ×2`), not file names | 🟡 `/context` (server-run) prints the section breakdown incl. instruction and memory section sizes; no content viewer | ✅ `/context` lists "Memory files" (CLAUDE.md, rules, auto memory) in its token grid; `/memory` lists every location | ❌ No /memory or /context view of loaded instructions; `opencode debug config` shows only the `instructions` array | ❌ No /memory or /context view of loaded instructions; `opencode debug config` shows only the `instructions` array | ✅ `/context list/detail/map/json` (per-file raw vs injected, truncation, skills, tool-schema sizes); Control UI Agents → Files |
| INS-16 | Edit instructions from the UI (/memory opens editor, `#` quick-add) | ❌ No in-app editing of instruction files and no `#` quick-add; `/newrule` has the agent draft a rule file | ❌ Cannot open or edit CLAUDE.md/AGENTS.md from the web; the Memory panel edits memory notes, not instruction files | ✅ `/memory` opens instruction files in your editor; ask Claude to "add this to CLAUDE.md"; old `#` quick-add removed | ❌ No in-app instruction editor or '#' quick-add; edit files externally (or ask the agent) | ❌ No in-app instruction editor or '#' quick-add; edit files externally (or ask the agent) | ✅ Control UI Settings → Agents → Files editor (Preview/Edit); agent edits its own files; `personal_instructions` tool |
| INS-17 | Generate instructions (/init) | ✅ `/init [focus]` has the agent study the repo and write or improve root AGENTS.md (TUI only; refused in server/web) | ❌ `/init` is client-only: refused -32030 `ui_only` over the wire (verified) | ✅ `/init` generates/improves CLAUDE.md; CLAUDE_CODE_NEW_INIT=1 multi-phase flow (subagent scan, skills, hooks) | ✅ /init: guided AGENTS.md creation/improve-in-place; may ask questions; focuses on commands, gotchas, existing rule files | ✅ Δ /init (AGENTS.md setup) appends its template to your text and runs in the current session | 🟡 No repo-analysis `/init`; setup seeds templates and BOOTSTRAP.md runs a first-run identity ritual |
| INS-18 | Size limits / truncation / warnings for large instruction files | ✅ 60 KiB read cap per doc, 64 KiB framed, 128 KiB total; overflow becomes a pointer line (never clipped); launch notice | 🟡 Inherited truncation; any warning reaches the web only via `/notices` | ✅ Files >4 MiB skipped; startup and `/status` warn over ~200 lines per file and on combined size; MEMORY.md capped 200 lines/25 KB | ❌ No size limits, truncation or warnings for instruction files (whole files injected every step) | ❌ No size limits, truncation or warnings for instruction files (whole files injected every step) | ✅ 20,000 chars/file, 60,000 total, USER.md fixed 4,000; built-in truncation notice; per-agent overrides |
| INS-19 | Workspace-trust gating before loading project instructions | ❌ Project instruction files load with no trust gate; mitigated by fencing, escaping and a "no authority over harness" preamble | ❌ No trust dialog: untrusted project hooks/MCP/commands stay off per user-tier allow-lists; the web cannot grant trust | 🟡 Interactive trust dialog runs before a new folder's session; CLAUDE.md is not itself listed as trust-gated; `-p` never asks | ❌ No workspace-trust gating; project AGENTS.md/config load automatically (OPENCODE_DISABLE_PROJECT_CONFIG is all-or-nothing) | ❌ No workspace-trust gating; project AGENTS.md/config load automatically (OPENCODE_DISABLE_PROJECT_CONFIG is all-or-nothing) | ❌ No workspace-trust prompt before loading instructions |
| INS-20 | Exclusion controls (claudeMdExcludes, disable flags) | ❌ No exclusion control for instruction docs (rules have `disabledRules` and `/rules` toggles) | 🟡 Exclusion keys appear in the schema-driven Settings form, editable only when their risk class allows remote writes | ✅ `claudeMdExcludes` globs, CLAUDE_CODE_DISABLE_CLAUDE_MDS, --bare, --safe-mode, --setting-sources, managed-only | 🟡 Env toggles: OPENCODE_DISABLE_CLAUDE_CODE(_PROMPT), OPENCODE_DISABLE_PROJECT_CONFIG; no per-file exclude list | 🟡 Δ OPENCODE_DISABLE_PROJECT_CONFIG (project AGENTS.md only); no per-file excludes; CLAUDE flags now only affect skills | ✅ `contextInjection` always/continuation-skip/never, `skipBootstrap`, `skipOptionalBootstrapFiles`, `agent:bootstrap` hook |
| INS-N1 | 🆕 Injection fencing / escaping of instruction-file content before it reaches the model | ✅ Root docs fenced, escaped against 11 tags + chat tokens, with provenance preambles | ✅ Inherited: web-started sessions assemble the same fenced prompt (PromptFence escaping + provenance preambles) server-side | 🟡 MEMORY.md and recalled notes are scrubbed of invisible chars and fake markup; CLAUDE.md itself is not fenced or escaped | ❌ No fencing/escaping: instruction files are pasted raw after 'Instructions from: <path>' | ❌ No fencing: files rendered raw as 'Instructions from: <path>' (updates as diffs), not escaped | ❌ Bootstrap files (incl. a repo's execution-folder AGENTS.md) are injected verbatim as trusted Project Context, not fenced |
| **MEM** | **Memory** |  |  |  |  |  |  |
| MEM-01 | Persistent cross-session memory exists | ✅ Persistent cross-session memory: markdown notes in a home store plus an optional repo-local store | ✅ Memory notes persist server-side and the Memory panel reads them across sessions | ✅ Two systems: CLAUDE.md (you write) and auto memory (Claude writes); both persist and load every session | 🔌 No native memory; plugins: opencode-agent-memory (used here), opencode-supermemory, opencode-honcho, oc-mnemoria, magic-context | 🔌 Δ Still no native memory; V1 plugins don't run — opencode-agent-memory 0.3.0 targets the v2 plugin API | ✅ Plain-Markdown memory in the agent workspace plus a derived SQLite search index |
| MEM-02 | Auto-memory (model saves facts without being asked) | ✅ Auto-memory at turn end: summary model proposes typed note ops (5-min throttle); model also saves via Memory tool | 🟡 Model-initiated saves (Memory tool) appear only as an ordinary tool card | ✅ Auto memory on by default locally: Claude saves `user`/`feedback`/`project`/`reference` notes when worth keeping | 🔌 Only via plugins (agent-memory prompts agent to self-edit blocks; supermemory/honcho/harness-memory auto-capture) | 🔌 Only via plugins (agent-memory prompts agent to self-edit blocks; supermemory/honcho/harness-memory auto-capture) | ✅ Agent writes durable facts as it works (template-driven), silent pre-compaction flush, dreaming promotion, optional hooks |
| MEM-03 | Memory scopes (user / project / local / agent / team) | ✅ Scopes: user (all projects), project (keyed per root; repo-local or home), agent (storage only, never in prompt) | ✅ Panel tabs for `user` / `project` / `agent` scopes; a new note goes to the open tab's scope | ✅ Per-repo auto memory; subagent memory user/project/local; CLAUDE.md scopes; team memory stores in remote sessions | 🔌 agent-memory: global + project blocks; oc-mnemoria tags per agent; native none | 🔌 agent-memory: global + project blocks; oc-mnemoria tags per agent; native none | 🟡 Per-agent workspace memory + per-user personal USER.md + privacy scoping (main/private vs groups); no project/team scope |
| MEM-04 | Storage format & location | ✅ `<uuid>.md` with YAML frontmatter (type, scope, tags, dates); per-scope MEMORY.md index; home dir is its own git repo | 🟡 Storage format/location not shown; entries list id and content | ✅ Markdown under ~/.claude/projects/<project>/memory/: MEMORY.md index + topic files with `type`/`modified` frontmatter | 🔌 agent-memory: markdown blocks in ~/.config/opencode/memory/ & .opencode/memory/ (seen locally); journal store | 🔌 agent-memory: markdown blocks in ~/.config/opencode/memory/ & .opencode/memory/ (seen locally); journal store | ✅ Markdown files in the workspace (git-backup recommended); index in per-agent SQLite (FTS5 + sqlite-vec + embedding cache) |
| MEM-05 | Memory index/entry file loaded into the prompt each session | ✅ `<project-memory>` system section is an INDEX (one line per note, 80-byte preview), user notes first, frozen per session | 🟡 Project memory is injected per session server-side (`/context` shows a `project-memory` section); not shown in the UI | ✅ First 200 lines / 25 KB of MEMORY.md injected at every session start (and re-injected after compaction) | 🔌 agent-memory injects blocks into system prompt each request (via system.transform hook) | 🔌 agent-memory injects blocks into system prompt each request (via system.transform hook) | ✅ MEMORY.md (private sessions only) + USER.md injected every run; daily notes only via tools or the /new startup prelude |
| MEM-06 | Relevance selection / retrieval (embedding / vector / keyword / hybrid) | ✅ Per-turn <memory-recall> (3 notes): BM25 + optional embeddings, recency, MMR, in the user-role tail row | ✅ Search box queries every scope (`memory.search`, best first, limit 100) | 🟡 Index + model-chosen file reads + side-model "memory recall" ("Recalled N memories"); no embeddings/vector search | 🔌 agent-memory journal semantic search; oc-mnemoria hybrid BM25+semantic; magic-context unified search | 🔌 Δ agent-memory 0.3 journal search (v2 API); supermemory 2.0.17 supports v1+v2; others unverified on v2 | ✅ Hybrid vector + BM25 + filename search, × recency decay × importance, MMR; trigger recall; Active Memory escalation |
| MEM-07 | Memory tools exposed to the model (search / get / write) | ✅ `Memory` tool: view / save / str_replace / delete / recall (no-ask in every mode, plan mode included) | 🟡 Memory/Recall tool calls render as generic tool cards | 🟡 No dedicated memory tool; Claude edits the memory dir with Read/Write/Edit (exempt from sensitive-file write blocks) | 🔌 agent-memory: memory_list/memory_set/memory_replace + journal_write/search/read tools | 🔌 agent-memory: memory_list/memory_set/memory_replace + journal_write/search/read tools | ✅ `memory_search`, `memory_get`, `intent`; writes via ordinary write/edit; `sessions_search`/`sessions_history` for transcript hits |
| MEM-08 | Consolidation / dreaming / dedup / pruning | ✅ Dream pass every 2h folds the compaction journal into notes (read-only Memory + Read); optional skill drafts | 🟡 Consolidation/dreaming not surfaced; `/skills` only lists dream-proposed skill drafts | 🟡 Index hygiene only (size reminders, rewrite-on-overflow error, merge/drop stale entries); no background consolidation/"dream" | 🔌 magic-context 'overnight dreamer', harness-memory gated pipeline; nothing native | ❓ Δ No native consolidation; magic-context/harness-memory v2 compatibility unverified | ✅ Default-on "dreaming": gated promotion into MEMORY.md, taint gate, tool-free merge/supersede, DREAMS.md diary |
| MEM-09 | User UI to view/edit memory (/memory, panel) | 🟡 `/memory list/add/search/edit/delete/clear/import/log/restore` text subcommands; no TUI panel or editor | ✅ Memory side panel: list per scope, search, add, inline edit, delete with confirm | ✅ `/memory`: lists instruction + memory files, auto-memory toggle, "open auto memory folder"; VS Code Customize → Memory | ❌ No memory UI; plugin memory files are plain markdown to edit by hand | ❌ No memory UI; plugin memory files are plain markdown to edit by hand | ✅ Control UI Memory (Dreams diary, Wiki), Agents → Files, Import Memory; `/dreaming`, `/active-memory`; `openclaw memory` CLI |
| MEM-10 | User is told when memory is saved or recalled | 🟡 Auto-memory/dream writes get a transcript notice; Memory tool shows as tool rows; recall invisible; flush silent | 🟡 No "memory saved/recalled" toast; the user sees only the tool card | ✅ Transcript notices "Saved N memories" / "Recalled N memories" with clickable filenames | 🔌 Plugin memory writes appear only as ordinary tool-call rows | 🔌 Plugin memory writes appear only as ordinary tool-call rows | 🟡 Mostly silent: Active Memory visible only with `/verbose` or `/trace`; flush silent unless degraded + notifyUser |
| MEM-11 | Daily logs / journal files | 🟡 No daily logs; the closest is the append-only compaction journal (JSONL of model-written summaries) | ❌ No daily-log/journal view | ❌ No daily logs/journal natively; 🔌 `remember` (official marketplace) adds tiered daily logs | 🔌 agent-memory journal (append-only, tagged entries) | 🔌 agent-memory journal (append-only, tagged entries) | ✅ memory/YYYY-MM-DD.md daily notes (plus slugged variants); today + yesterday loaded on bare /new or /reset |
| MEM-12 | Session-summary memory (past sessions searchable) | 🟡 Past compaction summaries are journaled and folded by the dream pass; not directly searchable by the model | ❌ No past-session search; the sidebar filter matches names/previews only | 🟡 AI titles/summaries for the resume picker, `/recap`, resume-from-summary; past sessions aren't a searchable memory | 🟡 All sessions persist in SQLite but search is title-only; no semantic past-session recall (🔌 magic-context) | 🟡 All sessions persist in SQLite but search is title-only; no semantic past-session recall (🔌 magic-context) | ✅ Optional session-transcript indexing, `sessions_search` + `sessions_history`, cross-conversation recall, session-backfill |
| MEM-13 | Subagent-specific memory | ❌ No sub-agent memory: the preset `memory:` key is parsed and inert; the agent scope is a shared import bucket, never in the prompt | 🟡 An `agent` scope tab exists; no per-sub-agent memory view | ✅ Subagent `memory: user/project/local` gives it its own MEMORY.md (first 200 lines/25 KB) plus read/write instructions | ❌ No subagent-scoped memory (agents have no memory field) | ❌ No subagent-scoped memory (agents have no memory field) | 🟡 Each configured agent has its own workspace memory/index; spawned sub-agents get no memory section |
| MEM-14 | Memory import/export / cross-tool memory | 🟡 /memory import claude/opencode: one-shot, lands in agent scope (not prompt/recall block); no export | ❌ No memory import/export in the web | 🟡 `/import` copies Codex/Gemini/Cursor instructions, MCP, commands, agents, skills; a claude.ai-synced `import-memory` skill | 🟡 Only cross-tool reuse is reading CLAUDE.md/~/.claude/CLAUDE.md; no memory import/export | 🟡 Only cross-tool reuse is reading CLAUDE.md/~/.claude/CLAUDE.md; no memory import/export | ✅ Import Codex, Claude Code auto-memory and Hermes memory (Control UI or migrate); plain-Markdown export; `memory forget` |
| MEM-15 | Pre-compaction memory flush (save durable facts before compaction) | ✅ Pre-compaction memory flush: one silent Memory-only step before an in-turn step summary or a host compaction | 🟡 Inherited (if the engine flushes before compaction); invisible | ❌ No pre-compaction memory flush; `PreCompact` (can block) / `PostCompact` hooks could script one | 🔌 Hook experimental.session.compacting lets plugins inject durable context; agent-memory blocks survive compaction | 🔌 Δ Plugins use `ctx.session.hook("compaction")` to steer summaries; core summary excludes AGENTS.md/env (re-sent fresh) | ✅ Silent pre-compaction memory flush on a private transcript copy, once per compaction cycle, optional local model |
| MEM-N1 | 🆕 Versioned memory (memory dir under git; log / diff / undo of memory changes) | ✅ Home memory dir is a private git repo; `/memory log` and `/memory restore <sha>` (restore is a new commit) | 🟡 `/memory log` and `/memory restore <sha>` run server-side when typed; Memory panel has no history and its edits commit only later | ❌ No versioned memory: memory files are plain markdown with a `modified` timestamp; no built-in log/diff/undo | ❌ No versioned memory (no native memory at all) | ❌ No versioned memory (no native memory) | 🟡 Workspace auto git-initialized (manual commits); dreaming keeps rewrite preimages; no memory log/diff/undo commands |
| MEM-N2 | 🆕 Repo-shareable project memory (project notes committed with the repo by default) | ✅ Project notes default to git-visible <repo>/.sugar-crush/memory/project/ | 🟡 Memory tool and typed /memory add save to repo-local .sugar-crush/memory/project/; the panel's Project tab sees only HOME | 🟡 CLAUDE.md is the committed shared memory; subagent `memory: project` is repo-shareable; auto memory stays machine-local | 🔌 🔌 opencode-agent-memory project blocks live in .opencode/memory/ and can be committed (not committed in this repo) | 🔌 🔌 opencode-agent-memory 0.3.0 (v2 API, needs v2.0.12+); project memory committable if stored in repo | ❌ Memory lives in the private per-agent workspace by design; nothing is committed with a project repo |
| MEM-N3 | 🆕 Standing intents (event-conditioned instructions stored and checked before acting) | ❌ No standing-intent store or tool; closest are path-scoped rules (`paths:`, delivered when a matching file is touched) and hooks | ❌ No standing intents (the engine has none either); path-scoped rules apply server-side but the web never shows them | ❌ No stored event-conditioned intents checked before acting | ❌ No standing intents / event-conditioned instructions | ❌ No standing intents | ✅ `intent` tool stores event-conditioned instructions checked before eligible replies |
| **PRM** | **System prompt & per-request context assembly** |  |  |  |  |  |  |
| PRM-01 | Base system prompt (provider/model-specific variants) | ✅ One base prompt (identity, tone, tools, acting vs asking, security) + family paragraph for DeepSeek/Qwen/MiniMax | 🟡 Inherited; the system prompt is never shown | ✅ Built-in `claude_code` system prompt (incl. git/commit rules inside the Bash tool description), snapshotted once per conversation | ✅ Model-family base prompts: anthropic, beast (gpt-4/o1/o3), gpt, gpt-astra (gpt-6), codex, gemini, kimi, trinity, meta, default | 🟡 Δ One short generic system.txt for all models; overrides only for gpt/astra/kimi/trinity/meta, Anthropic text appended | ✅ OpenClaw-owned prompt rebuilt every run; provider plugins can swap 3 named sections or add cache-aware prefix/suffix |
| PRM-02 | Environment block (cwd, OS, platform, date, shell) | ✅ `<env>` (last section): cwd, git-repo flag, platform, OS, PHP version, model, date (frozen at session start); no shell | 🟡 Inherited (`env` section in `/context`); not displayed | ✅ Environment block: cwd, git-repo flag, extra dirs, platform, shell, OS version, model name/ID, knowledge cutoff | ✅ <env>: model id, cwd, worktree root, is-git, platform, date; bash tool description adds OS, shell, tmp dir | ✅ Δ Built-in date + <env> (cwd, root, git, platform, OpenCode tmp dir) as instruction sources with change deltas | ✅ Runtime line (host, OS, Node, model, repo root, session) + Temporal Context (local date, time zone) |
| PRM-03 | Git status / branch / recent commits in prompt | ✅ Git branch, porcelain status, recent log and post-write diffs in the user-role `<turn-context>` row at the tail (not message 0) | 🟡 Inherited if the engine injects git state; the web shows no git info | ✅ Startup git snapshot (branch, main branch, user, status, recent commits), "a snapshot in time"; refreshed at compaction | ❌ No git status/branch/commits in prompt (only 'Is directory a git repo: yes/no') | ❌ No git status/branch/commits in prompt (only 'Is directory a git repo: yes/no') | 🟡 Only the repo root appears in the prompt; no git status/branch/log injected |
| PRM-04 | Directory tree / repo map in prompt | ✅ `<repo-map>` (directory and manifest facts, 8 KiB per section) plus `<symbol-map>` (PageRank-ranked definitions, 1,024 tokens) | 🟡 Inherited (RepoMap tool server-side); not shown | ❌ No directory tree or repo map injected; Claude explores with shell search and subagents | ❌ No directory tree or repo map in the prompt | ❌ No directory tree or repo map in the prompt | ❌ No directory tree or repo map in the prompt |
| PRM-05 | Tool schemas & descriptions (dynamic per agent/mode) | ✅ Tool schemas per request; plus a sorted tool-guidance prose layer; per-agent grants; Prune/Recall/Compress gated by pruning mode | 🟡 Tool sets per mode/agent are server-side; `/context` reports tool-schema tokens; no tool list (`server.info` unused) | ✅ Tool set varies by agent, mode, model, provider and flags; MCP tools deferred behind ToolSearch; tool list frozen per conversation | ✅ Per-agent tool list: denied tools removed, gpt→apply_patch swap, task desc lists allowed subagents, tool.definition hook | ✅ Δ Built-ins listed natively; MCP tools default behind Code Mode `execute` catalog; GPT gets `patch`; plugin tool transforms | ✅ Tools filtered per run by profile/policy/provider/sandbox/channel/sender; non-core schemas deferred behind Tool Search by default |
| PRM-06 | Skills listing in prompt (names + descriptions) | ✅ `<available-skills>` listing: badge, name and description per skill (≤1 KiB each); no total cap | 🟡 `/context` shows the `available-skills` section size; no skills list | ✅ Skill names + descriptions arrive as a system-reminder listing (budget 1% of context window; 1,536 chars per entry) | ✅ Skills listed twice: verbose <available_skills> (name, description, location) in system prompt + skill tool description | ✅ Δ Each step lists permitted skills (id, name, description); `opencode/autoinvoke:false` hides one; body via skill tool | ✅ `<available_skills>` XML (name, description, location) in the Skills section; model reads SKILL.md on demand |
| PRM-07 | Agents listing in prompt (subagent catalog) | 🟡 Agent catalog is names only (`Current agent roster: a, b, c`) in the Task tool-guidance; no descriptions | 🟡 Inherited; `agents.list` is unused by the web | ✅ Subagent catalog delivered as a system-reminder ("Available agent types for the Agent tool: name: desc (Tools: …)") | ✅ Subagent catalog (name: description) appended to task tool description, filtered by the agent's task permission | ✅ Δ Subagent catalog appended to the `subagent` tool description (non-primary, non-hidden, permitted agents) | 🟡 No sub-agent catalog in the prompt; Delegation section + `agents_list` tool + Active Subagents runtime block |
| PRM-08 | Mid-conversation system reminders (todo nudges, file-changed notices, mode notices) | ✅ Reminders: turn-context row, todo nudges, <context-reminder>, changed-since-read, plan-mode, path nudges | 🟡 Server reminders arrive as notice rows: context-usage "Heads up …" and truncation notices render in the transcript | ✅ Reminders for file changes on disk, task-list nudges, plan mode, background completions, hook context, deferred tools, attribution | 🟡 Synthetic reminders: plan-mode, plan→build switch, nested AGENTS.md, max-steps, post-compaction continue; no todo/file nudges | ✅ Δ Durable reminders: plan enter/leave, instruction & date/env deltas, nested AGENTS.md, max-steps, continuations | ✅ Runtime Context carriers (exec/sub-agent/media snapshots), steering events, post-compaction refresh, completion self-check |
| PRM-09 | Custom system prompt override / append (flags or config) | 🟡 No override or append flag (deliberate); append via `~/.sugar-crush/AGENTS.md`, rules, rulebooks and `instructions` globs | ❌ No custom system-prompt override/append from the web | ✅ `--system-prompt(-file)`, `--append-system-prompt(-file)`, `--append-subagent-system-prompt(-file)`; `--agent` replaces the prompt | 🟡 Agent `prompt` replaces base prompt; API per-message `system`; system.transform plugin hook; no CLI flag | 🟡 Δ Agent `system` replaces base prompt; API session instruction entries; `context` hook edits system; no CLI flag | 🟡 No full-replace flag; append via channel/group `systemPrompt`, workspace files, `before_prompt_build` and `agent:bootstrap` hooks |
| PRM-10 | Output styles / personas | 🟡 No output styles; user rulebooks toggled per session with `/rules <name>` are the nearest switchable persona packs | ❌ No output styles/personas switch | ✅ Output styles: Default, Proactive, Concise, Explanatory, Learning + custom (.claude/output-styles, plugins); `/output-style` | ❌ No output styles; personas only via custom agents' prompts | ❌ No output styles; personas only via custom agents' prompts | ✅ SOUL.md persona/tone + IDENTITY.md; OpenAI personality layer; Claude bundle output-styles become skills |
| PRM-11 | Per-agent prompt | ✅ Agent presets carry a prompt body sent as a leading system row on top of the session's full system prompt | 🟡 Per-agent prompts apply server-side; not visible | ✅ Each subagent's markdown body (or `prompt` JSON field) is its full system prompt, plus appended environment details | ✅ Agent `prompt` (inline or {file:...}) replaces the model-family base prompt for that agent | ✅ Δ Agent `system` (Markdown body) replaces provider prompt; instructions/skills/references still added | ✅ Per-agent workspace prompt files; sub-agent spawn envelope ("# Subagent Context") + minimal prompt; role templates |
| PRM-12 | Context-usage breakdown view (/context-style) | ✅ /context: per-layer system prompt, tool schemas, history, pruned refs, free space, largest msgs, cache % | 🟡 `/context` runs server-side and prints the full breakdown (sections, tools, history, free, largest messages) as a row | ✅ `/context`: colored grid by category (system prompt, tools, MCP, memory files, skills, messages) with optimization suggestions | 🔌 Sidebar shows total tokens/% only; breakdown via plugins (Context Analysis plugin, DCP /dcp context) | 🟡 Δ Sidebar context tokens/%/$; desktop shows context tokens + family cost; no category breakdown | ✅ `/context list/detail/map/json` + `/status`; Control UI "Prompt budget (last run)" meter |
| PRM-13 | IDE/editor context injection (open file, selection, diagnostics) | 🟡 Only via `sugarcrush acp`: editor resource/resource_link blocks inlined; no open-file, selection or diagnostics | ➖ A browser tab has no editor context to inject | ✅ `/ide` (VS Code family, JetBrains): current selection + active file path sent with each prompt; `getDiagnostics` tool | ✅ Editor selection/open file via Claude-Code-compatible IDE WebSocket (CLAUDE_CODE_SSE_PORT) or Zed; VS Code extension | ✅ Editor selection/open file via Claude-Code-compatible IDE WebSocket (CLAUDE_CODE_SSE_PORT) or Zed; VS Code extension | ❌ No IDE open-file/selection/diagnostics injection |
| PRM-14 | Date/time awareness & knowledge-cutoff note | 🟡 `Current date:` in `<env>`, frozen at session start (stale past midnight until a refresh point); no knowledge-cutoff note | 🟡 Inherited; not shown | ✅ Current date and the model's knowledge cutoff are in context | 🟡 'Today's date' (day granularity) in env block; no knowledge-cutoff note; no clock time | 🟡 Δ Date (day granularity) is an instruction source; rollovers inject 'Today's date is now:' | ✅ User-local date + time zone each run (below cache boundary); exact time via `session_status` |
| PRM-15 | Prompt debugging (view raw request / dump system prompt) | 🟡 No raw prompt or request dump; `/context` gives per-layer sizes; golden snapshots exist only in tests | ❌ No raw-request or system-prompt dump in the web | ✅ Raw request logging with `OTEL_LOG_RAW_API_BODIES=file:<dir>`; `--debug api`; no in-app system-prompt viewer | 🟡 No raw-request/system-prompt dump; debug logs, `opencode debug agent`, /experimental/tool schemas, OTLP spans | 🟡 Δ `opencode api` hits any server operation; `debug agents/config/paths`; still no system-prompt dump | ✅ `/export-trajectory` bundle incl. `system-prompt.txt` + tools; `openclaw proxy` capture proxy; `llm_input` hook |
| PRM-N1 | 🆕 Byte-stable prompt prefix engineered for provider prompt-cache hits (volatile state moved to a tail) | ✅ Per-session memo of standing layers; volatile git state in a tail user row | ✅ Inherited: served turns prime the per-session prompt memo before the fork and persist the volatile <turn-context> tail row | ✅ Prefix is engineered for cache hits: system-prompt snapshot, frozen tool list, append-only reminders, git snapshot frozen | 🟡 Mostly stable prefix (static day-granular env, ≤2 system msgs, sorted tools) but instructions re-read every step | ✅ Δ Frozen per-session instruction baseline; changes appended as chronological deltas; plan reminders reconciled near tail | ✅ Explicit system-prompt cache boundary; volatile facts moved to runtime-context carriers; memoized stable prefix; snapshot CI |
| PRM-N2 | 🆕 Project references (extra local dirs / git repos advertised to the model via config) | ❌ No project-references config: one `--root` per session; `instructions` globs inject file text, not browsable extra dirs/repos | ❌ No project references; picking a directory starts a separate workspace-host session rather than adding a root to one | ✅ Extra dirs via `--add-dir`, `/add-dir`, `additionalDirectories`; listed to the model as "Additional working directories" | ✅ `references` config: local dirs/git repos advertised in prompt, @alias autocomplete | ✅ Δ still supported (`references` map), advertised with MCP/skill guidance | 🟡 Project registry (`projects.register`) and sandbox bind mounts add extra repos/dirs for sessions; not advertised in the prompt |
| PRM-N3 | 🆕 Behavioural completion contract (prompt sections / checks that the agent finishes promised work) | ✅ Maxims restate OpenClaw's Execution Bias + Promised Work, plus a prove-before-done clause; opt-in /goal judge; no auto self-check | 🟡 Inherited Maxims + prove-before-done prompt clauses; `/goal` (the judge loop) is refused `ui_only` over the wire | ✅ `/goal` has a model check each turn's result against a completion condition; Stop/prompt hooks can block early stops | 🔌 No native completion contract; 🔌 opencode-goal-plugin adds evidence-gated /goal completion (v1 only) | ❌ Δ No completion contract; opencode-goal-plugin does not support OpenCode 2 | ✅ Execution Bias + Promised Work sections and a one-shot completion self-check |
| **CTX** | **Context-window management** |  |  |  |  |  |  |
| CTX-01 | Token counting method (provider-reported vs estimate) | 🟡 Mixed: turn budget = provider tokens + estimate; status bar calibrated; compaction tiers raw content-only estimate | 🟡 Shows the server's script-weighted estimate (`~N tok`), not provider-reported counts | ✅ Uses provider-reported usage (input + cache-creation + cache-read tokens) for % and compaction | ✅ Overflow decisions use provider-reported usage (total or in+out+cache); chars/4 estimates only for tail/prune sizing | ✅ Δ Runner estimates the complete model-visible request before each step; provider usage used for display | ✅ Provider-reported usage is authoritative; chars/4 (CJK-aware) estimates only for messages after the last usage |
| CTX-02 | Context usage display (% / tokens) in UI | ✅ Status bar `~81K/131K (62%) −52K pruned`, step `· ctx P%` when over budget; `/context` breakdown; ctx% shown to the model at ≥60% | ✅ Status bar: `~tokens · %` with a meter per session; tiles show step and spend | ✅ `/context` grid, context-low warning near the limit, verbose token counter, status-line %; VS Code context indicator | ✅ Sidebar: tokens, % of context, $ spent; child-session footer shows its own context % and cost | ✅ Sidebar: tokens, % of context, $ spent; child-session footer shows its own context % and cost | ✅ `/status` window fill, TUI footer token counts, Control UI context ring ("Prompt budget (last run)"), `/usage` footer |
| CTX-03 | Auto-compaction trigger (threshold) | ✅ Tiers: reminder 70% (cap 100k), auto 85% (cap 150k), block 95%, plus an in-turn 80% step budget; thrash breaker; per-model caps | 🟡 Auto-compaction runs server-side; `compaction.completed` renders "History compacted (kind): before → after tokens" | ✅ Auto-compacts at the model limit (~967K on native 1M); `/autocompact 100k–1M`, `--autocompact`, percent override; idle compaction | ✅ Auto-compact when reported tokens ≥ usable (input limit − min(20k, maxOut)); runs mid-turn; `compaction.auto` | ✅ Δ Auto-compact when estimated request exceeds window minus `buffer` (default 10% of limit); `compaction.auto` | ✅ Auto-compact when context > window − reserve (16,384; runner floor 20,000), keepRecent 20,000; byte guard optional |
| CTX-04 | Manual compaction (/compact [instructions]) | ✅ `/compact [focus]` (LLM summary) and `/compact --self` (model's Compress, previewed in the permission modal); TUI only | ❌ `/compact` is client-only — refused -32030 `ui_only` over the wire (verified); no compact button | ✅ `/compact [focus instructions]` | 🟡 /compact (alias /summarize, <leader>c) — no custom-instructions argument | 🟡 Δ /compact in TUI plus idempotent API compact (queued to next safe point); no custom instructions | ✅ `/compact [instructions]` (focus ≤800 code points, escaped as untrusted data) |
| CTX-05 | Compaction method (LLM summary prompt, what survives, file re-reads) | ✅ LLM writes 6-facet records + <session-state> block, merged across compactions; rows hidden; files re-injected | 🟡 Method inherited; the web shows only the before/after notice | ✅ LLM summary on the cached prefix; re-injects CLAUDE.md, memory, plan, 5 recent files, skills (5K/25K), fresh git status | ✅ Hidden compaction agent writes anchored 5-section summary; verbatim recent tail kept; prior summary merged; auto-continue | ✅ Δ Rolling structured summary by the session model + ~15k-token recent tail; or provider-native encrypted compaction | ✅ Structured LLM summary; default "safeguard" mode audits required headings and keeps history if no summary passes |
| CTX-06 | Tool-output clearing / micro-compaction / pruning old tool results | ✅ Deterministic pruning of stale or old tool output to one-line placeholders (over budget, at turn start in auto mode, `/sweep`) | 🟡 `/sweep`, `/pruning`, `/decompress`, `/recompress` run server-side as slash commands; no UI controls | ✅ Clears older tool outputs before summarizing; prunes oldest images/PDFs in batches | 🟡 Opt-in prune (`compaction.prune`, default false): old tool outputs → '[Old tool result content cleared]' beyond 40k | ❌ Δ No pruning: `compaction.prune`/`tail_turns` are ignored with a warning; recent tail just shortens long tool output | ✅ Session pruning (`contextPruning.mode: "cache-ttl"`): soft-trim then hard-clear old tool results; Anthropic server-side clearing |
| CTX-07 | Tool-output truncation at tool time (limits) | ✅ Tool output capped at 64 KiB; results over 30% of the window spill to a session file with head and tail preview | ✅ `tool.finished` content is capped at 256 KiB; the card offers "Load the full output" (paged `tool.output`) | ✅ Bash ~30K chars inline then saved file + 2K preview; MCP warn 10K / cap 25K tokens; hook text 10K chars | ✅ All tool output capped 2000 lines/50 KB; full text saved to file with hint to delegate to explore agent | ✅ All tool output capped 2000 lines/50 KB; full text saved to file with hint to delegate to explore agent | ✅ Tool results capped by window: 16k chars (<100k), 32k (≥100k), 64k (≥200k), ≤30% of window, aggregate ≤50% |
| CTX-08 | Large-file read handling (offset/limit, paging) | ✅ Read pages 2000 lines / 50 KiB with numbered lines, offset/limit, outline for long files | 🟡 Inherited; offset/limit visible only as Read arguments | ✅ Read returns ≤25K tokens with a `PARTIAL view` notice and offset/limit paging; refuses >256 KB unless `allow_large` (v2.1.296) | ✅ read: 1-indexed offset/limit, 2000-line default, 50 KB cap with continuation footer, lines cut at 2000 chars | ✅ read: 1-indexed offset/limit, 2000-line default, 50 KB cap with continuation footer, lines cut at 2000 chars | ✅ `read` offset/limit + `cursor` within long lines + continuation notices; sub-agents told never to cat whole files |
| CTX-09 | Agent-controlled self-pruning (DCP-style tools) | ✅ `Prune` (with distillation), `Compress` (ranges, manual by default), `Recall` (restore verbatim) tools for the model | 🟡 Prune/Compress tool calls render as ordinary cards | ❌ No model-callable pruning/discard tool (DCP-style); context management is harness-side only | 🔌 Only via 🔌 opencode-dynamic-context-pruning (compress tool, dedup/purge strategies) — installed here | ❌ Δ DCP plugin targets the V1 plugin API (@opencode-ai/plugin ≥1.18.29) and does not run on v2 | ❌ No agent-controlled (DCP-style) pruning tools |
| CTX-10 | Rewind / drop messages from context | ✅ `/rewind`, `/undo`, `/redo`, `/decompress` / `/recompress`; rewind is a prompt refresh point | 🟡 `/rewind`, `/undo`, `/redo` run server-side and the web re-snapshots; no per-message rewind affordance | ✅ `/rewind`: restore conversation and/or code; "Summarize from here" / "Summarize up to here" (partial compaction with focus text) | ✅ /undo, /timeline revert-to-message, /fork from any message; reverted messages soft-hidden then deleted | ✅ Δ /undo stages a rollback (prompt back in composer) committed on next submit; /redo cancels; Revert on any message | ✅ Control UI "Rewind to here" repoints the session before a message (context only); append-only store keeps old path |
| CTX-11 | Side questions without polluting context (/btw-style) | ✅ `/btw <q>`: title model answers from a transcript digest, beside a running turn, shown as a UI-only row, never in history | 🟡 `/btw` is a server-run built-in; whether it answers mid-turn via `command.exec` was not verified (built-ins refused mid-turn) | ✅ `/btw` side question: no tools, not added to history, replays last 20 side Q&As; `f` forks it into a background subagent | ❌ No /btw side-question mode (subtask commands are the closest) | ✅ Δ `/btw <question>`: one-shot answer from session context in a dialog, not added to the conversation | ✅ `/btw` (`/side`) side question on a session snapshot, never written to history; Control UI Side chat panel |
| CTX-12 | Context warnings near limit | ✅ Reminder-tier notice, model <context-reminder> past 60k/120k, ctx% to model, sub-agent wrap-up at 80% | ✅ Context-usage reminder rows plus the % meter; spend-cap breach shows a warning notice | ✅ Context-low warning ("Context left until auto-compact") in the notification row; `/context` over-limit warning | 🟡 Context % visible in sidebar but no explicit near-limit warning | 🟡 Context % visible in sidebar but no explicit near-limit warning | ✅ Control UI context-meter warning; optional compaction start/finish notices (`notifyUser`, compaction-notifier hook) |
| CTX-13 | Overflow recovery (prompt-too-long → compact & retry) | ✅ Prompt-too-long: full-strength prune + step summary, resend once; length-stop continued x3; dropped streams resumed | 🟡 Server truncation/compaction notices appear as rows; repro showed oversized prompts persisted as EMPTY rows (ISSUES) | ✅ Prompt-too-long → compact and retry; max_tokens shrink retries; thrash detection stops auto-compaction loops with an error | ✅ Provider context-overflow error triggers overflow compaction and replays the last user msg with media stripped | ✅ Δ One overflow-triggered compaction + rebuild of the same step before durable output; second overflow is terminal | ✅ Recognizes dozens of provider overflow errors → compact and retry inside the same run from settled tool results |
| CTX-N1 | 🆕 Ahead-of-need compaction (summaries prepared in the background before the limit is hit) | ✅ Summaries pre-fetched at reminder tier via the conversation cached prefix | ❌ Served sessions compact with the synchronous heuristic route; ahead-of-need model summaries exist only in the TUI's Chat | 🟡 No background-prepared summaries before the limit; idle compaction compacts 200K+ chats left idle ~54 min before cache expiry | ❌ Compaction runs synchronously at the threshold; no background pre-summarisation | ❌ No background pre-summarisation; compaction runs at the next safe point when over budget | 🟡 Optional maintenance (memory flush + compaction) runs after reply delivery so the next turn doesn't wait; no pre-built summaries |
| CTX-N2 | 🆕 Idle compaction / resume-from-summary (compacts idle long chats before cache expiry) | 🟡 Idle tier only holds the next prompt and advises /compact after 1 h idle past the whole window; no pre-expiry idle compaction | 🧪 No idle tier for served sessions: Host\ContextMeter::shouldPromptIdleCompaction() exists (tested) but has no caller | ✅ compacts idle 200K+ chats before cache expiry; offers summary on stale resume | ❌ No idle compaction or resume-from-summary | ❌ No idle compaction; `warming` keeps caches alive instead | ❌ No idle-time compaction or resume-from-summary before cache expiry |
| CTX-N3 | 🆕 Max-steps wrap-up (on the last allowed step tools are disabled and the agent must summarise) | ✅ At the step ceiling (maxToolSteps 1000) tools are disabled and one no-tools request asks for done/remaining/next; notice added | ✅ Inherited no-tools wrap-up at the step ceiling; `stopReason: max_steps` shows "Turn stopped: it reached its step limit." | ❌ No forced wrap-up step: subagent `maxTurns` returns partial output (resumable); `-p --max-turns` exits with an error | ✅ on the last allowed step tools are disabled and a summary-of-progress message is forced | ✅ Δ `steps` still forces a text summary; new user input resets the allowance | 🟡 No step cap; a tool-free finalization pass fires when a required-reply turn ends after tools without an answer |
| CTX-N4 | 🆕 Output-limit auto-continuation (a reply cut off at max output tokens is continued automatically) | ✅ A reply cut at the output-token limit with no tool call is continued up to 3 times (prefill where supported), then a notice | ✅ Inherited: served turns continue a length-stopped reply up to 3 times; if still cut the web says "the reply hit the output limit" | 🟡 Cut-off subagents are auto-prompted to continue; in the main chat an output-limit cut shows an error and you type `continue` | ❌ Replies cut at max output tokens are not auto-continued in 1.18.35 | ✅ Δ responses that hit max output tokens continue automatically (bounded) | 🟡 Auto-continues only when an OpenAI Responses reply hits the output limit while generating a tool call |
| **SES** | **Sessions** |  |  |  |  |  |  |
| SES-01 | Session persistence on disk (format / location) | ✅ SQLite WAL ~/.sugar-crush/session.db (0600), interned transcripts + checkpoints, saved within 0.5 s; -p not saved | ✅ Sessions persist in the server's store; the web lists them (paged `session.list`) and opens them from a snapshot | ✅ JSONL transcripts at `~/.claude/projects/<project>/<session-id>.jsonl` (internal format), saved continuously | ✅ SQLite at ~/.local/share/opencode/opencode.db (Drizzle); `opencode db` sqlite shell; 11.6 GB observed here | ✅ Δ SQLite via shared background service; v2 imports V1 sessions (v1-migration) into the same opencode.db | ✅ Gateway-owned sessions in per-agent SQLite (`agents/<id>/agent/openclaw-agent.sqlite`), append-only; archives on disk |
| SES-02 | Continue most recent (--continue / -c) | 🟡 `-c/--continue` reopens the most recent main/branch session — globally, not per project; cannot combine with `-p` | 🟡 Remembers open tabs, last session, drafts and layout per browser (localStorage); dashboard "Recent" lists 8 | ✅ `claude -c/--continue` resumes the most recent session in this directory (skips -p, SDK and /loop sessions) | ✅ `-c/--continue` (TUI, run, attach) continues last session; `--fork` to branch instead | ✅ `-c/--continue` (TUI, run, attach) continues last session; `--fork` to branch instead | ✅ Continuity is the default: DMs share the rolling main session; gateway TUI resumes last session; `openclaw resume` |
| SES-03 | Resume picker (--resume, /resume, session list) | ✅ --resume [id/prefix/name] or picker, /sessions, Ctrl+R; session list/show/... subcommands with JSON | ✅ Sidebar lists every session newest-first with status dot, open-question badge and age; palette jumps to any | ✅ `--resume [id/name/search/path]` and `/resume` picker with preview, search, all-projects, worktree and branch filters | ✅ /sessions (/resume,/continue, <leader>l) searchable picker with pins, rename, delete; `-s <id>`; `session list` | ✅ Δ /sessions + ctrl+o recent sessions & projects; `session list/delete/export/import` | ✅ TUI Ctrl+P session picker, `openclaw resume [query]`, Control UI sidebar + /sessions page, `openclaw sessions` |
| SES-04 | Auto session titles | ✅ Auto-title after the first exchange by the title backend; a user-set name always wins (title_source user/auto) | ✅ Server-generated titles appear live (sidebar, tabs, palette, notifications) | ✅ Small/fast model writes a title from the first prompt; accepting a plan retitles the session | ✅ Hidden title agent (small model) names the session on the first step; `run --title` | ✅ Δ Title agent (model from small_model/agents.title.model); plugin `title` hook can supply the title | ✅ Utility-model titles from the first non-command message (dashboard sessions, Telegram topics, Discord threads) |
| SES-05 | Rename session | ✅ `/rename <title>`, inline rename row, Ctrl+P "Rename session…", double-click tab, `sugarcrush session rename` | ✅ "rename" link → `window.prompt` → `session.rename` | ✅ `/rename`, `-n/--name`, Ctrl+R in the picker; name shown on the prompt bar and terminal title | ✅ /rename or ctrl+r; PATCH /session/:id | ✅ Δ /rename; agent can rename via Code Mode `opencode.session_rename` | ✅ `/name <title>`; click title in Control UI; `sessions` tool can set label/icon |
| SES-06 | Fork / branch a session | ✅ /branch forks the session; /fork clones into a background session; /handoff starts a summary-seeded branch | 🟡 `/fork` and `/branch` run server-side via `command.exec`; the `session.fork` RPC is unused; no Fork button | ✅ `/branch` (switch into a copy), `--fork-session`, `/fork` (copy into a background session), `/subtask` (forked subagent) | ✅ /fork, --fork, fork from timeline message; POST /session/:id/fork | ✅ /fork, --fork, fork from timeline message; POST /session/:id/fork | ✅ Control UI "Fork conversation" / "Fork from here" (`sessions.fork`); `sessions_spawn context:"fork"` |
| SES-07 | Rewind conversation to an earlier message (with/without code restore) | ✅ /rewind --chat/--files/--both, /undo, /redo, /diff over per-turn git snapshots (hidden refs or shadow repo) | 🟡 `/rewind`, `/undo`, `/redo` are server built-ins reachable by typing; no click-to-rewind on a message | ✅ Esc Esc or `/rewind`: restore code+conversation, conversation or code; restored prompt returns to the input | ✅ /undo reverts last turn's files (snapshots) and refills its prompt; /redo; timeline revert; fork = no code restore | ✅ Δ Staged rollback: /undo restores files + refills prompt; commits on next submit; /redo cancels | ✅ Rewind to an earlier user message (chat context only, no code restore) |
| SES-08 | Export transcript (markdown / json / html) | ✅ /share md/html/json/txt writes a 0600 export locally; `session show --output-format json` dumps transcripts | 🧪 `session.export` (markdown/json/text) exists with generated TS types but no web control; `/share` writes a file on the SERVER | 🟡 `/export` writes plain text (clipboard or file) only; JSON via `-p --output-format json`; raw JSONL on disk | ✅ /export Markdown to $EDITOR, /copy transcript, `opencode export` JSON (--sanitize redacts) | ✅ Δ /export with options (thinking, tools, sanitize, copy/export); `session export` JSON | ✅ `/export-session` HTML, Control UI Markdown download/copy, `/export-trajectory` JSONL, `openclaw transcripts` |
| SES-09 | Share link (public URL) | ❌ No share links: `ShareUploader::upload()` is a stub that always throws; `/share` only exports locally | ❌ No public share URL; `/share` exports to a server-local file | 🟡 Share links only for cloud sessions (Private/Team, or Public on Pro/Max); local CLI transcripts can't be shared | ✅ /share → public opncd.ai/s/<id> (synced live); share manual/auto/disabled; /unshare deletes data | ❌ Δ Share removed: 2.0.30 deleted the retired sharing backend and /share | ✅ Public read-only share links from Control UI (revocable); team sharing; Session Share / Beam plugins |
| SES-10 | Import session | ❌ No session import (no inverse of the JSON/markdown export, no foreign-transcript import) | ❌ No session import | 🟡 Resume any transcript by absolute path (`--resume /x.jsonl`); `/teleport` pulls cloud sessions; no import from other tools | ✅ `opencode import <file.json/share URL>` | ✅ Δ `opencode session import <file or URL>`; plus automatic V1 session import | ✅ Session catalogs discover Claude Code, Codex, OpenCode, Pi sessions; Claude CLI rows can be adopted (history imported) |
| SES-11 | Search across past sessions | 🟡 /sessions <query>: LIKE prefilter on name/last prompt/id + fuzzy ranking; no full-text transcript search | 🟡 Sidebar filter is client-side over name/preview/id only; no transcript-content search | 🟡 Picker search over names/titles/summaries/first prompts and PR URLs, Ctrl+A across all projects; no full-text search | 🟡 Session search matches titles only (SQL LIKE on title); no full-text transcript search | 🟡 Session search matches titles only (SQL LIKE on title); no full-text transcript search | ✅ `sessions_search` tool; Control UI ⌘K thread search + "Search transcripts"; ⌘F in-transcript search |
| SES-12 | Multiple concurrent sessions in one UI (tabs) | 🟡 Tab strip + Ctrl+Tab cycle recent sessions, one live session per window (refused mid-turn); /bg for concurrency | ✅ Tabs (Alt+1…9, max 12) and a live 3×3 grid; the focused tile streams, the others are narrated by the server every 2 s | ✅ Agent view (`claude agents`, research preview) lists/attaches many sessions; `←` switches; Desktop and VS Code have session tabs | 🟡 TUI shows one session at a time but others keep running server-side; web/desktop have multi-project tabs | ✅ Δ Session tabs in the TUI (ctrl+tab, vertical/horizontal, unread status) and desktop (Option+↑/↓) | ✅ Control UI split panes (each its own session/composer), tabs, live narration of background sessions |
| SES-13 | Background / detached sessions | ✅ /bg and /fork run background daemons that outlive the TUI and are re-adopted by the next launch | ✅ Turns keep running server-side when the browser closes; Background panel lists, starts, stops and injects `/bg` sessions | ✅ `--bg`, `/background`, supervisor process keeps sessions alive; `attach`, `logs`, `stop`, `rm`, `respawn` | ✅ Sessions are owned by the server: `opencode serve` + attach/run --attach/prompt_async run detached from any client | ✅ Δ Shared per-user background service owns all sessions; write-ahead claims resume sessions after crash | ✅ Sessions run in the always-on Gateway independent of clients; "Start in background"; device/cloud placement |
| SES-14 | Session handoff across devices (web ↔ CLI, teleport) | 🟡 serve + web UI + `attach [--url]` let several devices drive server sessions; no push of a local session | 🟡 Same session from a terminal via `sugarcrush attach`; a plain `--resume` opens read-only while served; no teleport UI | ✅ `--cloud` / `--teleport` (CLI↔web), `/desktop`, Remote Control, Dispatch from phone | 🟡 attach to remote server, share-URL import, TUI+web on one server; no one-click web↔CLI teleport | ✅ Δ `opencode pair` one-time links/QR for browser or iOS app; `pair --remote` via OpenTunnel E2E | ✅ Same session from Control UI, TUI, apps and channels; `openclaw resume --handoff`; Move session; `openclaw attach` |
| SES-15 | Per-session cost/usage history | 🟡 Spend/tokens tracked per launch (status bar, /budget), not persisted or shown per session | 🟡 Per-session spend in status bar, tiles and summaries; no per-turn/historical usage view | ✅ `/usage` (alias `/cost`, `/stats`) shows session cost, plan limits and activity; status-line cost fields | ✅ Per-session cost/tokens in sidebar; `opencode stats` (days, models, tools, project) | ✅ Δ Session + subagent family cost/tokens; `stats` with --cost/--tools/--models/--json | ✅ `/usage cost` (session/today/30 d), `/status` cost (API-key models), Control UI Usage page |
| SES-16 | Session locking (two clients on one session) | ✅ flock() session lock: second window opens read-only and takes over when the holder exits | ✅ Server holds the single-writer lock; up to 50 clients follow one session; a TUI on the same session is refused/read-only | ✅ One writer per transcript: a second process is refused ("already open in another running Claude session") | 🟡 No explicit lock; many clients attach to one session via the server, which serializes runs (busy → queued) | 🟡 Δ Process-local execution ownership with write-ahead claims; clients share the service; no cross-process lock | ✅ Concurrent clients on one session with live sync and presence; Gateway serializes runs per session lane |
| SES-17 | Session retention / cleanup | 🟡 Opt-in retention (default off) + empty-session sweep, but auto-titled sessions are exempt, so little is pruned | 🧪 `sessions.remove()` → `session.delete` exists in the store but no component calls it; no retention UI | ✅ `cleanupPeriodDays` (30 default) sweep at startup; `claude purge [path/--all]`; memory files exempt | 🟡 Session archive + `session delete`; no auto-retention (snapshots gc 7 d, tool-output files swept after 7 d) | 🟡 Δ Projects auto-archive when their directories vanish; sessions never auto-pruned; uninstall keeps session data | ✅ `session.maintenance`: archive after 30 d, dashboards 7 d, 5,000-row cap, disk budget; `sessions cleanup` |
| SES-N1 | 🆕 Incognito / ephemeral sessions (not persisted, no memory flush) | ❌ No incognito/ephemeral session: every TUI, -p and background session is saved to SQLite; auto-memory can only be disabled globally | ❌ No incognito: every web session is saved in the server's session store and listed to every authenticated client | 🟡 No single incognito toggle; `--no-session-persistence` (print mode only), `CLAUDE_CODE_SKIP_PROMPT_HISTORY` suppresses transcripts | 🟡 No per-session incognito; `OPENCODE_DB=:memory:` makes the whole process ephemeral (snapshots/logs still written) | 🟡 No per-session incognito; OPENCODE_DB=:memory: makes the server's store ephemeral | ✅ Control UI threads kept only in memory, 24 h expiry, no flush, excluded from logs |
| **AGT** | **Agents & sub-agents** |  |  |  |  |  |  |
| AGT-01 | Primary agent modes (build / plan / ask / ...) | 🟡 Single primary agent; its "modes" are the 6 permission modes (plan mode exits via PlanExit); no build/plan agent pair | 🟡 Permission modes (default, accept-edits, plan, auto, dont-ask, bypass) in the status bar; no primary-agent switch | ✅ Permission modes Manual, acceptEdits, plan, auto, dontAsk, bypassPermissions; plan mode is read-only planning; `opusplan` | ✅ Primary: build (default), plan (edit-denied except plan files); hidden compaction/title/summary; experimental plan mode | ✅ Δ build (default), plan (edits only in ~/.opencode/plan, plan reminders); hidden compaction/title/summary | 🟡 No build/plan/ask agent modes; behaviour set by permission modes, thinking level (incl. ultra), `/goal`, chosen agent |
| AGT-02 | Switch primary agent in UI (Tab, /agent) | 🟡 Alt+M toggles plan mode, /permissions mode <name> switches; no persona switch; palette "Switch agent" only runs /agents | ❌ No agent switcher; `/agents` (server-run) prints the roster as text | 🟡 Shift+Tab cycles permission modes; a main-thread agent persona only via `--agent` / `agent` setting (no in-session switch) | ✅ Tab / Shift+Tab cycle primary agents, <leader>a or /agents picker, `--agent`, `default_agent` | ✅ Δ Shift+Tab cycles agents (Tab no longer), <leader>a / /agents; a command `agent:` switches the session agent | ✅ TUI Ctrl+G agent picker and `/agent <id>`; Control UI agent switcher; routing bindings per channel |
| AGT-03 | Built-in sub-agent types (explore, general, plan, ...) | ✅ Six built-ins: coder, reviewer, debugger, architect, tester, devops; no explore/general/plan; their tool grants have gaps | 🟡 Built-ins (coder, reviewer, debugger, architect, tester, devops) run server-side; the web shows runs, not the catalog | ✅ Explore, Plan, general-purpose, `claude` catch-all, statusline-setup, claude-code-guide, plus `fork` | ✅ Built-in subagents: general (full tools minus todo) and explore (read-only); 'scout' documented but absent in 1.18.35 | ✅ Δ general (no subagents, no question) and explore (read/search/shell/web, external allowed); docs: no scout | 🟡 No typed sub-agent catalog (explore/plan); generic `sessions_spawn` children; role-template agents; internal helper sub-agents |
| AGT-04 | Custom agent definitions (file format + locations) | ✅ Markdown + YAML presets in <root>/.sugar-crush/agents and ~/.sugar-crush/agents; project tier beats user + built-ins | 🟡 Custom agent files apply server-side; not listed or editable in the web | ✅ Markdown + YAML in `.claude/agents/` (walk-up), `~/.claude/agents/`, managed dir, `--agents` JSON/file, plugin `agents/` | ✅ Markdown w/ YAML frontmatter in .opencode/agents/, ~/.config/opencode/agents/ (or agent/), or JSON `agent` config | ✅ Δ .opencode/agents (also agent/, mode/, modes/), global dir, JSON `agents`; nested paths give IDs like team/reviewer | ✅ Agents = `agents.entries.<id>` in openclaw.json + own workspace files + agentDir; role templates (CLAW.md) |
| AGT-05 | Agent definition fields (model, tools, permissions, prompt, color, memory, ...) | ✅ 15 fields: arg-scoped tools/disallowedTools, model, effort, narrow-only permissionMode, maxTurns, skills, isolation… | 🟡 Inherited; fields not shown | ✅ name, description, tools, disallowedTools, model, permissionMode, maxTurns, skills, mcpServers, hooks, memory, effort, isolation | ✅ description, mode, model, variant, prompt, temperature, top_p, steps, permission, color, hidden, disable, provider options | 🟡 Δ description, mode, model#variant, system, permissions[], steps, hidden, color, disabled, request — request NOT sent yet | ✅ Workspace, cwd, model/fallbacks/utility, thinking, skills, tools, sandbox, subagents, identity, bootstrap/context limits |
| AGT-06 | Agents list UI (/agents) — HOW it is displayed | 🟡 /agents lists only WORKING runs + an idle count; roster never listed; /agent <name> shows one; Agents pane = runs | 🟡 Agents panel = live tree of THIS session's delegated runs (status, step, tokens, cost); `agents.list` catalog unused | 🟡 `/agents` only prints a reminder (Running/Library UI removed after v2.1.197); `/list-agents` lists messageable agents | 🟡 /agents picker lists switchable primary agents (name + description/'native'); subagents only in @ menu; `agent list` | 🟡 Δ /agents picker; `opencode debug agents` lists all; subagents via @ and composer subagent picker | ✅ Control UI Agents home (roster, status, previews, Open chat), TUI picker, `openclaw agents list --bindings --tree` |
| AGT-07 | Agent creation wizard / AI-generated agent | ❌ No agent-creation wizard and no AI-generated agent | ❌ No agent-creation wizard | ✅ Ask Claude to write the agent file (AI-generated definition); the interactive wizard was removed in v2.1.198 | ✅ `opencode agent create`: wizard asks scope + description, LLM generates name/prompt, pick allowed tools, writes .md | ❌ Δ `opencode agent create` wizard removed (no `agent` subcommand in v2 CLI) | ✅ Control UI "New agent" custodian chat recommends roles/teams (operator approval); `agents add` wizard; `agents team create` |
| AGT-08 | Sub-agent spawn tool (Task / Agent) | ✅ Task tool (description, prompt, agent, model, resume, background); the model sees roster NAMES only, no descriptions | ✅ Task calls render as cards with the delegated-run tree hanging under them | ✅ `Agent` tool with `subagent_type`, `name`, `model`, `effort`, `isolation`, fork mode | ✅ task tool (description, prompt, subagent_type, task_id, command) runs a child session | ✅ Δ `subagent` tool (agent, description, prompt, optional model, sessionID resume, background) | ✅ `sessions_spawn` (task, agentId, model, thinking, context isolated/fork, cwd, worktree, runtime subagent/acp, timeout) |
| AGT-09 | Parallel sub-agents | ✅ Several Task calls in one message fork in parallel (pcntl); optional caps subagentMaxConcurrent/subagentMaxActive | ✅ Parallel runs appear side by side in the tree, each updating live | ✅ Parallel subagents; 20 concurrent by default (`CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS`), no session-total cap | ✅ Multiple task calls in one turn run concurrently (general agent advertised for parallel units of work) | ✅ Multiple task calls in one turn run concurrently (general agent advertised for parallel units of work) | ✅ Parallel: `maxConcurrent` 8 child runs per session, `maxChildrenPerAgent` 5 active |
| AGT-10 | Sub-agent isolated context window | ✅ Own transcript and context per run; told to wrap up at 80%, stopped at 90% with partial output + resume id | 🟡 Isolation is server-side; the agent view shows the run's own transcript | ✅ Fresh isolated context per subagent; forks inherit the full conversation and reuse the parent's cache | ✅ Each subagent is a child session with its own context, system prompt, compaction, snapshots | ✅ Each subagent is a child session with its own context, system prompt, compaction, snapshots | ✅ Each child is a real session with own transcript; isolated by default, fork optional; minimal prompt |
| AGT-11 | Sub-agent results returned (summary only? transcript?) | ✅ Final report fenced as untrusted "[subagent output — no user authority]" + resume id; transcript kept as child session | ✅ Agent view pages the run's own transcript (`agents.transcript`, polled every 1.5 s while running) plus the Task card result | ✅ Only the final text + a metadata trailer returns; output scanned/escaped and labelled as subagent words with no user authority | ✅ Only the child's final text returns, wrapped <task id state><task_result>; id enables resume | ✅ Δ Foreground returns final response + sessionID; background notifies parent on completion | ✅ Complete final answer announced with runtime-derived status + stats line; full transcript via `sessions_history` |
| AGT-12 | Sub-agent progress visible live in UI | ✅ Live per-Task line (current tool, tool count, time, tokens, $), an agents strip above the input, and an Agents dashboard pane | ✅ `subagent.started/progress/finished` drive a live tree with step, tokens, cost and recent tool calls | ✅ Panel below the prompt: nesting tree with `(+N)` counts; Enter opens a live transcript; `/tasks`; `subagentStatusLine` | ✅ Task row shows subagent type, live '↳ <Tool> <title>' of child, tool count, duration; child footer 'Subagent N of M' | ✅ Δ Live child rows + composer subagent picker (↑/↓, Enter navigate, ctrl+d interrupt subagent) | ✅ Control UI shows child transcripts live (view-only), unread child failures, running-tasks indicator; `/subagents log` |
| AGT-13 | Background sub-agents | ✅ Task background:true (or preset) runs as a detached daemon session; result arrives as a new message; asks refused | ✅ Agent view "Background" promotes a running top-level run to a `/bg` session (`agents.control background`) | ✅ Background by default in interactive sessions (fork mode on); results arrive later as completion notifications | 🟡 Experimental (OPENCODE_EXPERIMENTAL_BACKGROUND_SUBAGENTS): background=true, ctrl+b promotes, results auto-injected | ✅ Δ Background subagents are GA: `background: true`, and `subagent: true` commands always run in background | ✅ Non-blocking push completion; parent keeps working; completions arrive at the steering boundary |
| AGT-14 | Resume / continue a sub-agent | ✅ Every run is resumable for 7 days by resume id (completed, step-capped, failed or cancelled), in the same worktree | ✅ Messaging a finished run continues it from its `resumeId` (same preset) | ✅ Resume a finished subagent with full history via `SendMessage` to its ID/name (not Explore/Plan) | ✅ task_id resumes the same child session with prior messages/tool outputs (works after success or failure) | ✅ Δ Pass the returned sessionID to continue the same child conversation | ✅ Resume a paused child via `sessions_send` (resume mode); `followup` turns; ACP `resumeSessionId` |
| AGT-15 | Parent↔child messaging while running (SendMessage, mailboxes) | ✅ SendMessage, Subagents (list/wait/cancel), InterruptAgent; child replies to parent; user can type to a child in Agent View | ✅ Agent-view box → `agents.message` into the run's signed mailbox (read at its next step); pause / resume / cancel | ✅ `SendMessage` between parent, children, teammates and other sessions; user can type into an open subagent transcript | 🟡 Parent can push more context into a running background child via task_id (experimental); no mailbox/SendMessage | 🟡 Δ Continue a child by sessionID; composer picker can interrupt a running subagent; no mailbox/SendMessage | ✅ `sessions_send` steer / followup / notify / request-response into running children; hub-and-spoke |
| AGT-16 | Agent teams / swarms / coordinators | ✅ Team tool: SQLite task board (claim/complete/deps, CAS) + mailbox in ~/.sugar-crush/teams; Task*/TeammateIdle hooks | 🟡 Team tool runs server-side; members show only as generic sub-agent tree nodes | ✅ Agent teams (experimental, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`) and dynamic workflows (JS orchestration of many subagents) | 🔌 No native teams/swarms; 🔌 oh-my-openagent, opencode-workspace, CrewBee, subtask2 orchestration | ❓ Δ No native teams; V1 swarm plugins (oh-my-openagent pins @opencode-ai/plugin 1.18.31) don't run; v2 options unverified | ✅ Team preset (coordinator + researcher/reviewer/writer), Swarm (experimental, Code Mode), `/think ultra` orchestration |
| AGT-17 | Nested sub-agents (sub-agent spawns sub-agent) | ✅ Nesting to subagentMaxDepth (3); Task withheld at the last level; a preset with a tools: list must name Task to nest | ✅ Nested runs hang under the run that delegated them (`parentAgentId`) | ✅ Nested subagents up to 3 layers below main (`CLAUDE_CODE_MAX_SUBAGENT_SPAWN_DEPTH`) | ✅ Nested subagents via `subagent_depth` (default 1 = none; children get task denied unless granted) | ✅ Δ Nesting via `experimental.subagent_depth` (default 1); top-level `subagent_depth` ignored with a warning | ✅ Nested spawning to depth 5 by default (orchestrators vs leaves); cascade stop |
| AGT-18 | Worktree isolation for agents | ✅ isolation: worktree gives each Task run (and /bg, /fork) its own git worktree; tools jailed; a dirty tree is kept | 🟡 Inherited (worktree isolation server-side); not shown | ✅ `isolation: worktree` (frontmatter or per call) with enforced cwd and git-redirect checks; `.worktreeinclude` | 🟡 Experimental workspaces/worktree API (OPENCODE_EXPERIMENTAL_WORKSPACES); no auto per-agent worktree (🔌 opencode-worktree) | ✅ Δ Built-in worktree_create/list/remove + session_move tools; /worktrees and /cd; desktop worktrees | ✅ `sessions_spawn visible:true worktree:true` managed git worktrees (snapshot before removal, restorable) |
| AGT-19 | Import foreign agent definitions (e.g. .claude/agents read by another tool) | 🟡 Imports .claude/agents + .opencode/agents; Claude importer mis-parses comma skills, mcpServers maps; errors to error_log | 🟡 Inherited (foreign agent dirs read by the engine); not shown | 🟡 One-time `/import` of Codex/Gemini/Cursor subagents; other tools' agent directories aren't read live | ❌ .claude/agents (and .claude/commands) are not read; only CLAUDE.md and .claude/skills are compatible | ❌ .claude/agents (and .claude/commands) are not read; only CLAUDE.md and .claude/skills are compatible | 🟡 Claude bundle `agents/` folders load as skill roots (instructions, not agent defs); `.claude/agents` archive-only in migrate |
| AGT-20 | @-mention an agent to invoke it | ❌ No @agent mention. `@` means files, @diff, @session: or @url, and `$name` is for skills | ❌ No @agent mention; nothing is @-autocompleted | ✅ `@"name (agent)"` from the typeahead or `@agent-<name>` guarantees that subagent runs | ✅ @agent in prompt adds synthetic 'call the task tool with subagent X' (bypasses task deny; hidden agents excluded) | ✅ Δ @agent mention adds an agent mention to the prompt (does not switch the active agent) | ❌ No @agent invocation (@ mentions people in Control UI, files in TUI) |
| AGT-21 | Navigate into a child agent's session view | ✅ Agent View: click, Enter or /agents <run> opens the child's live transcript; Alt+N/P siblings; Ctrl+X c/p/s/o/b controls | ✅ Clicking a tree row opens that run's agent view in the side panel | ✅ Open a subagent/fork transcript from the panel or `/tasks` and chat with it directly | ✅ <leader>↓ enters first child, ←/→ cycle siblings, ↑ to parent; clicking a Task row opens the child (read-only view) | ✅ Δ Down opens the subagent picker in the composer; Enter navigates into the child session | ✅ Open a sub-agent run transcript from the parent (view-only, "Open parent session"); visible child sessions nested in sidebar |
| AGT-22 | Multi-agent routing (different agents per channel / peer / workspace) | ➖ No channel or peer concept. serve workspace hosts route by project root, not by agent | ➖ Single-owner server; no channel/peer routing concept | ❌ No per-channel or per-peer agent routing in the CLI | ➖ No channel/peer routing concept; per-project `default_agent` and per-command `agent` only | ➖ No channel/peer routing concept; per-project `default_agent` and per-command `agent` only | ✅ Bindings route channel / account / peer / group to isolated agents (own workspace, auth, sessions) |
| AGT-N1 | 🆕 Shared board / blackboard for a parallel batch of sub-agents (post/read results, asks, vetoes) | ✅ BoardPost/BoardRead (INFO/ASK/RESULT/HOLD/VETO) between members of one Task batch | 🟡 Inherited board for parallel Task batches; no board view — posts show only as BoardPost/BoardRead calls in each agent view | 🟡 Agent teams (experimental flag) share a locked task list with dependencies plus mailboxes; plain subagent batches share nothing | ❌ No shared board for parallel subagents | ❌ No shared board for parallel subagents | 🔌 Shared coordination board via the bundled-but-disabled `workboard` plugin (cards, claims, comments, proof, parent results) |
| AGT-N2 | 🆕 Live run control of sub-agents (cancel / pause / resume / send to background from the UI) | ✅ cancel, pause, resume or background a running sub-agent from the TUI (Ctrl+X) or serve | ✅ Agent view: Pause / Resume / Cancel / Background buttons and a message box; Continue for a finished run (`agents.control`) | ✅ Panel `x` stop, Ctrl+B to background, Ctrl+X Ctrl+K stop all, `/tasks` stop, message/resume any subagent; workflows pause/resume | 🟡 Esc cancels the turn and cascades to child jobs; ctrl+b backgrounds running subagents (experimental); no pause | 🟡 Composer subagent picker: navigate into or interrupt (ctrl+d) a running child; background launch; no pause | 🟡 Stop/cancel from the UI and `subagents cancel`; logs and status live; no pause/resume or send-to-background for children |
| AGT-N3 | 🆕 Cross-session messaging between independent top-level sessions (local or remote) | 🟡 No peer messaging between independent sessions; /bg and /fork daemons post their result back into the launching session | 🟡 Background panel "Send to this session" (`bg.inject`) puts a settled /bg answer into the open session; no agent-to-agent | ✅ `ListAgents`/`SendMessage` across local, other-machine and cloud sessions; notify-when-idle | ❌ No agent-facing messaging between independent top-level sessions | ❌ No agent-facing cross-session messaging (Code Mode can rename/move sessions only) | ✅ `sessions_send` between any visible sessions (steer/followup/notify/request-reply); A2A channel for external agents |
| AGT-N4 | 🆕 Thread-bound sub-agent sessions (child gets its own chat thread / channel binding) | 🟡 Each sub-agent run gets its own Agent View transcript + composer routed to its mailbox, kept as a child session; no chat threads | 🟡 Each run opens in an agent view (own transcript) with a message box routed to its mailbox; finished runs continue on message | 🟡 Teammates can run in their own tmux/iTerm panes and be messaged directly; no chat-thread or channel binding per sub-agent | 🟡 Each subagent runs in its own child session (separate navigable thread); no chat-channel binding | 🟡 Each subagent is its own child session (navigable, tabs); no chat-channel binding | ✅ `sessions_spawn thread:true` gives a child its own Discord/Matrix thread |
| **SKL** | **Skills** |  |  |  |  |  |  |
| SKL-01 | SKILL.md format support (frontmatter name/description) | ✅ SKILL.md YAML frontmatter; name = directory (key = path); description, invocation flags, paths, requires/os | 🟡 Inherited; skills are invisible in the web | ✅ `SKILL.md` per the Agent Skills open standard (agentskills.io) plus CC-only frontmatter extensions | ✅ SKILL.md with name (dir-matching, kebab) + description; license/compatibility/metadata accepted, others ignored | ✅ Δ SKILL.md or flat <id>.md; ID from path; name optional display; no name/length validation enforced | ✅ AgentSkills-spec SKILL.md (name/description) plus user-invocable, disable-model-invocation, command-dispatch, metadata.openclaw |
| SKL-02 | Discovery locations (user / project / plugin / bundled) | ✅ built-in (src/Skills/BuiltIn) < project .sugar-crush/skills < user ~/.sugar-crush/skills. No plugin directories | 🟡 Inherited; not listed | ✅ Enterprise, personal, project (walk-up), nested (lazy), --add-dir, plugin, claude.ai-synced and bundled skills | ✅ .opencode/skills (walk-up), ~/.config/opencode/skills, .claude/.agents skills, `skills.paths`, `skills.urls`, 1 built-in | ✅ Δ .opencode/skills, global, .claude/.agents skills, plus `skills` array of paths and HTTP catalogs; 2 built-ins | ✅ Precedence: workspace/skills > workspace/.agents/skills > ~/.agents/skills > managed > workshop > bundled > extraDirs/plugins |
| SKL-03 | Cross-tool locations read (.claude/skills, .agents/skills, .opencode/skills, ...) | ✅ Imports .agents/.claude/.opencode skills (project + user) and ~/.config/opencode/skills, badged by origin | 🟡 Inherited; not listed | 🟡 Reads only `.claude/skills/` and legacy `.claude/commands/`; not `.agents/skills` or `.opencode/skills` | ✅ Reads .claude/skills and .agents/skills (project walk-up to git root + home); OPENCODE_DISABLE_CLAUDE_CODE_SKILLS | ✅ Δ .claude/skills and .agents/skills (global + ancestor walk); CLAUDE env flags honored since 2.0.30 | 🟡 Reads `.agents/skills` (project + ~/.agents) natively; not .claude/skills, .opencode/skills or $CODEX_HOME/skills |
| SKL-04 | Progressive disclosure (listing in prompt, body loaded on use) | ✅ One-line <available-skills> listing in the system prompt each turn; body via Skill tool; asset loader dormant | 🟡 Inherited; `/context` shows only the available-skills section size | ✅ Names/descriptions listed every turn; full body injected only on invocation and kept in history | ✅ Names+descriptions in system prompt and skill tool desc; body (plus ≤10 sampled file paths) loaded on skill call | ✅ Names+descriptions in system prompt and skill tool desc; body (plus ≤10 sampled file paths) loaded on skill call | ✅ Compact list (name, description, location) in the system prompt; body read with `read` when needed |
| SKL-05 | Model auto-invocation (Skill tool) | ✅ Skill tool (name, args → $ARGUMENTS) + "MUST load matching skill" mandate; disable-model-invocation re-checked | 🟡 Model-invoked Skill calls render as tool cards | ✅ Model invokes skills via the `Skill` tool when the description matches; `Skill(name)` permission rules; `paths` auto-activation | ✅ skill tool; `skill` permission patterns allow/ask/deny per skill (deny hides it from the listing) | ✅ skill tool; `skill` permission patterns allow/ask/deny per skill (deny hides it from the listing) | ✅ Model picks skills itself by reading the listed SKILL.md (no dedicated Skill tool); `$skill` references force reads |
| SKL-06 | User invocation (/skill-name) | 🟡 $name attaches a skill body for one turn (max 3, Tab completes); no /skill-name; Ctrl+S picker has no model effect | ❌ Skills are not in `command.list`; a `/skill-name` typed in the web goes to the model as prose | ✅ `/skill-name args` at message start; up to 6 stacked skills share trailing args; `$ARGUMENTS`, `$N`, named args | ✅ Every skill is also a /slash command (unless a command of same name exists); body sent as prompt | 🟡 Δ User loads a skill with an `@skill-id` mention (autocomplete); skills are no longer slash commands | ✅ `/skill <name> [input]`, direct `/name` commands (native on Discord/Telegram), `$name` inline, tool-dispatch skills |
| SKL-07 | Skills list UI — HOW shown (/skills, palette, panel) | 🟡 Skills pane = names; Ctrl+S picker = name — description; /skills lists only dream-proposed drafts, not installed | ❌ No skills list anywhere; `/skills` (server-run) lists dream-proposed drafts only | ✅ `/skills` menu: filter, `t` sort by token cost, Space cycles visibility (on/name-only/user-only/off) saved to settings.local | ✅ /skills opens searchable dialog (name + description); selecting inserts '/skill '; `opencode debug skill` | ✅ Δ /skills dialog; skill loads render as dedicated timeline rows | ✅ Control UI Plugins → Skills (library editor, ClawHub add, import), `$` picker; `openclaw skills list/info/check`; macOS Skills UI |
| SKL-08 | Bundled / built-in skills | ✅ 8 bundled, all PHP-centric: php/laravel/symfony practices, phpunit-master, testing, api-design, security, composer | 🟡 Inherited; not shown | ✅ 20 bundled skills incl. `/doctor`, `/code-review`, `/batch`, `/debug`, `/loop`, `/simplify`, `/verify`, `/run`, `/claude-api` | 🟡 One bundled skill: customize-opencode (config schemas, overridable by a same-name disk skill) | 🟡 Δ Built-in skills: `OpenCode` (v2 docs index) and `Report` (file an issue); official Figma plugin ships 6 skills | ✅ 49 bundled skills (github, gh-issues, coding-agent, tmux, skill-creator, gog, notion, obsidian, 1password, spike...) + 4 custodian |
| SKL-09 | Skill registry / marketplace (ClawHub, plugin marketplaces, skills.sh) | ❌ No registry, marketplace or install command | ❌ No marketplace/registry UI | ✅ Plugin marketplaces (official catalog: 315 entries locally) and claude.ai skill sync; `/plugin` Discover tab | 🟡 `skills.urls` pulls skills from a remote index.json registry; no marketplace UI (🔌 ocx registry, skills CLI) | 🟡 Δ HTTP catalogs via `skills` URLs (index.json + version-based cache refresh); no marketplace | ✅ ClawHub registry (security scans, trust envelopes) via `openclaw skills search/install/update/verify` and `clawhub` CLI |
| SKL-10 | Skill-bundled scripts / resources | 🟡 Skill result names its base dir so the model can Read/Bash scripts/; dedicated asset loader has no caller | 🟡 Inherited; not shown | ✅ Supporting files and scripts in the skill folder; `${CLAUDE_SKILL_DIR}`, `${CLAUDE_PROJECT_DIR}`, `${CLAUDE_PLUGIN_ROOT/DATA}` | ✅ Skill output gives base dir + sampled file list; skill dirs pre-allowed for external_directory access | ✅ Skill output gives base dir + sampled file list; skill dirs pre-allowed for external_directory access | ✅ Skill folders carry scripts/references/assets; installer specs (brew/node/go/uv/download+sha256) for dependencies |
| SKL-11 | Skill-level tool restrictions (allowed-tools) | ❌ allowed-tools and disallowed-tools are parsed but inert (the launch notice says so) | 🟡 Inherited; not shown | ✅ `allowed-tools` pre-approves tools for the invoking turn; `disallowed-tools` removes tools while the skill is active | ❌ allowed-tools frontmatter ignored; restriction only via agent permissions | ❌ allowed-tools frontmatter ignored; restriction only via agent permissions | ❌ No `allowed-tools` frontmatter; skill visibility is separate from tool policy |
| SKL-12 | Skill gating (requires bins / env / OS) | ✅ requires: bins/anyBins/env + os gating (also metadata.openclaw/nanobot); an unmet skill is not registered | 🟡 Inherited; not shown | 🟡 Gating only by `paths` globs (auto-load on matching files); `compatibility` accepted but not enforced; no bins/env/OS checks | ❌ No requires-bins/env/OS gating (compatibility is free text) | ❌ Δ No requirement gating; only `disable-model-invocation` / `autoinvoke:false` visibility control | ✅ `metadata.openclaw.requires` bins/anyBins/env/config, `os`, `always`, `primaryEnv`; `skills check` explains |
| SKL-13 | Hot reload of skills | 🟡 Roster and descriptions read once at launch (new skill = relaunch); body re-read from disk on each Skill call | ❓ Engine question; the web shows no skill list to refresh | ✅ Live change detection for `~/.claude/skills`, project and `--add-dir` skill dirs; `/reload-skills` for new top-level dirs | ❌ Skills scanned once per instance; new/edited skills need restart (or instance reload via API config change) | ✅ Δ Skill sources are watched; changes reload without restart | ✅ Watcher (250 ms debounce) refreshes the snapshot on SKILL.md changes; picked up next turn in the same session |
| SKL-14 | Skill-creation helper | 🟡 The dream pass may propose up to 3 drafts (opt-in `memory.dreamProposeSkills`); /skills accept or reject them. No authoring wizard | 🟡 `/skills accept` / `reject` manage dream-proposed drafts as typed commands | ✅ Ask Claude; Anthropic `skill-creator` (claude.ai-synced or 🔌 plugin); `claude plugin init`; `/run-skill-generator` | 🟡 No skill wizard; built-in customize-opencode skill guides the model when creating skills/agents/config | 🟡 Δ Built-in `OpenCode` skill indexes v2 docs (incl. skills/plugins) for the model; no wizard | ✅ Bundled skill-creator; Skill Workshop proposals (`/learn`, `skill_workshop` tool); self-learning experience review |
| SKL-15 | Skill run in forked / sub-agent context | 🧪 `context: fork` is parsed, but the fork executor App::dispatchSkill() has no caller, so fork skills run inline like thread skills | 🟡 Inherited; a forked skill shows as sub-agent activity if it delegates | ✅ `context: fork` + `agent:` runs the skill as a subagent (background by default; `background: false` waits) | ❌ Skills always load inline into the current context; no fork/subagent execution option | ❌ Skills always load inline into the current context; no fork/subagent execution option | ❌ Skills run inline in the invoking session; no fork/sub-agent execution flag |
| **CMD** | **Slash commands / custom commands** |  |  |  |  |  |  |
| CMD-01 | Built-in slash commands (count + notable ones) | ✅ 55 built-ins (+5 aliases) from builtin-commands/ spec files, e.g. /compact /rewind /undo /diff /bg /fork /goal /init | 🟡 48 built-ins listed: 30 run server-side via `command.exec`, 18 refused `ui_only` (model, compact, init, help, keys, goal…) | ✅ ~116 built-in commands/bundled skills (119 table rows, 3 marked removed): /context, /compact, /rewind, /btw, /goal, /loop, /diff… | ✅ ~33 built-in slash commands: /init /review /compact /undo /redo /fork /timeline /share /sessions /models /agents /diff... | ✅ Δ ~38 TUI commands (+/btw /cd /open /pair /plugins /reload /settings /stats /terminal /worktrees); /share,/thinking gone | ✅ ~60 core slash commands incl. /new /reset /compact /model /think /queue /steer /btw /goal /loop /context /status /usage /approve |
| CMD-02 | Custom commands (markdown files, locations) | ✅ Markdown files in ~/.sugar-crush/commands and <root>/.sugar-crush/commands; project overrides user + built-ins | ✅ Project/user command files are listed in `/` completion and run server-side exactly as typing `/name args` | ✅ Custom commands merged into skills: `.claude/commands/*.md` (legacy) and `.claude/skills/<name>/SKILL.md` both create `/name` | ✅ Markdown in .opencode/commands/ or ~/.config/opencode/commands/ (command/ legacy), or JSON `command` config | ✅ Δ Markdown in commands/ (nested paths → /team/review) or JSON `commands`; hot-reloaded | 🟡 No markdown custom-command files; user-invocable skills are the command mechanism (Claude commands converted to skills) |
| CMD-03 | Arguments ($ARGUMENTS, $1, ...) | ✅ `$ARGUMENTS`, `$1`..`$9`, `$$`, and the /name:arg spelling. An empty expansion is refused | ✅ Arguments after `/name` pass through; expansion is the server's | ✅ `$ARGUMENTS`, `$ARGUMENTS[N]`, `$N`, named `$name` via `arguments:`; unplaced args appended as `ARGUMENTS:` | ✅ $ARGUMENTS plus positional $1..$n; hints shown in autocomplete | ✅ Δ $ARGUMENTS, quoted $1..$n (last consumes rest), args appended when no placeholder | 🟡 `/skill <name> [input]` passes free text; tool-dispatch skills get raw args; no $ARGUMENTS/$1 templating |
| CMD-04 | Frontmatter (description, model, agent, allowed-tools) | 🟡 description, argument-hint live; model, subtask parsed but inert; no allowed-tools/agent keys (flagged unknown) | 🟡 Completion shows description + argumentHint; the rest of the frontmatter applies server-side | ✅ Frontmatter: description, argument-hint, model, effort, allowed-tools, disallowed-tools, context/agent, hooks, shell | 🟡 Frontmatter: description, agent, model, subtask; no allowed-tools/argument-hint fields | ✅ Δ Frontmatter: description, agent, model#variant, subagent (background child; `subtask` alias) | 🟡 Skill frontmatter: user-invocable, disable-model-invocation, command-dispatch/tool/arg-mode; no model/agent/allowed-tools |
| CMD-05 | Shell interpolation in commands (!`cmd`) | ✅ !`cmd` substitution: 10 s budget, 16 KiB per substitution, gated; project tier needs trustedProjectCommands | ✅ Shell-interpolating command files expand in a forked child server-side (trust-gated), admitted `pending` | ✅ `` !`cmd` `` and ```` ```! ```` blocks run before Claude sees the content; output inlined | ✅ !`cmd` in templates runs in project root and inlines output | ✅ !`cmd` in templates runs in project root and inlines output | ❌ No !`cmd` shell interpolation in command/skill bodies |
| CMD-06 | File references in commands (@file) | ✅ `@path` includes in command bodies, contained to the checkout, with a bounded read | 🟡 Inherited; not previewed | ✅ `@file` references in local skills/commands attach file contents | ✅ @path in templates attaches file contents (same Read pipeline, triggers nested AGENTS.md) | ❌ Δ @path in command templates is NOT expanded in v2 (attach files via the composer instead) | ❌ No @file expansion inside commands |
| CMD-07 | Autocomplete popup for / | ✅ "/" popup with fuzzy matching and highlighting over built-ins and custom commands; Tab completes | ✅ Typing `/` opens a prefix popup (8 entries), ↑/↓ moves, Tab completes; only server-runnable + file commands | ✅ `/` menu with highlighting, typo-tolerant suggestions, hidden commands, mid-prompt completion; mouse in fullscreen | ✅ / opens fuzzy autocomplete of built-in, custom, MCP-prompt and skill commands with descriptions | ✅ / opens fuzzy autocomplete of built-in, custom, MCP-prompt and skill commands with descriptions | ✅ TUI `/` and `@` autocomplete; Control UI slash completion (runs mid-draft); Discord autocomplete, Telegram/Slack button menus |
| CMD-08 | Command palette (Ctrl+P etc.) | 🟡 Ctrl+P (Ctrl+K in shell) palette: 19 actions + provider/model/theme pickers; not every command, no custom ones | ✅ Ctrl+K / ⌘K palette: sessions, slash commands (no-arg ones run at once), panels, new session, theme | 🟡 No separate command palette in the CLI (Ctrl+P is history); the `/` menu doubles as one; `?` shortcut panel | ✅ ctrl+p command palette over all actions (categories, keybind hints); which-key overlay | ✅ ctrl+p command palette over all actions (categories, keybind hints); which-key overlay | ✅ Control UI ⌘K/Ctrl+K command palette (sessions, settings, commands, plugins, threads; Cmd+Enter starts a background session) |
| CMD-09 | MCP prompts exposed as commands | ❌ MCP prompts are not supported (no prompts/list) | ❌ MCP prompts are not offered as commands in the web | ✅ MCP prompts appear as `/server:prompt (MCP)` and run as `/mcp__server__prompt [args]` | ✅ MCP prompts become slash commands (arguments mapped to $1..$n) | ✅ Δ MCP prompts become `/<server>:<prompt>` commands (args positional); refreshed on prompts/list_changed | ❌ MCP prompts are model-facing tools (`prompts_list`/`prompts_get`), not slash commands |
| CMD-10 | Commands from plugins | ❌ No plugin system | ➖ No plugin system | ✅ Plugins ship commands/skills namespaced `/plugin:name`; mods can register instant commands that run code without a turn | 🟡 No command manifest for plugins; plugins add commands via `config` hook or TUI keymap layers; command.execute.before | ✅ Δ Plugins register commands via `ctx.command.transform` (first-class) | ✅ Plugins register commands (`registerCommand`); bundled plugin commands; bundle command packs via skills |
| CMD-11 | Bang-shell from the prompt (!cmd) | ✅ `!cmd` runs a shell for the user and its output lands as a user-role row. Deny rules and plan mode still refuse; Esc Esc cancels | 🟡 `!cmd` runs on the server, but `session.send` answers -32009 "refused" and its output rows emit no events (verified) | ✅ `!cmd` shell mode: runs outside Claude, output added to context, Claude responds automatically (`respondToBashCommands`) | ✅ !cmd from prompt runs a shell command; output stored as a user-executed tool result the model sees | ✅ Δ `!` shell mode; user shells run immediately as background work, output kept in the session | ✅ TUI `!cmd` runs on the TUI host (per-session consent); chat `! cmd` / `/bash` on host when commands.bash + elevated |
| CMD-12 | Per-command keybindings | ❌ No keybinding customization (fixed KeyBindingRegistry) | ❌ No per-command keybindings | 🟡 `~/.claude/keybindings.json` rebinds built-in actions (incl. chords) with hot reload; no binding for custom slash commands | 🟡 ~150 built-in actions rebindable in tui.json; custom markdown commands have no keybind field | 🟡 Δ Keybinds in global cli.json; custom commands still have no keybind field | ❌ No per-command keybindings |
| CMD-N1 | 🆕 Import foreign custom-command files (.claude/commands, .opencode/commands, etc.) | ❌ .claude/commands and .opencode/commands are not read, although foreign agents and skills are | ❌ Not supported by the engine (only .sugar-crush/commands trees are read); the `/` popup lists those files and server built-ins only | 🟡 Reads `.claude/commands` natively; one-time `/import` from Codex, Gemini CLI and Cursor; no `.opencode/commands` support | ❌ .claude/commands (and other tools' command dirs) are not imported | ❌ Only .opencode/command(s) and JSON `commands`; no .claude/commands import | ✅ `openclaw migrate claude` converts .claude/commands into skills; Claude/Cursor bundle `commands/` load as skill roots |
| **HK** | **Hooks** |  |  |  |  |  |  |
| HK-01 | Hook system exists | ✅ Shell-command hooks from YAML, with exit-code verdicts plus a Claude-Code-shaped JSON envelope | 🟡 Hooks run server-side for served sessions; the web has no hooks view | ✅ Mature hook system configured in settings JSON (event → matcher group → handlers), plus in-process "mods" hooks | 🟡 Hooks exist only as in-process JS/TS plugin hooks; no declarative shell-command hook config | 🟡 Δ New v2 plugin API (Plugin.define + setup, domain hooks/transforms); still no declarative shell hooks | ✅ Two systems: internal hooks (HOOK.md + handler) and typed plugin hooks (`api.on`); plus webhooks ingress |
| HK-02 | Events supported (list them) | ✅ 12 events: Pre/PostToolUse, Stop, SubagentStop, SessionStart/End, UserPromptSubmit, Pre/PostCompact, team events | 🟡 Inherited | ✅ 33 events: SessionStart/End, Setup, UserPromptSubmit/Expansion, Pre/PostToolUse(+Failure, Batch), Permission*, Stop… | ✅ Hooks: chat.message/params/headers, tool.execute.before/after, shell.env, command.execute.before, transforms, compaction, event | ✅ Δ session prompt/context/compaction/generate/title/model.request/http.*/retry/ws.*, permission.evaluate, shell, tool, events | ✅ Internal: command:new/reset/stop, session:compact:*, session:patch, agent:bootstrap, gateway:*, message:*; ~40 typed plugin hooks |
| HK-03 | Hook kinds (shell command / LLM prompt / HTTP / in-process JS/PHP plugin) | 🟡 Shell commands only (`/bin/sh -c`). No LLM-prompt or HTTP hooks; PHP hooks exist only as built-ins or for embedders | 🟡 Inherited | ✅ Five handler types: command (stdin JSON), http (POST), mcp_tool, prompt (LLM verdict), agent (experimental) | 🟡 In-process JS/TS functions only (can shell out via Bun $); no shell/HTTP/LLM-prompt hook types | 🟡 Δ In-process Effect or Promise plugin code (server) or CLI plugins (TUI); no shell/HTTP/LLM-prompt hook kinds | 🟡 In-process JS/TS handlers only (internal hook modules or native plugins); no shell-command, LLM-prompt or HTTP hook kinds |
| HK-04 | Matchers (by tool name / pattern) | ✅ Unanchored, case-insensitive PCRE on the tool name (lifecycle events match the event name). No argument matching | 🟡 Inherited | ✅ Matchers: exact names or lists, otherwise unanchored JS regex; per-event match fields; `if` uses permission-rule syntax | 🟡 No declarative matchers; hook code branches on input.tool/sessionID | 🟡 No declarative matchers; hook code branches on input.tool/sessionID | ✅ Plugin hook `matcher` = list of canonical tool ids (before/after_tool_call), `priority`, `eligibleTriggers` |
| HK-05 | Blocking / decision control (deny, modify input, inject context) | ✅ Exit 0 allow, 1/2 deny, 3 ask, 4 rewrite; JSON decision/updatedInput/additionalContext/continue honoured | 🟡 A hook `ask` reaches the web as a permission card (no "always" offered); a deny shows as a refused tool card | ✅ Exit 2 blocks; JSON permissionDecision allow/deny/ask/defer + updatedInput; decision:block; additionalContext | ✅ before-hooks can mutate args or throw to block; after-hooks rewrite output; transforms inject context. permission.ask unwired | ✅ Δ tool before/after can rewrite input/result; `permission.evaluate` can allow/ask/deny (non-deny decisions) | ✅ Block/rewrite tool calls or require approval; gate runs; claim replies; add prompt context; cancel/rewrite delivery |
| HK-06 | Config location / scopes | 🟡 ~/.sugar-crush/hooks.yaml always; project hooks.yaml only if trustedProjectHooks; no settings.json/managed tier | 🟡 Inherited (user tier + trusted project hooks) | ✅ User, project, local, managed settings; plugin `hooks/hooks.json`; skill and subagent frontmatter | ✅ .opencode/plugins, ~/.config/opencode/plugins, npm `plugin` list in global/project config; fixed load order | ✅ Δ `plugins` array (packages, git, paths, options objects; `-id`/wildcards disable) + .opencode/plugins discovery | ✅ `hooks.internal.entries` in openclaw.json + hook dirs (workspace opt-in, managed, bundled); plugin hook policy per plugin |
| HK-07 | Claude-Code-compatible hook config | ❌ Can't read .claude/settings.json hooks; payload in env vars not stdin (CC scripts see nothing); exit 1 differs | 🟡 Inherited | ✅ Claude Code is the reference format other tools emulate | ❌ Claude Code settings.json hooks not read (🔌 opencode-froggy / oh-my-openagent offer CC-style hook layers) | 🔌 Δ CC settings.json hooks not read natively; 🔌 opencode-froggy 1.4.2 (v2 plugin API) adds Claude-Code-style hooks | ❌ Claude Code hooks.json is detected but never executed (bundles) and archive-only in migration |
| HK-08 | Hook output surfaced in UI | 🟡 Verdicts and notes appear in tool results and transcript rows, plus a blocked-prompt notice. No dedicated hook log view | 🟡 Hook-asked questions appear as cards; other hook output only via `/notices` or a card's denial reason | ✅ `systemMessage` shown to user; `<hook> hook error` notices; spinner `statusMessage`; stdout to debug log for most events | 🟡 Plugins can show toasts (tui.toast.show / client.tui.showToast) and log; no built-in hook output pane | 🟡 Plugins can show toasts (tui.toast.show / client.tui.showToast) and log; no built-in hook output pane | 🟡 Hook replies on supported command paths, `/trace` plugin lines, compaction notices; errors in Gateway log |
| HK-09 | Hook timeout / failure handling | ✅ Default 60 s (1–3600 setting), chain budget, SIGTERM→KILL; timeout, crash or unknown exit = deny | 🟡 Inherited | ✅ Per-handler `timeout`; non-zero (not 2) = non-blocking error; `onFailure: "block"` (v2.1.295) fails closed | 🟡 Hooks awaited sequentially with no timeout; a throw aborts that tool call/request | 🟡 Δ Rejected Promise hooks/tools now become normal tool failures attributed to their call | ✅ Per-hook timeouts: 15 s fail-closed for run/tool/install gates, 15 s skip for prompt/message hooks, 30 s observers |
| HK-10 | Hooks management UI (/hooks) | ❌ No /hooks command or hooks UI | ❌ No hooks management UI | 🟡 `/hooks` is a read-only browser (source, full command, "All events"); edit via settings files or the `/update-config` skill | 🟡 TUI Plugins dialog toggles/installs plugins; no per-hook management UI | 🟡 Δ /plugins dialog; `opencode plugin list/check/update/remove` | 🟡 CLI `openclaw hooks list/info/check/enable/disable`; plugin hooks via Control UI Plugins / `plugins inspect --runtime` |
| **PLG** | **Plugins & extensibility** |  |  |  |  |  |  |
| PLG-01 | Plugin system exists (format) | ❌ No plugin system. Extensibility is file-based: skills, agents, commands, hooks, rules, workflows and MCP | ❌ No web plugin/extension mechanism | ✅ Plugins: `.claude-plugin/plugin.json` dir bundling skills, agents, hooks, MCP/LSP servers…; mods add in-process JS | ✅ Server plugins (JS/TS modules returning hooks) + TUI plugins (tui.json, OpenTUI/Solid slots, routes, keymaps) | ✅ Δ Effect/Promise server plugins (setup, transforms, hooks, RPC contracts) + CLI plugins in cli.json; V1 plugins don't run | ✅ Native in-process plugins (openclaw.plugin.json + TS entry) and bundles (Agent Plugins, Codex, Claude, Cursor) |
| PLG-02 | Plugin marketplace / install command | ❌ No marketplace or install command | ❌ — | ✅ Marketplaces (`marketplace.json` catalogs: GitHub/git/URL/path/npm); `/plugin` UI and `claude plugin install/enable/update/…` | ✅ `opencode plugin <npm> [-g]` installs + edits config; TUI plugin manager (shift+i); npm auto-install via Bun | ✅ Δ `opencode plugin add` from npm or git (incl. private/subdir), `check`/`update`; background install at startup | ✅ `openclaw plugins install` (ClawHub, npm, git, local, archive, Claude-compatible marketplaces); Control UI plugin store |
| PLG-03 | What plugins can add (commands, agents, skills, hooks, MCP, tools, themes, providers) | ➖ N/A, since there are no plugins (PLG-01 lists the file-based surfaces) | ➖ No plugins | ✅ Skills, commands, agents, hooks, mods, MCP (incl. MCPB), LSP, executables, settings, themes, styles, channels, monitors | ✅ Tools, hooks, auth methods, provider model lists, config mutation (agents/commands/MCP), TUI routes/slots/themes/sounds | ✅ Δ Transforms for providers, models, agents, commands, skills, tools, MCP, integrations, references, worktrees; hooks | ✅ Tools, skills, channels, providers, voice, media, search, memory, context engines, harnesses, hooks, commands, themes, UI panels |
| PLG-04 | Custom tools defined in code | 🟡 Embedders can pass PHP Tool objects (EngineBackend::withTools, Chat::registerTool); shipped binary: MCP only | 🟡 Server-defined custom tools render generically as tool cards | ✅ Custom tools via mods (`$.tool.register`, JS/TS in-process), MCP servers, or SDK in-process MCP tools | ✅ .opencode/tools/*.ts (or tool/) and plugin `tool` map using tool() + zod schema; override built-ins | ✅ Δ Tools via `ctx.tool.transform(...)`; may enforce permissions with `ctx.permission.assert` | ✅ `api.registerTool(...)` / defineToolPlugin with manifest `contracts.tools` |
| PLG-05 | Programmatic SDK | 🟡 Usable as a PHP library (ProviderInterface, Host\SessionHost) plus the sugarcrush.v1 JSON-RPC protocol. No packaged SDK | 🟡 Generated TS protocol types + `SugarCrushClient` (reconnect, resume, idempotent resend) are reusable, but not published as an SDK | ✅ Claude Agent SDK for TypeScript and Python (same harness; hooks, subagents, sessions, permissions, MCP) | ✅ @opencode-ai/sdk (JS/TS, createOpencode/createOpencodeClient); official Go and Python SDKs | ✅ Δ @opencode/sdk embeds the whole server in-process (incl. Cloudflare Durable Objects); @opencode/client for network | ✅ Plugin SDK (`openclaw/plugin-sdk/*`: runtime, harness, channel, provider, memory, testing) + `plugins init/build/validate/pack` |
| PLG-06 | Agent Client Protocol (ACP) support | ✅ `sugarcrush acp` stdio ACP agent (Zed/JetBrains/Neovim): new/load/prompt/set_mode; no slash-command advert | ➖ ACP is the server's stdio mode (`sugarcrush acp`), not part of the web | ❌ No Agent Client Protocol support in Claude Code itself | ✅ `opencode acp` (JSON-RPC stdio) for Zed, JetBrains, Avante.nvim, CodeCompanion; /undo /redo unsupported | ✅ Δ `opencode acp` (stdio JSON-RPC) with child sessions, questions, compaction, usage; `--login` | ✅ ACP both ways: `openclaw acp` server bridge for editors; ACP agents (Claude Code, Codex, Gemini, OpenCode, Droid, Pi) via ACPX |
| PLG-07 | Headless server / HTTP API | ✅ `sugarcrush serve`: WebSocket JSON-RPC (sugarcrush.v1) plus /api/health, login and ticket. Background mode and attach | ✅ The web UI is the reference client of the HTTP + WebSocket `sugarcrush.v1` API | 🟡 No local HTTP/WebSocket server API; programmatic use is stdio stream-json/SDK, `claude mcp serve`, or cloud surfaces | ✅ `opencode serve`: OpenAPI 3.1 HTTP API (/doc) + SSE; TUI itself is a client of this server | ✅ Δ New v2 HTTP API (breaking) served by the background service or `serve`; `opencode api` client | ✅ Gateway WS RPC (scoped), OpenAI-compatible `/v1/chat/completions`, OpenResponses, tools-invoke HTTP, admin HTTP RPC |
| PLG-08 | Event bus / subscription API | ✅ Durable, seq-numbered session events plus live deltas over serve (subscribe and replay); in-process `Events\*` value objects | ✅ Consumes the server event stream (34 event types) with gap-free per-session `seq` cursors | ✅ Hooks (33 events, HTTP POST handlers), mods `on('event')` in-process subscriptions, stream-json event stream, OTel events | ✅ /event and /global/event SSE streams; plugin `event` hook receives every bus event | ✅ Δ Durable session event log with replay (`sessions.log`) + live instance event stream; `ctx.event.subscribe` | ✅ Gateway WS event streams (chat, tool events, sessions, presence) + typed plugin hooks + diagnostic events |
| PLG-09 | Custom providers via plugin | 🟡 Any executable as backend (SUGARCRUSH_BACKEND_CMD[_STREAM]), OpenAI-compatible provider, or PHP ProviderInterface | ➖ — | ❌ No provider plugins; routing limited to Anthropic, Bedrock, Agent Platform, Foundry, AWS platform, Anthropic-format gateways | ✅ Plugin `auth`/`provider` hooks (e.g. gemini/antigravity auth); any AI-SDK npm package as custom provider | ✅ Δ `ctx.provider/model/integration.transform` add providers, models and auth methods | ✅ Provider plugins (catalogs, auth flows, runtime hooks) + config-only custom providers |
| PLG-10 | Notable ecosystem plugins (names) | ❌ No plugin ecosystem | ❌ — | ✅ Official marketplace (315 entries): code-review, security-guidance, hookify, feature-dev, plugin-dev, superpowers, LSPs… | ✅ DCP, oh-my-openagent, opencode-pty, goal plugin, agent-memory, supermemory, subtask2, plannotator, worktree, wakatime… | 🟡 Δ V1 ecosystem must be ported: agent-memory 0.3 + official Figma are v2; DCP, pty, subtask2 still V1-API | ✅ Codex harness, ACPX, Lobster, memory-lancedb/wiki, Honcho, Lossless Claw, tokenjuice, diffs, voice-call, meetings, team-reports |
| PLG-N1 | 🆕 In-process UI mods/plugins that draw panes, rewrite events, add commands/tools | ❌ No plugin/mod system; extension is file-based (hooks, commands, skills, agents, MCP); `statusLine` only feeds status-bar text | ❌ No web plugin or mod mechanism; the committed bundle is fixed and the CSP allows only same-origin scripts | ✅ in-process JS/TS plugins that draw panes, rewrite events, add commands/tools; several CC features are built-in mods | ✅ TUI plugins draw into slots, add routes/keymap commands/dialogs; server plugins rewrite messages, add tools | ✅ CLI plugins (cli.json) extend the TUI; server plugins transform registries, hook prompts/requests, add tools/commands | ✅ Native plugins run in-process: Control UI pages/widgets/view replacements, event-rewriting hooks, commands, tools, themes |
| PLG-N2 | 🆕 Plugin evaluation / cost measurement tooling | ❌ No plugins and no eval/cost tooling for extensions; `/context` shows per-section and tool-schema token cost only | ❌ No plugin system, hence no eval or cost tooling; `/context` (server-run) prints section and tool-schema token sizes | ✅ `claude plugin eval` scores plugin effect vs a no-plugin baseline; `plugin details` shows token cost | ❌ No plugin evaluation or cost-measurement tooling | ❌ No plugin eval/cost tooling (`stats --tools` reports tool reliability only) | 🟡 Skill Workshop evaluators (`workshop evaluate`, `skill_proposal_evaluate`) and SDK test utilities; no plugin cost/eval suite |
| **MCP** | **Model Context Protocol** |  |  |  |  |  |  |
| MCP-01 | Client: stdio | ✅ stdio client (sugar-mcp-client): env credential stripping, 60 s handshake, 120 s per-call cap + notifications/cancelled | 🟡 Inherited: a trusted `.mcp.json` stdio server starts server-side; invisible in the web | ✅ stdio servers (`claude mcp add name -- cmd args`, `-e KEY=v`) | ✅ type local: command array, env, cwd; started at instance init | ✅ type local: command array, env, cwd; started at instance init | ✅ stdio servers (command/args/env/cwd) with owned process-group cleanup |
| MCP-02 | Client: SSE | ❌ Legacy SSE transport is not supported (`"type":"sse"` fails as an unknown MCP server type) | 🟡 Inherited; invisible | ✅ SSE transport (`--transport sse`) | ✅ Remote servers fall back to SSE transport if Streamable HTTP fails | ❌ Δ SSE transport removed: remote servers use Streamable HTTP only | ✅ SSE (the default when a URL server omits `transport`) |
| MCP-03 | Client: Streamable HTTP | 🟡 Streamable HTTP with Mcp-Session-Id, SSE replies parsed; body buffered, no GET stream, fixed 30 s total timeout | 🟡 Inherited; invisible | ✅ Streamable HTTP (`--transport http`, headers) plus WebSocket servers | ✅ type remote: Streamable HTTP tried first, custom headers | ✅ Δ Streamable HTTP; optional MCP 2026-07-28 protocol via `protocol: auto/2026-07-28` | ✅ `transport: "streamable-http"` with headers (${ENV}), TLS/mTLS, timeouts |
| MCP-04 | OAuth for remote MCP servers | 🟡 OAuth (discovery, DCR, PKCE, loopback) only via `sugarcrush mcp auth login`; TUI prints the command; no 401 prompt | ❓ `/mcp` is server-run; whether `/mcp auth` OAuth can complete from a browser was not verified | ✅ OAuth via `/mcp` or `claude mcp login`; pre-registered client IDs, fixed callback port, scopes, headersHelper | ✅ Auto OAuth on 401 with DCR (RFC 7591) or pre-registered client; `mcp auth/logout/debug`; tokens in mcp-auth.json | ✅ Δ OAuth with PKCE via pre-registered client, CIMD, or DCR; snake_case oauth fields; /mcps sign-in | ✅ `auth: "oauth"`; `openclaw mcp login/logout`; Control UI Settings → MCP "Sign in" |
| MCP-05 | MCP tools | ✅ `mcp__server__tool` bridges with schema passthrough, permission gating and readOnlyHint honoured; 64 KiB result cap | 🟡 MCP tool calls render as generic tool cards | ✅ MCP tools exposed as `mcp__server__tool`, deferred by default behind tool search | ✅ Tools exposed as <server>_<tool>; permission/tools globs (e.g. mymcp_*); progress resets timeouts | ✅ Δ Tools named <server>_<tool>, default reached through Code Mode namespaces; `codemode:false` exposes directly | ✅ Tools as `<server>__<tool>` via bundle-mcp in coding/messaging profiles; include/exclude filters; per-session denials |
| MCP-06 | MCP resources (@-mention / read) | ❌ No resources (list or read) and no @-mention of resources | ❌ No MCP resource @-mention or picker | ✅ Resources via `@server:proto://path` mentions (fuzzy in typeahead) and List/ReadMcpResource tools | ✅ Resources @-mentionable in TUI + model tools list_mcp_resources/_templates/read_mcp_resource (blobs ≤10 MB) | ✅ Resources @-mentionable in TUI + model tools list_mcp_resources/_templates/read_mcp_resource (blobs ≤10 MB) | 🟡 Resources reachable by the model via generated `resources_list`/`resources_read` tools; MCP Apps UI resources rendered |
| MCP-07 | MCP prompts (as slash commands) | ❌ No prompts/list, so MCP prompts are not slash commands | ❌ MCP prompts are not slash commands in the web | ✅ Prompts become `/server:prompt` commands | ✅ MCP prompts listed as slash commands | ✅ Δ Prompts as `/<server>:<prompt>` commands | 🟡 Prompts reachable by the model via `prompts_list`/`prompts_get`; not exposed as slash commands |
| MCP-08 | Elicitation / sampling | ❌ No sampling, elicitation, roots, logging or list_changed. Server-initiated requests (even ping) are ignored | ❓ Not verified; the protocol has no elicitation event, so the web could only show it as a permission card | 🟡 Elicitation supported (form and URL modes, `Elicitation`/`ElicitationResult` hooks); sampling not supported | ❌ Sampling and elicitation capabilities deliberately disabled (issues #11948, #23066); roots supported | 🟡 Δ Elicitation supported (forms and URL requests); sampling still not | 🟡 No elicitation/sampling in the embedded MCP client; Codex harness relays Codex-native MCP elicitations |
| MCP-09 | Config scopes (user / project / local, .mcp.json) | 🟡 Only `<root>/.mcp.json`, gated by trustedProjectMcp plus per-server fingerprint pins. No user-level or local scope | 🟡 Inherited | ✅ Scopes local (default), project (`.mcp.json`, approval prompt), user; plus managed, plugin, claude.ai connectors, `--mcp-config` | 🟡 `mcp` key in global/project/remote/managed config; no .mcp.json and no local/private scope | 🟡 Δ `mcp.servers` in global/project/managed config; `mcp add [--global]`; still no .mcp.json | 🟡 One global `mcp.servers` in openclaw.json + bundle-contributed servers + per-agent/per-session policy; no project .mcp.json |
| MCP-10 | Management UI (/mcp, status, reconnect) | 🟡 /mcp list/add/remove/login (login prints CLI cmd) + McpPanel liveness; no reconnect or runtime enable/disable | 🟡 `/mcp` (server-run) prints the project config status as text; no status/reconnect UI | ✅ `/mcp` panel (status, tool counts, auth, reconnect/enable/disable, `reconnect all`); `claude mcp list/get` with health | ✅ /mcps toggle dialog (space), /status + sidebar status, `opencode mcp list/add/auth/debug`; POST /mcp adds live | ✅ /mcps toggle dialog (space), /status + sidebar status, `opencode mcp list/add/auth/debug`; POST /mcp adds live | ✅ Control UI Settings → MCP + composer Connectors/Tool access; `/mcp`; `openclaw mcp list/status/probe/doctor/reload/tools` |
| MCP-11 | Per-agent MCP enable/disable | 🟡 The preset `mcpServers` allowlist is enforced only when the preset also declares `tools:`, and is bypassed otherwise (bug) | 🟡 Inherited | ✅ Subagent `mcpServers` frontmatter (reference or inline definitions connected only for that agent) | ✅ Per-agent enable/disable through permission or tools globs on <server>_* | ✅ Per-agent enable/disable through permission or tools globs on <server>_* | ✅ Per-agent tools.allow/deny with server globs (`docs__*`); sandbox gate needs `bundle-mcp`; session denials |
| MCP-12 | Tool search / deferred MCP tool loading | ❌ Every MCP tool schema is sent on every turn. No tool search or deferred loading | 🟡 Inherited | ✅ Tool search on by default: only tool names + server instructions load; `ToolSearch` loads schemas on demand | 🟡 No deferred tool search; experimental code mode exposes MCP tools via one `execute` sandbox tool | ✅ Δ Code Mode is default: MCP tools listed as budgeted one-line signatures inside the `execute` tool | ✅ Tool Search (experimental, ON by default for embedded runs) defers MCP/plugin schemas behind tool_search/describe/call |
| MCP-13 | Act as an MCP server (expose self) | ❌ Does not act as an MCP server (the in-process `type: git` server serves sugar-crush itself; serve and acp are not MCP) | ➖ — | ✅ `claude mcp serve` exposes Claude Code as a stdio MCP server | ❌ Does not act as an MCP server (exposes ACP and HTTP API instead) | ❌ Does not act as an MCP server (exposes ACP and HTTP API instead) | ✅ `openclaw mcp serve` exposes channel conversations over stdio; `openclaw attach` gives Claude Code grant-scoped OpenClaw tools |
| MCP-14 | MCP output limits / truncation | 🟡 Text capped at 64 KiB (`mcpResultCapBytes`). Images and embedded resources become [image]/[resource] placeholders | 🟡 MCP output is capped like any tool (256 KiB event cap, full output on request) | ✅ Warn at 10K tokens, cap 25K (`MAX_MCP_OUTPUT_TOKENS`), text >50K chars saved to file; per-tool `anthropic/maxResultSizeChars` | ✅ MCP results pass through the same 2000-line/50 KB truncation-to-file; resource blobs capped at 10 MB | ✅ Δ Per-server timeouts startup/catalog/execution (12 h default execution); large outputs shortened to managed file | ✅ Results bounded by live tool-result caps; App UI resources ≤2 MiB; stderr 8 KiB; URL credentials redacted |
| MCP-15 | Import MCP config from other tools (Claude Desktop etc.) | 🟡 `sugarcrush mcp import claude/opencode <file>` prints a translated block, writes nothing; CC .mcp.json read natively | ❌ — | ✅ `claude mcp add-from-claude-desktop` (macOS/WSL), `/import` from Codex/Gemini/Cursor, `add-json` for pasted configs | ❌ No import from Claude Desktop/.mcp.json; `opencode mcp add` is interactive | ❌ No import from Claude Desktop/.mcp.json; `opencode mcp add` is interactive | ✅ `openclaw migrate claude` imports .mcp.json, ~/.claude.json (per-project too) and Claude Desktop config (secrets redacted) |
| MCP-N1 | 🆕 MCP server trust pinning (config fingerprints; re-prompt when a server definition changes) | ✅ Per-server config fingerprints in ~/.sugar-crush/mcp-trust.json; a changed server needs `mcp trust` | 🟡 Inherited pins for the server's MCP launches; a changed server silently stays off (`/notices` only); re-approve via CLI | 🟡 `.mcp.json` servers need per-project approval (by name) and trust; no documented fingerprint re-prompt on definition change | ❌ No MCP server trust pinning or fingerprinting | ❌ No MCP trust pinning; project config can replace server objects silently | ❌ No MCP definition fingerprinting or re-prompt when a server definition changes |
| **TL** | **Built-in tools** |  |  |  |  |  |  |
| TL-01 | Read file | ✅ Read: numbered pages (2000 lines / 50 KiB), offset+limit, outline of long files, 1 MiB per-call ceiling, jailed to root | ✅ Read runs server-side; card shows the path argument, duration and output (full output on request) | ✅ `Read`: line-numbered text, images, PDFs, notebooks; 25K-token pages with PARTIAL notice; `allow_large` | ✅ read: files, directories (listing), images, PDFs; offset/limit paging | ✅ Δ read: files, non-recursive dir listings, images/PDF ≤20 MiB; listings also trigger nested AGENTS.md | ✅ `read` (path, 1-based offset, limit, cursor within long lines, optional) with continuation notices and image support |
| TL-02 | Write file | ✅ Write: makes parent dirs, overwrite needs overwrite=true, atomic replace keeps mode/owner, refuses if changed since read | ✅ Write card shows a unified diff with line numbers and +N −M stats | ✅ `Write` creates/overwrites whole files (UTF-8); read-before-overwrite rules depend on model | ✅ write: create/overwrite; formatter + LSP errors for the file and up to 5 others | ✅ Δ write creates/replaces files, may format; no LSP diagnostics | ✅ `write` with per-file mutation queue and read-back verification of the persisted bytes |
| TL-03 | Edit (exact string replace) | ✅ Edit: exact match first, then 5 forgiving stages; must match one place; replace_all works on exact matches only; 1 MiB file cap | ✅ Edit card shows the diff; refusals show their reason | ✅ `Edit`: exact `old_string`→`new_string`, unique match or `replace_all` | ✅ edit: oldString/newString/replaceAll with 9-stage fuzzy replacer chain; diff shown in ask dialog | ✅ Δ edit: exact match, then quote/dash/space-variant and trailing-whitespace retries; CRLF/mixed endings kept | ✅ `edit` exact replacement; unique-match/empty errors; closest-matching-lines diagnostics with hints |
| TL-04 | Multi-edit / batch edit | ✅ Edit accepts an `edits` list (applied in order, all or nothing); ApplyPatch changes many files in one call | 🟡 No MultiEdit in the server's tool list; ApplyPatch covers batch edits and renders as a card + diff | ❌ No multi-edit tool in the current tool list (only `Edit` with `replace_all`) | ❌ No multiedit/batch edit tool in 1.18.35 (`experimental.batch_tool` key has no implementation) | ❌ No multiedit/batch edit tool in 1.18.35 (`experimental.batch_tool` key has no implementation) | ✅ `edit` takes `edits[]` (many oldText/newText pairs in one call) |
| TL-05 | Apply patch (unified diff / V4A) | ✅ ApplyPatch: Codex/opencode `*** Begin Patch` format (add/update/move/delete), hunks found by content, all-or-nothing with rollback | ✅ ApplyPatch card + diff | ❌ No apply-patch / unified-diff tool | 🟡 apply_patch (Codex *** Begin Patch format) — only offered to gpt-* models, replacing edit/write | 🟡 Δ `patch` tool (renamed from apply_patch) only for supported GPT models | ✅ `apply_patch` (`*** Begin Patch` Add/Update/Move/Delete, End of File), enabled for every model, workspace-contained |
| TL-06 | Glob | ✅ Glob: PHP iterators, `**` globstar, 1000-match cap, honours .gitignore, `..` refused, results realpath-filtered | ✅ Glob card | 🟡 `Glob` absent by default on macOS/Linux/WSL (Claude uses embedded `bfs` via Bash `find`); present on Windows | ✅ glob via ripgrep, sorted by mtime, respects .gitignore/.ignore | ✅ Δ glob with path/hidden/limit (default 100, 30 s timeout) | 🟡 No glob/find tool in OpenClaw's catalog (group:fs = read/write/edit/apply_patch, plus `ls`); discovery via `exec` |
| TL-07 | Grep (ripgrep) | 🟡 Grep is GNU `grep -rn` (BRE), not ripgrep; only pattern/path/include params; 64 KiB cap; no wall-clock timeout | ✅ Grep card | 🟡 `Grep` (ripgrep) absent by default on macOS/Linux/WSL (embedded `ugrep` via Bash `grep` instead); present on Windows | ✅ grep via ripgrep with regex + include filters | ✅ Δ grep with literal/include/caseSensitive/limit (default 100 lines, 30 s timeout) | 🟡 No grep tool; content search via `exec` (rg/grep) |
| TL-08 | List directory | 🟡 No list-dir tool: use Glob or read-only `ls` via Bash (unasked); dormant StreamingDirectoryLister has no callers | ❌ No dedicated list-directory tool in the server's tool list (the human-only `fs.listDirs` picker is not a model tool) | ❌ No list-directory tool; `ls` via Bash (Read refuses directories) | 🟡 No dedicated list tool; read on a directory returns a paged listing ('list' permission key is vestigial) | 🟡 No dedicated list tool; read on a directory returns a paged listing ('list' permission key is vestigial) | ✅ `ls` tool (works in sandboxes without shell; JSON-quoted names; `after` cursor paging) |
| TL-09 | Bash / shell | ✅ Bash: fresh setsid `bash -c` per call in the root, scrubbed non-interactive env; 120 s default / 600 s max; 64 KiB cap | ✅ Bash card with command, output and exit (output only once finished) | ✅ `Bash` (each command its own process) and `PowerShell` tool (default on Windows, opt-in elsewhere) | ✅ bash: command, timeout ms, workdir; tree-sitter parse for per-command permissions; Git/GitHub guidance in desc | ✅ Δ `shell` tool (bash renamed): command, workdir, timeout (0 disables), background | ✅ `exec` (alias bash): host auto/sandbox/gateway/node, pty, env, workdir, yieldMs, timeoutSeconds, elevated |
| TL-10 | Background shell + output polling / monitor | ❌ No background shell, output polling or kill tool | 🟡 PtyRun exists server-side; no live output in the web (nothing streams before `tool.finished`) | ✅ `run_in_background`, Ctrl+B, auto-background on timeout; `Monitor` streams output lines/WebSocket events back to Claude | 🔌 Native bash is synchronous only; background processes via 🔌 opencode-pty (installed here) | ✅ Δ Native background shell: returns immediately, streams output to a managed file, notifies on completion | ✅ Auto-background after `yieldMs` (10 s) or `background: true`; `process` tool for logs/poll/input/kill; push wake on exit |
| TL-11 | Todo / task-list tool | ✅ Todo tool (no-ask): the whole list is rewritten each call, at most one item in progress; reminder nudges; todo panel | ✅ Todo panel follows the Todo tool live via durable `todo.updated` (replayed on reconnect) | 🟡 `TaskCreate/Get/List/Update` (or legacy `TodoWrite`) only by default on Opus 4.7, Sonnet 4.6, Haiku 4.5 and older | ✅ todowrite (full-list replace, pending/in_progress/completed/cancelled, priority); denied to subagents by default | ❌ Δ No todo tool in v2 (todowrite/sidebar todos removed) | ✅ `progress_card` (on by default): durable plan + status note, ≤1 in_progress step; unavailable to sub-agents |
| TL-12 | Web fetch | 🟡 WebFetch: http(s), raw bytes (no HTML-to-markdown), 2 MiB / 64 KiB, 30 s reads, 3 re-checked redirects, strong SSRF guard | ✅ WebFetch card | ✅ `WebFetch`: HTML→Markdown then a separate model call extracts per prompt (lossy); 100K chars/call; 15-min cache | ✅ webfetch: URL → markdown/text/html, timeout ≤120 s | ✅ webfetch: URL → markdown/text/html, timeout ≤120 s | ✅ `web_fetch` (local, readability, optional Firecrawl, 20k chars, 15-min cache, redirects/SSRF limits) |
| TL-13 | Web search | 🟡 WebSearch works only against a user-configured SearXNG endpoint (no default); 10 results, 30 s | ✅ WebSearch card; `/websearch` is server-run | ✅ `WebSearch` via Anthropic's server-side search (titles+URLs), allowed/blocked domains; 200 calls/session, refills ~100/h | 🟡 websearch (Exa/Parallel hosted MCP, no key) only on OpenCode/Go providers or with OPENCODE_ENABLE_EXA/PARALLEL | ✅ Δ websearch with Exa, Firecrawl, Parallel, Tavily, TinyFish (key or /connect); provider chosen on first use | ✅ `web_search` via native model search or provider plugins (Brave, DuckDuckGo, SearXNG, Tavily, Perplexity, Exa, Gemini, Grok...) |
| TL-14 | External code search (e.g. Exa) | ❌ No external code-search tool | ❌ No external code-search tool | 🔌 No built-in external code search; via MCP plugins `exa`, `context7`, `sourcegraph`, `greptile`, `tavily` | 🟡 No built-in code search tool; docs recommend gh_grep/context7 MCP servers | 🟡 No built-in code search tool; docs recommend gh_grep/context7 MCP servers | 🔌 Exa via `@openclaw/exa-plugin` (search provider); also Perplexity/Tavily/Parallel plugins; no code-search API built in |
| TL-15 | Notebook (Jupyter) edit | ❌ No notebook (.ipynb) tool | ❌ No notebook tool | ✅ `NotebookEdit` replace/insert/delete cells by `cell_id` | ❌ No Jupyter notebook edit tool | ❌ No Jupyter notebook edit tool | ❌ No Jupyter notebook tool |
| TL-16 | LSP tool (definition, references, hover) | ✅ Lsp tool: definition/references/hover/symbols/code-actions/diagnostics; read-only class; needs a manual `lsp` server config | 🟡 Lsp tool runs server-side; generic card only | 🔌 `LSP` tool (definition, references, hover, symbols, call hierarchy) inactive until a code-intelligence plugin is installed | 🟡 Experimental lsp tool (OPENCODE_EXPERIMENTAL_LSP_TOOL): definition, references, hover, symbols, call hierarchy | ❌ Δ No LSP tool: v2 has no LSP runtime (`lsp` config accepted but inert) | 🔌 LSP tools only from enabled Claude-format plugin bundles shipping `.lsp.json` → `lsp_hover/definition/references_<server>` |
| TL-17 | Ask-user structured question tool | ✅ AskUser: one question with up to 6 choices, shown in the permission modal; no-ask class | 🟡 AskUser shows as a permission card; the answer is typed in the note field and sent with Allow once — no structured choices | ✅ `AskUserQuestion` multiple-choice (+Other/notes); optional auto-continue timeout (`askUserQuestionTimeout`) | ✅ question tool: header, options, multi-select, custom answer; enabled for TUI/desktop/app clients (build & plan) | ✅ Δ question tool (header, prompt, choices, multiple, free-form); dismiss cancels | ✅ `ask_user`: 1–3 structured questions (choices, multi-select, Other…, Skip); TUI stepper, Control UI, channels |
| TL-18 | Plan-mode exit / plan tool | ✅ PlanExit sends the plan in `.sugar-crush/plans/*.md` for approval; approving ends plan mode when the turn ends | 🟡 PlanExit is a permission card (Allow once + note); the plan is shown as raw JSON arguments, not rendered Markdown | ✅ `EnterPlanMode` / `ExitPlanMode` (plan approval dialog; Ctrl+G edits the plan) | 🟡 plan_exit tool only with OPENCODE_EXPERIMENTAL_PLAN_MODE in CLI; default plan mode is just the plan agent | ❌ Δ No plan-exit tool; plan mode ends only when the user switches agent | ❌ No plan mode / ExitPlanMode tool |
| TL-19 | Image / screenshot read | 🟡 Images reach the model only through the user's @-mention or paste (5 MiB, magic-byte sniffed); the Read tool cannot read images | 🟡 Images a tool reads are not rendered; only media-store artifacts get a MediaCard | ✅ Read returns images visually; Ctrl+V paste; screenshots from Chrome/computer use | ✅ read returns JPEG/PNG/GIF/WebP as image attachments | ✅ read returns JPEG/PNG/GIF/WebP as image attachments | ✅ `view_image` (path/paths/URL, maxImages 20, model-aware resize) |
| TL-20 | PDF read | ❌ No PDF reading (Read returns raw bytes) | ❓ Engine question; the web would show only the text output | ✅ Read handles PDFs (≤10 pages whole, else `pages` ranges up to 20) | ✅ read returns PDFs as file attachments | ✅ read returns PDFs as file attachments | ✅ `pdf` tool (needs PDF-capable model/auth) |
| TL-21 | Browser control | ❌ No browser control | ❌ No browser-control tool | ✅ Claude in Chrome (`--chrome`, extension; Chrome/Edge/Chromium) drives your logged-in browser; Pro/Max/Team/Ent | 🔌 No browser tool; via MCP (e.g. Playwright) or plugins like oh-my-openagent | 🟡 Δ Desktop-only `browser` Code Mode namespace drives the desktop app's attached browser (snapshots, clicks, Lighthouse) | ✅ `browser` (included plugin): dedicated Chrome profile, existing-session relay, remote CDP, sandboxed browser |
| TL-22 | Cron / scheduling tool | ❌ No cron/scheduling tool | ❌ No cron/scheduling tool | ✅ `CronCreate/Delete/List` (session-scoped, 7-day expiry), `ScheduleWakeup` (self-paced /loop), `RemoteTrigger` (routines) | 🔌 No cron tool; 🔌 opencode-scheduler (launchd/systemd) | ❓ Δ No cron tool; opencode-scheduler is V1-API (@opencode-ai/plugin) — v2 support unverified | ✅ `cron`/`automations` tool: one-shot, interval, cron expressions; delivery to channel/webhook/none |
| TL-23 | Messaging tools (send to a channel / other session) | 🟡 Messaging only inside the agent's own tree: SendMessage, Subagents, InterruptAgent, Team, BoardPost | 🟡 SendMessage (agent mailboxes) runs server-side; no channel messaging | ✅ `SendMessage`/`ListAgents` (subagents, teammates, other sessions incl. cloud), `PushNotification`, `SendUserFile` | ❌ No messaging tools | ❌ No messaging tools | ✅ `message` (send/react/edit across channels), `sessions_send`, `conversations_*` tools |
| TL-24 | Canvas / visual output | ❌ No canvas | ❌ — | ✅ `Artifact` tool publishes live HTML/Markdown pages to claude.ai (Pro/Max/Team/Ent); `/design`, `/slides` templates | ❌ No canvas/visual output tool | ❌ No canvas/visual output tool | ✅ Canvas plugin (macOS widget panel, A2UI), `show_widget`, `dashboard`, `portal`, `screen` (arrange Control UI) |
| TL-25 | Computer use (desktop control) | ❌ No computer use | ❌ — | 🟡 Computer use from the CLI: macOS-only research preview, Pro/Max only, interactive only; Desktop app on macOS/Windows | ❌ No computer-use tool | ❌ No computer-use tool | ✅ `computer` tool via paired nodes (macOS app), included cua-computer plugin, Codex computer use; Desktop viewer |
| TL-26 | Parallel tool calls in one turn | ✅ Parallel tool calls on by default; ParallelSafe tools run in concurrent forks; writers run one at a time | ✅ Concurrent calls appear as simultaneously running cards that settle independently | ✅ Parallel tool calls per turn; `PostToolBatch` hook fires after a batch resolves | ✅ Parallel tool calls executed concurrently; per-file semaphore serializes edits | ✅ Δ Parallel local tool calls start eagerly; Code Mode `execute` can run independent calls in parallel | ✅ Parallel tool batches with one atomic launch checkpoint; results in source order |
| TL-27 | Mode-scoped tool sets (read-only tools in plan mode) | 🟡 The tool list is the same in every mode; the gate enforces plan/dont-ask. Only Prune/Recall/Compress depend on the pruning mode | ✅ Picking `plan` in the status bar applies the server's read-only tool set to the next turns | ✅ Plan mode read-only; dontAsk/allowlists; per-subagent and per-skill tool sets; background subagents reduced set | ✅ Permission rules scope tools per agent; '*'-deny removes tools from schema (plan, explore, compaction) | ✅ Δ Ordered `permissions` rules; '*' deny removes tools; deny `execute` removes Code Mode | ✅ `read-only` mode omits mutation tools and denies exec; profiles minimal/coding/messaging/full; depth policies |
| TL-28 | Image generation / media tools | ✅ GenerateImage / GenerateVideo against the configured Stable Diffusion server (sdapi or sglang-diffusion); ask class | 🟡 GenerateImage/GenerateVideo artifacts render as MediaCards (lazy fetch, lightbox); no generate form (`generate.submit` unused) | ❌ No image/media generation tools (artifacts render HTML/SVG only) | 🔌 No media generation; 🔌 opencode-gpt-imagegen | ❓ Δ No media generation; opencode-gpt-imagegen is V1-API — v2 support unverified | ✅ `image_generate`, `music_generate`, `video_generate`, `tts` with provider failover |
| TL-N1 | 🆕 Scripted PTY driver tool (run interactive programs on a pty and replay keystrokes) | ✅ PtyRun runs a program on a real pty and replays keystroke steps with expect checks; 30/300 s | ✅ Inherited PtyRun for served sessions; its card shows the final transcript/screen at tool.finished (nothing streams while it runs) | ❌ No PTY driver tool; Bash runs non-interactive processes (computer use / tmux via Bash are workarounds) | 🔌 🔌 opencode-pty: agent spawns PTYs, sends keystrokes, reads output with regex (V1-API plugin) | ❌ Δ No agent PTY driver; persistent PTYs back the user /terminal only; opencode-pty is V1-API | ✅ `exec pty:true` + `process` send-keys / submit / paste / write / log, with waiting-for-input detection |
| TL-N2 | 🆕 Tool-loop / doom-loop detection (identical repeated tool calls detected and interrupted) | ✅ Repeat-call loop guard: identical call+args+result warned at 3rd, refused at 5th, ends the turn at 8th with a no-tools summary | ✅ Inherited loop guard; warnings and refusals show in tool cards; a guard-ended turn gets no harness notice (stopReason end_turn) | ❌ No detection of identical repeated tool calls; only narrower caps (compaction thrash, stop-hook cap, auto-mode block limits) | ✅ same tool call repeated 3× with identical input triggers a `doom_loop` permission ask | ❌ Δ removed — `doom_loop` is not a v2 permission action | 🟡 Repetitive-call detection is opt-in; a post-compaction repeat guard is on by default |
| TL-N3 | 🆕 Code-mode execution tool (a sandboxed script tool that calls other tools / MCP namespaces and combines results) | ❌ No code-mode tool; nearest is Workflow (a model-authored YAML plan of staged sub-agents with output interpolation) plus plain Bash | ❌ No code-mode tool in the engine; Workflow runs show in the Workflows panel and as cards, but nothing scripts tools/MCP | 🟡 No code-mode tool scripting tools/MCP directly; the `Workflow` tool runs Claude-written JS that orchestrates subagents instead | 🟡 Experimental `execute` code mode (OPENCODE_EXPERIMENTAL_CODE_MODE) runs a confined script over MCP tools | ✅ Δ JS sandbox (fetch only) that calls tool namespaces and combines results | ✅ Code Mode (experimental, off by default): model writes JavaScript calling the hidden tool catalog and `MCP.<server>.<tool>()` |
| **EDT** | **Editing & code intelligence** |  |  |  |  |  |  |
| EDT-01 | Edit fuzzy / whitespace-tolerant matching | ✅ Six-stage chain: exact, indent shift, trimmed lines, whitespace, block anchor, Unicode fold; each must find one place | 🟡 Inherited; invisible | ❌ Edit is strictly exact (no fuzzy/whitespace-tolerant matching, no regex) | ✅ Replacers: exact, line-trimmed, block-anchor (Levenshtein), whitespace, indentation, escape, boundary, context, multi | 🟡 Δ Only typographic-variant and trailing-whitespace fallbacks (v1 had a 9-stage replacer chain) | ✅ Fuzzy fallback (NFKC, trailing whitespace, smart quotes/dashes/Unicode spaces) that refuses ambiguous boundaries |
| EDT-02 | Read-before-write enforcement / stale-file detection | 🟡 Stale-file refusal by content hash in Edit, Write and ApplyPatch, shared across forks; no read-before-write rule (by design) | 🟡 Inherited; a stale-file refusal shows as a refused card with its reason | ✅ Read-before-edit/overwrite enforced (model-dependent), PARTIAL reads don't count, stale files only via exact current match | ❌ No read-before-edit or stale-file check despite edit.txt claiming one; only a per-file semaphore | ❌ Δ Still no read-before-edit/stale check; the false claim was removed from the edit description | ❌ No read-before-write requirement or stale-file check |
| EDT-03 | Diff preview before applying (approval) | 🟡 No diff before approval: modal clips Edit/Write/Patch/WebFetch/MCP args at 80 chars, no expand; diff shows after write | 🟡 The approval card shows the call's raw JSON arguments, not a rendered diff; the diff appears only after the edit lands | ✅ Edit/Write permission prompts show the diff in Manual mode; IDE extensions open native diff viewers | ✅ When edit permission is 'ask', the dialog shows a split/stacked diff (default permission is allow → no preview) | ✅ When edit permission is 'ask', the dialog shows a split/stacked diff (default permission is allow → no preview) | ❌ Edits are not approval-gated; tool cards show bounded inline diffs as they apply |
| EDT-04 | Auto-format after edit | ❌ No auto-format after edits; only possible with a user PostToolUse hook script | 🟡 Inherited; invisible | 🟡 No built-in formatter; documented PostToolUse hook recipe (e.g. prettier on edited file) | ✅ 26 built-in formatters run after write/edit/patch when `formatter: true` (disabled by default since 1.x) | ✅ 26 built-in formatters run after write/edit/patch when `formatter: true` (disabled by default since 1.x) | ❌ No auto-format after edit |
| EDT-05 | Lint feedback loop after edit | ✅ Post-edit lint: `php -l` by default plus a user-tier `lintCommands` map per extension; the report is appended to the edit result | 🟡 Inherited; invisible | 🟡 Lint loop via hooks (PostToolUse exit 2 feeds stderr to Claude) or LSP plugins; nothing built-in | 🟡 No lint-command loop; lint feedback only via LSP servers like eslint/oxlint when LSP is enabled | ❌ Δ No LSP → no lint feedback; docs say run lint/typecheck commands instead | ❌ No lint feedback loop (only a Python shell-syntax preflight on exec) |
| EDT-06 | LSP diagnostics after edit fed back to the model | ✅ With an LSP server configured, each edited file is re-checked; up to 20 errors appended to the result (waits 5 s) | 🟡 Inherited; invisible | 🔌 After each edit the LSP tool reports new type errors ("Found N new diagnostic issues") — needs a code-intelligence plugin | ✅ With `lsp` enabled: waits for fresh diagnostics, appends ≤20 ERRORs per file to edit/write/patch results | ❌ Δ No LSP diagnostics after edits (TODO comments in edit/write) | ❌ No LSP diagnostics fed back after edits |
| EDT-07 | LSP server auto-detect / auto-install | ❌ LSP servers come only from a hand-written user `lsp` config; no auto-detect or install | 🟡 Inherited; invisible | 🟡 "LSP plugin recommendation" dialog offers the matching plugin when a server binary is on PATH; binaries not auto-installed | ✅ 35+ LSP servers auto-detected by extension/markers, many auto-installed (OPENCODE_DISABLE_LSP_DOWNLOAD) | ❌ Δ No LSP servers are started in v2 | ❌ No LSP server auto-detection/installation |
| EDT-08 | Repo map / symbol index | ✅ RepoMap tool: PageRank over symbol references (PHP tokenizer, Universal Ctags for other languages), budgeted to tokens and cached | 🟡 RepoMap tool server-side; card only | ❌ No repo map or symbol index (LSP workspace-symbol search only with plugins); 🔌 `serena` | ❌ No repo map/symbol index; LSP workspaceSymbol via experimental lsp tool or /find/symbol API | ❌ No repo map/symbol index; LSP workspaceSymbol via experimental lsp tool or /find/symbol API | ❌ No repo map or symbol index |
| EDT-09 | External file-change watcher | 🟡 No live watcher: each turn lists read files that changed on disk; opt-in `watchFiles` polls for AI! comments | ❓ Engine question; no protocol event for external file changes | ✅ Files Claude read are watched: a system-reminder notes external changes; `FileChanged` hook for chosen files | 🟡 File watcher emits file.watcher.updated events to UI/plugins; the model is not told about external edits | 🟡 File watcher emits file.watcher.updated events to UI/plugins; the model is not told about external edits | 🟡 User-facing only: Control UI tracks branch/staged/working-tree changes (refresh on tool completion, 5-min fallback) |
| EDT-10 | Line-ending / encoding preservation | 🟡 Atomic writes keep mode/owner/hardlinks; fuzzy stages keep CRLF but exact match inserts LF into CRLF files; no BOM handling | 🟡 Inherited | 🟡 Refuses non-UTF-8 edits (keeps encoding safe), supports UTF-16LE BOM; Write converts to UTF-8; CRLF handling not documented | ✅ edit preserves BOM and CRLF/LF line endings | ✅ Δ Preserves BOM and mixed CRLF/LF endings per edited region | 🟡 Read normalizes BOM/CRLF for matching; apply_patch preserves nearby line endings; edit CRLF round-trip unverified |
| EDT-N1 | 🆕 Auto-test reflection loop (run tests after edits and feed failures back automatically) | ✅ autoTest + testCommand: run tests after edits, return failures to model up to 3 times | 🟡 Inherited when the server user's config enables autoTest + testCommand; both are exec-class, so read-only in the web Settings form | 🟡 No built-in test loop; documented async PostToolUse hook runs tests and feeds results next turn; `/verify` skill | ❌ No automatic test-run reflection loop (LSP error feedback only, when enabled) | ❌ No auto test loop (and no LSP feedback in v2) | ❌ No automatic test-run-and-reflect loop after edits |
| EDT-N2 | 🆕 AI-comment watch mode (`AI!` / `AI?` comments in saved files trigger the agent) | ✅ opt-in `watchFiles`: `AI!`/`AI?` comments saved mid-session become prompts (Aider-style) | ❌ TUI-only: AiCommentWatcher is wired from Chat::subscriptions(); served sessions never watch files for AI!/AI? comments | ❌ No `AI!`/`AI?` comment watch mode | ❌ No AI-comment watch mode | ❌ No AI-comment watch mode | ❌ No AI-comment (`AI!`/`AI?`) file-watch trigger |
| **SH** | **Shell execution** |  |  |  |  |  |  |
| SH-01 | PTY-backed execution | 🟡 Pipes by default (no tty); `interactive: true` adds a private pty (FFI, 8 s idle ceiling); PtyRun drives a pty | 🟡 Inherited (PtyRun); invisible as such | ❌ Bash tool isn't PTY-backed: "runs each command in a separate process", output to a working file; no PTY option | ❌ Agent bash runs as plain child processes (no PTY); PTY API exists only for user terminals in web/desktop | ❌ Δ Agent shell still non-PTY; persistent PTYs back the user /terminal panel (survive restarts) | ✅ `exec pty: true` opt-in for TTY-only CLIs, coding agents and TUIs |
| SH-02 | Persistent shell session (cwd/env persist between calls) | ❌ No persistent shell: each call is a fresh `bash -c`, so cwd, env and functions do not carry over (the description says so) | 🟡 Inherited | 🟡 cwd persists within project dirs; env vars don't; aliases/functions/options snapshotted from your rc at session start | ❌ Fresh process per call; cwd via `workdir` param, env via shell.env hook; nothing persists | ❌ Fresh process per call; cwd via `workdir` param, env via shell.env hook; nothing persists | ❌ Fresh shell per call (no persistent cd/env); bash/zsh startup snapshot of aliases/functions sourced each time |
| SH-03 | Timeouts (default / max) | ✅ Bash 120 s default / 600 s max, TERM then KILL on the group; PtyRun 30/300 s; interactive idle 8 s; Grep unbounded | 🟡 Inherited | ✅ Default 2 min, max 10 min (BASH_DEFAULT/MAX_TIMEOUT_MS); Claude sets per-call `timeout`; timeout moves it to background | ✅ Default 2 min (OPENCODE_EXPERIMENTAL_BASH_DEFAULT_TIMEOUT_MS); model may pass larger timeout; no max cap | ✅ Δ Foreground default 2 min; `timeout: 0` disables; background has no timeout by default | ✅ Default 1800 s per command, per-call `timeoutSeconds` (0 = none), auto-background after 10 s |
| SH-04 | Background processes & kill | ❌ No background jobs; `&` children block until timeout or are orphaned; timeout kill misses them after the shell exits | 🟡 Background panel manages `/bg` sessions (output, stop, inject); no per-shell-process list or kill | ✅ Background processes with IDs, `/tasks` list/stop, `TaskStop`, Ctrl+B; detached children killed on stop (macOS/Linux) | 🟡 No background jobs natively; abort kills the (detached) process group with 3 s force-kill; 🔌 opencode-pty | ✅ Δ Native background jobs with completion notification; running commands can be moved to background | ✅ Background via `background`/`yieldMs`; `process` poll/log/input/kill; completion wakes the agent |
| SH-05 | Interactive command handling | ✅ Prompts fail fast (no git prompt, askpass off, pager=cat, setsid); pty for TUI-only programs; PtyRun for keystrokes | ❌ No interactive stdin from the browser into a running command | ❌ No way for Claude to drive interactive programs (no TTY, no input channel); user runs them via `!` or their own terminal | ❌ No stdin interaction with running commands (🔌 opencode-pty sends input) | ❌ Δ No stdin interaction with agent commands; opencode-pty is V1-API (user /terminal is interactive) | ✅ PTY mode + `process` input; bundled tmux skill for interactive CLIs |
| SH-06 | OS sandbox for shell (bubblewrap / Seatbelt / containers) | 🟡 Opt-in bubblewrap (`bashSandbox` on/no-network): ro `/`, writable root+/tmp+$TMPDIR, policy paths ro; Linux; Bash only | 🟡 Inherited | ✅ Opt-in OS sandbox (`/sandbox`, `sandbox.enabled`): bubblewrap on Linux/WSL2, Seatbelt on macOS; not native Windows | ❌ No OS sandbox (🔌 opencode-daytona, opencode-devcontainers) | ❌ No OS sandbox (🔌 opencode-daytona, opencode-devcontainers) | ✅ Optional sandbox backends: Docker (default), Podman, SSH, OpenShell, Crabbox; off by default |
| SH-07 | Network restriction for shell | 🟡 The `no-network` sandbox mode removes network for Bash only; no domain allowlist or egress proxy | 🟡 Inherited | ✅ Sandbox routes network through a local proxy with domain allow/deny lists (start empty) | ❌ No network restriction | ❌ No network restriction | ✅ Docker sandbox `network: "none"` by default; secret egress proxy allowlist for gateway exec |
| SH-08 | Output streaming to UI | 🟡 The tool row shows running/done with elapsed time; output arrives only at completion; no live stdout streaming | ❌ Shell output appears only at `tool.finished`; there is no progress/stream event, so long commands look frozen | ✅ Bash output streamed to a working file with live progress in the UI; `!` shell mode shows real-time output | ✅ Output streams live into the tool row | ✅ Output streams live into the tool row | ✅ Partial output streams into the same tool card (TUI, Control UI terminal-style rows) |
| SH-09 | Output truncation | ✅ 64 KiB cap with head/tail markers; overflow spilled to an owner-only file (4 MiB, 7 days) that Read/Grep can open | ✅ 256 KiB per-event cap with a "Load the full output" button paging `tool.output` | ✅ ~30K chars inline; beyond that a saved file path + 2K preview; failures 10K head/tail | ✅ Bounded tail in memory; spills to file past 50 KB and returns tail + 'Full output saved to' | ✅ Bounded tail in memory; spills to file past 50 KB and returns tail + 'Full output saved to' | ✅ Output bounded by live tool-result caps (16k–64k chars by window); Tokenjuice plugin compacts noisy output |
| SH-10 | Dangerous-command detection | ✅ Heuristic layers: rm -rf / or ~ breaker (all modes), ConfirmRemoveHook, protect-files, auto classifier, optional LLM reviewer | 🟡 The server's reason for asking (e.g. destructive pattern) shows on the permission card | ✅ Compound-command splitting per subcommand, command-injection detection, critical-path `rm` guard, auto-mode classifier | 🟡 Commands parsed per sub-command for permission prefixes + external-path checks; no danger classifier (🔌 cc-safety-net) | 🟡 Δ Tree-sitter scanner (or experimental portable scanner) infers commands/dirs; no danger classifier | ✅ LLM exec reviewer (allow/deny/ask + risk), strict inline-eval, executable identity binding, safe bins |
| SH-N1 | 🆕 Child-process environment hardening (credential stripping, setsid detach, non-interactive env) | ✅ All children: setsid detach, non-interactive + credential-stripped env, drained pipes; fd CI gate | ✅ Inherited: every child a served turn spawns goes through ProcessContainment (setsid, non-interactive, credential-stripped env) | ✅ Opt-in `CLAUDE_CODE_SUBPROCESS_ENV_SCRUB` strips credentials from Bash/hooks/stdio MCP envs; sandbox credential masking | 🟡 Commands run detached (own process group), stdin ignored, PowerShell -NonInteractive; no credential stripping | 🟡 Shell children detached (process group); env adjustable via shell.create.before hook; no credential stripping | 🟡 PATH/LD_* overrides rejected, secret-free shell snapshots, private GH_TOKEN, sentinels, owned process groups |
| **PER** | **Permissions & safety** |  |  |  |  |  |  |
| PER-01 | Permission modes (default / acceptEdits / plan / bypass / auto) | ✅ Six modes: default, accept-edits, plan, auto, dont-ask, bypass-permissions; TUI starts in default, -p/background in bypass | ✅ Six modes selectable per session; bypass/dont-ask are refused (-32020) unless `serve --allow-bypass` — but see bg.spawn (P1) | ✅ Six modes: Manual(`default`), acceptEdits, plan, auto, dontAsk, bypassPermissions; auto is the starting mode since v2.1.283 | 🟡 allow/ask/deny rules + `--auto` (approve all not denied); plan agent; no named acceptEdits-style modes. Default: allow | 🟡 Δ Ordered allow/ask/deny `permissions` array + `--auto`; per-project auto-accept (desktop mod+shift+a) | ✅ Session permission modes read-only/guarded/workspace/full; exec modes deny/allowlist/ask/auto/full; elevated |
| PER-02 | Mode toggle in UI (Shift+Tab etc.) | ✅ Alt+M toggles plan; `/permissions mode <name>` switches (bypass warns); status bar shows mode; model gets a system row | ✅ Status-bar select → `session.setMode` for the session's next turns | ✅ Shift+Tab (Alt+M on some Windows) cycles modes; mode indicator in the footer; "Yes, and switch to auto mode" on prompts | 🟡 Palette 'Enable/Disable auto-approve permissions' (muted 'auto' tag); Tab switches build↔plan | 🟡 Δ Per-project auto-accept toggle (desktop mod+shift+a), `--auto` flag; Shift+Tab cycles agents (plan) | ✅ Control UI composer "Execution permissions" picker (applies to the running native turn); `/exec`, `/elevated` |
| PER-03 | Allow / deny / ask rules with patterns (e.g. Bash(git:*)) | ✅ `Tool(arg-glob)` rules, first match wins, shell-aware, symlink-resolved paths, `domain:`; Claude Code `:*` form never matches | 🧪 `permission.rules` (read-only) exists but the web never calls it; `/permissions` prints the gate as text; no rule editor | ✅ allow/ask/deny rules: Bash(npm run *), Read(~/x/**), Edit(/src/**), WebFetch(domain:x), Agent(Explore), Skill(x), MCP | ✅ Per-tool pattern objects (bash parsed commands, file globs, URLs, subagent/skill names), wildcards, last-match-wins | ✅ Δ Ordered {action, resource, effect} rules; shell `x *` also matches bare `x`; last match wins | ✅ tools.allow/deny/alsoAllow with wildcards and groups; exec allowlist + safeBins profiles; MCP server globs |
| PER-04 | Rule scopes (user / project / local / managed / session) | 🟡 Rules only in user tier (config.json/settings.json, `--config`) plus per-session grants saved with the session | 🟡 Rules are user-tier only; `remember: "user"` is not offered over the wire and the Settings page cannot write permission keys | ✅ Managed > CLI flags > local > project > user; session grants; deny at any level wins | 🟡 Remote, global, project, managed, OPENCODE_PERMISSION; 'always' approvals in-memory until restart (not persisted) | ✅ Δ Saved approvals are durable, project-scoped allow rules (v1: in-memory until restart) | ✅ Global, per-provider, per-agent, sandbox gate, per-session mode, per-message `/exec`, host approvals file |
| PER-05 | Per-agent permission overrides | ✅ Presets set `permissionMode` (stricter of preset/session; bypass session passes down) plus `tools`/`disallowedTools` | 🟡 Inherited | ✅ Subagent `permissionMode`, `tools`, `disallowedTools`; skill `allowed-tools`/`disallowed-tools` | ✅ Agent `permission` overrides; subagents inherit parent deny/external_directory rules; task perms per subagent | ✅ Δ Agent permissions appended after global rules; subagents use their OWN rules (v1 inherited parent denies) | ✅ Per-agent tools/sandbox/exec policy; sub-agents hard-denied gateway/cron/message/sessions_send etc. |
| PER-06 | Approval UI options (once / session / always / edit / explain) | ✅ y once, a session (per-part scope), p save to config, e edit scope, r refuse with note, x refuse and stop, n | ✅ Allow once / Always (this session, pattern shown) / Reject / Reject & stop, plus a note; `y`/`a`/`n` on a focused card | ✅ Yes / Yes-don't-ask-again (rule saved) / Yes-and-switch-to-auto / No; Tab adds a comment sent to Claude | ✅ Allow once / Allow always (bash arity prefix, e.g. 'git checkout *') / Reject with feedback; diff; fullscreen | ✅ Δ Allow once / Allow always (saved per project) / Reject (+feedback; cascades to pending) | ✅ allow-once / allow-always (optional expiry for automation grants) / deny via cards, native buttons, `/approve` |
| PER-07 | Auto-mode classifier (AI decides what is safe) | 🟡 auto = regex classifier: security flags ask, others deny (3/20 breaker); LLM reviewer only sees flagged calls; unflagged run | 🟡 `auto` mode is selectable; its security findings arrive as cards without "always" | ✅ Auto mode classifier (server-side review when available) blocks escalation, unknown infra, hostile-content-driven actions | ❌ No AI safety classifier | ❌ No AI safety classifier | ✅ LLM exec auto-reviewer (`tools.exec.mode: auto` or `workspace` permission mode) |
| PER-08 | Protected paths (.git, .env, outside workspace) | ✅ File tools jailed to root; protect-files denies .env/key/.ssh/.aws reads and policy-file writes; Bash itself not jailed | 🟡 Inherited | ✅ Protected paths (.git, .claude, shell rc, .mcp.json…) never auto-approved; critical-path rm unapprovable; dir confinement | 🟡 external_directory and *.env reads default to ask; .git and other paths not protected; edits allowed by default | 🟡 Δ REGRESSION: external_directory allowed by default (v1 asked); only *.env reads ask | 🟡 File tools contained to the session root in read-only/guarded/workspace; apply_patch workspace-only by default |
| PER-09 | Secret redaction / env scrubbing | 🟡 Child env strips *_API_KEY/*_TOKEN/*_SECRET/AWS_*; SecretRedactor used only for memory; outputs and audit log unredacted | ✅ `settings.get` masks secrets, credential-named keys at any depth and URL userinfo; the token never reaches the page | 🟡 `sandbox.credentials` masks env/credential files for sandboxed commands; `CLAUDE_CODE_SUBPROCESS_ENV_SCRUB`; telemetry redaction | 🟡 `export --sanitize` redacts transcripts; no env scrubbing for tools (🔌 opencode-vibeguard, envsitter-guard) | 🟡 `export --sanitize` redacts transcripts; no env scrubbing for tools (🔌 opencode-vibeguard, envsitter-guard) | ✅ Log/transcript redaction always on; secret-free shell snapshots; egress-proxy sentinels; redacted history/share |
| PER-10 | Prompt-injection defenses (web content, tool results) | 🟡 Prompt + WebFetch description call fetched content untrusted; PromptFence escapes injected blocks; MCP output not tagged | ✅ Browser side: Markdown `html:false` + DOMPurify, text interpolation everywhere else, strict CSP; model-side defenses inherited | ✅ WebFetch summarization, classifier sees no tool results, subagent output scanning, pastes marked untrusted, hidden chars cut | ❌ No explicit prompt-injection defenses for web/tool content | ❌ No explicit prompt-injection defenses for web/tool content | ✅ Provenance-tagged context, escaped internal-context delimiters, untrusted-transcript reviewer, dreaming taint gate |
| PER-11 | Workspace-trust dialog | 🟡 No trust dialog: four `trustedProject*` config allowlists plus quarantine of project policy after a config change | ❌ No workspace-trust dialog in the web | ✅ Workspace trust dialog in interactive sessions (keyed on repo root; lists granted rules/dirs); `.mcp.json` approval prompt | ❌ No workspace-trust dialog | ❌ No workspace-trust dialog | ❌ No workspace-trust dialog |
| PER-12 | Skip-all-permissions flag | ✅ `--permission-mode bypass-permissions` / env / config; serve needs `--allow-bypass`; breaker, deny rules, floor still apply | 🟡 Bypass mode only on `--allow-bypass` servers — yet `bg.spawn` (Background panel) runs in bypass anyway (verified, P1) | ✅ `--dangerously-skip-permissions` and `--allow-dangerously-skip-permissions` (adds bypass to the cycle) | ✅ `--auto` (TUI and run) or `permission: allow` config; explicit deny rules still enforced | ✅ `--auto` (TUI and run) or `permission: allow` config; explicit deny rules still enforced | ✅ `full` session mode (admin) / exec `mode: full` — the default for unsandboxed host exec; elevated full |
| PER-13 | Managed / enterprise policy enforcement | ❌ No managed or enterprise policy layer | 🟡 Inherited | ✅ Managed settings (file/MDM/server/`claudeMd`), allowManaged*Only locks, disableAutoMode, availableModels, forceLogin* | ✅ Managed config (/etc/opencode, ProgramData, macOS MDM plist) overrides all; experimental.policies provider.use | ✅ Δ `policies` (top-level, stable) incl. tool.use; Console-managed policies have final authority | 🟡 Policy plugin = conformance/drift detection (doctor --lint, attestation), not runtime enforcement; operator scopes |
| PER-14 | Permission audit log | ✅ AuditHook logs every call/refusal to `$TMPDIR/sugar-crush-audit-<euid>/audit.log` (4 KiB in, 200 B out); never rotated | ❌ No permission audit view | 🟡 OTel `tool_decision` events (classifier verdict/category since 2.1.301) + `/permissions` Recently denied; no local audit log | 🟡 permission.asked/replied bus events + logs; no dedicated audit log | 🟡 permission.asked/replied bus events + logs; no dedicated audit log | ✅ `openclaw audit` + Control UI Run inspector with decision receipts from the audit ledger |
| PER-15 | Remote approval (approve from phone / web / chat) | ✅ serve: `permission.requested` goes to every client, first valid answer wins, re-sent on reconnect, optional timeout | ✅ Answer any session's question from the approvals drawer, a grid tile or a phone; first valid answer wins everywhere | ✅ Remote Control forwards permission prompts and questions to phone/browser with push notifications | ✅ Approve from web UI, desktop, an attached TUI, ACP editor or SDK (POST permission reply) | ✅ Δ Approve from TUI, web/desktop, iOS app via pairing, ACP editors or API | ✅ Approve from chat channels (native buttons or `/approve`), Control UI, macOS/iOS apps, web push |
| PER-N1 | 🆕 Policy self-grant floor (agent cannot edit its own permission / hook / agent config) | ✅ Writes to hooks.yaml/config.json/agents/.git/hooks refused in every mode; others always ask | ✅ Inherited ProtectFilesHook floor on served turns; the Settings page cannot write security-class keys either | ✅ `.claude/` (settings, hooks, agents) and other config paths are protected: never auto-approved; allow rules can't pre-approve | ❌ Agent can edit opencode.json/.opencode (a bundled skill even helps); no self-grant floor | ❌ No self-grant floor by default; admins can hard-deny via policies (tool.use) or Console policies | 🟡 Config/permission changes go through typed, approval-gated `openclaw`/`gateway` operations; no hard self-grant floor |
| PER-N2 | 🆕 Restricted / safe mode flag (drop code-running tools, confine file tools) | 🟡 No --restricted/--safe-mode flag; plan/dont-ask refuse writes and non-read-only shell; disabledTools; project extras need trust | 🟡 Status-bar mode select offers plan and dont-ask; serve refuses bypass/dont-ask without --allow-bypass; no restricted-tools flag | ✅ `--restricted` drops code-running tools, confines file tools; `--safe-mode` disables customizations | 🟡 No safe-mode flag; approximated by the plan agent or OPENCODE_PERMISSION / deny rules (`--pure` only drops plugins) | 🟡 No safe-mode flag; plan agent or ordered deny rules/policies approximate it | ✅ `read-only` session permission mode omits mutation tools and denies exec; `minimal`/`messaging` profiles |
| **GIT** | **Git, checkpoints, review** |  |  |  |  |  |  |
| GIT-01 | Git awareness (branch / status) | ✅ Env block has branch, porcelain status, last 5 commits each render, plus diffs after writes; sessions tagged with branch | ❌ No branch/status display; `files.changed` is unused | ✅ Startup git snapshot in context; footer PR/MR badge with review state; worktree awareness | 🟡 Knows repo yes/no (env) and shows branch in footer; no git status in prompt | 🟡 Knows repo yes/no (env) and shows branch in footer; no git status in prompt | 🟡 Model gets only the repo root; Control UI shows branch, PR chips with CI, session diff |
| GIT-02 | Automatic commits | ✅ Opt-in `autoCommit` off/turn/edit; user dirty changes committed separately first; pathspec-limited; user hooks run | 🟡 Inherited | 🟡 No auto-commits in normal sessions; background sessions and `/batch` units are told to commit/push in their worktrees | ❌ No automatic commits | ❌ No automatic commits | ❌ No automatic commits |
| GIT-03 | Commit-message generation / /commit | 🟡 Auto-commit subjects from the title model (Aider prompt, Conventional Commits, 72 chars) or edit description; no /commit | ❌ No commit command/flow | ✅ Claude writes commits/PRs on request via built-in git instructions + attribution; no built-in /commit (🔌 commit-commands) | ❌ No /commit or message generator built in (bash tool desc gives commit guidelines; custom commands common) | ❌ No /commit or message generator built in (bash tool desc gives commit guidelines; custom commands common) | ❌ No `/commit` or commit-message generator |
| GIT-04 | PR creation / GitHub integration | 🟡 No PR tooling: Bash guidance plus `attribution.pr`, and the model runs `gh` itself | ❌ — | ✅ `gh`-driven PR creation, `--from-pr` resume, `/install-github-app`, `/autofix-pr` cloud watcher, GitHub MCP plugin | ✅ `opencode pr <n>` checks out a PR; GitHub agent opens branches/PRs; bash desc guides gh usage | 🟡 Δ No `opencode pr` or `github` commands in the v2 CLI; agent uses gh via shell | ✅ Control UI "Publish PR" and `github_publish` tool via a Gateway publication broker with managed `gh` identities |
| GIT-05 | Checkpoints of file edits | ✅ Checkpoint before every user turn (not every edit): `git stash create` into private refs, or a shadow repo outside git | 🟡 Checkpoints are server-side; `/undo` `/redo` reachable by typing | ✅ Automatic checkpoint before each turn-starting prompt for files changed by edit tools (100 kept, survive resume) | ✅ Shadow-git snapshot before/after every step; per-step patch parts list changed files | ✅ Δ Snapshot before model call and at step end, scoped to the active directory; packed object store | 🟡 Managed worktrees snapshot tracked + untracked files before removal; cloud sessions keep accepted-change checkpoints |
| GIT-06 | Undo / redo / rewind code | ✅ `/rewind [n] --chat/--files/--both`, `/undo` (last turn, or the last auto-commit under Aider's refusals), `/redo` | 🟡 `/undo`, `/redo`, `/rewind` via slash text; no buttons | ✅ `/rewind` (aliases `/undo`, `/checkpoint`) restores code, conversation or both | ✅ /undo /redo and timeline revert restore files (git projects only); unrevert supported | ✅ Δ Staged rollback (/undo) committed on submit, /redo cancels; rejects while running | 🟡 No code undo: Rewind/Fork are context-only; `openclaw worktrees restore` restores a removed worktree snapshot |
| GIT-07 | Snapshot mechanism (shadow git etc.) | ✅ Stash-shaped commits pinned at `refs/sugar-crush/checkpoints/…`; shadow repo under `~/.sugar-crush/checkpoints`; hardened git | 🟡 Inherited | ✅ File snapshots stored by CC (`~/.claude/file-history`), not git commits; swept with retention | ✅ Shadow git dir under data/snapshot/<project>/<hash>, alternates to repo objects, write-tree, gc 7 days, skip >2 MB | ✅ Δ Separate internal git object DB, crash/concurrency-safe capture, background packing; `snapshots` key | 🟡 Git-based snapshots only for managed worktrees and cloud checkpoints; no shadow-git per turn |
| GIT-08 | Worktree support | 🟡 Sub-agent presets with `isolation: worktree` get their own git worktree (removed if unused); no main-session worktree | 🟡 Inherited | ✅ `--worktree [name]` (+`--tmux`), `EnterWorktree`/`ExitWorktree`, subagent isolation, background-session worktrees | 🟡 Experimental worktree/workspace API + /workspaces, /warp; desktop uses worktrees; 🔌 opencode-worktree | ✅ Δ Built-in worktree tools + /worktrees, /cd, session move | ✅ Managed worktrees (CLI, New Session toggle with base-branch picker, `sessions_spawn worktree:true`) |
| GIT-09 | Diff review UI (session diff) | 🟡 `/diff [n]` posts a fenced unified diff vs a checkpoint (2000 lines / 256 KiB); edit diffs in tool rows; no review pane | 🧪 Per-call diffs render; the session-wide `files.diff` / `files.changed` methods exist but are unused; `/diff` prints text | ✅ `/diff` panel (fullscreen) or dialog: per-file stats, per-turn views T1/T2…, base cycling, "ask" attaches a diff to the prompt | ✅ /diff viewer (file tree, git or session mode, split/stacked) + sidebar Modified Files with +/- counts | ✅ Δ /diff opens docked beside the session (f toggles full screen); desktop per-turn changed files | ✅ Control UI session diff panel: per-file unified/split with highlighting, all/uncommitted/per-commit views |
| GIT-10 | Code-review command (/review, security review) | ❌ No built-in `/review` or security review; COMMANDS.md shows a user-written review.md example | ❌ No review command | ✅ `/code-review [effort] [--fix/--comment]`, `/code-review ultra` (cloud multi-agent), `/security-review`, `/simplify` | 🟡 /review (subtask) for uncommitted/commit/branch/PR; no security-review command | 🟡 Δ /review now runs in the current session (no longer a subtask); no security review | 🔌 Code review only via `/codex review` (`@openclaw/codex` harness plugin) or skills |
| GIT-11 | CI integration (GitHub Actions / GitLab) | ❌ No GitHub Action or CI recipe; only headless `-p`, which defaults to bypass | ➖ — | ✅ GitHub Actions (`anthropics/claude-code-action`), GitLab CI/CD, cloud-provider auth for Actions | ✅ GitHub Action anomalyco/opencode/github (`opencode github install`); GitLab CI component + GitLab Duo | 🟡 Δ GitHub Action (repo github/action.yml) still installs v1 and runs `opencode github run`; no v2 CI docs | 🟡 No official GitHub Action/GitLab recipe; `agent exec` is CI-friendly; UI shows GitHub Actions steps |
| GIT-12 | PR comment / issue-triage bot | ❌ No PR-comment or issue-triage bot | ➖ — | ✅ `@claude` in PR/issue comments (Action), Code Review app (inline comments, Team/Enterprise), `/autofix-pr` | ✅ /opencode or /oc mentions in issues/PR (incl. line comments); auto PR review & issue triage workflows | 🟡 Δ Issue/PR comment bot only via the V1-based GitHub Action | ✅ Bundled `gh-issues` skill: fetch issues, spawn fix agents, open PRs, process PR review comments |
| **UI** | **TUI / GUI UX** |  |  |  |  |  |  |
| UI-01 | Full-screen TUI vs inline rendering | ✅ Full-screen alt-screen "pane shell" (menu bar, docked panes, chat frame, status bar); no inline mode | ➖ Not a terminal: a browser SPA with a tabs view and a tiled grid view | ✅ Classic inline renderer or fullscreen alt-screen (research preview; default for users first on CC after May 6 2026) | ✅ Full-screen OpenTUI app by default; `--mini` / `run -i` inline split-footer mode writing to native scrollback | ✅ Δ Full-screen TUI by default; `opencode mini` inline interface | ✅ TUI renders inline into the terminal main screen + scrollback (pi-tui differential renderer); full web Control UI |
| UI-02 | Themes (count) & custom themes | 🟡 6 built-in themes (dark, light, dracula, tokyoNight, ansi, adaptive); no custom theme files | 🟡 auto / dark (TokyoNight) / light only; no custom themes; the TUI's theme set is not mirrored | ✅ 6 built-in themes + `auto` (dark, light, daltonized ×2, ANSI ×2) and custom JSON themes in `~/.claude/themes/` or plugins | ✅ 33 built-in themes + adaptive 'system'; custom JSON themes in ~/.config/opencode/themes and .opencode/themes | ✅ 33 built-in themes + adaptive 'system'; custom JSON themes in ~/.config/opencode/themes and .opencode/themes | ✅ Control UI: 11 built-in themes + plugin themes + agent-created personal themes (`theme` tool) + tweakcn import |
| UI-03 | Markdown rendering | ✅ Assistant Markdown rendered by sugar-markdown (a glamour port): wrapped to pane width, streamed section by section, OSC 8 links | ✅ markdown-it (raw HTML off, linkify) + DOMPurify; tables, blockquotes, code blocks with a language label, links open noopener | ✅ Markdown rendering in the terminal (VS Code panel adds LaTeX in 2.1.301) | ✅ Markdown rendering with code-block conceal toggle (<leader>h) | ✅ Markdown rendering with code-block conceal toggle (<leader>h) | ✅ TUI Markdown with hyperlinks; Control UI rich Markdown, tables, Mermaid, collapsible details, JSON viewer |
| UI-04 | Syntax highlighting | 🟡 Regex highlighter in code fences: 4 token classes (comment/string/number/keyword), 8 languages | ✅ Lazy 5 KB dependency-free highlighter: 7 language families (~60 aliases), text-in/escaped-out, 20,000-char cap | ✅ Syntax highlighting of code blocks (toggle with Ctrl+T inside `/theme`) | ✅ Tree-sitter syntax highlighting in code blocks and diffs | ✅ Tree-sitter syntax highlighting in code blocks and diffs | ✅ Syntax highlighting for code blocks, shell commands and diffs (Control UI); TUI markdown code highlighting |
| UI-05 | Diff rendering (side-by-side / unified) | 🟡 Unified diff with old/new line-number gutter and +/- colour for Edit/Write results; no side-by-side; no diff in approval prompt | 🟡 Unified diff with old/new line numbers and +/− stats; no side-by-side | 🟡 Unified diffs (word-level) in edit prompts and `/diff`; side-by-side only via IDE/Desktop native diff viewers | ✅ Diffs auto split/stacked by width (`diff_style`), line numbers, add/remove backgrounds | ✅ Diffs auto split/stacked by width (`diff_style`), line numbers, add/remove backgrounds | ✅ Control UI unified and split diffs (session diff + inline edit/write diffs); TUI edit cards show diffs |
| UI-06 | Multiline input | ✅ Multiline composer: Alt+Enter (Shift/Ctrl+Enter where reported), wraps CJK/emoji correctly, Kitty keyboard protocol negotiated | ✅ Textarea: Enter sends, Shift+Enter newline, IME-safe | ✅ Multiline via `\`+Enter, Shift+Enter (native in many terminals, `/terminal-setup` for others), Ctrl+J, Option+Enter | ✅ Multiline input (shift/ctrl/alt+enter, ctrl+j) with readline-style editing and undo/redo | ✅ Multiline input (shift/ctrl/alt+enter, ctrl+j) with readline-style editing and undo/redo | ✅ TUI Shift+Enter / Ctrl+J newline; Control UI configurable send shortcut |
| UI-07 | Vim mode | ❌ No vim mode in the chat composer; sugar-forms' Vim key handler exists only for TextInput | ❌ — | ✅ Rich vim mode (motions, text objects, visual mode, `.` repeat, `%` in 2.1.301, `jj`-style remaps) | ❌ No vim mode | ❌ No vim mode | ❌ No vim mode |
| UI-08 | External editor ($EDITOR) | ✅ `/editor [text]` composes the prompt in $VISUAL/$EDITOR; the settings view's `e` opens config files there too | ❌ No external-editor hand-off (a browser cannot spawn $EDITOR) | ✅ Ctrl+G / Ctrl+X Ctrl+E opens the prompt (or plan) in $EDITOR; optional last-response context | ✅ /editor (<leader>e) composes in $EDITOR; /export opens transcript in $EDITOR | ✅ /editor (<leader>e) composes in $EDITOR; /export opens transcript in $EDITOR | ❌ No $EDITOR compose in the TUI (Ctrl+G is the agent picker) |
| UI-09 | Input history & search (Ctrl+R) | 🟡 ↑/↓ on empty box recalls prompts across sessions (`prompt_history.jsonl`); no reverse/incremental history search | ❌ No ↑ prompt history or Ctrl+R; only a persisted per-session draft | ✅ Per-directory history (Up recalls past sessions), Ctrl+R reverse search; fullscreen dialog with session/project/all scopes | 🟡 Up/down prompt history (state/prompt-history.jsonl) and prompt stash; no Ctrl+R search | 🟡 Up/down prompt history (state/prompt-history.jsonl) and prompt stash; no Ctrl+R search | 🟡 TUI up/down input history, no Ctrl+R search; Control UI ⌘F transcript search and ⌘K thread search |
| UI-10 | @file mention autocomplete / fuzzy file picker | 🟡 `@path` mentions with shell-style Tab completion plus `@diff`, `@session:`, `@https://`, `$skill`; no popup or fuzzy picker | 🟡 `@path` mentions are resolved server-side when sent, but there is no autocomplete or file picker | ✅ `@` fuzzy file/folder autocomplete, MCP resources, agents, and other live sessions | ✅ @ fuzzy picker (frecency) for files/dirs, subagents, MCP resources, configured references | ✅ Δ @ picker: files (with #start-end ranges), skills, agents, references | ✅ TUI `@` fuzzy file completion (fd-backed); Control UI clickable file references and Files panel |
| UI-11 | Large-paste collapse | ❌ No large-paste collapse: a 300-line bracketed paste goes verbatim into the box, which grows to fill the whole chat pane | ❌ Large pastes are neither collapsed nor summarised | ✅ Pastes >800 chars or >3 lines collapse to `[Pasted text #N +X lines]`, sent in full and marked as pasted (untrusted) content | ✅ Large pastes collapsed into a summary token (toggle app_toggle_paste_summary) | ✅ Large pastes collapsed into a summary token (toggle app_toggle_paste_summary) | ✅ TUI collapses big pastes to `[paste #N +X lines]`; Control UI turns pasted text into "Pasted text" chips |
| UI-12 | Image paste / drag-drop | ✅ Ctrl+V attaches the clipboard image (pngpaste/wl-paste/xclip); dropping a file path onto the terminal becomes an @mention | ❌ No image paste or drag-drop | ✅ Image paste (Ctrl+V / Cmd+V iTerm2 / Alt+V Windows) as `[Image #N]` chips; dragged image paths | ✅ ctrl+v pastes clipboard images (macOS/Windows/Linux helpers); file paths as attachments | ✅ ctrl+v pastes clipboard images (macOS/Windows/Linux helpers); file paths as attachments | ✅ Control UI paste/drop images, Take photo, attachments; channels accept media; TUI only previews images |
| UI-13 | Message queueing while agent busy | ✅ Mid-turn Enter steers at the next step; Tab queues for after the turn; `queueMode` steer/followup/interrupt | ✅ Mid-turn: queue / steer / interrupt selector (default = server `queueMode`, steer unless set); queue strip with remove | ✅ Messages queue while Claude works (gray until read); delivered between tool calls in the same turn; Ctrl+Enter sends now | ✅ Messages typed while busy are queued (QUEUED badge) and picked up at the next step | ✅ Δ Enter while busy STEERS the active turn (default); ctrl+x Enter queues for later | ✅ Queue modes steer/followup/collect/interrupt (`/queue`, debounce, cap, summarize); Control UI durable editable queue |
| UI-14 | Interrupt (Esc) / mid-turn steering | ✅ Esc = soft stop (cancel running tool, stop after step); Esc Esc within 0.6 s = hard cancel; mid-turn steering | ✅ Stop button or Esc Esc = hard cancel; steer and interrupt deliveries | ✅ Esc interrupts (work kept, queued messages sent next); typing mid-turn steers without stopping | ✅ Esc interrupts; queued messages steer mid-turn because the loop reloads history each step | ✅ Δ Interrupt + steer by default; interrupt keeps pending input | ✅ Esc aborts in TUI; `/stop` and Control UI Stop (cancels descendants); `/steer` injects at the next tool boundary |
| UI-15 | Edit / resubmit an earlier message | 🟡 `/undo` restores the last prompt into the box; `/rewind [n]` and `/redo`; no pick-a-message-and-edit UI | ❌ Cannot edit/resubmit an earlier prompt | ✅ Rewind to an earlier prompt puts it back in the input for editing and resending (optionally restoring code) | 🟡 /undo puts the reverted prompt back in the input to edit/resubmit; no in-place edit of old messages | 🟡 /undo puts the reverted prompt back in the input to edit/resubmit; no in-place edit of old messages | ✅ Control UI rewind returns a message to the composer for edit + resend; Fork from here; queued items editable |
| UI-16 | Copy message / code block to clipboard | 🟡 Mouse drag-select in the transcript copies (OSC 52 plus tmux/pbcopy/wl-copy/xclip); Ctrl+C copies a draft selection | ❌ No copy buttons on messages or code blocks; native selection only (and the live reply is re-rendered every frame) | ✅ `/copy [N]` with a code-block/quote picker (`w` writes to a file over SSH); `/btw` `c` copy; in-app selection copies on release | ✅ <leader>y copies last reply, /copy transcript, copy-on-select with mouse | ✅ <leader>y copies last reply, /copy transcript, copy-on-select with mouse | ✅ Control UI copy for messages, code blocks, tables (TSV), images, session link/Markdown/ID, errors |
| UI-17 | Mouse support (click, scroll, select) | ✅ Mouse on by default: wheel scroll, click to expand rows / open agents / switch tabs, drag-select, drag-to-dock and resize panes | ✅ Native browser mouse/touch: click, scroll, select | ✅ Fullscreen: click to place cursor, pick menu options, expand tool results, open links/files, wheel scroll, scrollbars | ✅ Mouse click, scroll, selection (tui `mouse`, OPENCODE_DISABLE_MOUSE) | ✅ Δ Mouse + clickable OSC 8 hyperlinks (FORCE_HYPERLINK=1) | ✅ Control UI full mouse/touch (drag sessions into split panes, dividers, hovercards); TUI keyboard-first |
| UI-18 | Scrollback / transcript viewer | ✅ In-app scrollback with wheel / PgUp / PgDn and a `↑ 15/215 scrolled` readout; no transcript search | ✅ Virtualised transcript (@tanstack/vue-virtual) that follows the bottom until the reader scrolls up | ✅ Ctrl+O transcript viewer (full tool I/O, timestamps, models); `{`/`}` prompt jumps, `/` search, `[` dump to native scrollback | ✅ Scrollable transcript with page/half/line keys, first/last, jump-to-message /timeline | ✅ Scrollable transcript with page/half/line keys, first/last, jump-to-message /timeline | ✅ TUI loads 200 history entries; Control UI pages back up to 1,000 messages per request with prefetch |
| UI-19 | Sidebar / panels (files, todos, agents, LSP, MCP) | ✅ Six dockable panes (Files, Tools, Skills, Agents, Settings, Todo), docked left or right, stacked, drag-resizable, persisted | ✅ Side panels: Agents, Todo, Background, Workflows, Memory (drawer, or its own column on wide screens) | 🟡 Diff panel (fullscreen, wide terminals), subagent/fork panel below prompt, Ctrl+T task list, `/btw` overlay; mods can add panes | ✅ Sidebar (<leader>b): context, MCP, LSP, todo, modified files; plugins can add via slots | 🟡 Δ Sidebar: context + MCP only (no LSP, todo or modified-files panels); diff viewer separate | ✅ Control UI sidebar (agents, groups, people) + side panel tabs: Files, Review, Browser, Terminal, Desktop, Side chat, Dashboard |
| UI-20 | Todo-list display | ✅ Todo pane: ☑ done / ▸ in progress / ☐ pending / ☒ cancelled, done count in the title, updated live, kept in session metadata | ✅ Todo panel, live | 🟡 Ctrl+T shows up to 5 checklist items in the status area — only when task tools are enabled (older models or opt-in) | ✅ Todo list rendered in sidebar from todowrite | ❌ Δ No todo list (no todo tool) | ✅ `progress_card` shown as a durable plan/status card in the Control UI; goal state in the TUI footer |
| UI-21 | Tool-call display (collapsible, running → done) | ✅ Tool cards stream live (running → done/✗/denied), collapsed by default, Ctrl+O/click expands, Edit shows a diff, Task a live line | ✅ Tool cards: icon, name, key argument, +/− stats, duration; collapsed on success, open on error/refusal | ✅ Tool calls render as rows that collapse when done (e.g. "Called slack 3 times"); click/Ctrl+O expands; `/focus` one-line summaries | ✅ Inline tool rows with spinner→done icons, collapsible details, per-tool renderers | ✅ Inline tool rows with spinner→done icons, collapsible details, per-tool renderers | ✅ TUI tool cards (streaming, Ctrl+O expand); Control UI kind-aware rows, grouped logs, "Worked for … · N tool calls · M failed" |
| UI-22 | Thinking display toggle | ✅ Thinking streams live (`💭 Thinking…`), then folds into a collapsed `💭 Thought ▸ N lines` row; click or Ctrl+O toggles per row | 🟡 Reasoning folds open/close individually; no global show/hide thinking toggle | ✅ Thinking hidden by default, Ctrl+O reveals; Alt+T toggles thinking (older models) | ✅ /thinking toggles reasoning display | ✅ Δ cli.json `session.thinking: show/hide` (no /thinking command) | ✅ TUI Ctrl+T thinking visibility and /settings; `/reasoning`; Control UI "Keep commentary" |
| UI-23 | Status line (customizable / scriptable) | 🟡 `statusLine` setting runs a user command and appends its sanitised output; runs synchronously on the TUI thread | 🟡 Fixed status bar (state + step, context, spend, model, mode, connection); not customisable | ✅ Script-driven status line (JSON on stdin: model, cost, context %, cache, rate limits, git, session); `/statusline` generator | 🟡 No scriptable status line; TUI plugins can render into slots (app_bottom, sidebar_footer, prompt_right) | 🟡 Δ No scriptable status line; CLI plugins (cli.json `plugins`) can extend the TUI | 🟡 Fixed TUI status line + footer; per-reply usage footer is templatable (`messages.usageTemplate`, live-reloaded JSON) |
| UI-24 | Cost / token display | ✅ Status bar: `~81K / 131K context (62%, −52K pruned)`, `98% cache`, provider-reported `$` spend and cap; /context and /budget | ✅ Context tokens/% and session spend in the status bar; spend per tile in the grid | ✅ `/usage` cost/limits/stats; live output-token count beside spinner; verbose context counter; status-line cost fields | ✅ Sidebar tokens/%/$; subagent footer cost; `opencode stats` | ✅ Sidebar tokens/%/$; subagent footer cost; `opencode stats` | ✅ `/usage off/tokens/full/cost` footers, TUI token counts, Control UI context ring with plan-usage bars, Usage page |
| UI-25 | Keybinding customization | ❌ No keybinding customisation: `KeyBindingRegistry` is hard-coded display data; behaviour lives in Chat::update()/KeyboardHandler | ❌ No keybinding customisation | ✅ `~/.claude/keybindings.json` (contexts, chords, unbind, JSON schema), hot-reloaded; `/keybindings` | ✅ tui.json keybinds (~150 actions, leader key, multi-binding objects, 'none' to disable) | ✅ Δ Keybinds in global cli.json (migrated from tui.json) | 🟡 Only the Control UI send-shortcut and follow-up default are configurable; no keybinding file |
| UI-26 | Notifications (bell / desktop / when done) | ✅ `notify`: off (default), `bell` (BEL) or `osc9` desktop notification when a turn ends or a permission question waits | ✅ Opt-in desktop notifications for questions and finished turns out of sight, tab-title count; needs a secure context | ✅ Desktop notifications (Ghostty/Kitty/iTerm2), terminal bell option, Notification hooks, OSC 9/99/777, OSC 7501 status | ✅ Opt-in `attention`: desktop notification when terminal blurred + sounds for done/permission/question/error | ✅ Δ cli.json `attention` (notifications, sounds) + terminal program-status notifications | ✅ Browser Web Push (approvals, mentions, attention), native macOS notifications, mobile push |
| UI-27 | Terminal image rendering (sixel / kitty / iTerm2) | ✅ Inline images via sugar-image: kitty > iTerm2 > sixel > chafa > half-block, with `/rendermode` to switch | 🟡 N/A as terminal graphics; the browser renders media-store image/video artifacts inline (blob URLs, lightbox) | 🟡 No inline image rendering of conversation content; mods' `Image` elements use kitty graphics protocol when detected | ❌ No inline image rendering (sixel/kitty graphics); only kitty keyboard protocol | 🟡 Δ TUI diff viewer renders changed PNG/JPEG/WebP/GIF via OpenTUI image element (terminal-dependent) | ✅ TUI inline image previews on Kitty/iTerm2/Ghostty (static thumbnails ≤60×20 cells); off in tmux/screen; no sixel |
| UI-28 | Help screen / shortcuts overlay | ✅ `?` on an empty box (or `/keys`) opens a scrollable shortcut reference built from the live key registry; `/help` lists commands | ❌ No help screen or shortcuts overlay | ✅ `?` on empty input toggles a shortcut panel; `/help`; `/powerup` interactive lessons | ✅ /help dialog and which-key overlay (ctrl+alt+k) listing bindings | ✅ /help dialog and which-key overlay (ctrl+alt+k) listing bindings | ✅ Control UI shortcuts overlay (⌘/ or Ctrl+/); `/help`, `/commands` |
| UI-29 | Onboarding / first-run setup | 🟡 No setup wizard: with no provider it runs on the offline echo backend and posts a notice pointing at sugarcrush doctor and Ctrl+P | 🟡 Sign-in page explains code vs token; no provider/first-run setup | ✅ First run: theme pick, login (or provider wizard), trust dialog; fullscreen offer; LSP/plugin recommendation dialogs | ✅ /connect provider picker (API key or OAuth); OpenCode Zen free models; home screen tips | ✅ /connect provider picker (API key or OAuth); OpenCode Zen free models; home screen tips | ✅ `openclaw onboard` wizard (auto-launched by installer), Claude/Codex/Hermes import flow, BOOTSTRAP ritual |
| UI-30 | Accessibility (screen reader, reduced motion, colour-blind) | 🟡 NO_COLOR, WCAG-projected theme contrast, `SUGARCRUSH_REDUCED_MOTION`, mouse opt-out, i18n strings; no screen-reader mode | 🟡 Dialog/tab roles, focus rings, prefers-reduced-motion; but low-contrast tokens and a chatty aria-live streaming reply | ✅ Screen-reader mode (flat text, numbered menus, bell cues), reduced motion, daltonized themes, magnifier-friendly cursor | 🟡 Animation toggle, mouse disable, system theme; no screen-reader or reduced-motion mode | 🟡 Animation toggle, mouse disable, system theme; no screen-reader or reduced-motion mode | ✅ Beacon WCAG AAA theme (Atkinson Hyperlegible), reduced motion/transparency, forced colors, screen-reader labels |
| UI-31 | Spinner / progress / elapsed time | 🟡 Main turn shows a **static** `⠴` glyph plus `step N`; sub-agent lines animate with tool count, elapsed, tokens | 🟡 Spinning ◌ on running cards, "working · step n/m", per-call duration; no elapsed-time counter | ✅ Spinner with rotating verbs/tips, elapsed time and live token count; customizable verbs/tips | ✅ Spinners, background pulse, per-message and subagent durations, retry status | ✅ Spinners, background pulse, per-message and subagent durations, retry status | ✅ TUI run-state line (connecting/running/streaming/idle/error); Control UI working indicator, durations, running-tasks indicator |
| UI-32 | Compact / verbose transcript toggle | 🟡 `expandToolOutput` setting (live) shows all tool output in full; Ctrl+O toggles or walks rows; no one-key verbose/compact toggle | ❌ No global compact/verbose transcript toggle | ✅ Ctrl+O verbose transcript, `/focus` (fullscreen) compact view, `verbose` setting / `--verbose` | 🟡 Separate toggles for tool details, generic tool output, timestamps, concealment; no single compact mode | ✅ Δ `session.verbosity` low/high groups tools, thoughts and instruction loads into summaries | ✅ `/verbose on/full/off`, TUI Ctrl+O, Control UI collapsed "Worked" groups |
| UI-33 | Startup banner content (model, cwd, loaded files, tips) | ❌ No startup banner: a fresh launch shows only `_(empty conversation — …)_` (with literal underscores) | 🟡 Dashboard: served root, open-question count, start button, recent sessions, web/server versions | ✅ Welcome banner (art, version, model + effort, account/provider, cwd), what's-new/tips, company announcements, startup warnings | 🟡 Home: logo, prompt (agent·model·variant), tips, footer dir:branch, MCP count, version; no loaded-files list | 🟡 Δ Home shows release highlights once after update (/highlights); still no loaded-files list | 🟡 TUI header shows connection URL, agent and session only; no loaded-files/tips banner |
| UI-34 | Prompt suggestions / tips | ✅ After each turn the empty box shows a model-generated grey suggestion of your next message (→ accepts) | ❌ No prompt suggestions/tips | ✅ Example prompt from git history; next-prompt suggestions after replies (Tab accepts); opt-in "You should know" notes | ✅ ~60 rotating home tips (<leader>h) and prompt placeholders | 🟡 Δ Rotating home tips removed; release highlights shown once after updates (/highlights) | 🟡 `suggest_task` follow-up chips, ClawHub recommendation cards; no rotating prompt tips |
| UI-N1 | 🆕 Contrast-aware themes (colours adjusted against the detected terminal background) | ✅ Every theme colour is projected against the detected terminal background to clear WCAG contrast | ❌ Fixed auto/dark/light token sets with no background-aware adjustment; several pairs miss WCAG AA (dark muted on panel 3.87:1) | 🟡 `auto` theme follows the terminal's light/dark background; daltonized and ANSI-palette themes; no per-colour contrast tuning | 🟡 The `system` theme derives greys from the detected terminal background/palette; other themes are fixed | 🟡 `system` theme generated from terminal palette with dark/light detection; other themes fixed | 🟡 TUI auto-detects light/dark background and keeps body text in the terminal's default foreground |
| UI-N2 | 🆕 Menu bar (F10-style menus / clickable chrome) | ✅ F10 menu bar (Session / Model / App / Appearance / Agents) plus clickable, draggable pane tabs | ✅ Native clickable chrome: top bar, session sidebar, tabs/grid, side-panel tabs, Ctrl+K palette; no dropdown menu bar | ❌ No menu bar in the TUI (slash menu and dialogs only); the Desktop app is the GUI alternative | 🟡 No TUI menu bar; desktop app has native menus | 🟡 No TUI menu bar; desktop app has native menus and clickable chrome | 🟡 No TUI menu bar; the Control UI and macOS app provide fully clickable chrome and native menus |
| UI-N3 | 🆕 In-UI media generation (image / video generation forms inside the client) | ✅ Ctrl+D image-generation form (txt2img/img2img/inpaint/video tabs) and full-screen `/play` video player | 🧪 Generated images/videos render as MediaCards, but there is no generate form: `generate.submit/status` and canGenerate* are unused | ❌ No in-client image/video generation | ❌ No in-UI media generation | ❌ No in-UI media generation | 🟡 No generation forms; images/video/music are generated through chat tools and shown inline (Control UI, TUI previews) |
| UI-N4 | 🆕 Session recap after inactivity (one-line "where were we" summary) | ❌ No inactivity recap; closest are the next-message suggestion, session-picker previews and `/handoff` summaries on demand | ❌ No recap after inactivity; grid tiles show a live 2 s narration tail of running turns, not a summary | ✅ one-line recap after ≥3 min unfocused (3+ turns); `/recap` on demand; 400-char cap | ❌ No inactivity recap | ❌ No inactivity recap (release highlights only) | ✅ Control UI Activity shows rolling 1–3 sentence recaps per session ("what was done and where it stands") |
| UI-N5 | 🆕 Prompt hygiene (strip invisible Unicode before sending; spellcheck) | 🟡 Paste boundary strips escapes and C0/C1 controls; bidi/zero-width chars in the draft show as <U+XXXX> but are sent; no spellcheck | 🟡 Browser-native spellcheck on the composer textarea (no spellcheck attr set); invisible Unicode is neither stripped nor marked | ✅ strips invisible Unicode before sending; optional aspell/hunspell spellcheck underline | ❌ No invisible-Unicode stripping or spellcheck of prompts found | ❌ No prompt invisible-Unicode stripping or spellcheck found | ❌ User prompts are not scrubbed of invisible Unicode and there is no spellcheck feature |
| UI-N6 | 🆕 Cross-session attention model (server-wide approvals inbox, badge counts, title/desktop notifications) | 🟡 TUI: `notify` bell/OSC 9 on turn end or waiting question; one live session per window; no cross-session approvals inbox or badges | ✅ approvals drawer + ⚠ count + "(N) SugarCrush" title + sidebar/tab badges | ✅ Agent view (research preview) counts sessions needing input/working/done and notifies on needs-input/finish/fail | 🟡 Per-session attention (sounds/notifications); child permission asks surface in the parent; no server-wide inbox | 🟡 Tabs show status/unread; terminal program-status reports sessions awaiting permission; no approvals inbox | ✅ Approvals page + attention chips, unread markers incl. child failures, running-task indicator, web/macOS push |
| **WEB** | **Web, remote, multi-surface** |  |  |  |  |  |  |
| WEB-01 | Web UI | ✅ `serve` hands out the sugar-crush-web bundle on the same port as the API/WS (suggest package; `--web-root`, `--no-web`) | ✅ The web UI itself: multi-session dashboard served by `serve` on the API port | ✅ claude.ai/code web UI for cloud sessions and for Remote Control of local sessions | ✅ `opencode web` serves the embedded web app (sessions, server picker, terminal panel) | ✅ Δ Web UI from the same server, password-protected by default, opened via `opencode pair`; installable PWA | ✅ Control UI (Vite + Lit SPA on the Gateway port): chat, panes, sessions, agents, settings, usage, automations, plugins |
| WEB-02 | Server mode (HTTP / WebSocket API) | ✅ HTTP+WS JSON-RPC 2.0 `sugarcrush.v1`: 62 methods, 34 events, generated schema, validated params, idempotency, seq replay | ✅ Speaks the server's HTTP + WS JSON-RPC API; uses 45 of 62 methods | 🟡 No self-hostable HTTP/WebSocket server for the CLI; SDK uses stdio stream-json; remote access relays through Anthropic | ✅ HTTP OpenAPI 3.1 + SSE event stream; experimental websockets flag | ✅ Δ Shared background service (`opencode service`) or foreground `serve`; new v2 API | ✅ Gateway WebSocket RPC (versioned, scoped) + OpenAI-compatible and OpenResponses HTTP APIs + tools-invoke HTTP |
| WEB-03 | Multiple clients attached to the same session | ✅ Many clients per session (≤50 subscribers): browsers, `sugarcrush attach`, scripts; first valid permission answer wins | ✅ Many tabs/devices/attach on one session; deltas, cards and questions mirrored; first answer closes the card everywhere | ✅ Remote Control keeps terminal, browser and phone in sync on the same local session, all able to send messages | ✅ Any number of TUIs/web/desktop/SDK clients attach to one server and share live session state | ✅ Any number of TUIs/web/desktop/SDK clients attach to one server and share live session state | ✅ Many clients on one session (Control UI tabs, TUI, apps, channels) with live sync and presence |
| WEB-04 | Remote control from phone / browser | 🟡 Remote use via SSH tunnel or TLS reverse proxy (documented); `--allow-remote` direct bind is cleartext; no built-in TLS | 🟡 Usable from a phone through an SSH tunnel or TLS proxy, but at 375 px the top bar overflows (scrollWidth 465, verified) | ✅ Remote Control (`/rc`, `--remote-control`) from claude.ai/code or the mobile app; requires claude.ai subscription | 🟡 LAN access via --hostname 0.0.0.0/mDNS + basic auth; phone use via community Portal/CodeNomad | ✅ Δ `opencode pair --remote` exposes the server via OpenTunnel (E2E encrypted, password); QR for phones | ✅ Remote via Tailscale, stable HTTPS URL, Cloudflare Access, trusted proxy; `tui --url`; phone apps; chat apps |
| WEB-05 | Desktop app | ❌ No desktop app | ❌ No desktop app | ✅ Claude Desktop Code tab on macOS, Windows, Linux (beta): parallel sessions, diff review, terminal, previews, computer use | ✅ OpenCode Desktop (Electron) with bundled server sidecar | ✅ OpenCode Desktop (Electron) with bundled server sidecar | ✅ macOS menu-bar app (bundled gateway), Windows Hub (WinUI), Linux companion |
| WEB-06 | VS Code extension | ❌ No VS Code extension; editor support is via ACP-capable hosts only | ➖ Editor integrations are the server's `acp` mode, not the web | ✅ VS Code extension (chat panel or terminal mode, inline diffs, plugins UI, /btw panel, Chrome) | ✅ VS Code extension (sst-dev.opencode, auto-installs) for VS Code/Cursor/Windsurf/VSCodium | ✅ VS Code extension (sst-dev.opencode, auto-installs) for VS Code/Cursor/Windsurf/VSCodium | ❌ No VS Code extension |
| WEB-07 | JetBrains plugin | 🟡 JetBrains IDEs via `sugarcrush acp` per docs (not exercised in this audit) | ➖ As WEB-06 | ✅ JetBrains plugin (runs the CLI in the IDE terminal; diff viewer, selection sharing, diagnostics) | ✅ JetBrains IDEs via ACP (acp.json agent_servers) | ✅ JetBrains IDEs via ACP (acp.json agent_servers) | ❌ No JetBrains plugin |
| WEB-08 | Other editors (Zed, Neovim, Emacs via ACP etc.) | ✅ `sugarcrush acp`: Zed / Neovim (ACP plugin) as editor clients; sessions appear in `session list` and reopen in the TUI | ➖ As WEB-06 | 🟡 Other editors only via their terminals (`/terminal-setup` covers Zed, Alacritty, etc.); no ACP/Neovim/Emacs integrations | ✅ Zed (ACP registry), Avante.nvim, CodeCompanion.nvim; community opencode.nvim, Obsidian | ✅ Zed (ACP registry), Avante.nvim, CodeCompanion.nvim; community opencode.nvim, Obsidian | ✅ `openclaw acp` ACP bridge with documented Zed setup |
| WEB-09 | Chat-channel integrations (Slack, Telegram, WhatsApp, Discord, iMessage, ...) | ❌ No chat-channel integrations | ❌ No chat-channel integrations | ✅ Slack app (`@Claude`, cloud, Pro/Max), Claude Tag (Team/Ent); Telegram/Discord/iMessage via official channel plugins (preview) | 🟡 Repo ships an unpublished Slack bot sample (packages/slack); Discord via community kimaki | ❌ Δ The Slack bot sample (packages/slack) is gone from the v2 tree; community kimaki targets v1 SDK | ✅ 20+ channels: Telegram (bundled), WhatsApp, Discord, Slack, iMessage, Signal, Matrix, Teams, Google Chat, IRC, LINE... |
| WEB-10 | Mobile app | ❌ No mobile app; the web UI is responsive but has no PWA manifest | ❌ No native app and no PWA manifest (`dist/manifest.json` is Vite's build manifest) | ✅ Claude iOS/Android app Code tab: start/steer cloud sessions, Remote Control local ones, Dispatch to Desktop | ❌ No mobile app | 🟡 Δ OpenCode iOS app pairs by QR and can delegate voice-call requests (app not in this repo) | ✅ iOS/watchOS and Android apps as nodes (chat, Talk, camera/location/screen, push) |
| WEB-11 | Web UI auth & security model | ✅ Token never in browser; 128-bit one-time code in URL fragment → HttpOnly SameSite=Strict cookie → 30 s single-use ticket | ✅ Code in URL fragment → HttpOnly SameSite=Strict cookie → 30 s single-use ticket per socket; gaps: port-shared cookie, logout | ✅ claude.ai account auth; Remote Control outbound-only TLS with scoped short-lived creds; cloud sessions in isolated VMs | 🟡 Optional HTTP basic auth (OPENCODE_SERVER_PASSWORD); unsecured by default on 127.0.0.1; CORS allowlist | ✅ Δ Generated server password, one-time 5-min pairing links, 30-day cookies, OpenTunnel E2E for remote | ✅ Token/password auth, device pairing, operator scopes, trusted-proxy auth, TLS pinning, strict CSP, security audit |
| WEB-12 | Cloud / hosted execution (sandboxed cloud sessions) | ❌ No cloud/hosted execution | ❌ — | ✅ Cloud sessions on Anthropic infra or self-hosted runners (Pro, Max, Team, Enterprise premium seats) | 🔌 No hosted execution; 🔌 opencode-daytona sandboxes / experimental workspace adapters | 🟡 Δ No hosted sessions, but @opencode/sdk/workerd embeds OpenCode in Cloudflare Durable Objects | ✅ Opt-in Cloud Workers (bundled Crabbox provider) run session work on throwaway cloud machines |
| WEB-13 | Voice interface | ❌ No voice interface | ❌ — | 🟡 Voice dictation (push-to-talk/tap) only; no spoken replies or voice conversation | ❌ No voice interface | 🟡 Δ Voice only via the iOS app's voice-call delegation; no voice in TUI/desktop | ✅ Talk mode realtime voice (browser, macOS, iOS, Android), voice wake, phone calls plugin, Discord voice |
| WEB-14 | Approvals from web / remote | ✅ `permission.requested` per session + server-scope `permission.asked/settled` to all clients; attach and ACP answer too | ✅ Approvals from any browser tab/device, including sessions the page does not follow | ✅ Permission prompts and questions answerable from phone/browser over Remote Control with push notifications | ✅ Permission and question prompts answerable from web/desktop | ✅ Permission and question prompts answerable from web/desktop | ✅ Approvals from Control UI, apps, chat channels, web push |
| WEB-N1 | 🆕 New-session directory picker / browse server folders from the web UI | ✅ `fs.listDirs` lists directory names only under `--browse-root`, realpath-contained | ✅ keyboard-driven folder browser over `--browse-root` (`fs.listDirs`), project badges | 🟡 Web UI starts cloud sessions on a chosen repo; Remote Control server creates sessions in its own dir/worktrees; no folder browser | ✅ Web/desktop directory picker browses server folders to open a project or session | ✅ Web/desktop new-session project selector over the server's directories | ✅ New Session page browses Gateway folders, recent folders, registered projects, GitHub URLs, worktrees |
| WEB-N2 | 🆕 Multi-root workspaces (sessions in other project roots served by one server) | ✅ one PHP process per extra project root behind a gateway; ≤8 hosts; idle recycle | 🟡 listed and driven via workspace hosts, but a dead host freezes the view (verified) | ✅ One supervisor hosts background sessions from any project; agent view lists them all; `--add-dir` gives one session several roots | ✅ One server hosts instances for many project directories (per-directory instances, project list) | ✅ One shared service owns sessions across all projects (Location-scoped services) | ✅ One Gateway serves sessions in many roots (agent workspaces, any folder, registered projects, managed worktrees) |
| WEB-N3 | 🆕 Lossless reconnect (resume the event stream from a cursor without gaps or duplicates) | 🟡 Server side complete (durable seq log, `hello {resume}` replay, idempotency keys); `sugarcrush attach` never reconnects | ✅ resume every followed session from its gap-free seq cursor; idempotent requests resent once | 🟡 Remote Control auto-reconnects after sleep/network drops with the transcript held server-side; no cursor semantics documented | 🟡 SSE `/event` has no replay cursor; clients resync full state after reconnect | ✅ Δ `sessions.log({after, follow})` replays durable events after a cursor with a sync marker (live deltas not replayed) | ✅ Transcript `deltaCursor` catch-up, idempotency-keyed sends, durable browser outbox, branch-leaf guard on reconnect |
| WEB-N4 | 🆕 Background / detached server mode (serve --detach, status / stop / logs) | ✅ `serve --detach`, `serve status/stop/logs/url/token`, singleton lock, systemd unit | 🟡 Works against `serve --detach` (sign in with the `serve url` code; turns run with no tab open); no status/stop/logs in the browser | ✅ Background-session supervisor runs detached; `claude daemon run/status/stop/logs`; `--bg`, `claude logs/stop/rm/respawn` | ❌ `opencode serve` is foreground only; no built-in detach/status/stop (use systemd etc.) | ✅ Δ Per-user background service: `opencode service start/stop/restart/status/get/set` | ✅ Gateway runs as a service (`openclaw daemon` / `gateway` install, status, restart) with `openclaw logs --follow` |
| WEB-N5 | 🆕 Terminal client that attaches to a running server session (remote TUI) | 🟡 full TUI over the same WS (bearer token), approvals mirrored | ➖ The web is itself a client; the sessions it shows can also be driven from a terminal with `sugarcrush attach` (mirrored live) | ✅ `claude attach <id/name>` attaches a terminal to a running background session; `/resume` attaches too | ✅ `opencode attach <url>` connects a TUI to a running server; `run --attach` for headless | ✅ TUI attaches to the shared service by default, or any server via `--server <url>` | ✅ `openclaw tui` attaches to a running local or remote (`--url`) Gateway session; `openclaw resume`; `attach` for Claude Code |
| **AUT** | **Automation / headless** |  |  |  |  |  |  |
| AUT-01 | Non-interactive mode (-p / run) | 🟡 -p/--prompt and run; strict argv; bypass-permissions default; no persistence or --continue; spend cap ignored | ➖ Interactive UI; headless runs are the CLI's `-p` | ✅ `claude -p` print mode (workspace trust skipped; settings errors silent) | ✅ `opencode run [message]` with --agent/--model/--variant/--file/--command/--continue/--attach | ✅ Δ `opencode run` (model#variant, agent, file, title, thinking, auto) against the service or --standalone | ✅ `openclaw agent -m` (Gateway or `--local`) and `openclaw agent exec "<task>"` (isolated headless turn) |
| AUT-02 | Output formats (text / json / stream-json) | 🟡 --output-format text or json envelope ({schema,result,refusals} / error doc); no stream-json, no usage/cost/id | ➖ — | ✅ `--output-format text/json/stream-json`, `--input-format stream-json`, partial messages, hook events, replay | 🟡 --format default (formatted) or json (raw event stream); structured JSON results only via SDK | 🟡 --format default (formatted) or json (raw event stream); structured JSON results only via SDK | 🟡 Text or a stable `--json` envelope; no streaming-JSON event output from the CLI |
| AUT-03 | Stdin piping | 🟡 Piped stdin (<=10 MiB) prepended to the -p prompt; stdin alone is not a prompt; open silent pipe blocks | ➖ — | ✅ Piped stdin (capped at 10 MB) combined with a prompt | ✅ Piped stdin is read and combined with the message | ✅ Piped stdin is read and combined with the message | ✅ `--message-file -` reads the prompt from stdin |
| AUT-04 | Max-turns / budget limits for headless runs | 🟡 Only maxToolSteps setting (default 1000) + loop guard; no --max-turns/--max-budget; spend cap ignored on -p | ➖ Headless-run limits are not a web concern (spend-cap breaches do show as notices) | ✅ `--max-turns`, `--max-budget-usd` (print mode), subagent `maxTurns` | 🟡 Agent `steps` caps iterations (forced summary); no turn/cost budget flags for run | 🟡 Agent `steps` caps iterations (forced summary); no turn/cost budget flags for run | 🟡 Deadlines (`--timeout`, `runTimeoutSeconds`) and goal token budgets; no max-turns or spend cap |
| AUT-05 | GitHub Action / CI recipe | 🟡 Prose CI/container recipe (HOME, exit codes, JSON contract, serve tokens); no GitHub Action, no PR bot | ➖ — | ✅ `anthropics/claude-code-action` GitHub Action, GitLab CI/CD recipe, `claude ultrareview` for CI | ✅ Official GitHub Action (comments, PRs, issues, schedule, dispatch); GitLab CI component | 🟡 Δ GitHub Action exists only for the v1 CLI; no v2 CI recipe beyond `opencode run` | ❌ No GitHub Action / CI recipe for running OpenClaw agents |
| AUT-06 | SDK (TS / Python / PHP) | 🟡 No SDK package; usable as a PHP library, plus versioned JSON-RPC schema for serve and ACP | 🟡 The typed TS client (`generated.ts` + `client.ts`) is reusable but unpublished | ✅ Agent SDK for TypeScript and Python (no PHP) | ✅ JS/TS SDK (+ official Go and Python SDKs) | ✅ Δ @opencode/sdk (in-process host, Effect + Promise, workerd) and @opencode/client | 🟡 Plugin SDK (TS) + Gateway WS protocol + OpenAI-compatible HTTP; no standalone embeddable agent SDK |
| AUT-07 | Scheduled tasks / cron | ❌ No cron or scheduler; nothing runs prompts on a timetable | ❌ — | ✅ Routines (cloud cron/API/GitHub triggers, research preview), Desktop scheduled tasks, session `CronCreate` | 🟡 Scheduled runs via GitHub Actions `schedule`; local cron via 🔌 opencode-scheduler | 🟡 Δ Only via the V1-based GitHub Action schedule or external cron; scheduler plugin is V1-API | ✅ Automations scheduler: cron expressions, intervals, one-shot `--at`, delivery to channel/webhook/none, run history |
| AUT-08 | Loop / recurring prompts (/loop) | ❌ No /loop or recurring prompt. /goal repeats only until a condition is judged met | ❌ No loop/recurring prompts in the web | ✅ `/loop [interval] [prompt]` (alias `/proactive`); omit interval to self-pace via `ScheduleWakeup`; `loop.md` default prompt | 🔌 No /loop; 🔌 oh-my-openagent / hiai-opencode ralph-loop style continuation | ❓ Δ No native loop; ralph-loop style plugins are V1-API — v2 compatibility not verified | ✅ `/loop [interval] <prompt>` (owner): fixed or self-paced cadence backed by cron; `/loop status/stop` |
| AUT-09 | Goal / long-running autonomous mode | ✅ /goal (10 rounds) and /grind (50) keep working until the title model judges the condition met from transcript evidence | ❌ `/goal` and `/grind` are client-only — refused `ui_only` over the wire | ✅ `/goal <condition>`: a small model checks after each turn and keeps Claude going until met; `ultracode`; limit auto-continue | 🔌 🔌 opencode-goal-plugin (installed here): /goal with auto-continue, limits, evidence-gated completion | ❌ Δ opencode-goal-plugin explicitly does not support OpenCode 2; no native goal mode | ✅ `/goal` durable objective (goal tools, pause/resume/block/complete, token budget, TUI footer) + Promised Work rules |
| AUT-10 | Workflows / multi-step orchestration scripts | ✅ YAML/PHP staged workflows (/workflow run/pause/resume/status) + model-written YAML via Workflow tool; agent: = label | ✅ Workflows panel: run one (key=value vars), list the session's runs (/workflow + Workflow tool), pause/resume | ✅ Dynamic workflows: Claude-written JS (`agent()`, `parallel()`, `pipeline()`) run in background, resumable, saved as commands | 🔌 Subtask commands natively; orchestration via 🔌 subtask2, opencode-conductor, open-dynamic-workflows | 🟡 Δ Native background `subagent` commands; V1 orchestration plugins (subtask2, conductor) don't run | 🟡 Swarm (experimental Code Mode fan-out/collect) built in; Lobster typed workflows via 🔌 `@openclaw/lobster` |
| AUT-11 | Webhooks / external triggers | ❌ No webhooks or inbound triggers. The closest options are driving `serve` RPC or `sugarcrush -p` from outside | ❌ — | ✅ Routine API triggers (HTTP POST + bearer) and GitHub event triggers; channels push webhooks into a live session | 🟡 External triggers via HTTP API (prompt_async) and GitHub events; no generic webhook receiver | 🟡 Δ `opencode api` + HTTP API; still no webhook receiver | ✅ Authenticated Gateway HTTP hooks, Gmail Pub/Sub, IMAP trigger plugin (bundled), channel messages |
| AUT-12 | Exit codes / scripting ergonomics | ✅ Test-pinned exit codes 0/1/2 with typed JSON error document on every failure; bash/zsh/fish completion | ➖ — | ✅ 0/non-zero exit codes, 143 on SIGTERM, typo'd subcommands suggest and exit, `auth status` exit 1 when logged out | 🟡 Exits 1 on errors; headless run auto-rejects permission asks unless --auto | 🟡 Exits 1 on errors; headless run auto-rejects permission asks unless --auto | ✅ `agent exec` exits 0/1/2 (ok/error/timeout); `agent` preserves signal exit codes; JSON failure envelope |
| **CFG** | **Configuration** |  |  |  |  |  |  |
| CFG-01 | Settings-file hierarchy | ✅ config.json > settings.json > project local > project shared; session tier above; env/flags above all; trust-gated | ✅ Settings page shows each key's effective value, the winning layer/file, shadowed layers and env/flag locks | ✅ Managed (MDM/file/server) > CLI flags > `.claude/settings.local.json` > `.claude/settings.json` > `~/.claude/settings.json` | ✅ Remote .well-known → global → OPENCODE_CONFIG → project (walk to git root) → .opencode → inline → managed → MDM | ✅ Δ Same locations (V1 shapes normalized in memory) + global cli.json for the terminal client; managed > project | ✅ Single JSON5 `~/.openclaw/openclaw.json` ($include, env substitution): root infra + agents.defaults + agents.entries |
| CFG-02 | Settings UI (/config) | ✅ /settings (/config) view: value, source, apply mode per key; tier picker; e opens $EDITOR and re-applies | ✅ Form generated from `settings.schema`, grouped like the TUI editor; staged changes → diff preview → one atomic save | ✅ `/config` tabbed settings UI (Status, Config…) and `/config key=value` (works in `-p` and Remote Control) | 🟡 No /config screen; palette toggles persist (kv); desktop settings; PATCH /config API | ✅ Δ TUI /settings dialog and desktop Settings (projects, providers, pairing) | ✅ Control UI Settings (schema-rendered forms + Raw JSON), `openclaw configure`, `/config` (owner), `openclaw config` |
| CFG-03 | JSON schema / validation | 🟡 Typed SettingsSchema validates view writes, but unknown or mistyped keys in files are silently dropped | ✅ Client-side field validation plus server params schema; nonsense is refused in the field before sending | ✅ Published JSON schema (schemastore `claude-code-settings.json`); validation errors shown; `claude doctor` lists rejected entries | ✅ JSON Schemas opencode.ai/config.json + tui.json; invalid config hard-fails at startup | ✅ Δ Schema accepts both V1 and V2 shapes; warnings for ignored/conflicting fields | ✅ Strict JSON Schema validation (unknown keys refuse start); `config schema/validate`; `config.schema.lookup` |
| CFG-04 | Environment variables | ✅ 43 `SUGARCRUSH_*` variables (+20 provider/OS vars) documented in `docs/ENVIRONMENT.md` and `--help`, drift-tested | 🟡 Env/flag locks shown on fields (disabled with reason); environment variables themselves not editable | ✅ Hundreds of documented env vars (also settable in settings `env` block) | ✅ ~60 OPENCODE_* env vars plus {env:VAR} and {file:path} substitution in config | ✅ ~60 OPENCODE_* env vars plus {env:VAR} and {file:path} substitution in config | ✅ OPENCLAW_STATE_DIR, OPENCLAW_CONFIG_PATH, OPENCLAW_WORKSPACE_DIR, OPENCLAW_PROFILE, OPENCLAW_THEME, provider keys... |
| CFG-05 | Hot reload of config | 🟡 Settings-view saves apply live/next turn; files re-read per turn for next-turn keys; others need restart | 🟡 Apply-mode badges say when each change takes effect | ✅ Settings files watched and hot-reloaded (permissions, hooks, helpers); keybindings, skills, agents hot-reload | 🟡 Config changed through the API reloads the instance; on-disk edits need a restart | ✅ Δ Watched config/commands/skills/instructions reload automatically; `opencode reload` | ✅ Hybrid hot reload of revisioned config snapshots (file edits or Gateway writes); restart-only keys flagged |
| CFG-06 | Doctor / diagnostics command | 🟡 doctor checks PHP/exts/config/policy/provider name/store/.mcp.json; provider not actually constructed | ❌ No diagnostics page (`doctor` is CLI) | ✅ `claude doctor` (read-only), `/doctor` (Claude-run checkup that can fix), `/doctor prompt-audit`, `/debug` | 🟡 `opencode debug config/paths/info/lsp/rg/skill/snapshot/startup/agent`, `mcp debug`; no single doctor | 🟡 Δ `opencode debug agents/config/paths [selector]`; service status; no doctor | ✅ `openclaw doctor` (--fix, --lint, migrations, SQLite maintenance), `status --deep`, `security audit`, `triage` |
| CFG-07 | Per-project config | ✅ Per-project .sugar-crush/settings(.local).json, gated by trustedProjectSettings and a key whitelist | ✅ Can save to a trusted project's `settings.local.json` or committed `.sugar-crush/settings.json` (preview warns about the latter) | ✅ Per-project `.claude/settings.json` (shared) and `.claude/settings.local.json` (personal, gitignored) | ✅ Project opencode.json(c), .opencode/{agents,commands,plugins,skills,tools,themes}, project tui.json | ✅ Project opencode.json(c), .opencode/{agents,commands,plugins,skills,tools,themes}, project tui.json | 🟡 No per-project config file; per-agent entries (workspace/cwd), per-session settings, `agent exec --config` |
| CFG-08 | Config migration / import from other tools | 🟡 mcp import claude/opencode translates MCP configs; memory import; no settings/permission import | ❌ — | ✅ `claude import` / `/import` from Codex, Gemini CLI, Cursor; `mcp add-from-claude-desktop` | 🟡 Auto-migrates legacy keys (tools→permission, maxSteps→steps, TUI keys); no import of other tools' settings | ✅ Δ Reads V1 config, migrates tui.json → cli.json, imports V1 sessions; `ask OpenCode to migrate` path | ✅ `openclaw migrate claude/codex/hermes` with preview, verified backup and conflict handling |
| **OBS** | **Observability** |  |  |  |  |  |  |
| OBS-01 | Debug logs | ✅ TUI log ~/.sugar-crush/logs/sugarcrush.log (0600, rotated); /notices; SUGARCRUSH_DEBUG_* flags; serve.log | ❌ Server log not viewable from the web (`serve logs` is CLI); internal errors are not logged at all (ISSUES) | ✅ `--debug [filter]`, `--debug-file`, `/debug` (enables mid-session and analyzes); logs at `~/.claude/debug/<session-id>.txt` | ✅ Timestamped logs in ~/.local/share/opencode/log (10 kept); --log-level, --print-logs | ✅ Timestamped logs in ~/.local/share/opencode/log (10 kept); --log-level, --print-logs | ✅ Gateway file logs (`openclaw logs --follow`), log levels, diagnostics flags, verbose/trace |
| OBS-02 | OpenTelemetry / metrics export | ❌ No OpenTelemetry, metrics or trace export | ❌ — | ✅ OpenTelemetry metrics, events and traces (beta) with mTLS, dynamic headers, cardinality controls | ✅ OTLP traces + logs via OTEL_EXPORTER_OTLP_ENDPOINT; experimental.openTelemetry adds AI SDK spans | ✅ Δ OTLP export via util/observability (OTEL_* env); V1 experimental.openTelemetry ignored | 🔌 OpenTelemetry via `@openclaw/diagnostics-otel`, Prometheus via `@openclaw/diagnostics-prometheus` |
| OBS-03 | Usage / cost reports (/cost, /usage, stats) | 🟡 /budget (spend + tokens + cap), status-bar context and $ segments, /context; launch-scoped, no history | 🟡 Spend per session in status bar, tiles and summaries; no usage report page | ✅ `/usage` (`/cost`, `/stats`) with plan breakdown and cache stats; Team/Enterprise analytics dashboard | ✅ `opencode stats` token/cost by days, tools, models, project | ✅ Δ `opencode stats` with --days/--year/--all, --models, --tools (reliability), --cost, --json | ✅ `/usage cost`, `/status`, `openclaw status --usage`, Control UI Usage (session estimates + provider billing) |
| OBS-04 | Status command (/status) | 🟡 No /status; status bar, /settings, /permissions, doctor and serve status each cover part of it | 🟡 Top bar: connection state + RTT; dashboard: web and server versions; `server.health`/`server.info` unused | ✅ `/status`: version, model, account, connectivity, setting sources, session kind, policy, ignored skill grants | ✅ /status (<leader>s): MCP, LSP, formatter, plugin status | ✅ /status (<leader>s): MCP, LSP, formatter, plugin status | ✅ `/status` (runtime, uptimes, plugin health, quota, tokens/cache, compactions), `openclaw status`, TUI `/gateway-status` |
| OBS-05 | Raw transcript files on disk | 🟡 Transcripts in SQLite (session show), sub-agent trails on disk; no JSONL files; -p runs leave nothing | 🟡 Inherited (server-side session store/event log); not exposed | ✅ JSONL transcripts per session (+subagent transcripts), `transcript_path` passed to hooks and status line | 🟡 Transcripts live in SQLite (not per-session files); `opencode db` shell, `opencode export` JSON | 🟡 Δ SQLite durable events + `session export`; `debug paths db` for sqlite3 | 🟡 Transcripts live in per-agent SQLite, not plain files; `openclaw transcripts` materializes them; trajectory export |
| OBS-06 | Insights / analytics over sessions | ❌ No insights/analytics across sessions | ❌ — | ✅ `/insights` HTML report on up to 200 recent sessions (friction, suggestions); `/team-onboarding` guide | 🟡 Only `opencode stats`; 🔌 wakatime, helicone-session, sentry-monitor | 🟡 Δ Only `opencode stats` (now with tool reliability); wakatime etc. are V1-API plugins | ✅ Control UI Activity (rolling per-session recaps, today pulse, live activity, run inspector) and Usage analytics |
| **PA** | **Personal-assistant / always-on features** |  |  |  |  |  |  |
| PA-01 | Always-on daemon / gateway | 🟡 serve --detach (systemd unit example) and /bg daemons persist; it is a server, not a channel gateway | 🟡 The web attaches to a long-running `serve` (`--detach`, systemd unit, parent-pid watchdog); turns continue with no browser open | 🟡 A supervisor daemon hosts background sessions (survives sleep); no always-on chat gateway | 🟡 `opencode serve` can run headless/always-on (mDNS), but there is no assistant gateway or channels | 🟡 Δ Always-on per-user background service + remote pairing; still no assistant channels | ✅ Always-on Gateway daemon (launchd/systemd/schtasks) owning sessions, channels, tools, automations |
| PA-02 | Heartbeat / proactive check-ins | ❌ No heartbeat/proactive check-ins; `/goal` and `/grind` are user-started loops, not scheduled outreach | ❌ — | 🟡 Self-paced `/loop` with a built-in maintenance prompt, routines, `PushNotification`; no heartbeat/check-in system | ❌ No heartbeat/proactive check-ins | ❌ No heartbeat/proactive check-ins | ✅ Heartbeat monitor automation every 30 min (1 h with Anthropic OAuth/Claude CLI) in the main session; monitor scratch |
| PA-03 | Multi-channel inbox | ❌ No multi-channel inbox (Slack/Telegram/etc.) | ❌ — | 🔌 Several channel plugins (Telegram, Discord, iMessage, fakechat) can feed one open session (research preview) | ❌ No multi-channel inbox | ❌ No multi-channel inbox | ✅ One assistant across 20+ channels; DMs share the main session by default; groups isolated per group |
| PA-04 | Persona / identity files (SOUL.md, IDENTITY.md, USER.md) | ❌ No persona/identity files (SOUL.md, IDENTITY.md, USER.md); personal `~/.sugar-crush/AGENTS.md` is the nearest (INS row) | ❌ — | 🟡 Persona via output styles, `--agent`/system-prompt flags and CLAUDE.md; no SOUL/IDENTITY/USER file convention | 🔌 No persona files; agent-memory persona/human blocks (present here) emulate it | 🔌 No persona files; agent-memory persona/human blocks (present here) emulate it | ✅ SOUL.md, IDENTITY.md, USER.md (+ BOOTSTRAP ritual) injected into the system prompt every run |
| PA-05 | Device nodes (camera, screen, location) | ❌ No device nodes (camera, screen, location) | ❌ — | 🟡 Computer use (macOS, Pro/Max preview) and Chrome control; no camera/location/device nodes | ❌ No device nodes | ❌ No device nodes | ✅ Paired nodes expose camera, screen recording, location, notifications, SMS, system.run, computer control, file transfer |
| PA-06 | Voice wake / talk mode | ❌ No voice wake / talk mode | ❌ — | ❌ No wake word or talk mode (dictation only) | ❌ No voice wake/talk mode | 🟡 Δ iOS app voice calls delegate requests to OpenCode; no wake word | ✅ Global voice wake words (macOS 26+, iOS, Android), push-to-talk, realtime Talk sessions |
| PA-07 | Email / calendar integrations | ❌ No email/calendar integrations (only via generic MCP servers the user adds) | ❌ — | 🔌 Email/calendar only through MCP servers or claude.ai connectors (auto-available after claude.ai login) | ❌ No email/calendar integrations beyond MCP servers | ❌ No email/calendar integrations beyond MCP servers | ✅ IMAP trigger plugin, Gmail Pub/Sub, bundled gog (Gmail/Calendar/Drive) and himalaya skills; meeting plugins |
| PA-08 | Multi-user access control (DM pairing, allowlists) | 🟡 serve is single-owner: one token + one-time sign-in codes, IP/host/origin allowlists; no per-user accounts | 🟡 Single owner principal with all four scopes; `--allowed-ips` allow-list; no per-user accounts, roles or pairing | 🔌 Channel plugins provide pairing and allowlists (`/telegram:access` etc.); Claude Tag has admin-configured access | 🟡 Single shared basic-auth credential for the server; no per-user ACLs or pairing | 🟡 Δ Server password + one-time pairing links; still single-user | ✅ DM pairing for unknown senders, channel allowlists, access groups, owner allowlists, multi-user profiles |

---

## 4. How and when each tool surfaces context, to the model and to the user

The OpenCode cells in this section describe 1.18.35; §5 lists what 2.0.31 changes. The big table says *whether* a product has a feature. This section covers the question that matters most day to day: **what the model is actually given, at what moment, and what the human can see of it.** "Web" means what a browser user of sugar-crush-web can see; the engine behind it is the same as the TUI's.

### 4.1 Instruction files (CLAUDE.md / AGENTS.md / rules)

| | sugar-crush (TUI) | sugar-crush-web | Claude Code 2.1.301 | OpenCode 1.18.35 | OpenClaw 2026.9.8 |
|---|---|---|---|---|---|
| **Files read** | `CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, `.cursorrules`, `.clinerules` at every level; personal `~/.sugar-crush/AGENTS.md`; user-tier `instructions` globs; own rules tier (`~/.sugar-crush/rules`, `rulebooks/`, `<root>/.sugar-crush/rules`, `RULES.md`) | Same engine, server-side | `CLAUDE.md`, `.claude/CLAUDE.md`, `CLAUDE.local.md`, managed `/etc/claude-code/CLAUDE.md`, `~/.claude/CLAUDE.md`, `.claude/rules/**`, `~/.claude/rules/**`. `AGENTS.md` natively since 2.1.277, but only if no CLAUDE.md is on the path (switchable in `/config`) | First name with any hit wins, in the order `AGENTS.md` → `CLAUDE.md` → `CONTEXT.md` (so one AGENTS.md hides every CLAUDE.md); global `~/.config/opencode/AGENTS.md`, else `~/.claude/CLAUDE.md`; `instructions` config (paths, globs, URLs) | Workspace `AGENTS.md`, `SOUL.md`, `IDENTITY.md`, `USER.md`, `MEMORY.md` (plus `BOOTSTRAP.md` on first run); execution folder's `AGENTS.md`. **CLAUDE.md is not read** by the native runtime |
| **Not read** | `CLAUDE.local.md`, `~/.claude/CLAUDE.md`, `.claude/rules`, `.cursor/rules`, `copilot-instructions.md`, `.windsurfrules` | — | Other vendors' files (only imported once via `/init` or `/import`) | `CLAUDE.local.md`, any rules directory, `@imports` | No parent walk, no path-scoped rules, no local override |
| **Discovery** | Walks up to the nearest `.git` ancestor, outermost first; no walk when cwd *is* the git root | — | cwd up to the filesystem root | cwd up to the git worktree root | Single folder, no walk |
| **Nested sub-dir files** | Lazily, when Read/Edit/Write/ApplyPatch/Glob/Grep touches a path below them. Meant to be once per session, but on forked turns the marks are lost, so they are re-injected every turn (ISS-018), one file per touch, innermost first (ISS-069). ⚠ They arrive **unlabelled and unfenced inside the tool output** (ISS-017) | Not announced | Lazily on first read/write/edit (a Bash `cat` counts); terminal prints `Loaded <path>` | Lazily on Read / `@file`, appended to the tool result inside `<system-reminder>`; TUI shows `↳ Loaded <path>` | ❌ |
| **@imports** | `@path.md`, depth 4, `.md` only, confined to the importing file's boundary | — | `@path`, 4 hops, code spans ignored; imports outside the project need one-time approval | ❌ | ❌ |
| **How injected** | One system-prompt section per doc, fenced `<project-instructions>` / `<user-rules>`, escaped against 11 tags + chat tokens, with a "no authority over the harness" preamble; general → specific | — | A user-role reminder *after* the system prompt. A header ("…These instructions OVERRIDE any default behavior…") is followed by one "Contents of <path>" section per file. Order: managed → user → project (root to cwd), with CLAUDE.local.md after CLAUDE.md at each level | In the system message as `Instructions from: <path>` after the agent prompt and `<env>` | System prompt "Project Context" section above the prompt-cache boundary |
| **When / refresh** | First prompt build of a session, memoised; re-read only on `/clear`, compaction, rewind or a session switch | — | Session start; re-read after `/clear`, `/compact` or restart | **Rebuilt from disk on every LLM step**: edits apply mid-turn, URL instructions re-fetched each step, prompt cache rewritten | Every run (`contextInjection: always`); edits apply on the next message |
| **Size limits** | 60 KiB/file read, 64 KiB framed per doc, 128 KiB total; overflow becomes a named pointer line, never a silent clip | — | Files >4 MiB skipped; warnings over ~200 lines | None | 20k chars/file, 60k total, USER.md 4k; fixed truncation notice |
| **Shown at startup** | ❌ Nothing (only refusals/deferrals become notices) | ❌ | 🟡 No list; only size warnings and the "AGENTS.md loaded" line | ❌ | ❌ |
| **Inspect** | `/context` gives the layer's token size and section count, **no file names**; `/rules` covers rule packs only | `/context` text only | `/context` lists memory files with token costs; `/memory` lists every location; `InstructionsLoaded` hook | ❌ (only the `↳ Loaded` lines) | `/context list/detail/map` (per-file raw vs injected, truncation), Control UI Agents → Files |
| **Edit from UI** | ❌ | ❌ | `/memory` opens files in `$EDITOR` | ❌ | Control UI file editor |
| **Generate** | `/init` (TUI only) | ❌ refused (`ui_only`) | `/init` (plus a multi-phase variant) | `/init` | 🟡 first-run BOOTSTRAP ritual, no repo analysis |
| **Trust gate** | ❌ (mitigated by fencing/escaping) | ❌ | 🟡 Folder trust dialog, but CLAUDE.md itself isn't trust-gated | ❌ | ❌ |

**Take-aways.**
- sugar-crush has the *safest* instruction pipeline of the four: fenced, escaped, with provenance preambles and named budget overflow.
- Its *nested* path is the exception: raw text in tool output, re-injected each forked turn. And it is the least *visible*: no tool lists the loaded files at startup (Claude Code only notes AGENTS.md and size warnings), and sugar-crush doesn't even name them in `/context`.
- Claude Code and OpenClaw at least name the files on demand (`/memory`, `/context list`).

### 4.2 Memory

| | sugar-crush | sugar-crush-web | Claude Code | OpenCode | OpenClaw |
|---|---|---|---|---|---|
| **Native?** | ✅ | ✅ (panel) | ✅ (auto memory) | ❌ plugins only (`opencode-agent-memory` on this host; supermemory, honcho, oc-mnemoria, magic-context) | ✅ |
| **Store** | `~/.sugar-crush/memory/<scope>/*.md` (a private **git repo**: `/memory log`, `/memory restore`) + repo-local `<repo>/.sugar-crush/memory/project/` (shareable) | — | `~/.claude/projects/<project>/memory/`: `MEMORY.md` index + typed topic files | Plugin-defined | Workspace Markdown (`MEMORY.md`, `memory/YYYY-MM-DD.md`) + SQLite FTS5/vector index |
| **Reaches the model** | System prompt: `<project-memory>` **index** (one line per note, 40 entries / 4 KB, frozen per session). **Per turn**: `<memory-recall>`, up to 3 notes ranked BM25 + optional embeddings + recency + MMR, in the user-role tail row (cache-friendly) | — | First 200 lines / 25 KB of `MEMORY.md` in the session-start reminder; topic files read on demand; side-model "recall" step | Plugin injects blocks via `system.transform` | `MEMORY.md` + `USER.md` every run (private sessions only); trigger-phrase recall (no model call) adds up to 3 entries; Active Memory sub-agent for "about the past" turns; `memory_search` / `memory_get` |
| **Who writes, when** | `Memory` tool (no prompt, even in plan mode); auto-memory at turn end (5-min throttle); **dream pass** every 2 h over the compaction journal; silent pre-compaction flush | Panel add/edit/delete | Claude, during the session (typed `user/feedback/project/reference`); hard index-size hygiene | Plugin | Agent via write/edit; silent pre-compaction flush; default-on **dreaming** (gated promotion, taint gate, `DREAMS.md` diary); optional session-memory hook; imports |
| **User told?** | 🟡 Notices for auto/dream writes; recall invisible; flush silent | Tool card only | ✅ "Saved N memories" / "Recalled N memories" with clickable files | Tool rows | 🟡 Mostly silent unless `/verbose` or `/trace` |
| **User UI** | `/memory list/add/search/edit/delete/log/restore` (text) | 🟡 Best UI (scope tabs, ranked search, inline edit), but it sees only the HOME store: repo notes, the default target, never appear (ISS-020) | `/memory` (toggle, open folder) | ❌ | Control UI Memory (Dreams diary, Wiki), `openclaw memory` CLI |

**Take-aways.**
- sugar-crush and OpenClaw have the richest memory systems: hybrid recall, consolidation and a pre-compaction flush. OpenClaw's imports reach the prompt; sugar-crush's land in a non-prompt scope.
- sugar-crush adds git-versioned history with `/memory log` / `restore`. OpenClaw only git-initializes its workspace (manual commits, no log or undo); Claude Code and OpenCode have none.
- Claude Code's memory is simpler but the most *transparent*: it says when it saved or recalled something.
- One sugar-crush provenance bug undermines it: user notes in the repo store are labelled "not written by the user".

### 4.3 Skills

| | sugar-crush | sugar-crush-web | Claude Code | OpenCode | OpenClaw |
|---|---|---|---|---|---|
| **Discovery** | built-in < project `.sugar-crush/skills` < user `~/.sugar-crush/skills` (user wins, shadowing reported) + **7 foreign trees** (`.claude`, `.agents`, `.opencode`, `~/.config/opencode`), badged by origin | — | Enterprise, personal, project (walk-up), nested (lazy), `--add-dir`, plugins, claude.ai-synced, 20 bundled | `.opencode/skills`, `~/.config/opencode/skills`, `.claude/skills`, `.agents/skills`, `skills.paths`, `skills.urls` registries | Workspace `skills/`, `.agents/skills`, `~/.agents/skills`, managed, Workshop, 49 bundled, plugins; **not** `.claude/skills` |
| **Model sees** | `<available-skills>` fenced listing in the system prompt **every turn** with a "MUST load the matching skill" mandate (no size budget: 98 skills ≈ 10k tokens here) | — | System-reminder listing, budgeted to 1% of context (least-used lose descriptions first) | XML list in the system prompt + pointer in the `skill` tool | `<available_skills>` (name, description, path), budgeted |
| **Model invokes** | `Skill` tool | — | `Skill` tool | `skill` tool (per-skill allow/ask/deny) | Reads the SKILL.md with `read` (no Skill tool) |
| **User invokes** | `$name` mention (one turn, Tab completes); ⚠ Ctrl+S picker says "Enabled" but never reaches the model | ❌ | `/skill-name args` | Every skill is also a `/command` | `/skill <name>`, `/name`, `$name` |
| **User sees list** | ⚠ `/skills` shows only dream-pass *drafts*; the installed list only appears in the Ctrl+S picker / Skills pane | ❌ | `/skills` (filter, sort by token cost, visibility toggles) | `/skills` searchable dialog | Control UI Plugins → Skills, `$` picker, `skills list/check` |
| **Gating / limits** | ✅ `requires` bins/env/os (unique with OpenClaw); `allowed-tools` inert; `context: fork` dormant | — | `allowed-tools`, `context: fork`, `paths` globs | ❌ | ✅ `requires`/`os`/`always` |
| **Registry** | ❌ | ❌ | Plugin marketplaces (official catalog: 315 entries) + claude.ai sync | `skills.urls` | ClawHub (security-scanned) |

### 4.4 Agents and sub-agents

| | sugar-crush | sugar-crush-web | Claude Code | OpenCode | OpenClaw |
|---|---|---|---|---|---|
| **Built-ins** | coder, reviewer, debugger, architect, tester, devops (⚠ coder lacks Write; tester lacks Write+Edit) | runs only | Explore, Plan, general-purpose, `claude`, statusline-setup, claude-code-guide, fork | build/plan (primary), general/explore (sub) | none typed; generic `sessions_spawn` |
| **Custom defs** | `.sugar-crush/agents` (project beats user beats built-in, ⚠ silently), **plus imported `.claude/agents` and `.opencode/agents`** | — | `.claude/agents`, `~/.claude/agents`, managed, `--agents`, plugins | `.opencode/agents`, `~/.config/opencode/agents`, JSON | `agents.entries.<id>` with own workspace |
| **Model sees catalog** | ⚠ Names only ("Current agent roster: architect, coder, …") | — | System-reminder "Available agent types": name + description + tools | `task` tool description, filtered by permission | 🟡 No catalog in the prompt; `agents_list` tool + a Delegation section |
| **User sees catalog** | ⚠ `/agents` lists only *running* runs + an idle count; roster never listed | Agents panel = this session's runs; catalog unused | `/agents` prints a pointer only (UI removed in 2.1.198); `@` typeahead; `/list-agents` | `/agents` picker of primary agents; sub-agents in `@` menu | Control UI Agents home, TUI picker, `agents list --tree` |
| **Run model** | Parallel forks, nest to depth 3, background daemons, 7-day resume, worktree isolation, 80/90 % context watch | Live tree + agent view | Background by default (fork mode), 20 concurrent, 3 deep, worktree, resume via SendMessage | Child sessions, parallel, depth 1 default, background behind an experimental flag | Child sessions, 8 concurrent, depth 5, worktree, ACP harness children |
| **Live view / steering** | ✅ Per-Task live line, agents strip, **Agent View you can type into**; pause / cancel / background / broadcast (Ctrl+X) | ✅ Message, pause, resume, cancel, background from the browser | ✅ Panel tree, open and type into a subagent | ✅ Step into a child (read-only) | ✅ View-only child transcripts; `sessions_send` steering |
| **Agent↔agent** | SendMessage, Subagents, InterruptAgent, Team board, per-batch BoardPost/BoardRead | — | SendMessage across sessions and machines; agent teams (experimental) | 🟡 Push context to a running background child via `task_id` (experimental); no mailbox; teams only via plugins | `sessions_send`; team preset |

**Take-away.** For *running* sub-agents, sugar-crush edges out Claude Code: it adds pause, broadcast and a batch board to what both share. For *finding* them it is among the weakest: the model sees names only, and no view lists the roster with descriptions.

### 4.5 Slash commands, palette, bang-shell

- **sugar-crush**:
  - 55 built-in command specs (48 slash commands + 7 palette/menu-only, +5 aliases), one spec file each, with a generated, drift-tested `docs/COMMANDS.md`.
  - Custom Markdown commands in `~/.sugar-crush/commands` and `<root>/.sugar-crush/commands`: `$ARGUMENTS`, `$1..$9`, gated `` !`cmd` ``, `@path`.
  - **Not** read: `.claude/commands`, `.opencode/commands` (even though foreign agents and skills are imported).
  - Ctrl+P palette is a curated 19-action subset. F10 menu bar.
  - ⚠ An unknown `/word` is sent to the model as a prompt (a typo costs a turn).
  - ⚠ A project command can silently replace `/undo`, `/diff`, `/mcp` and similar; only 9 control-plane names are protected.
- **sugar-crush-web**: 48 built-ins listed; 30 run server-side, 18 refused as terminal-only (`/model`, `/compact`, `/init`, `/goal`, …). ⚠ `!cmd` runs but reports "refused".
- **Claude Code**: ~116 commands and bundled skills; custom commands merged into skills; MCP prompts become `/server:prompt`; typo suggestions. Messages typed during a turn are delivered *between tool calls*.
- **OpenCode**: ~33 built-ins; Markdown/JSON commands with `agent`, `model` and `subtask` frontmatter; MCP prompts and skills appear as commands; Ctrl+P palette over all actions.
- **OpenClaw**: ~60 core commands; skills double as commands (native menus on Discord/Telegram); ⌘K palette in the Control UI; no `` !`cmd` `` templating.

### 4.6 Hooks

| | sugar-crush | Claude Code | OpenCode | OpenClaw |
|---|---|---|---|---|
| Events | 12 (Pre/PostToolUse, Stop, SubagentStop, SessionStart/End, UserPromptSubmit, Pre/PostCompact, TaskCreated/Completed, TeammateIdle) | 33 | ~13 plugin hooks fire (`permission.ask` declared, never fired) | Internal hooks + ~40 typed plugin hooks |
| Kinds | Shell command only | command, http, mcp_tool, prompt (LLM), agent (experimental) | In-process JS/TS | In-process JS/TS |
| Payload | `CRUSH_*` env vars (stdin closed) | JSON on stdin | function args | function args |
| Verdicts | exit 0 allow / 1-2 deny / 3 ask / 4 rewrite, **plus Claude Code's JSON envelope** | exit 2 block; JSON allow/deny/ask/defer, updatedInput, additionalContext | mutate / throw | block / rewrite / approve / claim |
| Failure | **Fails closed** (60 s) | `onFailure: block` opt-in | no timeout | per-hook timeouts, fail-closed gates |
| Claude-Code compat | ⚠ Docs claim it; in practice CC stdin scripts receive nothing, and exit 1 means deny | reference | ❌ (🔌 opencode-froggy) | ❌ detected, never run |
| UI | Verdicts in tool rows; **no `/hooks`** | `/hooks` read-only browser | toasts via plugins | `openclaw hooks list` |

### 4.7 MCP and how tools are exposed

- **sugar-crush**:
  - Covers tools only, over stdio and Streamable HTTP (no legacy SSE), with a strong trust model: `.mcp.json` sits behind `trustedProjectMcp` with per-server fingerprint pins, credential env vars are stripped, and `mcp list` never spawns servers.
  - Every MCP schema is sent every turn, with no deferred loading.
  - Missing: resources, prompts, elicitation, sampling, user-level config, and MCP-server mode.
  - OAuth is CLI-only.
  - The HTTP tool call has a fixed 30 s total timeout.
- **sugar-crush-web**: MCP calls are generic cards; `/mcp` prints config status only.
- **Claude Code**:
  - Transports: stdio, SSE, HTTP and WebSocket.
  - OAuth, resources as `@server:` mentions, and prompts as commands; elicitation, but no sampling.
  - Five scopes (local, project, user, managed, plugin/connector).
  - **Tool search is on by default**, so only tool names enter context (it falls back to upfront loading for older models, non-first-party base URLs and Azure Foundry).
  - `claude mcp serve` exposes Claude Code itself as a server.
- **OpenCode**: stdio plus remote (HTTP, then SSE fallback); automatic OAuth on 401; resources via `@` and model tools; prompts as commands. No sampling or elicitation (disabled deliberately), and no deferred loading by default (an experimental code mode hides MCP tools behind one `execute` tool; v2 makes it the default).
- **OpenClaw**:
  - stdio, SSE and HTTP with OAuth; resources and prompts as model-side tools.
  - Tool Search on by default (marked experimental) behind `tool_search` / `tool_describe` / `tool_call`.
  - `openclaw mcp serve` exposes its channel conversations as a stdio MCP server, and `openclaw attach` gives Claude Code grant-scoped OpenClaw tools.

### 4.8 System prompt, per-turn reminders, prompt caching

- **sugar-crush**:
  - Up to 13 ordered slots: base (per model family) → maxims → tool guidance (incl. Task doctrine) → repo map → symbol map → user rules / personal AGENTS.md / ancestor / root / forced docs / project rules → `<project-memory>` → enabled skills → `<available-skills>` → plan-mode reminder → `<env>`.
  - Each step also gets a user-role `<turn-context>` tail: git status/diff after writes, changed-since-read files, memory recall, context % past 60 %, post-compaction re-injection.
  - Byte-stability is engineered (per-session memo, volatile state in the tail).
  - Explicit cache breakpoints exist only for Claude-on-Vertex and Bedrock. ⚠ The `anthropic` provider uses Anthropic's OpenAI-compatible endpoint, which **does not cache**.
  - No raw-prompt dump.
  - Harness text tells the model batching Task calls is sanctioned "even where project instructions advise running sub-agents one at a time", which ships into every project.
- **Claude Code**:
  - Three layers: system prompt (snapshotted once per conversation), project context (CLAUDE.md + memory reminder), conversation (append-only `<system-reminder>`s).
  - Git snapshot taken once at startup.
  - Deferred tools announced via reminders.
  - Idle compaction before the 1-hour cache expires.
- **OpenCode**: one system string rebuilt every step (agent prompt, `<env>`, references, instructions, MCP instructions, skills), collapsed to at most two system messages for caching; mode reminders as synthetic user parts.
- **OpenClaw**:
  - An OpenClaw-owned prompt with an explicit internal cache boundary (stable sections above, time/channel below).
  - Volatile runtime facts travel as user-role "Runtime Context" carriers.
  - Prompt snapshots are drift-checked in CI.

### 4.9 Context management and compaction

| | sugar-crush | Claude Code | OpenCode | OpenClaw |
|---|---|---|---|---|
| Counting | Three figures: calibrated status-bar estimate, provider-anchored in-turn budget, and ⚠ history-only text estimate for the 70/85/95 % tiers (misses system prompt + tool schemas, so tiers fire late) | Provider-reported | Provider-reported | Provider-reported |
| Auto trigger | Tiers 70/85/95 %, absolute caps 100k/150k, in-turn step budget, overflow retry | ≈ model limit (`/autocompact`), idle compaction before cache expiry | input limit − min(20k, maxOutput), mid-turn | window − reserve (16–20k); overflow; byte guard |
| Method | Model summary with six facets + merged `<session-state>`; summaries **pre-fetched ahead of need** using the cached prefix; re-inject files, plan, skills, git, todos | Clear old tool outputs first, then summarise; re-inject CLAUDE.md, memory, plan, last 5 files, skills, git | Anchored 5-section summary, verbatim ≤15k recent tail | **Safeguard** mode audits headings, pending asks and identifiers, and aborts rather than write a lossy summary |
| Model-driven pruning | ✅ `Prune` / `Compress` / `Recall` tools + ref tags | ❌ | 🔌 opencode-dynamic-context-pruning | ❌ (harness-side cache-TTL trim and Anthropic server-side clearing only) |
| Side questions | `/btw` (title model) | `/btw` | ❌ (v2 adds `/btw`) | `/btw` |

### 4.10 Permissions and safety at a glance

- **sugar-crush**:
  - Six modes (default, accept-edits, plan, auto, dont-ask, bypass).
  - A shell-aware rule engine: allow must cover every segment; deny fires on any segment.
  - Strong path jail for file tools (Bash isn't jailed) and DNS-pinned SSRF guard; opt-in bubblewrap sandbox for Bash that fails closed.
  - Policy self-grant floor; approval modal with once / session / save / edit-scope / refuse-with-note / refuse-and-stop.
  - **But:**
    - ⚠ **P0/P1** (ISS-001/002): `PtyRun` keystrokes bypass every rule, hook, classifier and the sandbox.
    - ⚠ Edit/Write approvals show 80-char-clipped arguments, **no diff**.
    - ⚠ Auto mode is a regex denylist.
    - ⚠ `-p` and background sessions default to bypass (documented), while the headless spend cap isn't enforced.
- **Claude Code**: **auto mode is the default starting mode since 2.1.283** for interactive terminal and VS Code sessions on supported models (classifier, falls back to prompts after 3 consecutive / 20 total blocks); protected paths; OS sandbox with network-proxy allowlists; managed policy; diff in the approval prompt.
- **OpenCode**: permissive defaults (only external dirs, `*.env` and doom-loops ask); tree-sitter bash parsing; diff in the ask dialog; "always" approvals lost on restart; no sandbox, no trust dialog.
- **OpenClaw**: **no sandbox and full host exec by default**; four session permission modes incl. an LLM exec reviewer; Docker/Podman/SSH sandboxes; approvals from phone or chat buttons; edits never approval-gated.

### 4.11 Startup screen, status, notifications

- **sugar-crush**:
  - ⚠ A fresh launch shows only `_(empty conversation — …)_` (underscores literal): no version, cwd, model, loaded files or tip.
  - Status bar: context %, cache share/age, mode, model.
  - `?` overlay generated from the live key registry.
  - Settings view showing source and apply mode per key.
  - Opt-in `notify`: terminal bell or OSC 9 desktop notification.
  - ⚠ The startup terminal probe leaves `^[[?1;2;4c…` in the shell on every quit.
  - ⚠ The configured `statusLine` command blocks the UI.
- **sugar-crush-web**: sessions sidebar with question badges, tab-title counts, opt-in desktop notifications, status bar (step, tokens, spend, mode).
- **Claude Code**: welcome banner (version, model + effort, account, cwd, tips, announcements); spinner with elapsed time and token count; scriptable status line fed JSON; desktop notifications in Ghostty/Kitty/iTerm2; Remote Control push.
- **OpenCode**: logo + rotating tips + footer (`dir:branch`, MCP count, version); sidebar with context %, cost, MCP/LSP status, todos, modified files.
- **OpenClaw**: TUI header (gateway URL / agent / session); the Control UI is the real surface (context ring, plan-usage bars, activity recaps).

### 4.12 Surfaces

- **sugar-crush**: TUI, `-p` headless, `serve` (WebSocket protocol + browser UI), `attach` (TUI over WS), `acp` (Zed / JetBrains / Neovim). No VS Code extension, desktop app, mobile app, chat channels or cloud execution.
- **sugar-crush-web**: multi-session cockpit (tabs + live 3×3 grid), server-wide approvals drawer, panels for agents/todo/background/workflows/memory, settings with provenance. Exposes 45 of 62 protocol methods.
- **Claude Code**: CLI, VS Code, JetBrains, Desktop, web (claude.ai/code), mobile (cloud sessions + Remote Control), Slack, channel plugins.
- **OpenCode**: one server, many clients (TUI, web, desktop, VS Code extension, ACP, SDK, GitHub/GitLab bots, `attach`).
- **OpenClaw**: always-on Gateway with 20+ chat channels, Control UI (a full agent IDE), TUI, macOS/Windows/iOS/Android apps, ACP, an OpenAI-compatible API.

---

## 5. OpenCode 1.18.35 → 2.0.31: what changes in the comparison

The "OpenCode 1.18.35" column in §3 and the OpenCode cells in §4 describe 1.18.35, the copy installed here. Upstream now ships two maintained lines, and both released on 2026-10-16:
- **v1:** npm `opencode-ai`, `latest` = 1.19.3.
- **v2:** a separate major, npm `@opencode/cli`, `latest` = 2.0.31. Opt-in via `/v2/install`, `npm i -g @opencode/cli`, brew `opencode-v2`, or `opencode upgrade --major` (a flag added after 1.18.35); mise ≥2026.10.9 installs v2 by default. The v2 installer *replaces* the v1 binary.

In the 2.0.31 column, **240 of 398 rows carry Δ and 77 have a different status code from 1.18.35**, which is why it gets its own column.

**What v2 gains (relevant to sugar-crush):**
- **Durable prompt inbox.** Typing while busy still steers at the next step, as in v1; v2 adds an explicit queue-until-idle (Ctrl+X Enter) and makes the inbox durable. Execution is crash-recoverable through write-ahead claims under one per-user background service that every client attaches to (TUI, web, desktop, iOS, ACP).
- **Cache-stable instructions.** A frozen per-session baseline. Later AGENTS.md edits reach the model as a **unified diff message** ("The instructions from X changed. Here's the diff:"). The TUI shows "Instructions updated" / "Loaded <file>" rows, but not their content.
- **MCP through Code Mode by default.** One `execute` sandbox tool with budgeted one-line signatures, so full schemas aren't sent every request. MCP elicitation supported.
- **Built in now:** background shell and background sub-agents, worktree tools, `/btw`, session tabs, output-limit auto-continuation, cache warming, provider-native (OpenAI Responses) compaction.
- **Permissions.** Persisted per-project "Allow always"; a working `permission.evaluate` plugin hook (v1's `permission.ask` never fired); org policies.
- **Remote access.** Password-protected web by default, one-time `opencode pair` links / QR codes, OpenTunnel end-to-end remote, an iOS app.

**What v2 removes or regresses:**
- **Instructions:** no CLAUDE.md fallback at all (AGENTS.md only; discovery now walks up to `$HOME`).
- **Code intelligence and tasks:** no LSP (no diagnostics, no lsp tool), no todo tool or panel, no structured output.
- **Sharing and commands:** no `/share`, no `@file` in command templates, skills are no longer slash commands (load with `@skill-id`), no pruning options, no SSE MCP transport, no `opencode agent create` wizard, no `opencode github` / `opencode pr`.
- **Prompts and editing:** per-agent temperature/top_p accepted but not sent; the Gemini/codex/beast family prompts are gone (models without an override, Gemini included, get a 15-line generic prompt; gpt/astra/kimi/trinity/meta keep theirs); the fuzzy edit chain shrank from 9 replacers to 2.
- **Permissions:** `external_directory` now *allowed* by default; doom-loop guard gone; sub-agents no longer inherit the parent's deny rules.
- **Plugins:** **every v1 plugin must be re-ported.** DCP, opencode-pty, the goal plugin, subtask2 and oh-my-openagent don't run on v2. On this host only `@plannotator/opencode` (both majors) and `opencode-agent-memory` ≥0.3.0 (v2 only) would work. `opencode-supermemory` 2.0.17 supports both majors but isn't installed here.

**Effect on the sugar-crush comparison:**
- **Where v2 catches up with sugar-crush:** cache-stable instructions (frozen baseline plus diffs), a detachable background service (WEB-N4 ❌→✅) and durable event replay (WEB-N3 🟡→✅).
- **Where v2 pulls further ahead of sugar-crush:** native background shell, deferred MCP loading (Code Mode) and built-in remote pairing/tunnel, none of which sugar-crush has.
- **Where v2 falls further behind sugar-crush:** no LSP, no todo tool, no CLAUDE.md, a permissive external-dir default, and an ecosystem reset that removes the plugin-provided features (DCP pruning, PTY, goals) the 1.18.35 column credits with 🔌.

**Host-specific notes:**
- **A setting that may need a change on this machine.** The global OpenCode config loads `opencode-agent-memory@latest`, which v1 re-resolves at startup and which now resolves to the v2-only 0.3.0. Pin `@0.2.0` to keep v1 working (not verified whether the cached copy is still 0.2.0).
- **v2 config migration.** It would ignore `subagent_depth: 4` (moved to `experimental.subagent_depth`) and make `lsp: true` inert.
- **Large first-start import.** On first start, v2 would import the 11.6 GB v1 session store (not exercised).

---

## 6. How sugar-crush and sugar-crush-web can improve (prioritised)

`ISS-nnn` refers to entries in [`../sugar_issues.md`](../sugar_issues.md). Project policy applies throughout: **wire dormant code instead of deleting it**, fix root causes rather than "disable + TODO", and never add a blanket total-request timeout to LLM calls. A striking share of the items below are *wiring* jobs.
- **Fully dormant:** `StreamingDirectoryLister`, `Tui\SessionTabs`, `CompleteRequest::$jsonSchema`, the served-session idle-compaction check, and 17 protocol methods the web never calls.
- **Live but used too narrowly:**
  - Vertex's native Anthropic body/cache code is unreachable from the `anthropic` type;
  - `SecretRedactor` covers memory, journal and dream writes only, not the audit log or tool output;
  - `ExecReviewer` runs only under opt-in `autoReview`, and on flagged calls only.

### 6.1 Fix first: safety and correctness

| # | What | Why / who does it better | Where | Issues |
|---|---|---|---|---|
| 1 | **Bring `PtyRun` inside the permission model.** Its decoded steps must become part of the rule/classifier/breaker/session-grant subject; render them in the modal; drive hook matchers from `ShellSubjectTools::NAMES`; pass the bwrap sandbox to `PtyDriver` | It is the one hole that defeats the rules, classifier, breaker, shell hooks and sandbox (default mode still asks, but never shows the steps) | `Permissions/PermissionRule.php`, `SafetyClassifier.php`, `PermissionGate.php`, `SessionPermissionMemo.php`, `Renderer::permissionSubjectLines`, `Hooks/BuiltIn/*`, `Tools/BuiltIn/PtyRun.php` | ISS-001, ISS-002 |
| 2 | **Diff preview and full arguments in the approval modal** (dry-run Edit/Write/ApplyPatch in the parent, render with `BuildsUnifiedDiff`, add a details/expand key) | Claude Code shows the diff before you approve; OpenCode does too when edits are set to ask | `Renderer.php`, `Chat.php`, `Permissions/PermissionPromptStage.php` | ISS-003 |
| 3 | **Background sessions inherit the server's permission mode** and send their asks to the approvals drawer | Today the browser's "Start" button escapes a no-bypass server | `Protocol/Methods/BgMethods.php`, `Sessions/BackgroundSupervisor.php`, `BackgroundSessionRunner` | ISS-004 |
| 4 | **Headless brakes:** enforce/validate `maxCostUsd` on `-p`; add `--max-cost` and `--max-turns` (the bypass launch notice already prints on stderr); fire UserPromptSubmit/SessionStart hooks on `-p`/background; stop auto-approving hook asks in bypass | `-p` defaults to bypass with up to 1,000 tool steps; Claude Code has `--max-turns` / `--max-budget-usd` | `Cli/NonInteractive.php`, `Cli/ArgvParser.php`, `Hooks/` | ISS-006, ISS-040, ISS-041 |
| 5 | **OpenAI reasoning models:** use `max_completion_tokens`, omit temperature, send `reasoning_effort` | gpt-5/o-series get HTTP 400 under OpenAI's documented contract (not live-tested) | `Providers/OpenAIProvider.php`, `EngineBackend::honoursReasoningEffort()` | ISS-005 |
| 6 | **Credential hygiene:** extend `protect-files` and the env scrub (gh `hosts.yml`, kube config, pgpass, `*_PASSWORD`, `DATABASE_URL`, Claude/OpenCode auth files); wire the existing `SecretRedactor` into the audit log and (opt-in) tool output; fix the quoted-`{file}` lint injection | Read-only auto-allow currently roams the disk | `Hooks/BuiltIn/ProtectFilesHook.php`, `Support/ProcessContainment.php`, `Memory/SecretRedactor.php`, `AuditHook` | ISS-010, ISS-011, ISS-059 |
| 7 | **Stronger auto mode:** send every non-read-only shell call through the existing `ExecReviewer` when `autoReview` is on; add download-and-execute, interpreter-pipe and process-substitution shapes | Claude Code's auto mode is a classifier with explicit fallbacks; OpenClaw has an LLM exec reviewer | `Permissions/SafetyClassifier.php`, `ExecReviewer` | ISS-013 |
| 8 | **Server transport hardening:** require a non-ambient per-login secret to mint tickets; lockout that never blocks valid tokens; close sockets on logout; IP/Host guard before body buffering plus read deadlines; log internal errors | Loopback cookies are shared across ports | `Server/ApiController.php`, `AuthMiddleware`, `src-web/protocol/auth.ts` | ISS-007, ISS-008, ISS-009, ISS-055, ISS-057 |
| 9 | **No data loss on oversized prompts** | A prompt can be stored as an empty row, and the model gets the emptied prompt | `Context/ContextCompactor.php:388-401,490-500` | ISS-016 |
| 10 | **Agent and workflow correctness:** give `coder` Write/Grep/Glob and `tester` Write/Edit; enforce a preset's `mcpServers` allowlist without `tools:`; load each PHP workflow once so a re-run can't kill the TUI; default Vertex to a current model | Built-in agents fall back to the shell to create files; a re-run crashes the session | `Agents/AgentDefinition.php`, `Agents/AgentManager.php`, `Tools/BuiltIn/TaskTool.php`, `Workflows/`, `Providers/VertexProvider.php` | ISS-025, ISS-030, ISS-033, ISS-045 |
| 11 | **Truthful config and `doctor`:** schema-check every settings file (unknown keys with did-you-mean, wrong types); construct the provider; report the sqlite3 extension correctly | A typo'd deny list silently vanishes while `doctor` says "No problems detected" | `Config/Settings/SettingsSchema.php`, `Cli/Subcommands.php` | ISS-028, ISS-029, ISS-083 |

### 6.2 Make what already exists visible (the biggest UX win; mostly wiring)

sugar-crush builds rich state and then hides it. Each competitor shows *something* here: Claude Code's `/memory`, `/context`, `/skills`, "Saved/Recalled N memories" and agent catalog reminder; OpenClaw's `/context list/detail/map`; OpenCode's `↳ Loaded <path>` lines, plus v2's "Instructions updated" rows.

1. **Startup summary instead of `_(empty conversation…)_`.** Show:
   - version, provider · model, permission mode, cwd · branch;
   - **instruction files actually loaded** (paths, with "via @import" notes);
   - memory note count, skill/agent/MCP counts, and one tip.

   All of it is already assembled for `/context` and `Bootstrap`. Affects ISS-096.
2. **`/context` names the files;** a bare `/memory` (or a new `/instructions`) lists loaded instruction and memory files with sizes and opens one in `$EDITOR` via `EditorCommand`. Fix `/context`'s missing layers (ISS-063).
3. **Inventory commands list inventory:**
   - `/skills` → installed skills with source/enabled state (ISS-089);
   - `/agents` → roster browser with name, description, tier/path, model and grant (ISS-034);
   - a read-only `/hooks`;
   - `/mcp reconnect`.
4. **Tell the model what agents are for.** Add an `<available-agents>` fence (name, description, tools summary, source badge) built like the skills listing. Claude Code lists `name: description (Tools: …)`. Affects ISS-032.
5. **Guard typos.** Refuse unknown `/word` locally with "did you mean", and make the Ctrl+P palette a superset of slash commands. Affects ISS-037.
6. **Wire the Ctrl+S skill picker** into the session's enabled skills (ISS-035), and add `/skill-name` invocation alongside `$name`.
7. **Say when memory is used.** One-line "Saved N notes" / "Recalled N notes" rows, as in Claude Code, including the pre-compaction flush (ISS-070).
8. **Distinguish app output from model output.** Local command output and errors get their own label and colour (ISS-097).

### 6.3 Context, prompt and memory correctness

- **Compaction tiers on the real request size:** feed the calibrated estimate (system + tool schemas + args) into `ContextCompactor` (ISS-015).
- **Nested instruction delivery:**
  - fence and escape on-touch files with their path, as root docs already are (ISS-017);
  - return every ancestor in one touch, outermost first (ISS-069);
  - carry "already shown" marks back from forked turns (ISS-018);
  - key them per delegated transcript (ISS-065).
- **Isolate state:** give the dream pass and each served session their own prompt memo and loader (ISS-021, ISS-054).
- **Memory provenance:** label notes by author and refresh the memory slot after `/memory add` (ISS-019, ISS-116).
- **Budget the skills listing** (`skills.listingMaxBytes`, ranked truncation, as Claude Code budgets to 1 % of context) (ISS-022).
- **Native Anthropic Messages provider:** lift `VertexProvider`'s Anthropic body and cache-breakpoint code into a shared shaper and point the `anthropic` type at `/v1/messages`. This gains prompt caching (ISS-023). Extended thinking (Vertex has only a dormant response-parse seam) and PDF document blocks (no provider sends them today) need new request-side work on top.
- A per-model window table for Vertex and a working `contextWindow` override for vertex/bedrock/sglang (ISS-073); split the stream header-wait bound from the connect bound (ISS-024); Retry-After and jitter (ISS-074).
- **Ideas worth borrowing:**
  - OpenCode v2's **instruction diffs as messages** (a cache-stable way to pick up mid-session CLAUDE.md edits);
  - OpenClaw's **safeguard compaction** (audit required headings, pending asks and identifiers; abort rather than write a lossy summary);
  - Claude Code's **idle compaction before cache expiry** (sugar-crush's own idle check only advises; the served-session one is unwired, ISS-064).

### 6.4 Interop: cheap wins for people arriving from Claude Code or OpenCode

- **Instruction files:** read `CLAUDE.local.md`, fall back to `~/.claude/CLAUDE.md` when `~/.sugar-crush/AGENTS.md` is absent, map `.claude/rules/*.md` (`paths:`) onto the existing `RuleLoader`, add `.cursor/rules/*.mdc` / `copilot-instructions.md` aliases and an `instructionsExclude` list.
- **Commands:** import `.claude/commands/**` and `.opencode/commands/*` at lowest precedence, badged (foreign agents and skills are already imported).
- **Hooks:**
  - send Claude Code's JSON payload on **stdin** as well as env vars (ISS-038);
  - accept CC event names as aliases or notices instead of failing the launch;
  - read the `hooks` block of `.claude/settings.json`;
  - resolve relative commands against the hooks file's directory (ISS-039).
- **Permission rules:** accept the `Tool(prefix:*)` spelling (the translation already exists in the foreign-agent importer) and warn on rules that can never match (ISS-012).
- **Precedence:** user beats project for agents/commands/workflows, as skills already do, with every shadowing reported at launch (ISS-031). Require trust before a project command overrides a built-in (ISS-036).
- **Claude agent importer:** fix it (ISS-086), surface its warnings (ISS-088), and stop `/bg`/`/fork` defaulting to a foreign preset (ISS-087).
- **Plugin bundles:** consider reading **Claude-format plugin bundles** (skills, commands, `.mcp.json`; agents and hooks need a decision). That gives an ecosystem without inventing a format. OpenClaw does this partly: it maps bundle commands and agents onto skill roots and loads bundle MCP servers, but never runs bundle hooks.

### 6.5 Breadth gaps (new capability)

| Area | Gap vs competitors | Suggested approach |
|---|---|---|
| MCP | Tools-only; every schema every turn; `.mcp.json` only | Resources as `@server:uri` mentions; prompts as `/mcp__server__prompt` commands; `~/.sugar-crush/mcp.json` user scope; legacy SSE; in-TUI OAuth; `/mcp reconnect`; configurable HTTP call timeout (ISS-042); embedded resources/images (ISS-044); correct `claude-mcp` default args (ISS-043); **deferred tool loading** (Claude Code tool search / OpenCode v2 Code Mode / OpenClaw Tool Search) |
| Plugins / marketplace | None (Claude Code 315-entry marketplace, OpenCode npm plugins, OpenClaw ClawHub) | Start with a bundle format (or Claude-bundle compatibility, above), then a registry |
| Providers | No model catalog, no `/effort`, no Gemini API-key type, no subscription login | Populate `/model` from `/v1/models` + the cached LiteLLM DB; `/effort` mapped per provider; cheap auxiliary defaults (ISS-077); Gemini via Vertex's body shaper |
| Automation | No cron, `/loop` or webhooks; headless lacks stream-json, `--resume`, usage JSON | `/loop` + `schedules` on the existing `BackgroundSupervisor`; persist `-p` sessions; `--output-format stream-json` from the typed `Events` bus; wire the dormant `CompleteRequest::$jsonSchema` as `--json-schema` |
| Shell & tools | No background shell; timeout kill misses `&` children; no LS tool; Read can't see images/PDFs; Grep unbounded | `run_in_background` + output/kill tool on `BackgroundSupervisor`, with the pgid recorded at spawn (ISS-014); wire the dormant `StreamingDirectoryLister` as `LS`; image/PDF Read; `rg` + timeout (ISS-060, ISS-061) |
| Editing | No auto-format; LSP needs manual config | `formatCommands` + a new `PostEditFormatHook` (modelled on `PostEditLintHook`) that records into `ReadLedger`; LSP auto-detect table (user-tier opt-in) |
| Git / review | No `/review` or `/security-review` (Claude Code has `/code-review` + `/security-review`; OpenCode has `/review`); no GitHub Action. No competitor ships a built-in `/commit`, so one would be a lead | Built-in command specs reusing `CommitMessageWriter` and the `@diff` mention; a GitHub Action around `-p` |
| Sessions | `-c` crosses projects; retention skips every auto-titled session; no per-session cost, full-text search or import; share is a stub | Project-scoped resume (ISS-026); retention fix (ISS-027); spend per session; FTS5 search; implement or self-host share pages via `serve` |
| Observability | No OTel, `/status`, usage history | OTel exporter subscribed to `src/Events/*`; `/status` summary |
| Distribution | Composer-only, Linux-first, no LICENSE in the split package | PHAR + Docker image for `serve`, `self-update`, LICENSE file (ISS-120) |

### 6.6 TUI polish

- **Composer:**
  - paste chips and an input height cap (ISS-051);
  - readline keys stop typing letters (ISS-047);
  - pin the composer outside the scroll window (ISS-050);
  - window the `/` popup (ISS-046);
  - fuzzy `@` file popup;
  - reverse history search;
  - vim mode (sugar-forms already has a Vim layer);
  - configurable keybindings through the existing `HasKeyOverrides` seam;
  - non-Alt fallbacks for macOS.
- **Layout:**
  - tame auto-dock with a width threshold and remembered undocks (ISS-049);
  - fix the 1-column sliver (ISS-099);
  - wire the dormant `Tui\SessionTabs` and create sessions lazily (ISS-102).
- **Feedback:**
  - animated spinner, elapsed timer and stall notice (ISS-103);
  - async status line fed session JSON on stdin, like Claude Code's (ISS-095);
  - run the terminal probe after raw mode (ISS-048);
  - per-tool card summarisers and project-relative diff paths (ISS-100);
  - picker bleed (ISS-094).

### 6.7 sugar-crush-web

1. **Security first:** ISS-004, ISS-007, ISS-008, ISS-009, ISS-055 (see 6.1).
2. **Wire the 17 unused protocol methods (no new server code):**
   - Export (`session.export`), Fork (`session.fork`), Delete (`session.delete`: the store's `sessions.remove()` wraps it, but no component calls it);
   - a **Changes** panel on `files.changed` + `files.diff`;
   - a Rules view (`permission.rules`);
   - an "About this server" panel (`server.info`) and the **agent catalog** (`agents.list`);
   - an image/video Generate form gated on the already-computed `canGenerate*`.
3. **Host bodies for the terminal-only built-ins** (`/compact`, `/model`, `/init`, `/goal`/`/grind`), then a model picker and a Compact button in the status bar.
4. **A Context panel:** structured data from a **new** `context.sources` method or new `server.info` fields (loaded instruction files with paths and sizes, memory sections, skills, MCP status, untrusted-file notices) plus a server-scope **trust prompt**, so a browser user can see and grant what the engine loads.
5. **Live shell output** (a **new** `tool.progress` tail event, which is server work: today output arrives only at `tool.finished`), and composer parity: `@file` search, prompt history, image paste, AskUser choice buttons, Markdown plan approval, diff preview in Edit/Write permission cards.
6. **Robustness:**
   - handle `server.overflow` / `server.shutdown` (ISS-052);
   - a `handled` admission for `!cmd` and built-ins (ISS-056);
   - paged snapshots (ISS-058);
   - incremental Markdown rendering (ISS-108);
   - dispose released stores (ISS-112);
   - Memory panel on both stores (ISS-020);
   - j/k ignored while typing in the sidebar filter (ISS-053);
   - persist hashed cookie sessions so a server restart doesn't sign everyone out.
7. **Polish:** 375 px layout (ISS-105), `event.code` shortcuts (ISS-106), WCAG AA tokens (ISS-107), ANSI-to-span rendering (ISS-109), `role=alert` for new questions, a PWA manifest, i18n from the server's `Lang` catalogue.
8. **Ideas from the competitors:**
   - OpenCode v2's pairing links / QR codes and end-to-end tunnel for phone access;
   - Claude Code's Remote Control push notifications;
   - OpenClaw's Control UI side panels (Files / Terminal / Review), PR chips with CI status, and approvals answerable from chat apps.

---

## Appendix A — Per-feature detail (how, when, where, evidence)

Every cell of the matrix in §3 is expanded below with the researching agent's full DETAIL text and EVIDENCE, grouped by category; click a row to expand it. sugar-crush citations are repo-relative at `10f121d49`. Competitor citations point to the installed package, the source tags examined, or official doc pages.

### PLAT — Platform & distribution

<details><summary><b>PLAT-01</b> Implementation language / runtime</summary>

- **sugar-crush** ✅ — Runs on stock php-cli. Each interactive turn runs in a forked child (pcntl) that streams length-prefixed frames to the parent over a socket pair; without pcntl turns, parallel tools and sub-agents run in-process and the screen only updates when they finish. `serve` additionally needs ext-pcntl, ext-posix and ext-ffi (doctor checks). composer.json also *requires* ext-sqlite3, which the code never uses (doctor says so itself) — see ISSUES. — _Evidence:_ `sugar-crush/composer.json:34-65`; `README.md:92-99`; doctor output `pdo_sqlite usable; ext-sqlite3 (declared in composer.json, unused by the code) loaded`
- **sugar-crush-web** ✅ — `src-web/` holds the TS (kept out of the PSR-4 `src/`). Deps: @tanstack/vue-virtual (transcript), markdown-it + DOMPurify (rendering). A one-class PHP shim `SugarCraft\CrushWeb\Assets` tells `serve` where `dist/` is. Node is only needed to develop, never to run. — _Evidence:_ `sugar-crush-web/package.json`; `sugar-crush-web/src/Assets.php`; `sugar-crush-web/README.md`
- **Claude Code 2.1.301** ✅ — The native installer keeps versioned binaries under `~/.local/share/claude/versions/` with a launcher symlink at `~/.local/bin/claude` (observed: ELF x86-64, 2.1.301 = 256 MB on disk; the binary's strings contain Bun runtime messages). The npm package `@anthropic-ai/claude-code` is still a supported install path and runs under Node; docs reference both "the Node or Bun runtime" (Windows VT input note). Bundles ripgrep; on macOS/Linux/WSL the shell's `find`/`grep` are embedded `bfs`/`ugrep`. The Agent SDK (TS/Python) drives the same CLI as a subprocess. — _Evidence:_ local: `file ~/.local/bin/claude`, `du -sh …/versions/2.1.301`; cc:setup; cc:interactive-mode (Alt+M note); cc:tools-reference#glob-tool-behavior
- **OpenCode 1.18.35** ✅ — v1 layout: `packages/opencode` holds the agent loop and HTTP server, `packages/tui` the OpenTUI/Solid terminal client, `packages/core` the shared Effect services (plus the unused v2 runner), `packages/app` the web/desktop UI, and `packages/desktop` the Electron 42 shell. The npm package `opencode-ai` installs a per-platform prebuilt binary. Tree-sitter WASM is used for bash parsing and syntax highlighting. — _Evidence:_ `ls -la /home/my/.opencode/bin`; packages/desktop/package.json (`electron 42.3.3`); tag v1.18.35
- **OpenCode 2.0.31** ✅ — 1.18.35: packages/opencode (loop+server) + tui + core with AI SDK everywhere. 2.0.31: packages/core (Location-scoped services, durable session inbox, plugin host), packages/server (new HttpApi), packages/cli, packages/tui (OpenTUI/Solid), packages/app + desktop (Electron), packages/ai (own provider protocols; AI-SDK packages still usable via `aisdk:` prefix), packages/sdk (in-process host incl. Cloudflare workerd). npm `@opencode/cli` installs per-platform binaries; linux-x64 binary is 211,793,376 bytes. — _Evidence:_ V2 repo tag v2.0.31 (22728d6) packages/; npm pack @opencode/cli-linux-x64@2.0.31; V2D/build/sdk/index
- **OpenClaw 2026.9.8** ✅ — Gateway, CLI, TUI and the embedded agent runtime (derived from Mario Zechner's pi agent-core; `@earendil-works/pi-tui` 0.86.1 for the TUI) ship as the `openclaw` npm package (ESM bundles, ~7.5k modules in dist/). Node is the default/recommended runtime (24.16+ or 26.1+, Node 26 recommended); Bun 1.4+ is an explicit opt-in. Companion apps: macOS menu-bar app, iOS/watchOS, Android, Windows Hub (WinUI), Linux companion. Control UI is a Vite + Lit SPA served by the Gateway. State is SQLite (`node:sqlite`). — _Evidence:_ `oc/package.json`; `oc/README.md` (Install); `oc/docs/platforms/index.md`; `oc/docs/web/control-ui.md`; `oc/node_modules/@earendil-works/pi-tui/package.json`

</details>
<details><summary><b>PLAT-02</b> License / open source</summary>

- **sugar-crush** 🟡 — `composer.json` `"license": "MIT"`; the only LICENSE is the monorepo root's. The README badge links `LICENSE` relative to `sugar-crush/`, a broken link in the monorepo and in the split `sugarcraft/sugar-crush` repo (the sync workflow does not add one). Open source, original work (port of charmbracelet/crush, now far beyond). — _Evidence:_ `sugar-crush/composer.json:5`; `sugar-crush/README.md:9`; `ls /home/sites/sugarcraft/*/LICENSE` → none
- **sugar-crush-web** ✅ — Same license as the monorepo; package.json `"license": "MIT"`. — _Evidence:_ `sugar-crush-web/package.json`
- **Claude Code 2.1.301** ❌ — Use is governed by Anthropic Consumer Terms (Free/Pro/Max) or Commercial Terms (Team/Enterprise/API); preinstalling/running CC inside your own products requires Commercial Terms. github.com/anthropics/claude-code publishes CHANGELOG.md, the issue tracker and a `mods/` directory with source for the built-in `diff`, `agents-md`, `sec-default` and `telemetry` mods. Adjacent OSS: `@anthropic-ai/sandbox-runtime` (the sandbox), `anthropics/claude-code-action`, the `claude-plugins-official` marketplace repo. — _Evidence:_ cc:legal-and-compliance; cc:plugins/mods/overview#read-the-source-of-built-in-mods; cc:sandboxing
- **OpenCode 1.18.35** ✅ — The whole monorepo is MIT: TUI, server, desktop, web, SDKs and docs. Official SDK repos opencode-sdk-js/-go/-python are listed in awesome-opencode. — _Evidence:_ LICENSE; awesome-opencode README (Official Repositories)
- **OpenCode 2.0.31** ✅ — The whole monorepo is MIT: TUI, server, desktop, web, SDKs and docs. Official SDK repos opencode-sdk-js/-go/-python are listed in awesome-opencode. — _Evidence:_ LICENSE; awesome-opencode README (Official Repositories)
- **OpenClaw 2026.9.8** ✅ — License MIT. README: developed in the open by the OpenClaw Foundation, which employs the core team and signs releases; donors (incl. OpenAI) do not own it. "Has no paid tier, hosted service, or token." Built originally by Peter Steinberger (formerly Clawdbot/Moltbot). — _Evidence:_ `oc/LICENSE`; `oc/package.json` ("license": "MIT"); `oc/README.md` (Governance)

</details>
<details><summary><b>PLAT-03</b> Install methods (npm, brew, curl script, composer, binary, docker)</summary>

- **sugar-crush** 🟡 — Bin entry `bin/sugarcrush` via composer `bin`. A systemd user unit example ships for `serve` (`docs/examples/sugarcrush.service`); container use is documented (HOME, `SUGARCRUSH_SERVER_DIR`, `SUGARCRUSH_SERVER_TOKEN`) but no image is published. — _Evidence:_ `README.md:84-115`; `README.md:1823-1842`; `docs/SERVER.md:201-210`
- **sugar-crush-web** ✅ — `serve` finds the UI from `--web-root`, `SUGARCRUSH_SERVER_WEB_ROOT`, or the installed package; `--no-web` serves API only. The bundle is byte-deterministic (no timestamps) and CI (`web.yml`) fails when `dist/` drifts from `src-web/` — rebuilt here into scratch: identical. — _Evidence:_ `sugar-crush/docs/SERVER.md:994-1004`; `sugar-crush-web/vite.config.ts`; build diff (this audit)
- **Claude Code 2.1.301** ✅ — Native: `curl -fsSL https://claude.ai/install.sh` piped to bash (macOS/Linux/WSL), `irm https://claude.ai/install.ps1` piped to `iex` (PowerShell), an `install.cmd` for CMD. Homebrew: `claude-code` (stable channel) and `claude-code@latest`. WinGet `Anthropic.ClaudeCode`. apt/dnf/apk repos for Debian/Fedora/RHEL/Alpine. `npm install -g @anthropic-ai/claude-code` (no sudo). Dev container reference/feature. Package-manager installs don't auto-update unless `CLAUDE_CODE_PACKAGE_MANAGER_AUTO_UPDATE=1` (brew/winget). `claude install [stable/latest/<version>]` switches native builds. No official Docker image of the CLI itself (dev containers instead). — _Evidence:_ cc:setup; cc:devcontainer; help (`install`)
- **OpenCode 1.18.35** ✅ — Channels: `curl -fsSL https://opencode.ai/install` piped to bash, `npm i -g opencode-ai` (bun/pnpm/yarn too), `brew install anomalyco/tap/opencode` (recommended over core brew), `pacman -S opencode` / `paru -S opencode-bin`, `choco`, `scoop`, `mise use -g github:anomalyco/opencode`, `docker run ghcr.io/anomalyco/opencode`, and GitHub release binaries. `opencode upgrade --method` accepts curl/npm/pnpm/bun/brew/choco/scoop. — _Evidence:_ DOCS/index (Install); CLI: `opencode upgrade --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 used `opencode-ai` npm, brew anomalyco/tap/opencode, choco/scoop. 2.0.31: `curl -fsSL https://opencode.ai/v2/install`, `npm i -g @opencode/cli` (postinstall picks native binary), `brew install anomalyco/tap/opencode-v2`, `paru -S opencode-beta`, `mise use -g opencode` (mise 2026.10.9+ installs v2), official WinGet `Anomaly.OpenCode` (changelog 2.0.28) — though the v2 docs page still says Windows package managers are not supported (doc drift), Docker `ghcr.io/anomalyco/opencode:2.x`, standalone zips incl. baseline/musl. The v2 curl installer replaces the V1 binary. — _Evidence:_ V2D/index; V2D/migrate-v1 (intro); V2CL v2.0.28 (WinGet); install script lines 24-25,198
- **OpenClaw 2026.9.8** ✅ — `curl -fsSL https://openclaw.ai/install.sh` piped to bash (macOS/Linux/WSL2) and `iwr .../install.ps1` piped to iex (Windows) provision a supported Node and auto-start onboarding; `npm install -g openclaw@latest --allow-scripts=openclaw`; local-prefix `install-cli.sh`; pnpm/bun; Docker/Compose, Podman, Nix, Ansible, Kubernetes, from source / GitHub main; desktop app downloads (macOS, Windows Hub x64/ARM64); many hosting guides (Hetzner, Fly, GCP, Azure, Oracle, DigitalOcean, Raspberry Pi, exe.dev, Daytona, Upstash...). — _Evidence:_ `oc/docs/install/index.md`; `oc/README.md`; `oc/docs/install/docker.md`; `oc/docs/install/nix.md`; `oc/docs/platforms/windows.md`

</details>
<details><summary><b>PLAT-04</b> OS support (Linux / macOS / Windows native / WSL)</summary>

- **sugar-crush** 🟡 — Stated in the README banner. Clipboard image paste covers pngpaste/wl-paste/xclip. serve requires posix/ffi. — _Evidence:_ `README.md:18-21`; `README.md:214`
- **sugar-crush-web** 🟡 — `serve` refuses to start without ext-pcntl/posix/ffi and refuses native Windows; the page itself is plain ES modules + CSS. — _Evidence:_ `sugar-crush/docs/SERVER.md:1124-1142,1272-1275`
- **Claude Code 2.1.301** ✅ — x64 or ARM64, 4 GB+ RAM, internet required, Anthropic supported countries only. Native Windows works without Git for Windows (PowerShell tool becomes the shell); with Git Bash, Bash tool uses it. Sandbox: macOS, Linux, WSL2 only (not native Windows/WSL1). Computer use from the CLI is macOS-only. Alpine needs `libgcc libstdc++ ripgrep` + `USE_BUILTIN_RIPGREP=0`. — _Evidence:_ cc:setup#system-requirements; cc:sandboxing; cc:computer-use
- **OpenCode 1.18.35** ✅ — The shell tool knows bash, pwsh, powershell and cmd. `OPENCODE_GIT_BASH_PATH` points at Git Bash on Windows. Windows keybinding defaults differ: ctrl+z undo, and no suspend. The desktop app needs WebView2 on Windows. Docs recommend WSL for best results. — _Evidence:_ OC/tool/shell/id.ts:1-17; DOCS/windows-wsl; DOCS/keybinds (Windows note)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Linux, macOS, Windows native (pwsh/cmd/Git Bash shells; WSL recommended). 2.0.31 (✅): 2.0.28 added native Windows ARM64 CLI/desktop builds; musl and x64-baseline Linux binaries are published. — _Evidence:_ V2CL v2.0.28; V2D/index (download list)
- **OpenClaw 2026.9.8** ✅ — Windows: native PowerShell CLI/Gateway, Windows Hub WinUI companion (Win10 20H2+/Win11, x64 + ARM64), or WSL2 for the most Linux-compatible Gateway. ChromeOS via Crostini, Omarchy, Raspberry Pi. Mobile devices run as companion nodes, not gateways. FreeBSD appears in 2026.10.3 update-recovery notes. — _Evidence:_ `oc/docs/platforms/index.md`; `oc/docs/platforms/windows.md`; `oc/docs/platforms/chromeos.md`

</details>
<details><summary><b>PLAT-05</b> Self-update / release channels</summary>

- **sugar-crush** ❌ — Version comes from `Composer\InstalledVersions` (pretty version + 7-char ref for dev). On a monorepo checkout the ref is whatever the last `composer install` recorded, so it goes stale (measured 115 commits behind HEAD). No update check, no channel selection. — _Evidence:_ `src/Cli/Help.php:92-139`; `--version` output `dev-master (d2f2a58)` vs HEAD `10f121d49`
- **sugar-crush-web** 🟡 — `SugarCrushClient.firstVersion` vs the latest `server.hello` version drives `ConnectionBanner`'s "The server was updated" + Reload. Updating is `composer update`. — _Evidence:_ `sugar-crush-web/src-web/protocol/client.ts:121-124`; `src-web/components/ConnectionBanner.vue`
- **Claude Code 2.1.301** ✅ — Checks at startup and periodically, installs in the background, takes effect next launch; `stable` is ~1 week old and skips releases with major regressions. `claude install <version>` pins; `DISABLE_AUTOUPDATER`; `claude respawn [--all]` restarts background sessions on the new binary; `/release-notes` version picker (shown in transcript, not sent to Claude); `claude doctor` reports the last update attempt. — _Evidence:_ cc:setup#auto-updates; cc:setup#configure-release-channel; help (`update`, `install`, `respawn`)
- **OpenCode 1.18.35** ✅ — `autoupdate` (default on) downloads updates at startup. `"notify"` only tells you. It does not apply to package-manager installs such as brew. `OPENCODE_DISABLE_AUTOUPDATE` and `OPENCODE_ALWAYS_NOTIFY_UPDATE` override it. `opencode upgrade <target>` pins a version. `opencode uninstall` takes `--keep-config` / `--keep-data` / `--dry-run`. v2 ships as a separately installed package rather than an in-place upgrade. — _Evidence:_ DOCS/config (Autoupdate); CLI: `opencode upgrade --help`, `opencode uninstall --help`; opencode.ai/v2/docs
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `autoupdate` true/false/notify on startup; `opencode upgrade [ver] --method`; separate v2 channel (@opencode/cli). 2.0.31 (✅): V1 `autoupdate` maps to `update` (`true`→auto, `notify`, `false`→disable). TUI `/update` and post-update highlights dialog (`/highlights`, disable via cli.json `highlights:false`). Both lines are actively released: npm `opencode-ai` latest 1.19.3 and `@opencode/cli` latest 2.0.31 were both published 2026-10-16. — _Evidence:_ V2CLI: `opencode upgrade --help`; V2D/migrate-v1 (autoupdate); V2CL v2.0.28; `npm view opencode-ai`/`@opencode/cli` dist-tags
- **OpenClaw 2026.9.8** ✅ — Four channels: stable (npm `latest`), extended-stable (trailing supported month), beta, dev (git main). Automatic updates, guarded upgrades with Doctor, rollback/recovery journal. Owners can update from chat (`/update`, or the `gateway` tool `update.run` on explicit request) and from Control UI Updates; completion/failure notice posted back. At research time npm latest = 2026.10.4. — _Evidence:_ `oc/docs/install/development-channels.md`; `oc/docs/cli/update.md`; `oc/docs/install/updating/automatic-updates.md`; `oc/docs/tools/slash-commands.md`; `npm view openclaw dist-tags`

</details>
<details><summary><b>PLAT-06</b> Interfaces offered (TUI / web / desktop / IDE / mobile / chat apps)</summary>

- **sugar-crush** ✅ — `serve` = WebSocket+HTTP JSON-RPC server (loopback by default, owner token + one-time sign-in URL) that serves `sugarcraft/sugar-crush-web` when installed; `attach [<session>] [--url]` runs the TUI against a server session; `acp` speaks Agent Client Protocol on stdio. All dispatched from `bin/sugarcrush` before any TTY attach. — _Evidence:_ `bin/sugarcrush:431-441`; `src/Cli/ParsedArgs.php:55`; `src/Cli/{Serve,Attach,Acp}.php`; `--help` output
- **sugar-crush-web** ✅ — One page: sessions sidebar, tabs + 3×3 grid, approvals drawer, side panels, settings form. The TUI can attach to the same server session the browser shows. — _Evidence:_ `sugar-crush-web/README.md`; `sugar-crush/docs/SERVER.md:857-911`
- **Claude Code 2.1.301** ✅ — Same engine on every surface; config, project memory and MCP servers are shared across local surfaces. CLI is the most complete (scripting, Agent SDK, third-party providers, computer use on macOS). Desktop = Chat/Cowork/Code tabs with parallel sessions, diff viewer, previews, Dispatch (Pro/Max). Web/mobile run cloud sessions; Remote Control drives a local session from web/mobile. Slack (Pro/Max, cloud) and Claude Tag (Team/Enterprise). — _Evidence:_ cc:platforms; cc:desktop; cc:mobile; cc:remote-control
- **OpenCode 1.18.35** ✅ — Surfaces: full-screen TUI (`opencode`); inline split-footer mode (`--mini`, `run -i`); `opencode web`; OpenCode Desktop (Electron); VS Code-family extension `sst-dev.opencode`; ACP for Zed/JetBrains/Neovim; GitHub Action and app; GitLab CI/Duo. The repo also has an unpublished Slack bot sample. There is no mobile app. — _Evidence:_ CLI: `opencode --help`; DOCS/ide, DOCS/acp, DOCS/github, DOCS/gitlab; packages/slack/README.md
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): TUI (+--mini inline), web UI, Electron desktop, VS Code ext, ACP editors, GitHub/GitLab bots; no mobile app. 2.0.31 (✅): Adds: shared background service all clients attach to, `opencode pair` one-time links/QR for browsers and the OpenCode iOS app, installable web app, OpenTunnel remote access. Removed from the v2 CLI: `github`, `pr`, `web`, `agent create`, `db` (GitHub Action in the v2 repo still installs v1 and runs `opencode github run`). — _Evidence:_ V2CLI: `opencode --help`; V2D/cli/web; V2CL v2.0.27/2.0.29/2.0.30; github/action.yml:61-70
- **OpenClaw 2026.9.8** ✅ — Surfaces all attach to one Gateway: `openclaw tui` / `openclaw chat` (local embedded), Control UI dashboard, macOS menu-bar app / Windows Hub / Linux companion, iOS/watchOS/Android nodes, chat channels (Telegram, WhatsApp, Discord, Slack, iMessage, Signal, Matrix, Teams, ...), `openclaw acp` for ACP editors, `openclaw mcp serve`, `/v1/chat/completions`. No VS Code or JetBrains extension. — _Evidence:_ `oc/README.md` (How it fits together); `oc/docs/web/tui.md`; `oc/docs/channels/index.md`; `oc/docs/cli/acp.md`

</details>
<details><summary><b>PLAT-07</b> Telemetry & opt-out</summary>

- **sugar-crush** ✅ — `ModelMetadata` fetches `raw.githubusercontent.com/BerriAI/litellm/.../model_prices_and_context_window.json` lazily (first lookup), in a double-forked detached grandchild, cached in `~/.sugar-crush/cache/` for 24 h; `SUGARCRUSH_DISABLE_MODEL_METADATA=1` turns it off. `share` upload is a stub that never sends. grep for telemetry/sentry/posthog/otel finds nothing. — _Evidence:_ `src/Providers/ModelMetadata.php:71,336-390`; `src/Share/ShareUploader.php`
- **sugar-crush-web** ✅ — Fonts are system stacks, no CDN, no analytics. Only per-viewer conveniences (theme, drafts, tabs, layout) go to localStorage under `sugar-crush-web:`. — _Evidence:_ `sugar-crush/src/Server/Http/Responses.php:29-36`; `src-web/stores/storage.ts`
- **Claude Code 2.1.301** ✅ — Metrics (latency, reliability, usage) never include code, prompts or file paths; error reports redact secrets/paths/emails; both off by default on Bedrock/Agent Platform/Foundry/Claude Platform on AWS. Session-quality surveys separate (`CLAUDE_CODE_DISABLE_FEEDBACK_SURVEY`). Gotcha: turning telemetry off also stops feature-flag fetching, which silently disables flag-dependent features (prompt suggestions, You-should-know mod, Monitor tool, `/skill-doctor`, claude.ai skill sync, Remote Control eligibility). Analytics are sent by the built-in `cc-plugin-telemetry` mod. — _Evidence:_ cc:data-usage#telemetry-services; cc:env-vars#features-that-need-feature-flag-fetching; cc:plugins/mods/overview#mods-built-into-claude-code
- **OpenCode 1.18.35** 🟡 — I grepped OC/, TUI/ and CORE/ for posthog, segment, mixpanel and telemetry SDKs and found none. The web and desktop renderers call `Sentry.init` only when `VITE_SENTRY_DSN` is set at build time, and no user opt-out is documented. Outbound calls: the models catalog (`https://models.opencode.ai`, off with `OPENCODE_DISABLE_MODELS_FETCH`), update checks (`OPENCODE_DISABLE_AUTOUPDATE`), npm plugin installs, LSP downloads, and share sync only for shared sessions. OpenCode-hosted providers (Zen/Go) receive `x-opencode-*` project/session headers. — _Evidence:_ APP/entry.tsx:133-137; packages/desktop/src/renderer/index.tsx:40-42; CORE/models-dev.ts:160; OC/session/llm/request.ts:184-201
- **OpenCode 2.0.31** 🟡 — I grepped OC/, TUI/ and CORE/ for posthog, segment, mixpanel and telemetry SDKs and found none. The web and desktop renderers call `Sentry.init` only when `VITE_SENTRY_DSN` is set at build time, and no user opt-out is documented. Outbound calls: the models catalog (`https://models.opencode.ai`, off with `OPENCODE_DISABLE_MODELS_FETCH`), update checks (`OPENCODE_DISABLE_AUTOUPDATE`), npm plugin installs, LSP downloads, and share sync only for shared sessions. OpenCode-hosted providers (Zen/Go) receive `x-opencode-*` project/session headers. — _Evidence:_ APP/entry.tsx:133-137; packages/desktop/src/renderer/index.tsx:40-42; CORE/models-dev.ts:160; OC/session/llm/request.ts:184-201
- **OpenClaw 2026.9.8** ✅ — "Automatic update checks send a daily request by default" with OpenClaw version, OS, Node version, CPU arch and request surface (server derives approximate location). Anonymous feature statistics (configured channel/provider kinds, plugin inventory, session-creation count) are off by default and ride the same request. `openclaw telemetry show` previews the exact payload; `update.checkOnStart: false` disables all automatic requests. Diagnostics export (OTel) only if you enable it. — _Evidence:_ `oc/docs/gateway/telemetry.md`; `oc/README.md`

</details>
<details><summary><b>PLAT-08</b> Offline / air-gapped operation (local models only)</summary>

- **sugar-crush** ✅ — No provider selected → `EchoProvider` through the full engine (stderr says so on `-p`). A failed LiteLLM refresh keeps the old file and waits a day. Caveat: the package ships `.sugar-crush/config.dev.json` naming a maintainer demo endpoint (`skynet2.interserver.net`), selectable but never default. — _Evidence:_ `src/Cli/NonInteractive.php:207-221`; `src/Cli/Bootstrap.php:10001-10015`; `sugar-crush/.sugar-crush/config.dev.json`; `README.md:156-161`
- **sugar-crush-web** ✅ — Nothing is fetched from outside the server origin; model reachability is the server's concern (sglang/custom local endpoints). — _Evidence:_ `sugar-crush-web/dist/index.html`; CSP above
- **Claude Code 2.1.301** ❌ — Network access is a system requirement. Air-gapped-ish enterprise setups use Bedrock/Agent Platform/Foundry private endpoints, an LLM gateway, or self-hosted cloud environments, but all still call Claude. Local state (transcripts, checkpoints, memory) is on disk, the agent loop is not. — _Evidence:_ cc:setup#system-requirements; cc:llm-gateway; cc:gateways
- **OpenCode 1.18.35** ✅ — Any local OpenAI-compatible server works through a custom provider using `@ai-sdk/openai-compatible`. This machine runs SGLang on skynet2 that way. Air-gap switches: `OPENCODE_DISABLE_MODELS_FETCH` / `OPENCODE_MODELS_PATH`, `OPENCODE_DISABLE_AUTOUPDATE`, `OPENCODE_DISABLE_LSP_DOWNLOAD`, `--pure` (no external plugins), and `share: disabled`. Unpinned npm plugins are fetched at startup, so offline use needs pinned or local plugins. websearch/webfetch obviously need network. — _Evidence:_ DOCS/providers (llama.cpp, LM Studio, Ollama); LOCAL: ~/.config/opencode/opencode.jsonc (sglang provider); CLI: `--pure`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Local models via Ollama/LM Studio/llama.cpp/any OpenAI-compatible; flags disable models.dev fetch, autoupdate, LSP downloads. 2.0.31 (✅): Same capability; custom providers now `providers.<id>.package: "aisdk:@ai-sdk/openai-compatible"` with `settings.baseURL`. Plugins are installed in the background at service start; pinned versions stay offline-safe. — _Evidence:_ V2D/migrate-v1 (Providers); V2D/plugins (Reload)
- **OpenClaw 2026.9.8** ✅ — Local providers ship in core (Ollama local+cloud, SGLang, vLLM, LM Studio); `@openclaw/llama-cpp-provider` installs a verified llama-server + GGUF for chat and local embeddings (`memory.search.provider: "local"`, keyword-only fallback otherwise). `tts-local-cli` plugin, whisper skills, `agent exec --local-model-lean`. Disable update checks for air-gapped. Channels themselves need network. — _Evidence:_ `oc/docs/gateway/local-models.md`; `oc/docs/concepts/memory-search.md`; `oc/docs/plugins/llama-cpp.md`; `oc/docs/cli/agent.md`

</details>
<details><summary><b>PLAT-09</b> Startup time / footprint</summary>

- **sugar-crush** ✅ — Measured on PHP 8.3.6 (no opcache CLI). Help/version/subcommands never construct Program, so they are fast. Footprint dominated by aws-sdk-php and google/cloud-ai-platform. — _Evidence:_ measurements in header of this file; `scratchpad/a3/tools/ttfb.py`
- **sugar-crush-web** ✅ — Measured by the scratch build. The first cold `serve` start here took more than 15 s; warm restarts printed the sign-in line in ~10 s (wall clock incl. PHP start). — _Evidence:_ build output (this audit); `sugar-crush-web/dist/assets/`
- **Claude Code 2.1.301** 🟡 — Measured locally: `claude --version` ≈10 ms, 40 MB RSS (fast path only, not a full session start). `--bare` skips hooks, LSP, plugin sync, attribution, auto-memory, background prefetches, keychain reads and CLAUDE.md discovery (sets CLAUDE_CODE_SIMPLE=1). Fullscreen renderer keeps memory flat in long conversations; `/heapdump`; `CLAUDE_CODE_TOOL_MEMORY_LIMIT` caps Bash/Monitor memory via cgroup on Linux. — _Evidence:_ local: `/usr/bin/time claude --version`; help (`--bare`, `--safe-mode`); cc:fullscreen; cc:tools-reference#memory-limit-on-linux-and-wsl
- **OpenCode 1.18.35** 🟡 — Measured 3× on this host with `/usr/bin/time opencode --version`: 1.11-1.12 s and 204 MB max RSS, for printing the version alone. I did not start the TUI (forbidden by the brief), so interactive cold-start was not measured. `opencode debug startup` exists for timing. MCP cold boot is the documented reason for `run --attach` to a warm server. — _Evidence:_ measured locally; CLI: `opencode debug --help`; DOCS/cli (run --attach rationale)
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): `opencode --version` measured 1.1 s / ~200 MB RSS; 186 MB binary; `opencode debug startup` prints timing. 2.0.31 (✅): Measured 3x on this host in an isolated fake HOME with the npm-packed linux-x64 2.0.31 binary: 0.14-0.16 s, 88.7-89.5 MB max RSS. The TUI was not launched. Startup of real sessions depends on the shared background service (already running = fast attach). — _Evidence:_ measured: scratch npmpack/linux/package/bin/opencode --version
- **OpenClaw 2026.9.8** 🟡 — Measured locally: `openclaw --version` 0.14 s wall / 68.7 MB max RSS (Node compile cache warm); `du -sh` of the installed package = 735 MB (dist + node_modules). The real runtime is a long-lived Gateway process with SQLite state, so per-message startup is not paid; first run of onboarding/gateway not measured (not started per brief). No published footprint numbers. — _Evidence:_ Local `/usr/bin/time openclaw --version`; `du -sh oc/`

</details>
<details><summary><b>PLAT-10</b> Localization / i18n of the UI</summary>

- **sugar-crush** 🟡 — `Lang::useLaunchLocale()` is the first statement after autoload; any non-en locale falls back to en because `lang/` contains only `en.php`. Some strings remain hard-coded (e.g. the no-TTY refusal in bin/sugarcrush). — _Evidence:_ `bin/sugarcrush:227`; `src/Lang.php:74-89`; `lang/en.php` (only file)
- **sugar-crush-web** ❌ — Server error messages arrive localised via `Lang::t`, but every label, button and help text in `src-web/` is a literal. — _Evidence:_ `sugar-crush-web/src-web/components/*.vue`
- **Claude Code 2.1.301** 🟡 — `language` (any settings file, unvalidated string such as "japanese") is passed verbatim as an instruction to always respond in that language; voice dictation has a fixed supported-language list; session titles otherwise follow the conversation language. Word motions handle CJK; spellcheck skips CJK/Thai/Lao/Khmer/Myanmar. No translated interface. — _Evidence:_ cc:settings-reference#language; cc:interactive-mode; cc:voice-dictation
- **OpenCode 1.18.35** 🟡 — `APP/i18n/` has about 60 locale files (am … zh, zht) with a parity test. The TUI has no i18n layer. Docs exist in about 17 languages (ar, bs, da, de, es, fr, it, ja, ko, nb, pl, pt-br, ru, th, tr, zh-cn, zh-tw). — _Evidence:_ APP/i18n/*.ts; packages/web/src/content/docs/<lang>/
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): TUI English-only; desktop/web app ships ~60 locales; docs translated into ~17 languages. 2.0.31 (🟡): APP runtime i18n has ~67 locale files (packages/app/src/runtime/i18n). The v2 docs site under services/www is English; console docs proxy by locale. — _Evidence:_ packages/app/src/runtime/i18n/; README.*.md
- **OpenClaw 2026.9.8** ✅ — Control UI picks locale from the browser (en, ar, de, es, fa, fr, hi, id, it, ja-JP, ko, nl, pl, pt-BR, ru, th, tr, uk, vi, zh-CN, zh-TW), override in Settings → Appearance → Language, lazy-loaded, English fallback. Docs site generated for the same locales. CLI and TUI strings are English. — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Language support)

</details>

### MOD — Models & providers

<details><summary><b>MOD-01</b> Anthropic API</summary>

- **sugar-crush** 🟡 — `ProviderFactory::createAnthropic()` builds a `CustomProvider` with Anthropic headers; tools go out as OpenAI `tools`, images as `image_url`, streaming with `stream_options.include_usage`. Consequences: no cache_control breakpoints, no native thinking blocks/budget, no PDF/document blocks, default max_tokens 4096. Native Claude is available through `vertex` (rawPredict, native body) and `bedrock` (Converse), or `claude-code` (shells to the CLI). Docs are honest about this (Limitations). Default model `claude-sonnet-4-6`. — _Evidence:_ `src/Providers/ProviderFactory.php:983-1056`; `README.md:1431-1432`; `README.md:2189`
- **sugar-crush-web** 🟡 — The browser can only use the provider/model the server process resolved at launch (flag → env → config). The status bar shows `provider · model` read-only from the session summary. — _Evidence:_ `src-web/components/StatusBar.vue:28-32`
- **Claude Code 2.1.301** ✅ — Auth precedence covers env `ANTHROPIC_API_KEY`/`ANTHROPIC_AUTH_TOKEN`, `apiKeyHelper`, stored OAuth. `--betas` adds beta headers (API-key users). First-party-only extras: advisor server tool, server-side auto-mode review, web search, tool search, feature flags. — _Evidence:_ cc:authentication; cc:model-config; help (`--betas`)
- **OpenCode 1.18.35** ✅ — Built-in `anthropic` provider. `anthropic.txt` base prompt for `claude` model IDs. Ephemeral cacheControl on the first 2 system and last 2 messages. Built-in variants `high` (default) and `max` thinking budgets. — _Evidence:_ OC/session/system.ts:43; OC/provider/transform.ts:356-385; DOCS/models (Variants)
- **OpenCode 2.0.31** ✅ — Built-in `anthropic` provider. `anthropic.txt` base prompt for `claude` model IDs. Ephemeral cacheControl on the first 2 system and last 2 messages. Built-in variants `high` (default) and `max` thinking budgets. — _Evidence:_ OC/session/system.ts:43; OC/provider/transform.ts:356-385; DOCS/models (Variants)
- **OpenClaw 2026.9.8** ✅ — Included `anthropic` plugin. Two routes: API key (anthropic-messages) or Claude CLI runtime (`claude-cli/*`, Claude Code owns its login). Prompt caching, `clear_tool_uses_20250919` server-side context edits, adaptive/xhigh/max effort mapping for Opus 4.7+, Admin API cost history in Control UI Usage. — _Evidence:_ `oc/docs/providers/anthropic.md`; `oc/docs/concepts/session-pruning.md`; `oc/docs/tools/thinking.md`

</details>
<details><summary><b>MOD-02</b> OpenAI API (Chat Completions / Responses)</summary>

- **sugar-crush** 🟡 — Default model gpt-4o; built-in price/vision tables (vision list includes gpt-5/o1/o3/o4-mini); LiteLLM DB for unknown prices. `complete()`/`completeStream()` always send `temperature: 0.7` and `max_tokens: 4096` (or maxOutputTokens), no `max_completion_tokens`, no `reasoning_effort` — so the README's own `--model gpt-5` example fails with a 400. See ISSUES P1. — _Evidence:_ `src/Providers/OpenAIProvider.php:366-445`; `src/Providers/OpenAIProvider.php:100-112`; `README.md:1559`
- **sugar-crush-web** 🟡 — As MOD-01. — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ❌ — The gateway compatibility guide lists only those three API formats (Foundry and Claude Platform on AWS implement Anthropic Messages). Docs state Anthropic "doesn't support routing Claude Code to non-Claude models through any gateway". — _Evidence:_ cc:llm-gateway-protocol#api-formats; cc:llm-gateway
- **OpenCode 1.18.35** ✅ — OpenAI provider via @ai-sdk/openai (Responses). Base prompts: beast.txt for gpt-4/o1/o3, gpt.txt, gpt-astra.txt for gpt-6, codex.txt. `gpt-*` models other than oss and gpt-4 get `apply_patch` and lose edit/write. Tools are sent with `strict:false`. `promptCacheKey` is the session ID. Variants none…xhigh. — _Evidence:_ OC/session/system.ts:33-41; OC/tool/registry.ts:297-300; OC/session/llm/request.ts:150-160; OC/provider/transform.ts:1323-1336
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): OpenAI (Responses via @ai-sdk/openai); GPT models get apply_patch instead of edit/write; codex/gpt prompts. 2.0.31 (✅): GPT-family models get the `patch` tool instead of edit/write (docs). ChatGPT OAuth requests default to zstd body encoding (`settings.encoding`). Native OpenAI Responses compaction is available (`settings.compaction.type: native`). — _Evidence:_ V2D/tools (Patch); V2CL v2.0.28 (encoding); V2D/compaction (Providers); V2C/plugin/optimize.ts:15-19
- **OpenClaw 2026.9.8** ✅ — Included `openai` plugin; API key and ChatGPT/Codex OAuth share the `openai` provider id. Responses requests preserve cached prefixes across effort changes (`configuration_update`). Built-in GPT-5 `stablePrefix` behaviour contract + `personality` (friendly default). Optional native Codex harness plugin. Azure under the same docs. — _Evidence:_ `oc/docs/providers/openai.md`; `oc/docs/concepts/system-prompt.md`; `oc/docs/providers/openai/authentication.md`

</details>
<details><summary><b>MOD-03</b> Google Gemini / Vertex AI</summary>

- **sugar-crush** 🟡 — `VertexProvider` routes `gemini-*` to `:generateContent`/`:streamGenerateContent` with `contents`/`systemInstruction`, `inlineData` images, `thinkingConfig.thinkingBudget`. Docs admit the Gemini thinking changes were not checked against a live endpoint. A Gemini key could be used via `custom` + Google's OpenAI-compat URL (undocumented). Price table has 2.0/2.5 only. — _Evidence:_ `src/Providers/VertexProvider.php:70-90,2015-2025,195-230`; `docs/SETTINGS.md:469-478`
- **sugar-crush-web** 🟡 — As MOD-01. — _Evidence:_ `server.info` providers list (live probe)
- **Claude Code 2.1.301** 🟡 — `CLAUDE_CODE_USE_VERTEX=1`, `ANTHROPIC_VERTEX_BASE_URL`; alias table differs per provider (opus→Opus 5.5, sonnet→Sonnet 4.5, haiku→Haiku 4.5 there); tool search only on 4.5+ models there; auto mode only on Sonnet 5+/Opus 4.7+/Haiku 5.5/Fable on that provider. — _Evidence:_ cc:google-vertex-ai; cc:model-config#model-aliases; cc:mcp#configure-tool-search; cc:permission-modes
- **OpenCode 1.18.35** ✅ — Google (AI Studio) and Google Vertex AI providers from the models.dev catalog. `gemini-*` model IDs get the gemini.txt base prompt. Variants `low` and `high`. — _Evidence:_ OC/session/system.ts:42; DOCS/providers (Google Vertex AI); DOCS/models
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Google Gemini + Vertex AI providers; gemini.txt base prompt; low/high variants. 2.0.31 (✅): 2.0.26 added Google Cloud ADC credentials to the Vertex connect flow; 2.0.28 continues split Gemini/Vertex responses via provider continuation tokens. The v1 gemini.txt base prompt is gone (generic system.txt used). — _Evidence:_ V2CL v2.0.26, v2.0.28; V2C/plugin/optimize.ts (no gemini plugin)
- **OpenClaw 2026.9.8** ✅ — `google` plugin is included (Gemini 3 / 2.5, `/think adaptive` → dynamic thinking, Gemini embeddings incl. image/audio indexing, Google Live realtime voice). `@openclaw/anthropic-vertex-provider` is an installable official plugin for Anthropic on Vertex. — _Evidence:_ `oc/docs/providers/google.md`; `oc/docs/plugins/reference/google.md`; `oc/docs/plugins/reference/anthropic-vertex.md`

</details>
<details><summary><b>MOD-04</b> AWS Bedrock</summary>

- **sugar-crush** ✅ — Default model `us.anthropic.claude-sonnet-4-6`, region us-east-1. Credentials left to the SDK default chain (env, profile, instance role). Streaming is incremental (the SDK sets `@http.stream=true` for event streams). Caveats: SDK-internal retries stack with the engine's retry ladder; connect bound not honoured on the streaming transport (see ISSUES). — _Evidence:_ `src/Providers/BedrockProvider.php:300-325,620-650`; `vendor/aws/aws-sdk-php/src/AwsClient.php:668-689`
- **sugar-crush-web** 🟡 — As MOD-01. — _Evidence:_ `server.info` providers list (live probe)
- **Claude Code 2.1.301** ✅ — Telemetry/error reporting default off; WebSearch, PushNotification, Monitor, routines, Remote Control, channels, voice are unavailable on Bedrock; fallback targets resolve through deployment model IDs. — _Evidence:_ cc:amazon-bedrock; cc:claude-platform-on-aws; cc:tools-reference
- **OpenCode 1.18.35** ✅ — `amazon-bedrock` options: `region`, `profile`, `endpoint`. `AWS_BEARER_TOKEN_BEDROCK` or `/connect` takes precedence over profiles. Caching uses Bedrock `cachePoint`. — _Evidence:_ DOCS/config (Amazon Bedrock); DOCS/providers; OC/provider/transform.ts:370-372
- **OpenCode 2.0.31** ✅ — `amazon-bedrock` options: `region`, `profile`, `endpoint`. `AWS_BEARER_TOKEN_BEDROCK` or `/connect` takes precedence over profiles. Caching uses Bedrock `cachePoint`. — _Evidence:_ DOCS/config (Amazon Bedrock); DOCS/providers; OC/provider/transform.ts:370-372
- **OpenClaw 2026.9.8** 🔌 — Not in the core package: "Install route: npm or ClawHub: clawhub:@openclaw/amazon-bedrock-provider". Uses the AWS credential chain; Bedrock also available as a memory-embedding provider. Onboarding/`openclaw plugins install` fetch it. — _Evidence:_ `oc/docs/plugins/reference/amazon-bedrock.md`; `oc/docs/providers/bedrock.md`; `oc/docs/providers/bedrock-mantle.md`

</details>
<details><summary><b>MOD-05</b> Azure OpenAI / Foundry</summary>

- **sugar-crush** 🟡 — grep for azure in src/docs finds nothing. Bearer-key auth on `custom` works only with Azure's newer `/openai/v1/` surface; deployments of reasoning models also hit the MOD-02 parameter bug. — _Evidence:_ `src/Providers/ProviderFactory.php:89-121` (type roster)
- **sugar-crush-web** 🟡 — No Azure-specific surface anywhere in the web UI.
- **Claude Code 2.1.301** 🟡 — `CLAUDE_CODE_USE_FOUNDRY`, `ANTHROPIC_FOUNDRY_BASE_URL`; Azure-hosted Foundry deployments reject tool search so MCP tools load upfront; alias table: opus→Opus 4.6 there. — _Evidence:_ cc:microsoft-foundry; cc:mcp#scale-with-mcp-tool-search; cc:model-config#model-aliases
- **OpenCode 1.18.35** ✅ — Both providers are documented. A built-in azure plugin handles auth. `useCompletionUrls` switches to the chat-completions URL and strips Responses-only options. — _Evidence:_ DOCS/providers (Azure OpenAI, Azure Cognitive Services); OC/session/llm/request.ts:88-94; OC/plugin/azure.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Azure OpenAI + Azure Cognitive Services providers (useCompletionUrls option). 2.0.31 (✅): Provider ID `azure-cognitive-services` → `azure` on migration; 2.0.28 stopped querying Azure resources at startup. — _Evidence:_ V2D/migrate-v1 (provider ID table); V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — Azure OpenAI documented under the OpenAI provider; `microsoft-foundry` plugin is included in OpenClaw; Foundry keeps client-side pruning. — _Evidence:_ `oc/docs/providers/openai/azure.md`; `oc/docs/plugins/reference/microsoft-foundry.md`

</details>
<details><summary><b>MOD-06</b> OpenRouter / aggregators</summary>

- **sugar-crush** 🟡 — `OPENAI_BASE_URL` redirects `openai`, `sglang` and `custom` built-ins; named provider blocks in config can declare any base URL/model/apiKey (`${VAR}` interpolation). Pricing from LiteLLM exact match (incl. `provider/id` form). — _Evidence:_ `docs/ENVIRONMENT.md:255-258`; `src/Providers/ProviderFactory.php:118-121,407-436`
- **sugar-crush-web** 🟡 — As MOD-01.
- **Claude Code 2.1.301** 🟡 — Gateways centralise credentials, budgets, audit logs, provider switching; `allowedProviders: ["customEndpoint"]` (managed) forces gateway use; model discovery via the gateway's models endpoint cached in `~/.claude/cache/gateway-models.json`. `claude gateway --config <yaml>` runs the enterprise auth/telemetry gateway. Aggregators (OpenRouter-style) only work if they expose Anthropic Messages format, and non-Claude models are explicitly unsupported. — _Evidence:_ cc:llm-gateway; cc:llm-gateway-protocol#model-discovery; cc:claude-apps-gateway; help (`gateway`)
- **OpenCode 1.18.35** ✅ — All are first-class directory entries. OpenRouter also gets cacheControl. OpenCode Zen (curated pay-as-you-go) and OpenCode Go (subscription) are OpenCode's own gateways. — _Evidence:_ DOCS/providers (directory headings); DOCS/zen; DOCS/go
- **OpenCode 2.0.31** ✅ — All are first-class directory entries. OpenRouter also gets cacheControl. OpenCode Zen (curated pay-as-you-go) and OpenCode Go (subscription) are OpenCode's own gateways. — _Evidence:_ DOCS/providers (directory headings); DOCS/zen; DOCS/go
- **OpenClaw 2026.9.8** ✅ — OpenRouter (`/login openrouter` browser flow; `openclaw models scan` for free models), LiteLLM, Vercel AI Gateway, Cloudflare AI Gateway, ClawRouter managed routing, Kilocode, OpenCode Zen/Go, Together, Fireworks, etc. — _Evidence:_ `oc/docs/providers/index.md`; `oc/docs/providers/openrouter.md`; `oc/docs/tools/slash-commands.md`

</details>
<details><summary><b>MOD-07</b> Local models (Ollama, LM Studio, llama.cpp, vLLM, SGLang)</summary>

- **sugar-crush** ✅ — SglangProvider reads `/model_info` + `/server_info` once per session for context window and `has_image_understanding`, session-affinity headers, heartbeat on batch path; `toolCallParser` openai/minimax-xml-fallback/dsml. Caveat: streamed requests bound the response-header wait to 15 s (servers that withhold headers until first token, e.g. Ollama, can fail) — ISSUES P2. — _Evidence:_ `src/Providers/SglangProvider.php:600-670,1118-1160`; `README.md:1440-1535`; `src/Providers/Concerns/HttpClientDefaults.php:284-300`
- **sugar-crush-web** 🟡 — Works for web sessions exactly as for TUI sessions of the same server. — _Evidence:_ `server.info` providers: anthropic, bedrock, claude-code, custom, dev-sglang, openai, sglang, vertex
- **Claude Code 2.1.301** ❌ — Unsupported per docs. Unrecognized model IDs behind a gateway get conservative request shapes (fixed thinking budget, no effort/context-management fields, assumed window) — community hacks exist but are outside support. — _Evidence:_ cc:gateways; cc:llm-gateway-protocol; cc:model-config#correct-the-window-for-a-gateway-or-custom-model-id
- **OpenCode 1.18.35** ✅ — Local engines are configured as custom providers with `baseURL` and model `limit`s. This host's global config defines an `sglang` provider (`@ai-sdk/openai-compatible`, 1M context, custom reasoning variants). — _Evidence:_ DOCS/providers (Ollama, LM Studio, llama.cpp); LOCAL: ~/.config/opencode/opencode.jsonc `provider.sglang`
- **OpenCode 2.0.31** ✅ — Local engines are configured as custom providers with `baseURL` and model `limit`s. This host's global config defines an `sglang` provider (`@ai-sdk/openai-compatible`, 1M context, custom reasoning variants). — _Evidence:_ DOCS/providers (Ollama, LM Studio, llama.cpp); LOCAL: ~/.config/opencode/opencode.jsonc `provider.sglang`
- **OpenClaw 2026.9.8** ✅ — Local servers are first-class providers; `--local-model-lean` trims the tool surface for small models; Code Mode `auto` per-model; node-local inference on paired machines. — _Evidence:_ `oc/docs/providers/ollama.md`; `oc/docs/providers/sglang.md`; `oc/docs/providers/vllm.md`; `oc/docs/providers/lmstudio.md`; `oc/docs/plugins/llama-cpp.md`

</details>
<details><summary><b>MOD-08</b> Arbitrary OpenAI-compatible endpoint</summary>

- **sugar-crush** ✅ — Named blocks live under `providers.<name>` in user config (or the package's config.dev.json) and are selectable by name via `SUGARCRUSH_PROVIDER` or `/model <name>`. `${VAR}`/`${VAR:-default}` env interpolation. Streams with include_usage. — _Evidence:_ `src/Providers/ProviderFactory.php:118-121,1520-1549`; `src/Providers/CustomProvider.php:460-680`
- **sugar-crush-web** 🟡 — Provider/egress settings are risk classes `egress`/`spend`, so `settings.set` refuses them over the wire. — _Evidence:_ `sugar-crush/src/Protocol/Methods/SettingsMethods.php:110`
- **Claude Code 2.1.301** ❌ — `ANTHROPIC_BASE_URL` + `ANTHROPIC_AUTH_TOKEN`/`apiKeyHelper`; tool search auto-disables for non-first-party hosts unless `ENABLE_TOOL_SEARCH` is set. — _Evidence:_ cc:llm-gateway-connect; cc:mcp#configure-tool-search
- **OpenCode 1.18.35** ✅ — Under `provider.<id>`: `npm`, `name`, `options` (baseURL, apiKey, headers, timeouts, setCacheKey), and `models` (limits, `reasoning`, `tool_call`, options, `variants`). `whitelist`/`blacklist` filter the picker. — _Evidence:_ DOCS/providers (Custom provider, Hiding models); DOCS/config (Models)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Custom provider = any AI-SDK npm pkg (e.g. @ai-sdk/openai-compatible) + baseURL, models, limits, variants. 2.0.31 (✅): Custom provider: `providers.<id>.package` (aisdk:… or native), `settings.baseURL`, headers, body, compatibility — _Evidence:_ V2D/providers; V2D/migrate-v1 (Providers)
- **OpenClaw 2026.9.8** ✅ — Custom provider entries choose an api adapter (OpenAI completions/responses, anthropic-messages, ollama...), models with context window and `compat` flags (e.g. `supportedReasoningEfforts`, `codeMode: "preferred"`); usable for embeddings too. — _Evidence:_ `oc/docs/concepts/model-providers/custom-providers.md`; `oc/docs/gateway/config-tools/custom-providers.md`

</details>
<details><summary><b>MOD-09</b> Subscription OAuth login (Claude Pro/Max, ChatGPT, Copilot, etc.)</summary>

- **sugar-crush** ❌ — `ClaudeCodeProvider::options()` hard-codes `'bare' => true`; Claude Code 2.1.301 `--help`: "Anthropic auth is strictly ANTHROPIC_API_KEY or apiKeyHelper via --settings (OAuth and keychain are never read)". So a Claude Pro/Max login cannot be used; ChatGPT/Copilot logins unsupported. MCP OAuth exists but is a different row. — _Evidence:_ `src/Providers/ClaudeCodeProvider.php:112-127`; `src/Providers/ClaudeCodeInvocation.php:108-152`; `claude --help`
- **sugar-crush-web** ❌ — The web never handles provider credentials.
- **Claude Code 2.1.301** ✅ — `/login`; `claude auth login --claudeai/--console/--sso/--email`; `claude auth status --json`; free plan excluded; multi-account login; managed `forceLoginMethod`/`forceLoginOrgUUID`. Subscription usage limits apply, with automatic continue when a limit resets (v2.1.234+) and usage credits. — _Evidence:_ help (`auth login`, `setup-token`); cc:authentication; cc:interactive-mode#wait-for-a-usage-limit-to-reset
- **OpenCode 1.18.35** 🟡 — `/connect` or `opencode providers login` offers ChatGPT Plus/Pro OAuth (built-in openai plugin, regional residency), GitHub Copilot device flow, and GitLab Duo. The docs' info box says Claude Pro/Max plugins have not been bundled since 1.3.0 because Anthropic prohibits it, so Claude subscriptions need a third-party auth plugin. Older `opencode-anthropic-auth` and `opencode-copilot-auth` packages are still in this host's cache. — _Evidence:_ DOCS/providers (Anthropic info box, OpenAI, GitHub Copilot); OC/plugin/openai, OC/plugin/github-copilot; LOCAL: ~/.cache/opencode/node_modules/opencode-anthropic-auth
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Native OAuth for ChatGPT Plus/Pro, GitHub Copilot, GitLab Duo; Claude Pro/Max unbundled since 1.3.0 (3rd-party plugin). 2.0.31 (🟡): `opencode auth` gains `switch` (active account per integration), `export` (prints secrets as JSON) and `import`. Desktop Settings → Providers manages multiple accounts (2.0.29). — _Evidence:_ V2CLI: `opencode auth --help`; V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — OAuth "subscription auth" for OpenAI Codex (PKCE / device pairing) and Anthropic via Claude CLI reuse (OpenClaw never reads/refreshes Claude's native tokens); GitHub Copilot subscription; `/login [provider]` from chat; token-sink auth profiles prevent refresh-token clobbering; per-session account pins. — _Evidence:_ `oc/docs/concepts/oauth.md`; `oc/docs/providers/anthropic.md`; `oc/docs/providers/github-copilot.md`

</details>
<details><summary><b>MOD-10</b> Provider count / model catalog source (e.g. models.dev)</summary>

- **sugar-crush** 🟡 — `ProviderFactory::availableTypes()` = openai, anthropic, claude-code, sglang, bedrock, vertex, custom. `ModelChoice::paletteLabels()` offers at most three ids per provider; any id can be typed (`/model <provider> <model>`). LiteLLM DB is used only to size/price, not to list. Vertex default `claude-3-sonnet@20240229` is a retired model (ISSUES P2). — _Evidence:_ `src/Providers/ProviderFactory.php:438-575`; `src/Config/Settings/ModelChoice.php:71-81`; `sugarcrush models` output
- **sugar-crush-web** 🧪 — 17 of the 62 protocol methods are unused by the web, `server.info` among them, so there is no catalog/about view. — _Evidence:_ `sugar-crush/src/Protocol/Methods/ServerMethods.php`; grep of `src-web/` (this audit)
- **Claude Code 2.1.301** 🟡 — Provider routes: Anthropic API, Bedrock (incl. Mantle), Agent Platform, Foundry, Claude Platform on AWS, gateways. Current API defaults: Opus 5.5, Sonnet 5.5, Haiku 5.5 (v2.1.293), Fable 5.1. `ANTHROPIC_DEFAULT_{FABLE,OPUS,SONNET,HAIKU}_MODEL` pins; `availableModels` allowlist; org default model; custom model option; `modelOverrides`. — _Evidence:_ cc:model-config#model-aliases; cc:model-config#restrict-model-selection; CL v2.1.293
- **OpenCode 1.18.35** ✅ — The catalog comes from `OPENCODE_MODELS_URL` or `https://models.opencode.ai`, cached in `~/.cache/opencode/models.json`. `opencode models [provider] --verbose` shows costs. `--refresh` re-fetches. `OPENCODE_ENABLE_EXPERIMENTAL_MODELS` shows experimental entries. — _Evidence:_ CORE/models-dev.ts:160; CLI: `opencode models --help`; DOCS/models
- **OpenCode 2.0.31** ✅ — The catalog comes from `OPENCODE_MODELS_URL` or `https://models.opencode.ai`, cached in `~/.cache/opencode/models.json`. `opencode models [provider] --verbose` shows costs. `--refresh` re-fetches. `OPENCODE_ENABLE_EXPERIMENTAL_MODELS` shows experimental entries. — _Evidence:_ CORE/models-dev.ts:160; CLI: `opencode models --help`; DOCS/models
- **OpenClaw 2026.9.8** ✅ — Provider directory lists 64 LLM providers plus transcription/media providers (162 plugin reference pages overall). The hosted model catalog "hydrates model ids and metadata from models.dev" at publish time and refreshes at runtime (`models.catalogRefresh`). — _Evidence:_ `oc/docs/providers/index.md`; `oc/docs/concepts/models.md` (Hosted catalog updates); CLI: `openclaw models --help`

</details>
<details><summary><b>MOD-11</b> Mid-session model switching (/model)</summary>

- **sugar-crush** ✅ — `/model` writes `provider` to `~/.sugar-crush/config.json` and the model into the per-provider `models` map via SettingsWriter; engine rebuild waits for a running turn. Persisted choice is global (affects every project's next launch). — _Evidence:_ `builtin-commands/0300-model.php`; `README.md:149-152`; `src/Config/Settings/ModelChoice.php:7-25`
- **sugar-crush-web** ❌ — No model switch exists anywhere in the web; a new session takes the server default. `session.create` has no provider/model param. — _Evidence:_ live probe `command.exec name=model`; `sugar-crush/src/Protocol/Schema/MethodSchemas.php:157-160`
- **Claude Code 2.1.301** ✅ — `/model` saves the default for new sessions unless `s`; arrows adjust effort in the picker; PreModelSwitch (can block) / PostModelSwitch hooks; switching to a smaller-window model summarizes first with the previous model (v2.1.301); remote devices can switch; `--model`. — _Evidence:_ cc:commands; cc:interactive-mode#when-claude-code-sends-what-you-queued; cc:hooks#premodelswitch; CL v2.1.301
- **OpenCode 1.18.35** ✅ — Model picker supports favorites (ctrl+f) and provider list (ctrl+a). `model_cycle_recent` is F2/shift+F2. `/variants` and ctrl+t switch reasoning variant. Every user message stores its provider/model/variant, and the loop resolves the model from the latest user message. — _Evidence:_ DOCS/keybinds; OC/session/prompt.ts:1140 (`getModel(lastUser.model…)`); TUI/app.tsx:636-720
- **OpenCode 2.0.31** ✅ — Model picker supports favorites (ctrl+f) and provider list (ctrl+a). `model_cycle_recent` is F2/shift+F2. `/variants` and ctrl+t switch reasoning variant. Every user message stores its provider/model/variant, and the loop resolves the model from the latest user message. — _Evidence:_ DOCS/keybinds; OC/session/prompt.ts:1140 (`getModel(lastUser.model…)`); TUI/app.tsx:636-720
- **OpenClaw 2026.9.8** ✅ — `/model <ref> -s/-a/-g`, `/models`, `/model status`, `/model default`; `/new <model>`; if a run is active the switch is marked pending and applied at the next clean retry point. Docs warn that mid-session changes alter cache/context behaviour. — _Evidence:_ `oc/docs/tools/slash-commands.md` (/model); `oc/docs/web/tui.md` (Pickers)

</details>
<details><summary><b>MOD-12</b> Per-agent / per-subagent model selection</summary>

- **sugar-crush** ✅ — An unservable model is never relabelled: the run uses the session model and says so on the trail and in the Task result. Workflow stages likewise run on the session provider. — _Evidence:_ `docs/AGENTS_AUTHORING.md:224-245`; `src/Tools/BuiltIn/TaskTool.php:1125-1150`
- **sugar-crush-web** 🟡 — Agent view header shows status, step, tokens, cost. — _Evidence:_ `src-web/components/agents/AgentView.vue`
- **Claude Code 2.1.301** ✅ — Order: per-invocation param → definition `model` → `CLAUDE_CODE_SUBAGENT_MODEL` → main model (env var can be forced onto all, Explore included). Explore runs on Opus when the main model is Fable on 1P. `CLAUDE_CODE_WORKFLOW_SUBAGENT_MODEL` (v2.1.296); Agent `effort` param (v2.1.292). — _Evidence:_ cc:sub-agents#choose-a-model; CL v2.1.292; CL v2.1.296
- **OpenCode 1.18.35** ✅ — `agent.<name>.model` and `variant`. A task child uses `next.model ?? parent message model`. A command's `model:` overrides the model for that command (and its subtask). — _Evidence:_ OC/agent/agent.ts:281-283; DOCS/agents (Model); OC/session/prompt.ts:1420-1450
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Agent `model`/`variant`; subagents inherit invoking agent's model unless set; commands can set model. 2.0.31 (✅): Variant joins the model reference after `#`. The `subagent` tool has an optional `model` (only when the user asks), validated against available models; the model can list models with the Code Mode `opencode.models` tool. Child uses override → agent model → parent model. — _Evidence:_ V2D/agents (Model); V2C/tool/plugin/subagent.ts:30-37,49-66; V2C/tool/plugin/opencode.ts:220
- **OpenClaw 2026.9.8** ✅ — `agents.entries.*.model`, `agents.defaults.subagents.model` / per-agent / `sessions_spawn model`, cross-agent spawns use target agent's model; `compaction.model`, `compaction.memoryFlush.model`, `tools.exec.reviewer.model`, `utilityModel`, media models. — _Evidence:_ `oc/docs/tools/subagents/tool-reference.md`; `oc/docs/concepts/compaction.md`; `oc/docs/tools/exec.md`

</details>
<details><summary><b>MOD-13</b> Small/fast model for auxiliary tasks (titles, summaries, classifiers)</summary>

- **sugar-crush** 🟡 — `Bootstrap::toollessBackend()` uses env → setting → the provider block's own `model`. The title backend also drives prompt suggestions (on by default, one extra call per turn over the last 12 messages), `/btw`, `/goal` judging and autoReview; the summary backend drives compaction and auto-memory. README calls it a "cheap small-model backend". — _Evidence:_ `src/Cli/Bootstrap.php:10093-10095,10186-10213`; `README.md:824-826`; `docs/ENVIRONMENT.md:50`
- **sugar-crush-web** ✅ — "The server titles its own sessions" — the web just renders `summary.name`. — _Evidence:_ `src-web/stores/sessions.ts:105-110`; `sugar-crush/docs/SERVER.md:890-894`
- **Claude Code 2.1.301** ✅ — Background summarization for `--resume` costs ~<$0.04/session; prompt suggestions and "You should know" checks use the session model on the warm cache; `ANTHROPIC_SMALL_FAST_MODEL` deprecated. — _Evidence:_ cc:sessions#name-your-sessions; cc:costs#background-token-usage; cc:model-config#environment-variables
- **OpenCode 1.18.35** ✅ — `small_model` runs the lightweight hidden agents (title at temperature 0.5, summary). If unset, OpenCode picks a cheaper model from the same provider, else the main model. Plugins can override this through `experimental.provider.small_model`. — _Evidence:_ DOCS/config (Models); OC/agent/agent.ts:235-258; PLUG/index.ts:300
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): `small_model` (auto-picks cheap model) for title/summary agents; plugin hook experimental.provider.small_model. 2.0.31 (🟡): V1 small_model drove title/summary. V2 maps it to `agents.title.model`; docs: “Compaction uses the session's model. There is no separate compaction model.” — _Evidence:_ V2D/migrate-v1 (small_model); V2D/compaction (Limits)
- **OpenClaw 2026.9.8** ✅ — Optional lower-cost model for short internal tasks; retries once with the primary if it fails. Separate knobs for compaction and memory-flush models. — _Evidence:_ `oc/docs/gateway/config-agents/models.md`; `oc/docs/concepts/models.md`

</details>
<details><summary><b>MOD-14</b> Fallback model on overload/error</summary>

- **sugar-crush** ✅ — Same provider only (no cross-provider fallback). Switch only before the first streamed token. Status bar names the pinned model. — _Evidence:_ `src/Providers/FallbackProvider.php:1-60`; `README.md:1436`
- **sugar-crush-web** ❓ — Engine fallback behaviour is the SC column's; the protocol has no fallback event, so the web cannot show one. — _Evidence:_ `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` (events)
- **Claude Code 2.1.301** ✅ — Switch lasts the current turn; applies to subagents and to compaction (never to a smaller window); a notice appears only when it fires (not at startup, not in `/status`). Content fallback: Fable/Opus 5.5 cyber→Opus 4.8, bio→Opus 5; Sonnet 5.5 cyber→Sonnet 5; optional "ask before switching". — _Evidence:_ help (`--fallback-model`); cc:model-config#fallback-model-chains; cc:model-config#automatic-model-fallback
- **OpenCode 1.18.35** ❌ — Overload and 5xx errors are retried (MOD-18), but there is no `fallback_model` concept. Multi-model orchestration plugins exist, but I found no documented fallback feature in core. — _Evidence:_ OC/session/retry.ts; `grep fallback` over provider/session/config: none
- **OpenCode 2.0.31** ❌ — 1.18.35 (❌): No fallback model on overload/error; only retries with backoff. 2.0.31 (❌): 2.0.27: “Preserved a saved session's unavailable model selection instead of silently replacing it with a fallback.” — _Evidence:_ V2CL v2.0.27
- **OpenClaw 2026.9.8** ✅ — Bounded same-model recovery continues the transcript; then profile rotation with cooldowns; then configured fallbacks (incl. image fallbacks). Explicit user session selections disable fallback; `agent exec --fallback` adds an explicit chain. — _Evidence:_ `oc/docs/concepts/model-failover.md`; `oc/docs/cli/agent.md`

</details>
<details><summary><b>MOD-15</b> Reasoning/thinking control (effort levels, budgets, keywords)</summary>

- **sugar-crush** 🟡 — Main-session effort comes from provider config; `EngineBackend::withReasoningEffort()` is called only by TaskTool for agents declaring `effort:` (ignored with a note on non-sglang providers). No Anthropic thinking budget (compat endpoint), no OpenAI reasoning_effort, no `/effort` or think keywords. — _Evidence:_ `src/Providers/SglangProvider.php:612-665`; `src/Backend/EngineBackend.php:814-820,1598`; `src/Tools/BuiltIn/TaskTool.php:1139-1150`; `src/Providers/VertexProvider.php:2020`
- **sugar-crush-web** ❌ — Nothing in the composer or status bar sets reasoning effort. — _Evidence:_ `sugar-crush/src/Config/Settings/Definitions/ModelProviderSettings.php:123-130`
- **Claude Code 2.1.301** ✅ — Adaptive reasoning on current models; thinking can't be disabled on Opus/Sonnet/Haiku 5.5 or Fable; Alt+T toggles on older models; `maxEffortLevel` caps; effort shown in the session header ("with low effort"); `/effort` applies mid-turn after a cache warning; `max` is session-only. — _Evidence:_ cc:model-config#adjust-effort-level; help (`--effort`); cc:commands
- **OpenCode 1.18.35** ✅ — A variant is a named bundle of provider options (reasoningEffort, thinking budget, and others), merged last over model and agent options. Built-in variants exist per provider. Users can define custom ones; this host defines xhigh/medium/low × preserve_thinking. `--variant` works for `run`. — _Evidence:_ OC/session/llm/request.ts:80-87; DOCS/models (Variants); LOCAL: opencode.jsonc variants
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Model variants (e.g. high/max, low..xhigh) cycled with ctrl+t, `--variant`, agent `variant`, custom variants. 2.0.31 (✅): V1 variants object → V2 array `[{id, settings}]`. — _Evidence:_ V2D/migrate-v1 (Models and variants)
- **OpenClaw 2026.9.8** ✅ — Levels mapped per provider (Anthropic adaptive/effort, OpenAI reasoning.effort, Gemini budgets, DeepSeek, Ollama...). `ultra` = harness-level planning/verification + Proactive Sub-Agent Orchestration prompt section. Resolution: inline > session > per-agent > per-model > global > provider default. `/fast` maps to service tiers. — _Evidence:_ `oc/docs/tools/thinking.md`; `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>MOD-16</b> Thinking/reasoning display in UI</summary>

- **sugar-crush** ✅ — Reasoning deltas cross the fork as `reasoning` frames (empty ones double as heartbeats); providers extract `reasoning_content`, Anthropic/Vertex `thinking` blocks, CC `thinking` deltas. — _Evidence:_ `README.md:764-766`; `docs/ARCHITECTURE.md:321`; `src/Providers/ClaudeCodeProvider.php:596`
- **sugar-crush-web** ✅ — `ReasoningFold` (live and settled), attached to assistant rows and to the first tool card of a step. — _Evidence:_ `src-web/components/ReasoningFold.vue`; `src-web/stores/reducer.ts`
- **Claude Code 2.1.301** ✅ — Interactive Anthropic-API sessions receive redacted thinking by default; transcript viewer also shows per-message model and timestamp. — _Evidence:_ cc:model-config#extended-thinking; cc:interactive-mode#keyboard-shortcuts
- **OpenCode 1.18.35** ✅ — Reasoning streams into reasoning parts. The TUI `/thinking` (alias toggle-thinking) only hides or shows them. `opencode run --thinking` prints them in headless mode. — _Evidence:_ DOCS/tui (thinking); TUI/routes/session/index.tsx (slash thinking); CLI: `opencode run --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Reasoning parts rendered; /thinking toggles visibility; `run --thinking`. 2.0.31 (✅): No `/thinking` slash command in the v2 slash list; visibility is a cli.json setting. — _Evidence:_ V2D/cli/config (Sessions); V2CLI: `opencode run --help`
- **OpenClaw 2026.9.8** ✅ — Reasoning visibility per session; TUI toggles thinking visibility (reloads history); Control UI shows preambles/commentary inline during runs and collapses them under "Worked for …"; ACP streams `agent_thought_chunk`. Docs warn `/reasoning` is risky in groups. — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/web/tui.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>MOD-17</b> Streaming responses</summary>

- **sugar-crush** ✅ — openai-php createStreamed, Guzzle SSE (custom/sglang/anthropic), ConverseStream, streamRawPredict/streamGenerateContent, CC `stream-json --include-partial-messages`. Shell-out `SUGARCRUSH_BACKEND_CMD_STREAM` is token-per-line but repaints once at the end. — _Evidence:_ `src/Runtime.php:1580-1615`; `src/Providers/ClaudeCodeInvocation.php:108-116`; `src/Cli/NonInteractive.php:261-293`
- **sugar-crush-web** ✅ — A duplicate delta is dropped, a gap is marked "…" and healed by the completed reply. Rendering cost is O(n²) per reply (ISSUES). — _Evidence:_ `src-web/stores/reducer.ts:269-280`; `src-web/views/SessionView.vue:125-128`
- **Claude Code 2.1.301** ✅ — Spinner shows live output-token count; first-byte and idle watchdogs. — _Evidence:_ help; cc:errors#automatic-retries; cc:costs#read-the-token-count-beside-the-spinner
- **OpenCode 1.18.35** ✅ — Every part is written to SQLite and part-delta events go out on the bus and SSE (`/event`), so the TUI, web, desktop and SDK all render the same stream live. — _Evidence:_ DOCS/server (Events); OC/session/processor.ts
- **OpenCode 2.0.31** ✅ — Every part is written to SQLite and part-delta events go out on the bus and SSE (`/event`), so the TUI, web, desktop and SDK all render the same stream live. — _Evidence:_ DOCS/server (Events); OC/session/processor.ts
- **OpenClaw 2026.9.8** ✅ — TUI updates in place; Control UI live `chat` events; channels get progress drafts/typing indicators/block streaming. — _Evidence:_ `oc/docs/concepts/streaming.md`; `oc/docs/web/tui.md` (History + streaming)

</details>
<details><summary><b>MOD-18</b> Retries / backoff / rate-limit handling</summary>

- **sugar-crush** 🟡 — `TransientFailure::isTransient()` walks the exception chain (Guzzle, openai-php, AWS, Google ApiCore, Anthropic error types). Mid-stream failures resume via continuation/prefill rather than repainting. Connect bound 15 s (`SUGARCRUSH_CONNECT_TIMEOUT`), stream read-idle 3600 s, turn idle ceiling 120 s (`turnIdleTimeoutSeconds`, re-armed per frame) — verified there is no total-request timeout on any LLM call (Vertex SDK's hidden 60 s deadline is explicitly neutralised). Weak spots: Retry-After ignored, ladder totals ≈1.5 s by default, AWS SDK retries stack on top. — _Evidence:_ `src/Providers/TransientFailure.php:15-30,140-200,700-703`; `src/Runtime.php:1580-1715`; `src/Providers/Concerns/HttpClientDefaults.php`; `src/Providers/VertexProvider.php:2885-2950`; `src/Backend/EngineBackend.php:75-126`
- **sugar-crush-web** 🟡 — Provider retry/backoff is invisible in the web. Transport reconnect: Backoff ±30 %, ladder reset after 60 s up, fresh ticket each try. — _Evidence:_ `src-web/protocol/reconnect.ts:17-74`
- **Claude Code 2.1.301** ✅ — Retries 5xx/529/timeouts/dropped connections/429 throttles (not spend-limit 429s); `system/api_retry` events in stream-json; `CLAUDE_CODE_RETRY_WATCHDOG` waits out 429/529 unattended (caps via `…_MAX_WAIT_MS`, `…_MAX_USAGE_LIMIT_WAIT_MS` in 2.1.301); `CLAUDE_CODE_OVERLOADED_RETRY_*` delays. — _Evidence:_ cc:errors#automatic-retries; cc:headless#handle-api-retries; CL v2.1.295; CL v2.1.301
- **OpenCode 1.18.35** ✅ — RETRY_MAX_RETRIES=5, initial 2000 ms, factor 2, 25% jitter, 30 s cap when there are no headers. Retries on 429/500/502/503/504/524 and overloaded patterns, and honor `retry-after-ms` / `retry-after`. Session status `retry` is shown, including on subagent rows. Defaults: `headerTimeout` and SSE `chunkTimeout` are 5 min. The source applies **no** total `timeout` unless one is set; the docs claim a 300000 default, which the code does not apply. — _Evidence:_ OC/session/retry.ts:26-60; OC/provider/provider.ts:94-125; DOCS/config (timeout)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Up to 5 retries, 2 s exponential backoff w/ jitter, honors retry-after(-ms); retry status shown in UI. 2.0.31 (✅): Generic retry covers rate limits, provider-internal and unsent/unknown-delivery transport failures and incomplete streams; at most four retries. 2.0.28 raised the streamed-chunk timeout from 5 to 15 min and disabled Bun's fetch idle timeout. 2.0.29 auto-continues responses that hit the output-token limit. Plugins can veto/alter retries via `ctx.session.hook("retry")`. — _Evidence:_ specs/v2/session.md (Retry Is Narrow And Observable); V2CL v2.0.28, v2.0.29; V2D/build/plugins/index (Retry policy)
- **OpenClaw 2026.9.8** ✅ — Recovery continues the transcript after partial output/tool activity instead of resubmitting ("preserve completed work and inspect interrupted actions"); unfinished tool calls are never executed; retry status shown with wait + attempt count. — _Evidence:_ `oc/docs/concepts/retry.md`; `oc/docs/concepts/model-failover.md`

</details>
<details><summary><b>MOD-19</b> Prompt caching (provider cache breakpoints)</summary>

- **sugar-crush** 🟡 — `CacheBreakpoints` (wipe and reapply, ≤4: system tail, optional last tool, tail chain) on Vertex's Anthropic arm. Bedrock: system plus last-message `cachePoint`. OpenAI and SGLang cache prefixes server-side. Switches: `promptCache` and `SUGARCRUSH_DISABLE_PROMPT_CACHE`. `CacheHealthWatch` warns after 3 zero-cache replies and counts cache breaks. Anthropic's docs: "Prompt caching is not supported" via the compat layer. — _Evidence:_ `src/Providers/CacheBreakpoints.php`; `src/Providers/VertexProvider.php:951-1000`; `src/Providers/BedrockProvider.php:691-712`; `src/Providers/ProviderFactory.php:983-1036`; https://platform.claude.com/docs/en/api/openai-sdk
- **sugar-crush-web** 🟡 — `usage.updated` carries a usage object but the status bar shows `~tok · %` and spend. — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — CLAUDE.md/memory frozen per session; reminders appended, never inserted; tool list frozen at the first request; model/effort switch warns about cache cost; per-subagent `experimental.cacheTtl`; `--exclude-dynamic-system-prompt-sections` for cross-user reuse; idle compaction before the 1h TTL expires. — _Evidence:_ cc:prompt-caching; help; CL v2.1.299
- **OpenCode 1.18.35** ✅ — `applyCaching` sets ephemeral breakpoints for anthropic, openrouter, openaiCompatible, copilot and alibaba, and cachePoint for bedrock. The system prompt is kept to at most 2 messages, [header, rest], and tools are sorted by name for prefix stability. Non-OpenCode providers get `x-session-affinity` and `X-Session-Id` (sticky routing for SGLang/vLLM routers). OpenAI-family and OpenCode providers get `promptCacheKey`; `setCacheKey` forces it. — _Evidence:_ OC/provider/transform.ts:356-385,1323-1336,1380; OC/session/llm/request.ts:68-78,180-201
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Auto cache breakpoints on first 2 system + last 2 msgs; promptCacheKey=sessionID; x-session-affinity header. 2.0.31 (✅): Cache markers per provider compatibility (Anthropic/Alibaba styles). Instruction changes become chronological deltas so the cached prefix survives edits. New `warming`: periodic keep-alive requests (every 4 min for 30 min by default) to keep provider caches warm; disabled by default. — _Evidence:_ V2CL v2.0.28; V2D/warming; specs/v2/session.md (Instructions Are Value Deltas)
- **OpenClaw 2026.9.8** ✅ — Stable sections (tooling, Project Context, skills) above an internal boundary, volatile ones (runtime, channel, time) below; exec/subagent/media facts travel in user-role carrier messages to keep the history prefix stable; MCP catalogs sorted deterministically; per-agent cache retention knobs. — _Evidence:_ `oc/docs/reference/prompt-caching.md`; `oc/docs/concepts/system-prompt.md`; `oc/docs/concepts/context.md`

</details>
<details><summary><b>MOD-20</b> Long-context (1M) support / context-window detection</summary>

- **sugar-crush** 🟡 — Absolute compaction caps (100k / 150k, `compaction.modelTokenCaps`) stop 1M windows compacting at 700k. `VertexProvider::contextWindow()` returns 200,000 for every model and ignores the setting. claude-code is fixed at 200k. No `[1m]` / beta-header handling on the anthropic type. — _Evidence:_ `src/Providers/CustomProvider.php:300`; `src/Providers/SglangProvider.php:1119`; `src/Providers/BedrockProvider.php:203-216`, `:437`; `src/Providers/VertexProvider.php:463`; `docs/SETTINGS.md:223`
- **sugar-crush-web** ✅ — Status bar `~N tok · P%` plus a meter; grid tiles show step and spend. — _Evidence:_ `src-web/stores/session.ts:36-38`; `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — Auto-compacts at ≈967K on native-1M models; `CLAUDE_CODE_DISABLE_1M_CONTEXT`; subagent window = its own model's; plan rules for 4.6 `[1m]` (usage credits on some plans). — _Evidence:_ cc:model-config#extended-context; cc:model-config#default-auto-compact-thresholds
- **OpenCode 1.18.35** ✅ — Model `limit.context`, `limit.input` and `limit.output` come from the catalog or config. 1M-context models work (this host's config declares 1,000,000). Max output is min(limit.output, 32k) unless `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX` is set. — _Evidence:_ OC/session/overflow.ts:10-20; LOCAL: opencode.jsonc limit.context 1000000
- **OpenCode 2.0.31** ✅ — Model `limit.context`, `limit.input` and `limit.output` come from the catalog or config. 1M-context models work (this host's config declares 1,000,000). Max output is min(limit.output, 32k) unless `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX` is set. — _Evidence:_ OC/session/overflow.ts:10-20; LOCAL: opencode.jsonc limit.context 1000000
- **OpenClaw 2026.9.8** ✅ — Control UI meter distinguishes "Context window" from "Prompt budget (last run)"; live tool-result caps 16k/32k/64k chars by window size; per-model context caps. — _Evidence:_ `oc/docs/concepts/context.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (contextLimits)

</details>
<details><summary><b>MOD-21</b> Vision input (images to model)</summary>

- **sugar-crush** ✅ — supportsVision per provider (OpenAI family list, anthropic, Claude/Gemini on Vertex, Claude 3+/Nova on Bedrock, SGLang asks the server; custom/claude-code/echo answer no; override `supportsVision`). — _Evidence:_ `README.md:385-397`; `src/Providers/AttachmentEncoding.php`; `src/Providers/ClaudeCodeProvider.php:47-50`
- **sugar-crush-web** ❌ — Images a tool generates are shown (MediaCard) but a user cannot send one. — _Evidence:_ `sugar-crush/src/Protocol/Schema/MethodSchemas.php:186-193`
- **Claude Code 2.1.301** ✅ — Large images resized/recompressed (>500 KB re-encoded as JPEG); oldest images/PDFs pruned in batches at request limits to avoid repeated cache misses. — _Evidence:_ cc:interactive-mode; cc:tools-reference#read-tool-behavior; cc:prompt-caching#accumulating-many-images
- **OpenCode 1.18.35** ✅ — Clipboard image paste (ctrl+v), @-mentioned image files, and read-tool image attachments (JPEG/PNG/GIF/WebP). `attachment.image` sets `auto_resize`, `max_width`, `max_height` (2000) and `max_base64_bytes` (5 MiB). Oversized tool images are omitted; oversized user images error. — _Evidence:_ DOCS/config (Image attachments); OC/tool/read.ts:19,304-306; TUI/component/prompt/index.tsx:378-384
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Images via paste, @file, read tool; auto-resize >2000px or >5 MB base64 (attachment.image). 2.0.31 (✅): Same auto-resize knobs under `media.image`. — _Evidence:_ V2D/attachments (Images); V2D/migrate-v1 (Media)
- **OpenClaw 2026.9.8** ✅ — `view_image` accepts `path`/`paths` (maxImages 20); `imageMaxDimensionPx` 1200; `imageQuality` auto ladder; non-vision models fall back to media-understanding models. — _Evidence:_ `oc/docs/tools/index.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`

</details>
<details><summary><b>MOD-22</b> PDF / document input</summary>

- **sugar-crush** ❌ — `AttachmentType` is File (text, ≤256 KiB inline `<file>` block) or Image only. — _Evidence:_ `src/AttachmentType.php`; `README.md:378-383`
- **sugar-crush-web** ❌ — `session.send` → `TurnMethods::admit()` resolves `@path` mentions server-side. — _Evidence:_ `sugar-crush/src/Protocol/Methods/TurnMethods.php:80-105`
- **Claude Code 2.1.301** ✅ — Notebooks returned with outputs; Remote Control file attachments arrive as `@` references. — _Evidence:_ cc:tools-reference#read-tool-behavior; cc:remote-control
- **OpenCode 1.18.35** ✅ — `read` returns PDFs as attachments (`isPdfAttachment`). @file of a PDF becomes a base64 data-URL file part. MCP resource blobs may be PDF (≤10 MB). — _Evidence:_ OC/tool/read.ts:306; OC/session/tools.ts:32-38; OC/tool/read.txt
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): PDFs attached as file parts via @file or read tool (provider must support PDF input). 2.0.31 (🟡): V2 attachments doc: PDF, audio, video and other binaries attached to a prompt are excluded; convert to image/text first. The `read` tool passes PNG/JPEG/GIF/WebP/PDF up to 20 MiB. — _Evidence:_ V2D/attachments (Formats); V2D/tools (Read)
- **OpenClaw 2026.9.8** ✅ — `pdf` belongs to group:media and group:openclaw; document extraction for inbound files; Gemini embeddings can index media. — _Evidence:_ `oc/docs/tools/pdf.md`; `oc/docs/plugins/reference/document-extract.md`

</details>
<details><summary><b>MOD-23</b> Audio / voice input or output</summary>

- **sugar-crush** ❌ — Only media output is image/video generation through an SD/sglang-diffusion server (separate TL row). — _Evidence:_ grep `whisper/speech/voice` in src → none
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — Not available with API keys, Bedrock, Agent Platform, Foundry, SSH or cloud sessions; WSL needs WSLg; transcription doesn't count toward usage. — _Evidence:_ cc:voice-dictation
- **OpenCode 1.18.35** ❌ — `audio.ts` / `attention` play notification sound packs. There is no speech-to-text or TTS. — _Evidence:_ TUI/audio.ts; DOCS/tui (Attention)
- **OpenCode 2.0.31** ❌ — `audio.ts` / `attention` play notification sound packs. There is no speech-to-text or TTS. — _Evidence:_ TUI/audio.ts; DOCS/tui (Attention)
- **OpenClaw 2026.9.8** ✅ — Inbound audio via OpenAI/Deepgram/ElevenLabs/Mistral/whisper CLI; `tts` tool + `/tts`; Talk realtime sessions (OpenAI WebRTC, Google Live); voice wake on macOS/iOS/Android; `@openclaw/voice-call` for telephony. — _Evidence:_ `oc/docs/tools/tts.md`; `oc/docs/nodes/talk.md`; `oc/docs/nodes/voicewake.md`; `oc/docs/plugins/voice-call.md`

</details>
<details><summary><b>MOD-24</b> Structured output / JSON-schema mode</summary>

- **sugar-crush** 🧪 — `SglangProvider` builds `response_format` when `jsonSchema` is non-null; grep finds no caller passing `jsonSchema:`; `supportsJsonSchema()` is advisory. Headless JSON mode is an envelope, not schema-constrained output. — _Evidence:_ `src/Providers/CompleteRequest.php:62`; `src/Providers/SglangProvider.php:1804-1840`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Invalid schema exits with an error; internal side calls (titles, memory recall, prompt hooks) also use structured outputs (`CLAUDE_CODE_DISABLE_STRUCTURED_OUTPUTS` for gateways that reject them). — _Evidence:_ help (`--json-schema`); cc:headless#get-structured-output; CL v2.1.288
- **OpenCode 1.18.35** ✅ — When the latest user message has `format.type=json_schema`, the loop adds a `StructuredOutput` tool, sets toolChoice=required, and appends STRUCTURED_OUTPUT_SYSTEM_PROMPT. Failure yields StructuredOutputError. There is no CLI flag; use the SDK or HTTP API. — _Evidence:_ OC/session/prompt.ts:1243-1249,1269,1284-1290; DOCS/sdk (Structured Output)
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): API/SDK `format: {type:'json_schema'}` forces a StructuredOutput tool; result in info.structured_output. 2.0.31 (❌): The v2 schema/protocol/client contain no json_schema or structured_output field (only packages/schema/src/v1). The v1 SDK feature has no v2 equivalent at 2.0.31. — _Evidence:_ grep packages/{schema,protocol,client}/src (only schema/src/v1/session.ts); services/www/openapi.json
- **OpenClaw 2026.9.8** ✅ — Swarm `agents.run(..., {schema})` adds a synthetic `structured_output` tool, validates, one corrective nudge, else `schemaError`. `llm-task` (bundled optional plugin) runs one JSON-only call validated against a schema (for Lobster workflows). Normal chat replies are not schema-constrained; CLI has `--json` envelopes. — _Evidence:_ `oc/docs/tools/swarm.md`; `oc/docs/tools/llm-task.md`

</details>
<details><summary><b>MOD-25</b> Credential storage (keychain, env, config file, auth.json)</summary>

- **sugar-crush** 🟡 — Bedrock/Vertex use their SDK credential chains (AWS profile/role, GOOGLE_APPLICATION_CREDENTIALS). Project tiers cannot set `providers`/`provider` (trust model). Error messages are Guzzle-shaped (URI with userinfo obfuscated, no headers) — no key leakage found. — _Evidence:_ `src/Providers/ProviderFactory.php:407-436,690-700`; `src/Config/LayeredSettings.php:819-878`; `docs/ENVIRONMENT.md:185-200`
- **sugar-crush-web** ✅ — `settings.get` masks secret-typed keys, credential-named keys and URL userinfo as `********`. Caveat: the cookie is not port-scoped (ISSUES P1). — _Evidence:_ `src-web/protocol/auth.ts:1-12`; `sugar-crush/src/Server/Http/ApiController.php:96-100`
- **Claude Code 2.1.301** ✅ — `CLAUDE_CONFIG_DIR` scopes creds; Keychain-locked SSH fallback to the file; MCP OAuth tokens stored per endpoint and deleted on server removal; slow-helper notice after 10 s; `--bare` never reads OAuth/keychain. — _Evidence:_ cc:authentication#credential-management; cc:security; cc:headless#start-faster-with-bare-mode
- **OpenCode 1.18.35** 🟡 — `/connect` keys and OAuth tokens live in `~/.local/share/opencode/auth.json` (here mode 0600). MCP OAuth tokens live in `mcp-auth.json`. Provider env vars (ANTHROPIC_API_KEY etc.) are read automatically. Config can reference `{env:VAR}` / `{file:~/.secrets/x}`. `PUT /auth/:id` sets credentials over HTTP. No keychain integration. — _Evidence:_ DOCS/providers (Credentials); DOCS/config (Variables); DOCS/mcp-servers (mcp-auth.json); `ls -la ~/.local/share/opencode/auth.json`
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): auth.json (~/.local/share/opencode, 0600), env vars, {env:}/{file:} config substitution; no OS keychain. 2.0.31 (🟡): Stored outside project config (OAuth for MCP too). No OS keychain mentioned. — _Evidence:_ V2CLI: `opencode auth --help`; V2D/mcp-servers (OAuth)
- **OpenClaw 2026.9.8** ✅ — Auth order: SQLite auth profiles → env → `models.providers.*.apiKey`. SecretRefs (env/file/exec/store); shared store is unencrypted SQLite with 0600 perms; secret egress proxy injects plaintext only at outbound HTTPS time. Log/transcript redaction always on. No native OS-keychain backend (exec providers can call one). — _Evidence:_ `oc/docs/gateway/secrets.md`; `oc/docs/gateway/secrets/secret-store-and-egress.md`; `oc/docs/concepts/oauth.md`

</details>
<details><summary><b>MOD-N1</b> 🆕 Advisor / second-opinion model the agent can consult mid-task</summary>

- **sugar-crush** 🟡 — The main agent cannot ask a second model a quick question in the middle of a task with the conversation as context. The closest path: Task's `model` argument overrides the preset's and the session's model (a model the provider cannot serve falls back to the session model, and the result says so), so the agent can launch the built-in `architect` or `reviewer` preset on a stronger model of the same provider. That is a full sub-agent run with its own context and step budget, not a lightweight consult. Second models are used inside the harness, but the agent cannot call them: autoReview (the title model reviews flagged calls in auto mode), the `/goal` judge, and `/btw` side questions. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:717-723`, `:943`; `src/Permissions/ExecReviewer.php`; `src/Goal/GoalJudge.php`
- **sugar-crush-web** 🟡 — No advisor surface. A sub-agent the model launches on another model appears as a Task card and a tree node; the agent view header shows status, step, tokens and cost, but not the model. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:717-723`; `sugar-crush-web/src-web/components/agents/AgentView.vue`
- **Claude Code 2.1.301** ✅ — Anthropic API and Claude Platform on AWS only; advisor sees the full conversation; billed separately. — _Evidence:_ cc:advisor; CL v2.1.300
- **OpenCode 1.18.35** 🟡 — Configured, not built in. The agent must call `task` with that subagent. — _Evidence:_ DOCS/agents (Model)
- **OpenCode 2.0.31** 🟡 — The description says to set `model` only when the user explicitly asks. — _Evidence:_ V2C/tool/plugin/subagent.ts:30-37; V2C/tool/plugin/opencode.ts:220
- **OpenClaw 2026.9.8** 🟡 — `decision_evaluate` returns typed choice/score/Boolean judgments from a separately selected `decisionModel`; the optional `llm-task` tool runs one JSON-only call on an allowed model; `sessions_spawn model:` or `sessions_send` to another agent can serve as a second opinion. — _Evidence:_ `oc/docs/concepts/decision-models.md`; `oc/docs/tools/llm-task.md`; `oc/docs/tools/subagents/tool-reference.md`

</details>
<details><summary><b>MOD-N2</b> 🆕 Fast mode / priority service tier toggle</summary>

- **sugar-crush** ❌ — A grep for service_tier, fast mode or priority tier finds nothing in `src/Providers`, and OpenAIProvider sends a fixed parameter set. `extraBody` (custom provider only; user tier, egress class, applies at restart) merges top-level request fields, so a user could hard-code `"service_tier":"priority"` for an OpenAI-compatible endpoint. That is untested, has no UI and cannot be switched per turn. — _Evidence:_ `src/Providers/CustomProvider.php:128-183`; `docs/SETTINGS.md:461`, `:1010`
- **sugar-crush-web** ❌ — `session.create` takes no provider or model, `/model` is `ui_only`, and provider keys are egress/spend class, so `settings.set` cannot write them remotely. — _Evidence:_ `sugar-crush-web/src-web/components/StatusBar.vue:28-32`; `src/Protocol/Methods/SettingsMethods.php:110`
- **Claude Code 2.1.301** ✅ — Applies from the next turn. — _Evidence:_ cc:fast-mode
- **OpenCode 1.18.35** 🟡 — Variants are arbitrary provider-option bundles merged last. — _Evidence:_ OC/session/llm/request.ts:80-95; DOCS/models (Variants)
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2D/models (variants example); V2D/providers (service_tier body); V2C/aisdk-native.ts:187,253
- **OpenClaw 2026.9.8** ✅ — OpenAI/Codex map it to `service_tier=priority`; direct Anthropic to `service_tier=auto` or `standard_only`; the exec reviewer has its own `fastMode`. Persisted as a session override; reset via Sessions UI `inherit`. — _Evidence:_ `oc/docs/tools/slash-commands.md` (verbose / trace / fast / reasoning safety); `oc/docs/tools/exec.md`

</details>
<details><summary><b>MOD-N3</b> 🆕 Cache warming (keep-alive requests that preserve provider prompt caches while idle)</summary>

- **sugar-crush** ❌ — No code sends idle requests to keep a provider prefix warm, and a grep for keepalive or cache warm finds only transport/stream keepalives. Vertex and Bedrock cache marks carry no `ttl`, so the provider default of about 5 minutes applies (`BedrockProvider` says it is the only TTL it asks for). CacheHealthWatch deliberately does not count a cache miss after a gap of 240 s or more as a break, because 'providers evict an idle prefix after about five minutes'. The TUI status bar shows the cache hit % and the age of the last report but assumes no TTL. Byte-stable prefixes (PRM-N1) make the cache hit while a session is active; nothing keeps it alive between prompts. — _Evidence:_ `src/Backend/CacheHealthWatch.php:52-59`; `src/Providers/BedrockProvider.php:118-128`; `src/Providers/VertexProvider.php:240`; `src/Renderer.php:2956-2960`
- **sugar-crush-web** ❌ — As SC. The status bar shows context % and spend only; the `usage.updated` cache fields are not displayed. — _Evidence:_ `src/Backend/CacheHealthWatch.php:52-59`; `sugar-crush-web/src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** 🟡 — Main conversation gets a one-hour TTL by default on Claude subscriptions within plan usage (5 min on API keys/cloud/usage credits); `promptCacheTtl`, `CLAUDE_CODE_PROMPT_CACHE_TTL`, `ENABLE_PROMPT_CACHING_1H`, per-subagent `experimental.cacheTtl`; idle compaction (v2.1.299) compacts 200K+ chats ~54 min idle before the 1h cache expires; Pro/Max offer resume-from-summary after long breaks; VS Code shows a cache-expiry clock. Nothing re-sends requests just to keep a cache warm. — _Evidence:_ cc:prompt-caching#cache-lifetime; cc:settings-reference#promptcachettl; CL v2.1.299; cc:vs-code
- **OpenCode 1.18.35** ❌ — Added in v2 (`warming`). — _Evidence:_ OC/ (no warming); V2D/warming
- **OpenCode 2.0.31** ✅ — Not present in 1.18.35. `warming: true` → every 4 min after the last model request for up to 30 min; prompt/interval/duration configurable. — _Evidence:_ V2D/warming
- **OpenClaw 2026.9.8** 🟡 — Prompt-caching docs recommend heartbeat cadence to keep cache windows warm after idle gaps; heartbeats are real main-session agent turns (monitor scratch context, model cost), default 30 min (1 h with Anthropic OAuth/Claude CLI). `cacheRetention` short/long sets Anthropic 5 min / 1 h TTL; `prompt_cache_key` keeps OpenAI routing stable; cache-TTL pruning runs only after expiry. — _Evidence:_ `oc/docs/reference/prompt-caching.md` (Heartbeat keep-warm, cacheRetention); `oc/docs/gateway/heartbeat.md`

</details>

### INS — Project instruction / rules files

<details><summary><b>INS-01</b> CLAUDE.md read</summary>

- **sugar-crush** ✅ — `InstructionFileLoader::FILENAMES` puts `CLAUDE.md` first. Root files load at the session's first prompt build (lazily, at the first turn's prime in the parent) and are frozen in `SessionPromptMemo` for the session. Each one is its own `<project-instructions>` section with an authority preamble, and the body goes through `PromptFence::escape()`. A separate launch-time loader is built only to report deferrals and refusals. — _Evidence:_ `src/Context/InstructionFileLoader.php:131`, `:312`; `src/Runtime.php:4559-4590`, `:4875`; `src/Cli/Bootstrap.php:3548`, `:9571`
- **sugar-crush-web** 🟡 — A test CLAUDE.md in the probe repo shows up in `/context` as `project-instructions ~89 per-session`; no file name, no content, no banner. — _Evidence:_ live `command.exec name=context`
- **Claude Code 2.1.301** ✅ — All discovered files are concatenated (no override), ordered filesystem root → cwd; block-level HTML comments stripped before injection; files ≤4 MiB. Delivered as a user-message system-reminder, not the system prompt. — _Evidence:_ cc:memory#how-claude-md-files-load; cc:memory#claude-isnt-following-my-claude-md
- **OpenCode 1.18.35** 🟡 — The project name list is ["AGENTS.md","CLAUDE.md","CONTEXT.md" (deprecated)]. Each name is searched from cwd up to the worktree, and the first name with any hit wins, so one AGENTS.md anywhere in the chain suppresses every CLAUDE.md. Global: the first existing of ~/.config/opencode/AGENTS.md, ~/.claude/CLAUDE.md. No @imports and no CLAUDE.local.md. `OPENCODE_DISABLE_CLAUDE_CODE(_PROMPT)` drops CLAUDE.md from both lists. Consequence here: /home/sites/sugarcraft has a root AGENTS.md, so OpenCode never loads CLAUDE.md. That is harmless only because CLAUDE.md just @-includes AGENTS.md and CONTRIBUTING.md (which OpenCode also does not read). — _Evidence:_ OC/session/instruction.ts:60-68,122-133; DOCS/rules (Claude Code Compatibility, Precedence)
- **OpenCode 2.0.31** ❌ — 1.18.35 used CLAUDE.md when no AGENTS.md was found, and ~/.claude/CLAUDE.md when no global AGENTS.md. 2.0.31 discovers only AGENTS.md; the migration guide tells users to move CLAUDE.md guidance into AGENTS.md (and file a compatibility issue). OPENCODE_DISABLE_CLAUDE_CODE* now only affect `.claude/skills`. — _Evidence:_ V2D/instructions (note: AGENTS.md only); V2D/migrate-v1 (Instruction files); V2C/config/plugin/instruction.ts:41,55,79
- **OpenClaw 2026.9.8** 🟡 — The embedded runner builds its resource loader with `noContextFiles: true`, so pi's AGENTS.md/CLAUDE.md ancestor loader is disabled; OpenClaw injects only its own workspace bootstrap set. CLAUDE.md therefore matters only (a) when a session runs on the `claude-cli` CLI backend — Claude Code loads it natively, except restricted runs (cron with toolsAllow) which pass `claudeMdExcludes: ["**/CLAUDE.md","**/CLAUDE.local.md","**/.claude/rules/**"]`; (b) `openclaw migrate claude` copies project `CLAUDE.md` + `.claude/CLAUDE.md` into workspace AGENTS.md and `~/.claude/CLAUDE.md` into USER.md (preview + backup). — _Evidence:_ `oc/dist/resource-loader-DGY8KcdK.mjs` (createEmbeddedAgentResourceLoader: noContextFiles true); `oc/dist/cli-shared-D13Avb_w.mjs:42`; `oc/docs/gateway/cli-backends.md`; `oc/docs/install/migrating-claude.md`

</details>
<details><summary><b>INS-02</b> AGENTS.md read</summary>

- **sugar-crush** ✅ — Same code path as CLAUDE.md. When CLAUDE.md `@import`s AGENTS.md, AGENTS.md is inlined at the import site and deduplicated by realpath, so it is not emitted twice. — _Evidence:_ `src/Context/InstructionFileLoader.php:131`, `:1445`; `src/Commands/InitCommand.php:36`
- **sugar-crush-web** 🟡 — As INS-01.
- **Claude Code 2.1.301** ✅ — `/config` → **Project instructions**: `claude-md-or-agents-md` (default), `claude-md-and-agents-md`, `claude-md`, `managed-only` (also `pluginConfigs."cc-plugin-agents-md@builtin"`). User/managed CLAUDE.md and rules don't count against AGENTS.md. Prints `no CLAUDE.md found; AGENTS.md loaded: <path>`. Subdir AGENTS.md loads on Read. Not read: AGENTS.local.md, AGENTS.override.md, `.agents/`. InstructionsLoaded hooks don't fire for directly read AGENTS.md. `@AGENTS.md` import in CLAUDE.md (this repo's setup) never double-loads. — _Evidence:_ cc:memory#agents-md; cc:plugins/mods/overview#mods-built-into-claude-code
- **OpenCode 1.18.35** ✅ — `findUp(AGENTS.md, cwd, worktree)` returns every ancestor copy, nearest first, and all of them are included. The global AGENTS.md comes first in the system prompt. See INS-12 for timing and INS-13 for placement. — _Evidence:_ OC/session/instruction.ts:110-153; CORE/fs-util.ts:154-166
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): AGENTS.md from cwd up to git root (all ancestor copies) + global ~/.config/opencode/AGENTS.md, re-read every step. 2.0.31 (✅): Order: global ~/.config/opencode/AGENTS.md, then nearest-first ancestors (packages/web, packages, root). Walk stop = home if the workspace is under home, else project root (1.18.35 stopped at the git worktree). Files are watched (100 ms debounce) and changes are delivered as instruction updates. — _Evidence:_ V2C/config/plugin/instruction.ts:36-62,76-86,195-200; V2D/instructions (Scope)
- **OpenClaw 2026.9.8** ✅ — AGENTS.md = operating instructions + memory workflow + `## Tools` section (replaces retired TOOLS.md). Injected under Project Context on every run (default `contextInjection: "always"`). When a session executes in another folder or managed worktree, that folder's AGENTS.md is appended after the workspace files (persona/memory files are never taken from the execution folder). Native Codex harness loads AGENTS.md via its own project-doc discovery instead. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Workspace bootstrap injection); `oc/docs/concepts/agent-workspace.md`; `oc/docs/reference/templates/TOOLS.md` (retired)

</details>
<details><summary><b>INS-03</b> Other vendor rule files (.cursorrules, .cursor/rules, GEMINI.md, copilot-instructions.md, CONVENTIONS.md, .windsurfrules)</summary>

- **sugar-crush** 🟡 — The aliases go through the same gates (containment, 60 KiB ceiling, UTF-8 scrub, @import, dedup). Not read: the `.clinerules/` directory form, `.cursor/rules/*.mdc`, `.github/copilot-instructions.md` (`/init` reads it once as a source but the loader never does), `CONVENTIONS.md`, `.windsurfrules`, `.claude/rules/*.md`. PROMPT_ENGINEERING.md lists widening vendor discovery as deliberately not done. — _Evidence:_ `src/Context/InstructionFileLoader.php:131`; `docs/PROMPT_ENGINEERING.md` ("Widening context-file discovery")
- **sugar-crush-web** 🟡 — As INS-01.
- **Claude Code 2.1.301** 🟡 — `/init` folds `.cursor/rules/`, `.cursorrules`, `.github/copilot-instructions.md` into the generated CLAUDE.md (with `CLAUDE_CODE_NEW_INIT=1` also AGENTS.md, `.devin/rules/`, `.windsurf/rules/`, `.windsurfrules`, `.clinerules`). `claude import [codex/gemini/cursor] [--dry-run]` appends a one-time copy of instruction files to the matching CLAUDE.md and carries MCP servers, commands, subagents, skills. No GEMINI.md/CONVENTIONS.md live loading. — _Evidence:_ cc:memory#migrate-instructions-from-other-tools; help (`import`); cc:commands
- **OpenCode 1.18.35** 🟡 — Only AGENTS.md, CLAUDE.md and CONTEXT.md are auto-discovered. Docs show `"instructions": [".cursor/rules/*.md"]` for reuse. The /init template tells the model to reference existing rule sources such as Cursor and Copilot rules inside AGENTS.md. — _Evidence:_ OC/session/instruction.ts:64-68; DOCS/rules (Custom Instructions, Initialize)
- **OpenCode 2.0.31** 🟡 — Only AGENTS.md, CLAUDE.md and CONTEXT.md are auto-discovered. Docs show `"instructions": [".cursor/rules/*.md"]` for reuse. The /init template tells the model to reference existing rule sources such as Cursor and Copilot rules inside AGENTS.md. — _Evidence:_ OC/session/instruction.ts:64-68; DOCS/rules (Custom Instructions, Initialize)
- **OpenClaw 2026.9.8** ❌ — Cursor bundles: `.cursor/rules`, `.cursor/agents`, `.cursor/hooks.json` are "detected but not executed" (only `.cursor/commands` map to skills). Claude `.claude/rules/` is archive-only in the Claude migration. — _Evidence:_ `oc/docs/plugins/bundles.md` (Detected but not executed); `oc/docs/install/migrating-claude.md`

</details>
<details><summary><b>INS-04</b> User-global instructions file (~/.claude/CLAUDE.md, ~/.config/opencode/AGENTS.md, ...)</summary>

- **sugar-crush** ✅ — `loadPersonal()` reads only `AGENTS.md` under the owned home's `~/.sugar-crush`. It is priced first under the combined instruction budget and framed with `USER_RULES_AUTHORITY_PREAMBLE`, ahead of project docs. Imports are bounded to `~/.sugar-crush`. Also user-tier: `~/.sugar-crush/rules/*.md` and `rulebooks/*.md`. Cross-tool user files (`~/.claude/CLAUDE.md`, `~/.config/opencode/AGENTS.md`) are not read. — _Evidence:_ `src/Context/InstructionFileLoader.php:134`, `:448`, `:499`; `src/Runtime.php:4889`
- **sugar-crush-web** 🟡 — Sessions run as the `serve` process user, so its `~/.sugar-crush` and HOME files apply.
- **Claude Code 2.1.301** ✅ — Loaded before project files; their @imports load without the external-import dialog (except Cowork); user rules precede project rules (neither overrides). — _Evidence:_ cc:memory#choose-where-to-put-claude-md-files; cc:memory#user-level-rules
- **OpenCode 1.18.35** ✅ — Exactly one global file: the first that exists of the two. Its `Instructions from:` block comes before project files. `OPENCODE_CONFIG_DIR` does **not** change the global AGENTS.md path (it uses Global.Path.config). — _Evidence:_ OC/session/instruction.ts:60-63,115-120
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): ~/.config/opencode/AGENTS.md (else ~/.claude/CLAUDE.md) loaded into every session's system prompt. 2.0.31 (✅): Global file is always first. `OPENCODE_DISABLE_PROJECT_CONFIG=1` skips project discovery without disabling the global file. — _Evidence:_ V2C/config/plugin/instruction.ts:41,70-74; V2D/instructions
- **OpenClaw 2026.9.8** ✅ — Instructions belong to the agent, not to a project: workspace AGENTS.md/SOUL.md/IDENTITY.md/USER.md apply to every session of that agent regardless of where it executes. USER.md holds dated active/superseded directives with its own 4,000-char budget. On multi-user Gateways each authenticated profile gets a personal USER.md edited via the `personal_instructions` tool. No separate ~/.config-style file. — _Evidence:_ `oc/docs/concepts/agent-workspace.md`; `oc/docs/concepts/user-model.md`; `oc/docs/tools/index.md`

</details>
<details><summary><b>INS-05</b> Project-local private override (CLAUDE.local.md or equivalent)</summary>

- **sugar-crush** 🟡 — No local-override tier exists. The user-tier `instructions: ["CLAUDE.local.md"]` setting force-loads that file in every project that has one (globs are repo-relative). — _Evidence:_ `src/Cli/Bootstrap.php:9632`; `src/Context/InstructionFileLoader.php:875`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Counts as a CLAUDE.md for the AGENTS.md check (so it suppresses AGENTS.md by default); per-worktree only, so docs suggest importing a `~/` file instead; skipped when `local` is excluded from `--setting-sources`. — _Evidence:_ cc:memory#import-additional-files; cc:memory#agents-md
- **OpenCode 1.18.35** ❌ — No private per-project override file is discovered. Workarounds: a git-ignored file listed in project `instructions`, or the `OPENCODE_CONFIG` / `OPENCODE_CONFIG_CONTENT` env vars. — _Evidence:_ OC/session/instruction.ts:64-68
- **OpenCode 2.0.31** ❌ — No private per-project override file is discovered. Workarounds: a git-ignored file listed in project `instructions`, or the `OPENCODE_CONFIG` / `OPENCODE_CONFIG_CONTENT` env vars. — _Evidence:_ OC/session/instruction.ts:64-68
- **OpenClaw 2026.9.8** ❌ — `CLAUDE.local.md` is archive-only in migration. The closest analogue is a per-user personal USER.md on shared Gateways, which is per person, not per project. — _Evidence:_ `oc/docs/install/migrating-claude.md` (What stays archive-only)

</details>
<details><summary><b>INS-06</b> Managed / enterprise policy instructions</summary>

- **sugar-crush** ❌ — Policy files (`PolicySnapshot`, `readPolicyFile`) govern permissions and hooks only. — _Evidence:_ `src/Cli/Bootstrap.php:7170`
- **sugar-crush-web** ❓ — —
- **Claude Code 2.1.301** ✅ — Loads first; `managed-only` instruction mode; subagents with `omitClaudeMd` still get managed files. — _Evidence:_ cc:memory#deploy-organization-wide-claude-md
- **OpenCode 1.18.35** 🟡 — Managed `opencode.json` in /etc/opencode (Linux), /Library/Application Support/opencode, or %ProgramData%\opencode, plus the macOS MDM plist domain `ai.opencode.managed`, overrides everything. Org remote config comes from `.well-known/opencode`. Because `instructions` arrays are concatenated across layers, a managed entry is always included, but users can add more. — _Evidence:_ OC/config/managed.ts; DOCS/config (Managed settings, Remote); OC/config/config.ts:48-49
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): No dedicated managed-instructions file; admins can push `instructions` via managed config (/etc/opencode, MDM) or .well-known. 2.0.31 (🟡): Policies (not instructions) are the admin-enforcement mechanism in v2; `instructions` from all config documents are combined. — _Evidence:_ V2D/migrate-v1 (Configuration); V2D/policies
- **OpenClaw 2026.9.8** ❌ — `openclaw policy` (bundled Policy plugin, `policy.jsonc`) reports drift via `doctor --lint` with an attestation hash over channels, MCP, providers, sandbox, `## Tools` metadata in AGENTS.md, etc.; "does not enforce tool calls or rewrite runtime behavior". — _Evidence:_ `oc/docs/cli/policy.md`

</details>
<details><summary><b>INS-07</b> Discovery walks up parent dirs from cwd</summary>

- **sugar-crush** 🟡 — `ancestorRoot()`: if cwd has `.git`, there is no walk. Otherwise it walks up to the first dir with `.git` and loads each level outermost-first, then cwd's own files. It stops (returns null) on reaching `$HOME` or `/` without a marker. So `~/code/CLAUDE.md` above several repos is never read, unlike Claude Code's walk to the filesystem root. — _Evidence:_ `src/Context/InstructionFileLoader.php:700-760`, `:766`
- **sugar-crush-web** 🟡 — Each workspace host is a separate process with its own root statics. — _Evidence:_ `sugar-crush/docs/SERVER.md:768-812`
- **Claude Code 2.1.301** ✅ — Project skills/agents also walk up (to the repo root; worktrees stop at the worktree root). — _Evidence:_ cc:memory#how-claude-md-files-load; cc:skills#discovery-from-parent-and-nested-directories; cc:sub-agents#choose-the-subagent-scope
- **OpenCode 1.18.35** ✅ — The walk stops at `ctx.worktree` (git root). Relative `instructions` entries are `globUp`'d in every directory from cwd to the worktree. `OPENCODE_DISABLE_PROJECT_CONFIG` disables project discovery; relative globs then resolve in the global config dir only. — _Evidence:_ OC/session/instruction.ts:79-89,123-133; CORE/fs-util.ts:154-196
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): findUp from cwd to worktree (git root) for AGENTS.md→CLAUDE.md→CONTEXT.md; relative `instructions` globbed upward too. 2.0.31 (✅): For a workspace under $HOME an AGENTS.md in e.g. ~/code/ is now included. Relative `instructions` entries still resolve per directory from the workspace to the project root, not relative to the config file (same gotcha as v1: `./tools/philosophy.md` in this host's configs matches nothing in /home/sites/sugarcraft). — _Evidence:_ V2C/config/plugin/instruction.ts:38-40,88-102; V2D/instructions (Configuration table)
- **OpenClaw 2026.9.8** ❌ — pi's walk-up loader is disabled (see INS-01). Skills doc: "A selected nested workspace stays nested: discovery does not walk up to its parent repository." — _Evidence:_ `oc/docs/tools/skills.md` (Loading order); `oc/dist/resource-loader-CFBQ8DO5.mjs` (loadProjectContextFiles unused by embedded runner)

</details>
<details><summary><b>INS-08</b> Nested subdirectory instruction files loaded lazily when files there are touched</summary>

- **sugar-crush** 🟡 — Verification correction (V1): on the default forked-turn path the announce-once marks are lost, so nested docs are re-injected every turn, one file per touch, innermost first (ISS-018, ISS-069); the text is prepended raw, with no path, fence or escaping (ISS-017). ∣ Original audit detail: `loadForPath()` walks from the touched file's dir up to (not including) the root and returns the first not-yet-emitted file. The text is prepended to Read output, and appended with a label in Glob/Grep. Deduped per session via `emittedPaths`, carried back across forks via `CarriesSessionState`. Gotchas, see ISSUES: one file per touch, raw/unfenced text, the shared set leaks across sub-agents and server sessions, and refresh points re-inject. — _Evidence:_ `src/Context/InstructionFileLoader.php:982-1110`; `src/Tools/BuiltIn/Read.php:458`; `Edit.php:304`; `Write.php:290`; `ApplyPatch.php:599`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Terminal prints a `Loaded <path>` line; such files never appear under `/context` Memory files; edits before first load apply, after load don't (they're in history); compaction summarizes them away and they reload on next touch. Also loads subdir `.claude/skills/`. — _Evidence:_ cc:memory#how-claude-md-files-load; cc:prompt-caching#editing-claude-md-mid-session; cc:tools-reference#edit-tool-behavior
- **OpenCode 1.18.35** ✅ — `Instruction.resolve` walks from the read file's directory up to, but excluding, the instance directory. It attaches the first of AGENTS.md / CLAUDE.md / CONTEXT.md per directory that is not already a system file, not already in a non-compacted earlier read, and not claimed for this message. The content is appended to the **read tool result** inside `<system-reminder>`. `metadata.loaded` makes the TUI print `↳ Loaded <path>` under the Read row. It is re-attached after the carrying read was pruned. Only `read`, or an @file mention that goes through read, triggers it; edit, grep and glob do not. Files outside cwd never trigger it. — _Evidence:_ OC/session/instruction.ts:179-221; OC/tool/read.ts:300-356; TUI/routes/session/index.tsx:2149-2180
- **OpenCode 2.0.31** ✅ — 1.18.35 appended nested files to the read tool result inside <system-reminder>. 2.0.31 publishes a separate durable synthetic message `Instructions from: …` with description `Loaded packages/web/AGENTS.md`; dedup by scanning model-visible history metadata (re-injected after compaction/revert). Edits to an already-loaded nested file are NOT detected (start a new session). — _Evidence:_ V2C/session/instructions.ts:40-90; V2C/tool/plugin/read.ts:16,82-90; V2D/instructions (Discovery)
- **OpenClaw 2026.9.8** ❌ — Nearest: the bundled `bootstrap-extra-files` hook can statically include `packages/*/AGENTS.md` on every run (not on touch). — _Evidence:_ `oc/docs/automation/hooks/bundled-hooks.md` (bootstrap-extra-files)

</details>
<details><summary><b>INS-09</b> @path imports inside instruction files</summary>

- **sugar-crush** ✅ — `ImportResolver` (MAX_DEPTH 4). Fenced ``` blocks are skipped. In the loader, the gate blocks out-of-repo targets (`<import-blocked>`), skips already-emitted ones (`<import-skipped>`), and defers oversize ones (`<import-deferred>` pointer). Absolute paths are not supported. Inline-code and `user@x.md` forms are expanded, contrary to the docs (ISSUES). Forced docs get no import expansion. — _Evidence:_ `src/Context/ImportResolver.php:27`, `:149`; `src/Context/InstructionFileLoader.php:1445`; `docs/MEMORY.md:713-732`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Backslash-escaped spaces; quoted paths not imported; imports load at launch (no context saving); approval dialog lists external files at first interactive start and is remembered (decline = disabled); network paths (UNC, /net) never followed. — _Evidence:_ cc:memory#import-additional-files
- **OpenCode 1.18.35** ❌ — Docs: “opencode doesn't automatically parse file references in AGENTS.md”. They recommend `instructions` globs, or prose telling the agent to lazily Read `@rules/x.md`. — _Evidence:_ DOCS/rules (Referencing External Files)
- **OpenCode 2.0.31** ❌ — Docs: “opencode doesn't automatically parse file references in AGENTS.md”. They recommend `instructions` globs, or prose telling the agent to lazily Read `@rules/x.md`. — _Evidence:_ DOCS/rules (Referencing External Files)
- **OpenClaw 2026.9.8** ❌ — Bootstrap files are injected verbatim (after truncation); no include/import directive documented or implemented. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/concepts/agent-workspace.md`

</details>
<details><summary><b>INS-10</b> Path-scoped rules (.claude/rules/*.md with globs, etc.)</summary>

- **sugar-crush** ✅ — `RuleLoader` has three tiers: user `~/.sugar-crush/rules` and `rulebooks`, project `.sugar-crush/rules`, root `RULES.md`. A rule with a `PathTrigger` is skipped from the standing prompt and nudged by `RulePathNudge` in tool output on first matching touch. The nudge re-walks the rules on every consult, so mid-session edits are picked up. `keywords:` and `models:` are parsed but inert. It does not read `.claude/rules`. — _Evidence:_ `src/Runtime.php:4525-4545`; `src/Context/RuleLoader.php:290`, `:444`; `src/Context/RulePathNudge.php`
- **sugar-crush-web** 🟡 — No path-scoped-rule UI; the output lands as a UI-only transcript row after a re-snapshot. — _Evidence:_ `sugar-crush/src/Host/Commands/RulesHostCommand.php`; live `command.list` (runsIn server)
- **Claude Code 2.1.301** ✅ — Brace expansion budget (1,000 patterns / 4 MiB); only `paths` frontmatter honored; symlinked shared rules (external targets need the import approval, then only unscoped ones load); `~/.claude/rules/` for user; path rules reload on demand after compaction. — _Evidence:_ cc:memory#organize-rules-with-claude/rules/
- **OpenCode 1.18.35** ❌ — No `.claude/rules`-style frontmatter globs. Every `instructions` match is loaded unconditionally. — _Evidence:_ OC/session/instruction.ts (no glob-conditional logic)
- **OpenCode 2.0.31** ❌ — No `.claude/rules`-style frontmatter globs. Every `instructions` match is loaded unconditionally. — _Evidence:_ OC/session/instruction.ts (no glob-conditional logic)
- **OpenClaw 2026.9.8** ❌ — `bootstrap-extra-files` globs choose extra files to inject unconditionally (basenames limited to AGENTS/SOUL/IDENTITY/USER/BOOTSTRAP/MEMORY); nothing activates by edited path. — _Evidence:_ `oc/docs/automation/hooks/bundled-hooks.md`

</details>
<details><summary><b>INS-11</b> Extra instruction files via config (opencode `instructions` array, globs, URLs)</summary>

- **sugar-crush** 🟡 — `Bootstrap::forcedInstructions()` → `loadForced()`. Patterns starting `/` are `continue`d with no refusal recorded. `~/x` globs relative to the repo and matches nothing. No `@import` expansion. A project may not set it. — _Evidence:_ `src/Cli/Bootstrap.php:9632`; `src/Context/InstructionFileLoader.php:890`, `:946`; `docs/SETTINGS.md:262`
- **sugar-crush-web** 🟡 — `settings.set` only writes cosmetic/tuning/narrowing keys. — _Evidence:_ `sugar-crush/docs/SERVER.md:670-686`
- **Claude Code 2.1.301** 🟡 — With the env var, an added dir contributes CLAUDE.md, .claude/CLAUDE.md, .claude/rules/*.md, CLAUDE.local.md. `--append-system-prompt-file` for system-prompt-level text; SessionStart hooks can inject arbitrary text. — _Evidence:_ cc:memory#load-from-additional-directories; help
- **OpenCode 1.18.35** ✅ — Gotcha: relative entries are **not** rebased to the config file's directory; they are globbed from cwd up to the git root. The `"./tools/philosophy.md"` entry in this host's global config (~/.config/opencode/opencode.jsonc) and in the repo's .opencode/opencode.jsonc therefore matches nothing inside /home/sites/sugarcraft (the file is at ~/.config/opencode/tools/philosophy.md). It is silently dropped with no warning. Missing files and failed or slow URLs are dropped silently too. — _Evidence:_ OC/session/instruction.ts:95-103,135-150,155-168; OC/config/config.ts:48-49; LOCAL: both opencode.jsonc `instructions`; `ls /home/sites/sugarcraft/tools/philosophy.md` → absent
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `instructions` array: files, globs, ~/, absolute paths, http(s) URLs (5 s timeout); merged+deduped across config layers. 2.0.31 (✅): No longer re-fetched every step. New files matching a glob load on the next config change. — _Evidence:_ V2C/config/plugin/instruction.ts:104-163; V2D/instructions (Configuration)
- **OpenClaw 2026.9.8** 🟡 — Enable `hooks.internal.entries.bootstrap-extra-files` with `paths: ["packages/*/AGENTS.md"]` (2 MiB read cap, normal 20k/60k injection limits, subagents keep only AGENTS.md). Channels/groups accept a `systemPrompt` string (e.g. WhatsApp per group/DM). Internal `agent:bootstrap` hook can mutate or replace injected files (e.g. swap SOUL.md). No URLs or arbitrary filenames. — _Evidence:_ `oc/docs/automation/hooks/bundled-hooks.md`; `oc/docs/channels/whatsapp.md`; `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>INS-12</b> WHEN loaded (session start / every turn / on demand) and re-read on change</summary>

- **sugar-crush** ✅ — `SessionPromptMemo` plus the loader's own cache. `EngineBackend::beginSessionTurn()` detects refresh points from the history (`observeTurn`) and calls `InstructionFileLoader::refresh()`. Mid-session disk edits are not seen until then (same trade-off as Claude Code and Aider). Bug: a dream pass and multi-session server use also trigger refreshes (ISSUES). Path-scoped rules are re-walked per consult. — _Evidence:_ `src/Context/SessionPromptMemo.php:189`; `src/Backend/EngineBackend.php:2702-2717`; `src/Context/InstructionFileLoader.php:1328`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Project-root CLAUDE.md and unscoped rules are re-injected from disk after compaction; `InstructionsLoaded` hook fires at start and on every lazy load (with reason); the Project-instructions setting applies from the next message. — _Evidence:_ cc:prompt-caching#editing-claude-md-mid-session; cc:context-window#what-survives-compaction; cc:hooks#instructionsloaded
- **OpenCode 1.18.35** ✅ — `instruction.system()` is called inside the agent loop for each step, together with `sys.environment()` and `sys.skills()`. Files are re-globbed and re-read, and http(s) instruction URLs are fetched again each step (up to 5 s each). Edits take effect mid-turn, but any change rewrites the system prefix and breaks the prompt cache. The v2 engine's frozen "context epoch" baseline exists in CORE/session/context-epoch.ts but is not used by the v1 loop. Nested files: once per assistant message (`claims` cleared per message), deduped against earlier read parts. — _Evidence:_ OC/session/prompt.ts:1257-1267; OC/session/instruction.ts:105-108,155-169; CORE/session/context-epoch.ts (v2 only)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Rebuilt from disk on EVERY LLM step (no cache): edits apply next step; remote URLs re-fetched each step. 2.0.31 (✅): Content-addressed values (SHA-256) per source key; first delta is the baseline; later changes render once as chronological System messages (`The instructions from X changed. Here's the diff:` with a unified patch, or `no longer apply`). Baseline resets after compaction or a committed revert; fork adopts parent's newest values. 1.18.35 instead rebuilt the system prompt from disk every step. — _Evidence:_ specs/v2/session.md (Instructions Are Value Deltas); V2C/instruction-discovery.ts:126-149; V2C/session/instruction-state.ts
- **OpenClaw 2026.9.8** ✅ — System prompt is "rebuilt each run"; with default `contextInjection: "always"` bootstrap files are re-resolved per turn. `continuation-skip` skips re-injection on continuation turns after a recorded full-bootstrap turn (heartbeats, post-compaction retries still full); `never` disables. After compaction an AGENTS.md excerpt (`postCompactionMaxChars` 1800) is re-injected. BOOTSTRAP.md only while pending on a brand-new workspace. Note: `docs/concepts/agent.md` still says "on the first turn of a new session" — inconsistent with the system-prompt and config docs. — _Evidence:_ `oc/docs/concepts/context.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (contextInjection, contextLimits); `oc/docs/concepts/agent.md`

</details>
<details><summary><b>INS-13</b> HOW injected (system prompt vs user-message reminder) and precedence order</summary>

- **sugar-crush** ✅ — Each document is its own fenced section, `<user-rules>` or `<project-instructions>`, with a provenance preamble saying the harness and maxims layers outrank it. All sit after base, maxims, tool-guidance, repo-map and symbol-map, and before memory, skills and env. Nested files arrive in tool results; path rules arrive as `<system-reminder>` in tool results. — _Evidence:_ `src/Runtime.php:4333-4740`, `:122`, `:149`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Observed in this session: one reminder opens with "Codebase and user instructions are shown below… IMPORTANT: These instructions OVERRIDE any default behavior…", then each file as "Contents of <path> (project instructions, checked into the codebase):", with @-imported AGENTS.md and CONTRIBUTING.md following CLAUDE.md as their own blocks, and MEMORY.md labelled "(user's auto-memory, persists across conversations)". Lives in the cached "project context" layer. Use `--append-system-prompt` for true system-prompt placement. — _Evidence:_ obs; cc:memory#claude-isnt-following-my-claude-md; cc:agent-sdk/modifying-system-prompts#reminders-claude-code-adds-to-the-conversation; cc:prompt-caching#how-the-cache-is-organized
- **OpenCode 1.18.35** ✅ — System content in order: (1) the agent `prompt` OR the model-family base prompt, (2) the `<env>` block, (3) `<available_references>`, (4) instruction blocks: global → project nearest-first → config files → URLs, (5) `<mcp_instructions>`, (6) the skills list, (7) an optional per-message `system` from the API. Plugin hook `experimental.chat.system.transform` runs next, then the result is collapsed to ≤2 system messages. Nested files ride in tool results. Plan and build-switch reminders are synthetic **user** text parts. — _Evidence:_ OC/session/llm/request.ts:56-78; OC/session/prompt.ts:1257-1268; OC/session/system.ts:69-137; OC/session/reminders.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Joined into the single system message after base/agent prompt + <env>; each prefixed 'Instructions from: <path>'. 2.0.31 (✅): Baseline goes into the system prompt; updates and nested loads arrive as in-history System/synthetic messages. Date and environment moved after instructions; `Today's date is now:` and `The environment you are running in is now:` deltas on change. — _Evidence:_ V2D/instructions (Ordering); V2C/instructions/builtins.ts:20-55
- **OpenClaw 2026.9.8** ✅ — Rendered under "Workspace Files (injected)". Missing required files inject a short marker; truncation adds a fixed notice. Codex harness variant: AGENTS.md via native discovery, SOUL/IDENTITY/USER appended to parent-only request instructions, MEMORY.md replaced by a note pointing at memory tools. Sub-agents (promptMode `minimal`) receive only AGENTS.md, labelled Subagent Context. Heartbeat scratch is appended only to the heartbeat user message. — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>INS-14</b> Shown to the user at startup (banner / count / list)?</summary>

- **sugar-crush** ❌ — The welcome text is "Welcome to SugarCrush! Start typing to chat...". `reportPromptBudgetDeferrals()` and `reportProjectTierRefusals()` add warning rows only when a file was left out or refused. — _Evidence:_ `lang/en.php:1966`; `src/Cli/Bootstrap.php:5469-5530`
- **sugar-crush-web** ❌ — `/notices` (server-run) prints this launch's warnings on demand. — _Evidence:_ `src-web/views/DashboardView.vue`; `sugar-crush/src/Host/Commands/NoticesHostCommand.php`
- **Claude Code 2.1.301** 🟡 — Lazily loaded files print `Loaded <path>`; skipped files raise an `Instruction file not loaded` notice; size warnings repeat in `/status`. To see what loaded, run `/context` or `/memory`. — _Evidence:_ cc:memory#my-claude-md-is-too-large; cc:memory#when-claude-code-reads-agents-md; cc:errors#instruction-file-not-loaded
- **OpenCode 1.18.35** ❌ — The home screen shows logo, prompt, tips and a footer (dir:branch, MCP count, version) but no instruction list. The TUI never mentions root, global or config instruction files. — _Evidence:_ TUI/feature-plugins/home/footer.tsx; TUI/routes/session/index.tsx:2172-2180
- **OpenCode 2.0.31** 🟡 — 1.18.35 (❌): No startup banner/count of loaded rule files; only lazily-loaded nested files show '↳ Loaded <path>' under Read. 2.0.31 (🟡): Clients can see which instruction sources changed (keys/paths) but not their contents (“Instruction values remain privileged”). The TUI groups consecutive instruction loads and counts them in activity summaries. — _Evidence:_ V2D/instructions (Updates); V2T/routes/session/index.tsx:1820,1971-1979; V2T/routes/session/group-view.tsx:234-240
- **OpenClaw 2026.9.8** ❌ — TUI footer shows agent, session, model, goal, toggles, token counts; no list or count of injected files. `/context list`, `/status`, doctor and logs carry file names and raw vs injected sizes. — _Evidence:_ `oc/docs/web/tui.md` (What you see); `oc/docs/concepts/context.md`

</details>
<details><summary><b>INS-15</b> Inspect loaded instructions in the UI (/memory, /context, panel)</summary>

- **sugar-crush** 🟡 — `/context` (also a server host command) prints per-label tokens and stability. No command lists the loaded paths: `/memory` is memory notes only, and `/rules` lists rule files but not CLAUDE.md/AGENTS.md. — _Evidence:_ `src/Commands/ContextCommand.php:48-56`; `src/Runtime.php:4253`
- **sugar-crush-web** 🟡 — Verified: rows "System prompt ~2.8K … project-instructions ~89 per-session, project-memory ~442 …". — _Evidence:_ live probe
- **Claude Code 2.1.301** ✅ — `/context all` expands the per-item list (fullscreen collapses it); `/memory` shows user/project entries even for files that don't exist yet; `/doctor prompt-audit` audits content (v2.1.283+). — _Evidence:_ cc:memory#view-and-edit-with-/memory; cc:commands
- **OpenCode 1.18.35** ❌ — `/status` lists MCP, LSP, formatters and plugins only. There is no endpoint or UI that renders the assembled system prompt. — _Evidence:_ TUI/component/dialog-status.tsx:54-160; CLI: `opencode debug --help`
- **OpenCode 2.0.31** ❌ — `/status` lists MCP, LSP, formatters and plugins only. There is no endpoint or UI that renders the assembled system prompt. — _Evidence:_ TUI/component/dialog-status.tsx:54-160; CLI: `opencode debug --help`
- **OpenClaw 2026.9.8** ✅ — `/context list` prints each injected file's raw/injected chars+tokens and truncation; `/context detail` adds top skills/tools by size and compactable message counts; `/context map` renders a treemap image; sizes only, never the full prompt. — _Evidence:_ `oc/docs/concepts/context.md`

</details>
<details><summary><b>INS-16</b> Edit instructions from the UI (/memory opens editor, `#` quick-add)</summary>

- **sugar-crush** ❌ — `/newrule [focus]` asks the agent to Write `.sugar-crush/rules/<name>.md` from the conversation, approval required. `/editor` edits the input box only. — _Evidence:_ `src/Host/Commands/NewRulePrompt.php`; `builtin-commands/1410-newrule.php`
- **sugar-crush-web** ❌ — — — _Evidence:_ `src-web/components/panels/MemoryPanel.vue`
- **Claude Code 2.1.301** ✅ — Selecting a missing file creates it; `Ctrl+G` external editor for prompts. The old `#` shortcut was removed ("tell Claude to edit your CLAUDE.md instead"). — _Evidence:_ cc:memory#view-and-edit-with-/memory; CL v2.0.70 (removed # shortcut for quick memory entry)
- **OpenCode 1.18.35** ❌ — No `/memory`, and no `#` prefix handling in the prompt; `!` is shell mode and `/` is commands. — _Evidence:_ TUI/component/prompt/index.tsx; DOCS/tui
- **OpenCode 2.0.31** ❌ — No `/memory`, and no `#` prefix handling in the prompt; `!` is shell mode and `/` is commands. — _Evidence:_ TUI/component/prompt/index.tsx; DOCS/tui
- **OpenClaw 2026.9.8** ✅ — Workspace files editable in the Control UI; asking "remember X" makes the agent write the right file; on shared gateways personal USER.md via tool. No `#` quick-add and no `/memory` editor command. — _Evidence:_ `oc/docs/concepts/agent-workspace.md` (Workspace file map); `oc/docs/concepts/memory.md`

</details>
<details><summary><b>INS-17</b> Generate instructions (/init)</summary>

- **sugar-crush** ✅ — Sends `InitCommand::PROMPT` as a user turn. It reads the existing AGENTS.md, CLAUDE.md, GEMINI.md, .cursorrules, .clinerules and copilot-instructions first. The result is not in the prompt until the next refresh point. It has no host command, so `serve`/web reject it as `runsIn: client`. — _Evidence:_ `src/Commands/InitCommand.php:36`; `builtin-commands/2500-init.php`; `src/Host/SessionHost.php:967`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.exec name=init`
- **Claude Code 2.1.301** ✅ — Offers `/import` when Codex/Gemini config is found; `/doctor` proposes trims of derivable content; `/doctor prompt-audit` finds stale/conflicting instructions. — _Evidence:_ cc:memory#set-up-a-project-claude-md; cc:commands
- **OpenCode 1.18.35** ✅ — The built-in server command `init` (template `initialize.txt`, with `${path}` = worktree). It scans the repo, may ask targeted questions (question tool), and writes or improves AGENTS.md in place. It focuses on build/lint/test commands, ordering, non-obvious architecture, quirks and existing Cursor/Copilot rules. Also exposed as `POST /session/:id/init`. — _Evidence:_ OC/command/index.ts:70-78; OC/command/template/initialize.txt; DOCS/rules (Initialize)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /init: guided AGENTS.md creation/improve-in-place; may ask questions; focuses on commands, gotchas, existing rule files. 2.0.31 (✅): /init (AGENTS.md setup) appends its template to your text and runs in the current session — _Evidence:_ V2C/plugin/command.ts:28-40
- **OpenClaw 2026.9.8** 🟡 — `openclaw setup`/`onboard` create AGENTS.md, SOUL.md, IDENTITY.md, USER.md, BOOTSTRAP.md from templates; the BOOTSTRAP ritual has the agent co-author identity/user/soul with the user, then the file is deleted. Control UI "New agent" (custodian) writes an approved purpose into the new AGENTS.md; role templates (coordinator/researcher/reviewer/writer). — _Evidence:_ `oc/docs/start/bootstrapping.md`; `oc/docs/reference/templates/BOOTSTRAP.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>INS-18</b> Size limits / truncation / warnings for large instruction files</summary>

- **sugar-crush** ✅ — Over-limit documents become `Instruction file deferred: budget (N bytes…). Read <path>` (≤1 KiB). At most 4 pointers, plus a "N further deferred" note. Imports that would overflow become `<import-deferred>`. Every decision is recorded in `refusedPaths()` and reported at launch. A nested file refused mid-session gets one transcript notice. — _Evidence:_ `src/Context/InstructionFileLoader.php:98`, `:580`; `src/Runtime.php:231`, `:251`, `:261`, `:4875`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Each CLAUDE.md, rule and import counts as a separate file; `/doctor` estimates cost and biggest contributors. — _Evidence:_ cc:memory#my-claude-md-is-too-large; cc:memory#how-it-works
- **OpenCode 1.18.35** ❌ — `read()` returns the whole file and `system()` concatenates everything with no cap or warning. — _Evidence:_ OC/session/instruction.ts:91-93,155-169
- **OpenCode 2.0.31** ❌ — `read()` returns the whole file and `system()` concatenates everything with no cap or warning. — _Evidence:_ OC/session/instruction.ts:91-93,155-169
- **OpenClaw 2026.9.8** ✅ — `bootstrapMaxChars` / `bootstrapTotalMaxChars` (per-agent overridable); USER.md cap can only be lowered. When truncation happens a concise, non-configurable notice tells the model to read the files directly (no per-file detail); diagnostics in `/context`, `/status`, doctor (bootstrap-size check), logs. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`

</details>
<details><summary><b>INS-19</b> Workspace-trust gating before loading project instructions</summary>

- **sugar-crush** ❌ — Trust lists cover hooks, MCP, `!cmd` in project commands and project settings, but not CLAUDE.md/AGENTS.md. Root docs are `PromptFence`-escaped. Nested docs are not escaped (ISSUES). — _Evidence:_ `src/Cli/Bootstrap.php:6382-6585`; `src/Runtime.php:122`
- **sugar-crush-web** ❌ — Trust grants are not `settings.set`-writable; a picked directory runs in a workspace host under the same frozen allow-lists. — _Evidence:_ `sugar-crush/docs/SERVER.md:86-91,670-686`
- **Claude Code 2.1.301** 🟡 — Trust gates `permissions.allow`, `additionalDirectories`, subagent frontmatter hooks/MCP, headersHelper, `.mcp.json` servers (separate prompt). External `@` imports and external symlinked rules have their own approval. Project hooks, `env` and helpers still run under untrusted `-p`. — _Evidence:_ cc:permissions#what-runs-before-you-trust-a-folder; cc:security; cc:memory#import-additional-files
- **OpenCode 1.18.35** ❌ — No trust prompt. A cloned repo's `.opencode/` plugins, tools, MCP servers and instructions load and run at startup. The only guard is the global env flag disabling all project config. — _Evidence:_ CORE/flag/flag.ts (OPENCODE_DISABLE_PROJECT_CONFIG); DOCS/plugins (auto-load at startup)
- **OpenCode 2.0.31** ❌ — No trust prompt. A cloned repo's `.opencode/` plugins, tools, MCP servers and instructions load and run at startup. The only guard is the global env flag disabling all project config. — _Evidence:_ CORE/flag/flag.ts (OPENCODE_DISABLE_PROJECT_CONFIG); DOCS/plugins (auto-load at startup)
- **OpenClaw 2026.9.8** ❌ — The workspace is operator-configured; the state DB stores a workspace attestation only so startup refuses to silently reseed BOOTSTRAP.md for a wiped workspace. Workspace *hooks* need explicit opt-in; execution-folder AGENTS.md has no trust gate documented. — _Evidence:_ `oc/docs/concepts/agent.md`; `oc/docs/automation/hooks.md`

</details>
<details><summary><b>INS-20</b> Exclusion controls (claudeMdExcludes, disable flags)</summary>

- **sugar-crush** ❌ — There is nothing like `claudeMdExcludes` and no flag to skip root, ancestor or nested docs. — _Evidence:_ `src/Context/InstructionFileLoader.php` (no switch); `docs/SETTINGS.md:1098`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/settings/SettingsView.vue`
- **Claude Code 2.1.301** ✅ — Per-subagent `omitClaudeMd`; built-in Explore/Plan skip CLAUDE.md; managed file can't be excluded. — _Evidence:_ cc:memory#exclude-specific-claude-md-files; cc:agent-sdk/modifying-system-prompts#turn-off-the-context-your-agent-replaces; help
- **OpenCode 1.18.35** 🟡 — You can drop ~/.claude/CLAUDE.md and project CLAUDE.md, or all project config. There is no glob exclude for specific AGENTS.md files. — _Evidence:_ DOCS/rules; DOCS/cli (Environment variables)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Env toggles: OPENCODE_DISABLE_CLAUDE_CODE(_PROMPT), OPENCODE_DISABLE_PROJECT_CONFIG; no per-file exclude list. 2.0.31 (🟡): OPENCODE_DISABLE_PROJECT_CONFIG (project AGENTS.md only); no per-file excludes; CLAUDE flags now only affect skills — _Evidence:_ V2D/instructions; V2CL v2.0.30
- **OpenClaw 2026.9.8** ✅ — Per-agent overrides for injection and limits; skip creating optional SOUL/USER/IDENTITY; hooks can filter/replace the file list. — _Evidence:_ `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`

</details>
<details><summary><b>INS-N1</b> 🆕 Injection fencing / escaping of instruction-file content before it reaches the model</summary>

- **sugar-crush** ✅ — `PromptFence::escape()` defangs `<project-instructions>`, `<system-reminder>`, `<∣im_start∣>` and the rest inside every root, ancestor, forced and personal doc, plus rules, memory and skills. Authority preambles tell the model a repo doc cannot override the harness. Pinned by `BaseSystemPromptTest` forgery guards. Exception: nested on-touch docs (ISSUES). — _Evidence:_ `src/Context/PromptFence.php`; `src/Runtime.php:122`, `:4896-4900`; `docs/PROMPT_ENGINEERING.md` "Fence and provenance rules"
- **sugar-crush-web** ✅ — A served turn runs SessionHost → TurnRunner → EngineBackend → Runtime, the same path as the TUI. So `PromptFence::escape()` and the authority preambles cover every root, ancestor, forced and personal doc, plus rules, memory and skills, with the same exception SC has (nested on-touch docs, per a1). A workspace host for a picked directory fences that root's files. The browser shows none of this; `/context` (server-run) gives section sizes only. — _Evidence:_ `src/Host/SessionHost.php:48-60`; `src/Host/TurnRunner.php:488-496`; `src/Context/PromptFence.php`
- **Claude Code 2.1.301** 🟡 — Since 2.1.284 auto-memory text has invisible characters and tags imitating CC markup neutralized before it reaches Claude. CLAUDE.md content is injected verbatim (minus block-level HTML comments) in a reminder that tells Claude the instructions override defaults. Related escaping elsewhere: hook `additionalContext` gets `<system-reminder>` tags escaped; synced skill descriptions have angle brackets escaped; subagent reports are scanned. — _Evidence:_ CL v2.1.284; cc:memory#how-claude-md-files-load; cc:hooks#add-context-for-claude; cc:skills#how-claude-code-handles-the-frontmatter-of-a-synced-skill
- **OpenCode 1.18.35** ❌ — System-prompt instructions and nested AGENTS.md (inside `<system-reminder>`) are injected verbatim. There is no escaping of tags or delimiters in file content. — _Evidence:_ OC/session/instruction.ts:155-169,214; OC/tool/read.ts:356
- **OpenCode 2.0.31** ❌ — Values are “privileged” and hidden from clients, but they are not escaped for the model. — _Evidence:_ V2C/instruction-discovery.ts:126-149; V2C/session/instructions.ts:73-79
- **OpenClaw 2026.9.8** ❌ — `buildProjectContextSection` emits `## <path>` followed by the file body; the only sanitization strips the legacy default-heartbeat block. Fencing/escaping exists for *other* inputs: inbound channel text has internal-context delimiters escaped and provenance-quoted, Active Memory injects a "hidden untrusted prompt prefix", inter-session messages are marked `isUser=false`, untrusted MCP names never enter the system prompt. — _Evidence:_ `oc/dist/package-update-activation-recovery.mjs` (buildProjectContextSection, sanitizeContextFileContentForPrompt); `oc/docs/concepts/system-prompt.md` (context provenance)

</details>

### MEM — Memory

<details><summary><b>MEM-01</b> Persistent cross-session memory exists</summary>

- **sugar-crush** ✅ — `MemoryStore` (one `.md` per note, YAML frontmatter). Home store: `~/.sugar-crush/memory/{user,project/<key>,agent}/`. Repo store: `<repo>/.sugar-crush/memory/project/`. — _Evidence:_ `src/Memory/MemoryStore.php`; `docs/MEMORY.md:15-60`
- **sugar-crush-web** ✅ — `memory.list/search/add/edit/delete` are `/memory` over the wire. — _Evidence:_ `src-web/stores/agents/memory.ts`
- **Claude Code 2.1.301** ✅ — Machine-local; one auto-memory dir per git repo, shared across its worktrees and subdirectories. — _Evidence:_ cc:memory
- **OpenCode 1.18.35** 🔌 — Core has no memory subsystem: no memory tool, file or prompt section in OC/. This host's global config loads `opencode-agent-memory@latest` (Letta-style blocks plus journal). The ecosystem page lists `opencode-supermemory`; awesome-opencode lists Honcho, oc-mnemoria, Magic Context, Harness Memory and Lemma. — _Evidence:_ `grep -rn memory OC/session`: none; LOCAL: ~/.config/opencode/opencode.jsonc plugin list; ~/.cache/opencode/packages/opencode-agent-memory@latest README; DOCS/ecosystem
- **OpenCode 2.0.31** 🔌 — 1.18.35 (🔌): No native memory; plugins: opencode-agent-memory (used here), opencode-supermemory, opencode-honcho, oc-mnemoria, magic-context. 2.0.31 (🔌): `npm view opencode-agent-memory` 0.3.0 depends on `@opencode/plugin ^2.0.12` (v2). Other memory plugins (supermemory, honcho, mnemoria) need checking per version. No memory code in V2C (grep). — _Evidence:_ npm view opencode-agent-memory dependencies; V2D/migrate-v1 (Plugins warning)
- **OpenClaw 2026.9.8** ✅ — "The model only remembers what gets saved to disk; there is no hidden state." Files: USER.md, MEMORY.md, memory/YYYY-MM-DD.md, DREAMS.md; engines builtin (default), Honcho, LanceDB plugins. — _Evidence:_ `oc/docs/concepts/memory.md`

</details>
<details><summary><b>MEM-02</b> Auto-memory (model saves facts without being asked)</summary>

- **sugar-crush** ✅ — `AutoMemoryConsolidator::call()` is scheduled from `Chat` when a turn settles and nothing is queued. It reads ≤24 msgs / 24 KB of user and assistant text, redacts secrets, writes in the parent process, and may only touch `auto-memory`-tagged notes. Disabled by `SUGARCRUSH_DISABLE_AUTO_MEMORY` or `memory.autoConsolidate:false`. — _Evidence:_ `src/Chat.php:2468-2473`; `src/Memory/AutoMemoryConsolidator.php`; `docs/MEMORY.md:506-566`
- **sugar-crush-web** 🟡 — No "memory saved" notice. — _Evidence:_ `src-web/components/ToolCard.vue`
- **Claude Code 2.1.301** ✅ — Skips anything derivable from code or already in CLAUDE.md; "remember X" → auto memory, "add to CLAUDE.md" → CLAUDE.md. Toggle in `/memory` (`autoMemoryEnabled`) or `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`; can't be switched on from background or Claude-spawned sessions; off by default in self-hosted environments; `--bare` disables. Changelog references background "memory-extraction" writes. — _Evidence:_ cc:memory#auto-memory; CL v2.1.77 (memory-extraction race fix)
- **OpenCode 1.18.35** 🔌 — opencode-agent-memory's prompt encourages the agent to maintain its blocks. Harness Memory auto-captures evidence from tool interactions. Supermemory and Honcho capture conversation memory. — _Evidence:_ opencode-agent-memory README (Features); awesome-opencode (Harness Memory, Honcho)
- **OpenCode 2.0.31** 🔌 — opencode-agent-memory's prompt encourages the agent to maintain its blocks. Harness Memory auto-captures evidence from tool interactions. Supermemory and Honcho capture conversation memory. — _Evidence:_ opencode-agent-memory README (Features); awesome-opencode (Harness Memory, Honcho)
- **OpenClaw 2026.9.8** ✅ — AGENTS.md template tells the agent to record durable facts/daily notes ("remember this" → writes file); pre-compaction flush turn; default-on dreaming promotes recall-backed candidates into MEMORY.md; opt-in `session-memory` hook saves excerpts on /new, /reset, auto-rollover; self-learning (default `auto`) turns corrections into Workshop skills; LanceDB plugin auto-capture. — _Evidence:_ `oc/docs/concepts/memory.md`; `oc/docs/reference/templates/AGENTS.md`; `oc/docs/automation/hooks/bundled-hooks.md`; `oc/docs/tools/self-learning.md`

</details>
<details><summary><b>MEM-03</b> Memory scopes (user / project / local / agent / team)</summary>

- **sugar-crush** ✅ — User and project reach the prompt; agent is for imports only. `MemoryScope::Local` maps to `agent`. Per-agent memory: the preset `memory:` key is inert. — _Evidence:_ `src/Memory/MemoryStore.php`; `src/Support/FrontmatterKeyAudit.php:90-95`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/agents/memory.ts:7-8`
- **Claude Code 2.1.301** ✅ — `autoMemoryDirectory` relocation; `CLAUDE_CODE_PROJECT_DIR_NAME` shares one dir across projects; `CLAUDE_MEMORY_STORES` team memory stores in remote sessions (changelog only); Claude Tag has workspace/channel memory (separate Slack product). — _Evidence:_ cc:memory#storage-location; cc:sub-agents#enable-persistent-memory; CL v2.1.172
- **OpenCode 1.18.35** 🔌 — agent-memory: global blocks shared across projects, project blocks shared across sessions. oc-mnemoria tags entries by creating agent. — _Evidence:_ opencode-agent-memory README; awesome-opencode (oc-mnemoria)
- **OpenCode 2.0.31** 🔌 — agent-memory: global blocks shared across projects, project blocks shared across sessions. oc-mnemoria tags entries by creating agent. — _Evidence:_ opencode-agent-memory README; awesome-opencode (oc-mnemoria)
- **OpenClaw 2026.9.8** 🟡 — MEMORY.md only loads in the main, private session (never group contexts); `rememberAcrossConversations` recalls across the agent's private conversations only; memory-wiki supports per-agent vaults; personal USER.md per authenticated profile on multi-user gateways. — _Evidence:_ `oc/docs/concepts/agent-workspace.md`; `oc/docs/concepts/session.md` (Remember across conversations); `oc/docs/concepts/multi-agent.md`

</details>
<details><summary><b>MEM-04</b> Storage format & location</summary>

- **sugar-crush** ✅ — Index ≤200 lines / 25 KB, not written in the repo store. FTS5 search cache `.search-<key>.sqlite`. Embeddings cache `~/.sugar-crush/cache/embeddings.sqlite`. Every `/memory` mutation is a git commit (`/memory log`, `/memory restore`). — _Evidence:_ `docs/MEMORY.md:21-53`, `:194-222`; `src/Memory/MemoryHistory.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/panels/MemoryPanel.vue`
- **Claude Code 2.1.301** ✅ — `modified` ISO timestamp auto-maintained (v2.1.214+); excluded from the transcript retention sweep; plain files you can edit/delete. — _Evidence:_ cc:memory#storage-location; cc:memory#how-it-works
- **OpenCode 1.18.35** 🔌 — Observed on this host: ~/.config/opencode/memory/{persona,human,orchestration-directives,…}.md and /home/sites/sugarcraft/.opencode/memory/*.md (dozens of lane and campaign notes). — _Evidence:_ LOCAL: `ls ~/.config/opencode/memory /home/sites/sugarcraft/.opencode/memory`
- **OpenCode 2.0.31** 🔌 — Observed on this host: ~/.config/opencode/memory/{persona,human,orchestration-directives,…}.md and /home/sites/sugarcraft/.opencode/memory/*.md (dozens of lane and campaign notes). — _Evidence:_ LOCAL: `ls ~/.config/opencode/memory /home/sites/sugarcraft/.opencode/memory`
- **OpenClaw 2026.9.8** ✅ — memory/imports/<tool>/ for imported memory; DREAMS.md diary; index state in `openclaw-agent.sqlite`; `openclaw memory index --force` rebuilds. — _Evidence:_ `oc/docs/concepts/memory.md`; `oc/docs/concepts/memory-builtin.md`; `oc/docs/concepts/agent-workspace.md`

</details>
<details><summary><b>MEM-05</b> Memory index/entry file loaded into the prompt each session</summary>

- **sugar-crush** ✅ — `MemoryBlock::capture()`, capped at 40 entries / 4 KB / 512 B per line (user ≤4 / 1 KB), followed by `STANDING_INSTRUCTIONS` on when and what to save and to search before answering. Memoised per session, so a note saved mid-session is not in the index until a refresh point (docs say "next turn", see ISSUES). — _Evidence:_ `src/Context/MemoryBlock.php:141-231`; `src/Runtime.php:5650-5666`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `/context` probe
- **Claude Code 2.1.301** ✅ — Observed as a "Contents of …/memory/MEMORY.md (user's auto-memory…)" block in the instruction reminder. Near-limit writes trigger a reminder to shorten; over-limit writes succeed but return an error telling Claude to rewrite the index; invisible chars and fake markup neutralized. Not loaded into non-fork subagents. — _Evidence:_ obs; cc:memory#how-it-works; cc:context-window#what-survives-compaction
- **OpenCode 1.18.35** 🔌 — Memory blocks “appear in the system prompt, always in-context”. They use the plugin hooks, since core has no memory slot. — _Evidence:_ opencode-agent-memory README; PLUG/index.ts:291-298
- **OpenCode 2.0.31** 🔌 — Memory blocks “appear in the system prompt, always in-context”. They use the plugin hooks, since core has no memory slot. — _Evidence:_ opencode-agent-memory README; PLUG/index.ts:291-298
- **OpenClaw 2026.9.8** ✅ — Bounded by bootstrap limits (truncation = signal to distill). Native Codex gets a small "use memory_search/memory_get" note instead of the file. Bare `/new`/`/reset` with follow-up text prepend a one-shot startup prelude of recent daily notes (`startupContext.dailyMemoryDays` 2, `maxTotalChars` 2800). — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (startupContext)

</details>
<details><summary><b>MEM-06</b> Relevance selection / retrieval (embedding / vector / keyword / hybrid)</summary>

- **sugar-crush** ✅ — `HybridMemoryRanker` (0.7 vector + 0.3 BM25, gate 0.35, half-life 30 d, MMR λ0.7). Embeddings are opt-in (`embeddingModel`). The query is the latest user message. Primed in the parent before the fork. The embedding HTTP call is synchronous there (ISSUES). `/memory search` uses FTS5 BM25. — _Evidence:_ `src/Context/MemoryRecallBlock.php:126`; `src/Memory/HybridMemoryRanker.php`; `src/Backend/EngineBackend.php:3528-3560`
- **sugar-crush-web** ✅ — Ranking is the server's. — _Evidence:_ `src-web/stores/agents/memory.ts:37-49`
- **Claude Code 2.1.301** 🟡 — Topic files read on demand with standard tools; changelog shows recall uses structured-output side calls (fixed on Mantle/gateways in 2.1.288) and restored memories carry an age note. Retrieval logic beyond that is undocumented. — _Evidence:_ cc:memory#how-it-works; CL v2.1.288
- **OpenCode 1.18.35** 🔌 — agent-memory's `journal_search` is semantic. oc-mnemoria uses hybrid BM25 + semantic over an append-only store. Magic Context searches across history, memories and facts. — _Evidence:_ opencode-agent-memory README (Journal Tools); awesome-opencode
- **OpenCode 2.0.31** 🔌 — 1.18.35 (🔌): agent-memory journal semantic search; oc-mnemoria hybrid BM25+semantic; magic-context unified search. 2.0.31 (🔌): agent-memory 0.3 journal search (v2 API); supermemory 2.0.17 supports v1+v2; others unverified on v2 — _Evidence:_ npm view opencode-agent-memory/opencode-supermemory dependencies
- **OpenClaw 2026.9.8** ✅ — Parallel vector and BM25 legs, weighted merge, deterministic re-rank (30-day half-life for dated notes; MEMORY.md/USER.md evergreen), MMR diversity, keyword fallback when embeddings fail (explicit provider failure reports "unavailable"). Deterministic trigger recall adds ≤3 trusted entries per eligible turn without a model call; Active Memory (default `escalate`) runs a blocking recall sub-agent only for recall-intent turns with no strong hit. Embeddings: OpenAI default, Gemini, Voyage, Mistral, Bedrock, DeepInfra, local GGUF, Ollama, LM Studio, Copilot, OpenAI-compatible. — _Evidence:_ `oc/docs/concepts/memory-search.md`; `oc/docs/concepts/active-memory/how-it-works.md`

</details>
<details><summary><b>MEM-07</b> Memory tools exposed to the model (search / get / write)</summary>

- **sugar-crush** ✅ — Writes route through `MemoryWriter`, so a project note goes to the repo store when the tree can host one (it creates the dir), otherwise the home store. Recall searches every scope, agent-scope imports included. — _Evidence:_ `src/Tools/BuiltIn/MemoryTool.php:42`, `:232`; `src/Memory/MemoryWriter.php:87-114`, `:186`
- **sugar-crush-web** 🟡 — `server.info` tools include Memory and Recall. — _Evidence:_ live `server.info`
- **Claude Code 2.1.301** 🟡 — The API-level memory tool is a separate Claude API feature, not used by CC. Subagent `memory` auto-enables Read/Write/Edit for that agent. — _Evidence:_ cc:memory; cc:sub-agents#enable-persistent-memory; CL v2.1.283 (auto-memory edits no longer blocked as sensitive writes)
- **OpenCode 1.18.35** 🔌 — Six plugin tools. Visible to the user as ordinary tool rows. — _Evidence:_ opencode-agent-memory README (Usage)
- **OpenCode 2.0.31** 🔌 — Six plugin tools. Visible to the user as ordinary tool rows. — _Evidence:_ opencode-agent-memory README (Usage)
- **OpenClaw 2026.9.8** ✅ — `memory_get` excerpt cap 12,000 chars, 120-line default window, continuation notice; prompt's Memory Recall section makes searching mandatory before answering about prior work/people/dates and asks for `Source: path#line` citations. memory-wiki adds wiki_status/search/get/apply/lint. — _Evidence:_ `oc/docs/concepts/memory.md` (Memory tools); `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`

</details>
<details><summary><b>MEM-08</b> Consolidation / dreaming / dedup / pruning</summary>

- **sugar-crush** ✅ — `DreamPass::call()` runs at turn settle on the session engine with tools swapped to `DreamMemoryView` plus Read, 12 steps, the same JSON ops and caps as auto-memory, and one git commit per pass. It can draft up to 3 skills in `~/.sugar-crush/skills-proposed/` (opt-in). Side effect: it refreshes the main session's prompt memo (ISSUES). — _Evidence:_ `src/Memory/DreamPass.php:293-395`; `src/Chat.php:2484-2488`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Host/Commands/SkillsHostCommand.php`
- **Claude Code 2.1.301** 🟡 — `/doctor prompt-audit` audits instruction files, not memory; 🔌 `claude-md-management` ("capture session learnings"), 🔌 `remember`. — _Evidence:_ cc:memory#how-it-works; local: claude-plugins-official catalog
- **OpenCode 1.18.35** 🔌 — No native consolidation. Magic Context has a background historian and an “overnight dreamer” for memory maintenance. — _Evidence:_ awesome-opencode (Magic Context, Harness Memory)
- **OpenCode 2.0.31** ❓ — 1.18.35 (🔌): magic-context 'overnight dreamer', harness-memory gated pipeline; nothing native. 2.0.31 (❓): No native consolidation; magic-context/harness-memory v2 compatibility unverified — _Evidence:_ npm metadata (not found/unverified)
- **OpenClaw 2026.9.8** ✅ — Cron-managed sweep; candidates must pass score, recall-frequency and query-diversity gates; untrusted/system provenance never enters consolidation; append-only fallback; `openclaw memory promote/promote-explain/forget/rem-backfill/session-backfill`. — _Evidence:_ `oc/docs/concepts/dreaming.md`; `oc/docs/concepts/memory.md`; CLI: `openclaw memory --help`

</details>
<details><summary><b>MEM-09</b> User UI to view/edit memory (/memory, panel)</summary>

- **sugar-crush** 🟡 — Host command, so it also works over `serve` (web has a MemoryPanel). `add` always writes `type: pattern` with no tags. `edit` replaces the whole content inline. — _Evidence:_ `src/Host/Commands/MemoryCommand.php`; `builtin-commands/2400-memory.php`
- **sugar-crush-web** ✅ — Covered by e2e "the memory panel adds, finds and deletes a note". — _Evidence:_ `src-web/components/panels/MemoryPanel.vue`; `e2e/panels.spec.ts:24`
- **Claude Code 2.1.301** ✅ — Left/right arrows flip toggles; files open in $EDITOR/GUI editor. — _Evidence:_ cc:memory#view-and-edit-with-/memory; CL v2.1.274 (VS Code Memory/Instructions entries); CL v2.1.287 (arrow toggles)
- **OpenCode 1.18.35** ❌ — No `/memory` command or panel. — _Evidence:_ DOCS/tui (commands list)
- **OpenCode 2.0.31** ❌ — No `/memory` command or panel. — _Evidence:_ DOCS/tui (commands list)
- **OpenClaw 2026.9.8** ✅ — CLI: status (with --deep/--fix), search, index, forget, promote, reset, rem-harness. — _Evidence:_ `oc/docs/concepts/memory.md`; `oc/docs/web/control-ui/settings.md` (Import assistant memory)

</details>
<details><summary><b>MEM-10</b> User is told when memory is saved or recalled</summary>

- **sugar-crush** 🟡 — Recalled notes ride a hidden `<turn-context>` row. The pre-compaction memory flush writes notes with no notice at all (ISSUES). — _Evidence:_ `src/Chat.php:2533-2570`; `src/Context/Compaction/MemoryFlush.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Filenames highlight on hover and open on click. — _Evidence:_ cc:memory#how-it-works; CL v2.1.86 (memory filenames clickable)
- **OpenCode 1.18.35** 🔌 — No native notification. Plugin tool calls render like any tool. — _Evidence:_ TUI/routes/session/index.tsx (generic tool rendering)
- **OpenCode 2.0.31** 🔌 — No native notification. Plugin tool calls render like any tool. — _Evidence:_ TUI/routes/session/index.tsx (generic tool rendering)
- **OpenClaw 2026.9.8** 🟡 — `/verbose on` appends "🧩 Active Memory: status=ok elapsed=… query=…", `/trace on` a debug summary; memory writes show as normal write/edit tool cards; flush exhaustion surfaces a "degraded" notice only with `compaction.notifyUser`. — _Evidence:_ `oc/docs/concepts/active-memory/session-controls.md`; `oc/docs/concepts/compaction.md`

</details>
<details><summary><b>MEM-11</b> Daily logs / journal files</summary>

- **sugar-crush** 🟡 — `~/.sugar-crush/memory/.compaction-journal-<key>.jsonl`, secrets redacted. Only the dream pass reads it. Unbounded growth (ISSUES). — _Evidence:_ `src/Memory/CompactionJournal.php:82-179`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ local: claude-plugins-official marketplace.json (`remember`)
- **OpenCode 1.18.35** 🔌 — `journal_write` with title, body and tags; searchable later. — _Evidence:_ opencode-agent-memory README
- **OpenCode 2.0.31** 🔌 — `journal_write` with title, body and tags; searchable later. — _Evidence:_ opencode-agent-memory README
- **OpenClaw 2026.9.8** ✅ — Indexed for search, not injected on normal turns; 30-day decay in ranking; session-memory hook writes `YYYY-MM-DD-<slug>.md`. — _Evidence:_ `oc/docs/concepts/memory.md`

</details>
<details><summary><b>MEM-12</b> Session-summary memory (past sessions searchable)</summary>

- **sugar-crush** 🟡 — The journal has no tool or command. Cross-session search lives in sessions features, outside this area. — _Evidence:_ `src/Memory/CompactionJournal.php`; `src/Memory/DreamPass.php:434`
- **sugar-crush-web** ❌ — — — _Evidence:_ `src-web/components/SessionSidebar.vue:24-28`
- **Claude Code 2.1.301** 🟡 — Background summarization feeds `--resume`; `/insights` analyzes up to 200 recent sessions; 🔌 `remember`, `session-report`. — _Evidence:_ cc:sessions; cc:costs#background-token-usage; cc:interactive-mode#session-recap
- **OpenCode 1.18.35** 🟡 — Session list search is a SQL `LIKE` on title. There is no tool for the model to search past sessions. — _Evidence:_ OC/session/session.ts:561,991-992; TUI/component/dialog-session-list.tsx:24-92
- **OpenCode 2.0.31** 🟡 — Session list search is a SQL `LIKE` on title. There is no tool for the model to search past sessions. — _Evidence:_ OC/session/session.ts:561,991-992; TUI/component/dialog-session-list.tsx:24-92
- **OpenClaw 2026.9.8** ✅ — memory_search can return `sessions/...jsonl` hits; follow with sessions_search then sessions_history (bounded, redacted); `openclaw memory session-backfill` distills retained history into staged candidates. — _Evidence:_ `oc/docs/concepts/memory.md`; `oc/docs/concepts/session-search.md`; CLI: `openclaw memory --help`

</details>
<details><summary><b>MEM-13</b> Subagent-specific memory</summary>

- **sugar-crush** ❌ — Sub-agents see the session's `<project-memory>` index and recall like the parent. — _Evidence:_ `src/Support/FrontmatterKeyAudit.php:90-95`; `docs/MEMORY.md:258-263`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/stores/agents/memory.ts:7`
- **Claude Code 2.1.301** ✅ — Dirs `~/.claude/agent-memory/<agent>/`, `.claude/agent-memory/<agent>/`, `.claude/agent-memory-local/<agent>/`; main auto memory is never passed to non-fork subagents. — _Evidence:_ cc:sub-agents#enable-persistent-memory
- **OpenCode 1.18.35** ❌ — The agent schema has no memory option. — _Evidence:_ OC/agent/agent.ts:270-290
- **OpenCode 2.0.31** ❌ — The agent schema has no memory option. — _Evidence:_ OC/agent/agent.ts:270-290
- **OpenClaw 2026.9.8** 🟡 — Sub-agents use promptMode `minimal` (no Memory Recall, only AGENTS.md); Active Memory itself is a recall sub-agent; no per-subagent memory field like CC's agent `memory:`. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Prompt modes); `oc/docs/concepts/multi-agent.md`

</details>
<details><summary><b>MEM-14</b> Memory import/export / cross-tool memory</summary>

- **sugar-crush** 🟡 — `ForeignMemoryImporter` reads `~/.claude/projects/<slug>/memory` (honours `CLAUDE_CONFIG_DIR`) and `.opencode/memory`. Imports are reachable only via Memory tool `recall` and `/memory search`. — _Evidence:_ `src/Memory/ForeignMemoryImporter.php`; `src/Host/Commands/MemoryCommand.php:import`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — Export is just the plain files; `anthropic-skills:import-memory` ("import a memory export from another AI assistant") observed in this session's skill list (synced from claude.ai). — _Evidence:_ cc:memory#migrate-instructions-from-other-tools; obs
- **OpenCode 1.18.35** 🟡 — CLAUDE.md fallback (INS-01) is the only Claude Code memory compatibility. Claude Code's auto-memory directory is not read. — _Evidence:_ OC/session/instruction.ts:60-68
- **OpenCode 2.0.31** 🟡 — CLAUDE.md fallback (INS-01) is the only Claude Code memory compatibility. Claude Code's auto-memory directory is not read. — _Evidence:_ OC/session/instruction.ts:60-68
- **OpenClaw 2026.9.8** ✅ — Imports land in memory/imports/{codex,claude-code,hermes}/, indexed but not merged into MEMORY.md; preview, conflict marking, verified backup. — _Evidence:_ `oc/docs/concepts/memory.md` (Import from coding assistants); `oc/dist/memory-C_gkkH5A.mjs`

</details>
<details><summary><b>MEM-15</b> Pre-compaction memory flush (save durable facts before compaction)</summary>

- **sugar-crush** ✅ — `MemoryFlush`: other tools refused, asks answered no, once per compaction cycle (counted on the `ContextLedger`), skipped past the spend cap or on a PreCompact deny. — _Evidence:_ `src/Backend/EngineBackend.php:2008-2035`, `:4447`; `src/Context/Compaction/MemoryFlush.php:53`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ❌ — Compaction re-injects MEMORY.md from disk afterwards; nothing prompts Claude to save first. — _Evidence:_ cc:hooks#precompact; cc:context-window#what-survives-compaction
- **OpenCode 1.18.35** 🔌 — Core has no pre-compaction flush. The hook can append `context[]` or replace the whole compaction prompt. System-prompt memory blocks survive compaction by construction. — _Evidence:_ PLUG/index.ts:301-315; DOCS/plugins (Compaction hooks)
- **OpenCode 2.0.31** 🔌 — 1.18.35 (🔌): Hook experimental.session.compacting lets plugins inject durable context; agent-memory blocks survive compaction. 2.0.31 (🔌): The v2 compaction prompt tells the summarizer to leave out instruction files and environment details because “the next agent receives current versions of all of these separately”. — _Evidence:_ V2D/build/plugins/index (Model requests); V2C/session/compaction.ts:147
- **OpenClaw 2026.9.8** ✅ — Fires at a soft threshold before compaction (or on a large transcript); writes only to `memory/<today>.md` append-only, bootstrap files read-only; its messages never enter later turns; skipped for read-only sandboxes and incognito; `compaction.memoryFlush.enabled/model`. — _Evidence:_ `oc/docs/concepts/memory.md` (Automatic memory flush); `oc/docs/concepts/compaction.md`

</details>
<details><summary><b>MEM-N1</b> 🆕 Versioned memory (memory dir under git; log / diff / undo of memory changes)</summary>

- **sugar-crush** ✅ — Pinned to its own `.git` with hooks, signing and pager off. Search cache, throttles and journal are gitignored. — _Evidence:_ `src/Memory/MemoryHistory.php`; `docs/MEMORY.md:194-222`
- **sugar-crush-web** 🟡 — `/memory` is a server-run built-in (`command.exec`), so `log` and `restore` work on the home memory git repo from the composer; the output appears as a text row after the re-snapshot. The Memory panel calls `memory.add/edit/delete`, which write the store directly with no MemoryHistory commit. Such a change is only committed, under the generic "outside" subject, the next time a mutating `/memory` command runs. The browser has no log, diff or undo UI. — _Evidence:_ `src/Host/Commands/MemoryCommand.php:51-79`; `src/Protocol/Methods/MemoryMethods.php:58-91`; `sugar-crush-web/src-web/stores/agents/memory.ts`
- **Claude Code 2.1.301** ❌ — You can put the memory dir under your own git. Checkpoints cover files Claude's edit tools change in a session, but nothing documents history for the memory directory. — _Evidence:_ cc:memory#storage-location; cc:checkpointing
- **OpenCode 1.18.35** ❌ — opencode-agent-memory stores plain markdown blocks with no git history or undo. — _Evidence:_ OC/ (no memory subsystem); opencode-agent-memory README
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2C/ (no memory subsystem)
- **OpenClaw 2026.9.8** 🟡 — New workspaces are `git init`ed and Doctor nags when the workspace isn't under git, but commits are manual. Dreaming retains rewrite preimages and consolidation-history excerpts with provenance lineage; `memory forget` deletes by session lineage; `rem-backfill`/`session-backfill --rollback` undo staged backfills only. No `memory log/diff/undo` surface. — _Evidence:_ `oc/docs/concepts/agent-workspace.md` (Git backup); `oc/docs/concepts/memory-provenance.md`; `oc/docs/concepts/dreaming.md`; `oc/docs/gateway/doctor/workspace-and-dreams.md`

</details>
<details><summary><b>MEM-N2</b> 🆕 Repo-shareable project memory (project notes committed with the repo by default)</summary>

- **sugar-crush** ✅ — Labelled apart in the prompt. Labelling bug: the user's own notes are called "not written by the user" (ISSUES). — _Evidence:_ `src/Context/ProjectMemoryWriter.php:119`; `src/Context/MemoryBlock.php:264`
- **sugar-crush-web** 🟡 — Inherited: the model's Memory `save` and a typed `/memory add` go through MemoryWriter → ProjectMemoryWriter (repo-local and git-visible), and those notes reach the model's project-memory section. But `memory.list/search/add` work on `WorkspaceContext::$memoryStore`, which is `Bootstrap::memoryStore($root)`: `~/.sugar-crush/memory` with the project scope keyed under `project/<key>/`. So repo-shared notes never appear in the web Memory panel (list or search), and a note added on its Project tab stays private in HOME, never shared with the repo. — _Evidence:_ `src/Protocol/Methods/MemoryMethods.php:41-67`, `:93-97`; `src/Cli/Bootstrap.php:1974`, `:10309-10323`, `:5396`; `src/Host/Commands/MemoryCommand.php:155-160`; `src/Context/ProjectMemoryWriter.php:107-140`
- **Claude Code 2.1.301** 🟡 — Subagent project memory lives in `.claude/agent-memory/<agent>/` ("shareable via version control"); auto memory in `~/.claude/projects/<project>/memory/` is never committed or synced across machines. — _Evidence:_ cc:sub-agents#enable-persistent-memory; cc:memory#storage-location
- **OpenCode 1.18.35** 🔌 — Native project-shared guidance is AGENTS.md, which is instructions rather than memory. The agent-memory 0.2 project blocks sit under the repo's .opencode/memory/. 0.3.0 (current @latest) requires OpenCode v2.0.12+, so v1 must pin 0.2.x. — _Evidence:_ LOCAL: /home/sites/sugarcraft/.opencode/memory (untracked: `git ls-files` = 0); npm view opencode-agent-memory
- **OpenCode 2.0.31** 🔌 — Native shared guidance is AGENTS.md (instructions). I did not verify the 0.3 storage paths; its journal is at ~/.config/opencode/journal. — _Evidence:_ npm view opencode-agent-memory (README: Requirements, Journal)
- **OpenClaw 2026.9.8** ❌ — Docs: "Keep it private and treat it as memory"; the execution folder only contributes its AGENTS.md (instructions, not memory). Sharing happens via workspace git remotes, memory-wiki vaults or Session Share, not repo-committed notes. — _Evidence:_ `oc/docs/concepts/agent-workspace.md`; `oc/docs/concepts/system-prompt.md` (Workspace bootstrap injection)

</details>
<details><summary><b>MEM-N3</b> 🆕 Standing intents (event-conditioned instructions stored and checked before acting)</summary>

- **sugar-crush** ❌ — Neither the model nor the user can record "when X happens, do Y" and have it checked before the agent acts. Memory notes are recalled by relevance (the memory-recall block plus the Memory tool's `recall`), not by an event condition. Rule files with `paths:` frontmatter are the only condition-triggered instructions. RulePathNudge delivers a matching rule (or a pointer to it) beside the result of a Read, Grep, Glob, Edit or Write that touches the path. These are user-authored files, the condition can only be a path, and the agent cannot add them as prospective memory. Hooks are event-conditioned, but they are scripts, not instructions to the model. — _Evidence:_ `src/Context/RulePathNudge.php:9-40`; `src/Context/Rule.php` (PathTrigger); `src/Tools/BuiltIn/MemoryTool.php`; no intent tool in `src/Tools/BuiltIn/`
- **sugar-crush-web** ❌ — As SC. The web has no rules view; `/rules` (server-run) prints rule packs as text. — _Evidence:_ `src/Host/Commands/RulesHostCommand.php`; `src/Context/RulePathNudge.php`
- **Claude Code 2.1.301** ❌ — Nearest built-ins: hooks with matchers and `if` rules (deterministic, scripted), path-scoped rules, `/goal` completion conditions. — _Evidence:_ cc:hooks; cc:memory#path-specific-rules; cc:goal
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2C/
- **OpenClaw 2026.9.8** ✅ — Prospective memory ("when Alice mentions the launch, ask about rollback") kept in the agent SQLite DB; owner-only creation from an authenticated channel sender; inspect/cancel from Control UI or CLI; time-based reminders stay in automations. — _Evidence:_ `oc/docs/concepts/standing-intents.md`; `oc/docs/concepts/memory.md` (Memory tools)

</details>

### PRM — System prompt & per-request context assembly

<details><summary><b>PRM-01</b> Base system prompt (provider/model-specific variants)</summary>

- **sugar-crush** ✅ — Single base by design. The family is resolved once per session from the served model. No per-provider base variants. — _Evidence:_ `src/Runtime.php:5217-5398`; `src/Context/Sections/FamilyPrompt.php`
- **sugar-crush-web** 🟡 — `/context` gives its size and section list. — _Evidence:_ live `/context`
- **Claude Code 2.1.301** ✅ — `--system-prompt-snapshot on` (default) renders the prompt on the first request and reuses it verbatim on every later request and resume until compaction; model- and surface-gated tools change what's included (task tools only on older models, EndConversation only interactive on new models). Subagents get only their own prompt plus env details, not the CC prompt. — _Evidence:_ help (`--system-prompt-snapshot`); cc:agent-sdk/modifying-system-prompts; cc:sub-agents#what-loads-at-startup
- **OpenCode 1.18.35** ✅ — Selected by `model.api.id` substring. Unknown families (DeepSeek, Qwen, GLM, MiniMax, so this host's SGLang models) get `default.txt`. An agent `prompt` replaces the base prompt entirely. — _Evidence:_ OC/session/system.ts:28-51; OC/session/prompt/*.txt
- **OpenCode 2.0.31** 🟡 — 1.18.35 had full family prompts (anthropic, beast, gpt, codex, gemini, kimi, trinity, meta, default). 2.0.31: `runner/prompt/system.txt` (15 lines: harness, `<system-reminder>` semantics, parallel tools, tool guidance) for everyone; OptimizePlugin overrides for GPT/astra/kimi/trinity/meta and appends anthropic.txt for Claude. Gemini/codex/beast/default prompts are gone; DeepSeek/Qwen (this host) get only the generic prompt. — _Evidence:_ V2C/session/runner/prompt/system.txt; V2C/session/system-prompt.ts; V2C/plugin/optimize.ts:15-66
- **OpenClaw 2026.9.8** ✅ — Sections: Tooling, Execution Bias, Promised Work, Care, Runtime Context, Skills, OpenClaw Control, Messaging, Workspace, Documentation, Workspace Files, Sandbox, Temporal Context, Output Directives, UI Presentation, Collapsible Details, Runtime, Reasoning. Provider contributions replace `interaction_style`/`tool_call_style`/`execution_bias` or add stable prefix / dynamic suffix (GPT-5 behaviour contract). Prompt modes full/minimal/none; committed prompt snapshots with CI drift check. — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>PRM-02</b> Environment block (cwd, OS, platform, date, shell)</summary>

- **sugar-crush** ✅ — Static half only, memoised per session and keyed by root and model. — _Evidence:_ `src/Context/EnvironmentBlock.php:1023-1059`; `src/Runtime.php:5500`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `/context`
- **Claude Code 2.1.301** ✅ — Observed in this session's system prompt. `--exclude-dynamic-system-prompt-sections` moves the per-machine parts (cwd, env info, memory paths, git status) into the first user message for cross-user cache reuse. — _Evidence:_ obs; help; cc:how-claude-code-works
- **OpenCode 1.18.35** ✅ — Lines: “You are powered by the model named X. The exact model ID is P/X”, then `<env>` with Working directory, Workspace root folder, Is directory a git repo, Platform, and Today's date (`toDateString`). The shell tool description adds OS, shell and a pre-approved tmp dir. — _Evidence:_ OC/session/system.ts:69-85; OC/tool/shell/shell.txt:1-7
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): <env>: model id, cwd, worktree root, is-git, platform, date; bash tool description adds OS, shell, tmp dir. 2.0.31 (✅): Built-in date + <env> (cwd, root, git, platform, OpenCode tmp dir) as instruction sources with change deltas — _Evidence:_ V2C/instructions/builtins.ts:20-55
- **OpenClaw 2026.9.8** ✅ — Temporal Context sits below the cache boundary; exact time via `session_status`; `agents.defaults.userTimezone`; Workspace/cwd roles stated when they differ. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Structure, Time handling)

</details>
<details><summary><b>PRM-03</b> Git status / branch / recent commits in prompt</summary>

- **sugar-crush** ✅ — Re-polled on a turn's first step and after any write step, with diffs capped (`env.diffMaxBytes`). Sent only when the bytes change. — _Evidence:_ `src/Runtime.php:5545`; `src/Backend/EngineBackend.php:1700-1716`; `src/Context/TurnContextBlock.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `includeGitInstructions:false` / `CLAUDE_CODE_DISABLE_GIT_INSTRUCTIONS=1` removes it together with built-in commit/PR instructions; Explore/Plan skip it; each other subagent gets its own snapshot (its worktree's branch when isolated). — _Evidence:_ obs; cc:prompt-caching#cache-scope; cc:sub-agents#what-loads-at-startup
- **OpenCode 1.18.35** ❌ — Deliberately static for cache stability. The model must run git itself. — _Evidence:_ OC/session/system.ts:76-85
- **OpenCode 2.0.31** ❌ — Deliberately static for cache stability. The model must run git itself. — _Evidence:_ OC/session/system.ts:76-85
- **OpenClaw 2026.9.8** 🟡 — `agents.defaults.repoRoot` or auto-detected by walking up from the workspace; Execution Bias tells the model to "live-check files/git/time..." instead. Git facts are shown to the user in the Control UI, not to the model. — _Evidence:_ `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (repoRoot); `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>PRM-04</b> Directory tree / repo map in prompt</summary>

- **sugar-crush** ✅ — Both per-session. `repoMap.enabled` and `repoMap.maxBytes` settings. The symbol map is primed in the parent before the fork. — _Evidence:_ `src/Context/RepoMapBlock.php`; `src/Context/SymbolMapBlock.php`; `src/Runtime.php:5774-5836`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — `/doctor` even proposes removing directory layouts from CLAUDE.md as derivable from the codebase. — _Evidence:_ cc:memory#my-claude-md-is-too-large; cc:how-claude-code-works
- **OpenCode 1.18.35** ❌ — No file tree is emitted. — _Evidence:_ OC/session/system.ts
- **OpenCode 2.0.31** ❌ — No file tree is emitted. — _Evidence:_ OC/session/system.ts
- **OpenClaw 2026.9.8** ❌ — Model explores with `ls` / `exec`. — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>PRM-05</b> Tool schemas & descriptions (dynamic per agent/mode)</summary>

- **sugar-crush** ✅ — Same tool list in every permission mode (plan mode is enforced by the gate, kept stable for the cache). `disabledTools` and `allowedTools`. MCP tools appended. — _Evidence:_ `src/Runtime.php:4770`; `src/Backend/EngineBackend.php:3130`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `/context` ("Tool schemas: ~13.3K (29 tools)")
- **Claude Code 2.1.301** ✅ — `--tools`, `--allowedTools`, `--disallowedTools`; subagent `tools`/`disallowedTools`; background subagents get a reduced built-in set; Glob/Grep absent by default on Unix; a bare-tool deny rule changes the list (cache bust) but scoped rules don't. — _Evidence:_ cc:tools-reference; cc:mcp#scale-with-mcp-tool-search; cc:prompt-caching#denying-an-entire-tool
- **OpenCode 1.18.35** ✅ — `ToolRegistry.tools()` filters websearch by provider/flag and swaps patch vs edit/write for GPT. `Permission.disabled` removes any tool whose last matching rule is `'*'` deny. Plugin `tool.definition` can rewrite descriptions and schemas. Tools are sorted by name. Experimental code mode adds `execute`. — _Evidence:_ OC/tool/registry.ts:292-340; OC/permission/index.ts:204-219; OC/session/llm/request.ts:184
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Per-agent tool list: denied tools removed, gpt→apply_patch swap, task desc lists allowed subagents, tool.definition hook. 2.0.31 (✅): MCP servers default to `codemode: true`: their tools appear as one-line signatures in the `execute` tool's namespace catalog under a token budget rather than as separate tool schemas. Denying `execute` removes Code Mode and tools reached only through it. — _Evidence:_ V2D/tools (Execute, Extensions); V2D/mcp-servers (Permissions); V2C/codemode/catalog.ts:60-130
- **OpenClaw 2026.9.8** ✅ — "If policy removes a tool, the model does not receive that tool's schema for the turn." With the default (experimental) Tool Search, core coding primitives and direct-only tools keep full schemas while other OpenClaw, plugin, MCP and client tools appear in a bounded capability directory and are hydrated via `tool_describe`/`tool_call`. `/tools` shows the live set; Code Mode (opt-in) hides everything behind `exec`/`wait`. — _Evidence:_ `oc/docs/tools/index.md`; `oc/docs/tools/tool-search.md`; `oc/docs/gateway/config-tools/tool-policy.md`

</details>
<details><summary><b>PRM-06</b> Skills listing in prompt (names + descriptions)</summary>

- **sugar-crush** ✅ — Enabled skill bodies go in full (5k / 25k-token budgets). The listing has no aggregate budget: 98 skills measured at 40 KB (ISSUES). — _Evidence:_ `src/Skills/SkillMatcher.php:86`; `src/Skills/SkillPromptLine.php:60`; `src/Runtime.php:4706-4712`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `/context`
- **Claude Code 2.1.301** ✅ — Observed: "The following skills are available for use with the Skill tool: - name: description"; more entries can arrive in later reminders. Overflow drops descriptions of least-used skills first; `skillOverrides` name-only/off; `disable-model-invocation` hides from the listing; `skillListingBudgetFraction`. — _Evidence:_ obs; cc:skills#skill-descriptions-are-cut-short; cc:skills#override-skill-visibility-from-settings
- **OpenCode 1.18.35** ✅ — `SystemPrompt.skills` adds “Use the skill tool to load a skill…” plus an XML list, filtered by the agent's `skill` permission. It is omitted if skill is denied. Skills without a description are not listed. — _Evidence:_ OC/session/system.ts:107-119; OC/skill/index.ts:321-345; OC/tool/skill.txt
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Skills listed twice: verbose <available_skills> (name, description, location) in system prompt + skill tool description. 2.0.31 (✅): Each step lists permitted skills (id, name, description); `opencode/autoinvoke:false` hides one; body via skill tool — _Evidence:_ V2D/skills (Loading, Frontmatter)
- **OpenClaw 2026.9.8** ✅ — Budget `skills.limits.maxSkillsPromptChars` (keeps names/locations first, then shortened descriptions, adds a note to run `openclaw skills check`); small-context models get shorter descriptions; Codex harness receives the list via parent-local request instructions. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Skills); `oc/docs/tools/skills.md` (Token impact)

</details>
<details><summary><b>PRM-07</b> Agents listing in prompt (subagent catalog)</summary>

- **sugar-crush** 🟡 — `TaskTool::promptGuidance()` and `rosterNames()`. An unknown name is refused with the roster listed. The model chooses agents by name alone. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:674-683`, `:2480`
- **sugar-crush-web** 🟡 — — — _Evidence:_ grep `src-web/`
- **Claude Code 2.1.301** ✅ — The Agent tool's own description just says agent types "are listed in <system-reminder> messages". Startup warning if custom agent descriptions exceed 15,000 tokens. Subagents get a sibling-roster reminder of named agents they can `SendMessage`. — _Evidence:_ obs; cc:agent-sdk/modifying-system-prompts#reminders-claude-code-adds-to-the-conversation; cc:sub-agents#what-loads-at-startup
- **OpenCode 1.18.35** ✅ — `describeTask` lists non-primary agents not denied by `task` permission, sorted. A denied subagent is “removed from the Task tool description entirely”. The catalog is **not** in the system prompt. — _Evidence:_ OC/tool/registry.ts:262-275; DOCS/agents (Task permissions)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Subagent catalog (name: description) appended to task tool description, filtered by the agent's task permission. 2.0.31 (✅): Tool renamed from `task` to `subagent`; permission action `subagent`. — _Evidence:_ V2C/tool/plugin/subagent.ts:169-180
- **OpenClaw 2026.9.8** 🟡 — `delegationMode` prefer (main session) adds a Delegation section about `sessions_spawn`; `agents_list` returns spawnable agents (denied to sub-agents); running children are listed in the Runtime Context carrier each turn. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/tools/subagents/tool-reference.md`

</details>
<details><summary><b>PRM-08</b> Mid-conversation system reminders (todo nudges, file-changed notices, mode notices)</summary>

- **sugar-crush** ✅ — All are appended at the tail or inside tool output, so the cached prefix stays stable. — _Evidence:_ `src/Backend/EngineBackend.php:1700-1760`; `src/Context/Pruning/NudgePolicy.php`; `src/Context/Sections/PlanModeSection.php`
- **sugar-crush-web** 🟡 — Rows with role `system` become notices. — _Evidence:_ `src-web/stores/reducer.ts:192-194`; live snapshot
- **Claude Code 2.1.301** ✅ — Always appended (never inserted) to keep the cache; `CLAUDE_CODE_DISABLE_ATTACHMENTS=1` turns off nudges, file notes and the skill list; post-compaction reminder lists still-running background work. — _Evidence:_ cc:agent-sdk/modifying-system-prompts#reminders-claude-code-adds-to-the-conversation; cc:prompt-caching#editing-files-in-your-repository; cc:context-window#what-survives-compaction
- **OpenCode 1.18.35** 🟡 — All are synthetic user-message text parts, except nested instructions (in the read result) and max-steps (an extra assistant-role message on the last step). Experimental plan mode uses a 5-phase plan-file workflow reminder. No todo reminders, no external file-change notices, no context-usage nudges. — _Evidence:_ OC/session/reminders.ts:15-89; OC/session/prompt.ts:1281,531 (compaction continue)
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Synthetic reminders: plan-mode, plan→build switch, nested AGENTS.md, max-steps, post-compaction continue; no todo/file nudges. 2.0.31 (✅): Plan reminders are reconciled per request near the tail (cache-friendly) and survive compaction/revert. No todo nudges (no todo tool). — _Evidence:_ V2C/plugin/plan.ts:5-60; specs/v2/session.md
- **OpenClaw 2026.9.8** ✅ — User-role messages delimited by `<<<BEGIN_OPENCLAW_INTERNAL_CONTEXT>>>`; each capability emits a current snapshot (incl. `none`); interpretation guidance lives once in the stable prompt. Also skipped-tool synthetic results, merged sub-agent completion events, promised-work self-check, heartbeat poll markers. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Runtime Context); `oc/docs/concepts/queue-steering.md`

</details>
<details><summary><b>PRM-09</b> Custom system prompt override / append (flags or config)</summary>

- **sugar-crush** 🟡 — PROMPT_ENGINEERING.md: "Do not add a user-supplied system-prompt override key." — _Evidence:_ `docs/PROMPT_ENGINEERING.md` ("do not do this" item 5)
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — SDK `systemPrompt` preset + append, `SYSTEM_PROMPT_DYNAMIC_BOUNDARY`; resumed conversations keep their original prompt by default. — _Evidence:_ help; cc:cli-reference; cc:agent-sdk/modifying-system-prompts
- **OpenCode 1.18.35** 🟡 — No `--system-prompt` or `--append-system-prompt`. Options: set `agent.build.prompt` (replaces the base), the SDK/HTTP `system` field per message (appended), or a plugin with `experimental.chat.system.transform`. — _Evidence:_ OC/session/llm/request.ts:58-73; DOCS/server (POST /session/:id/message body `system`)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Agent `prompt` replaces base prompt; API per-message `system`; system.transform plugin hook; no CLI flag. 2.0.31 (🟡): Agent `system` replaces base prompt; API session instruction entries; `context` hook edits system; no CLI flag — _Evidence:_ V2D/agents (System); V2D/instructions (Ordering item 6); V2D/build/plugins/index (Model requests)
- **OpenClaw 2026.9.8** 🟡 — Provider plugins contribute sections; plugin hook can add context or narrow the turn's tools (needs allowPromptInjection); `promptMode` is runtime-only. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/plugins/hooks.md`

</details>
<details><summary><b>PRM-10</b> Output styles / personas</summary>

- **sugar-crush** 🟡 — `~/.sugar-crush/rulebooks/*.md`. — _Evidence:_ `src/Context/RuleLoader.php:410`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `/output-style` (v2.1.269+) or `/config`; mid-session switch delivered as a conversation message (cache-safe, v2.1.251+); not applied to non-fork subagents. — _Evidence:_ cc:output-styles; cc:prompt-caching#changing-output-style
- **OpenCode 1.18.35** ❌ — No output-style concept. Users create agents (e.g. this host's `build` orchestrator prompt). — _Evidence:_ DOCS/agents
- **OpenCode 2.0.31** ❌ — No output-style concept. Users create agents (e.g. this host's `build` orchestrator prompt). — _Evidence:_ DOCS/agents
- **OpenClaw 2026.9.8** ✅ — SOUL.md personality guide; per-agent identities (name/emoji/avatar/theme); TTS personas. — _Evidence:_ `oc/docs/concepts/soul.md`; `oc/docs/plugins/bundles.md`; `oc/docs/tools/tts/personas.md`

</details>
<details><summary><b>PRM-11</b> Per-agent prompt</summary>

- **sugar-crush** ✅ — Task: `SystemMessage($preset prompt)` plus the engine's assembled prompt (CLAUDE.md, memory and the rest inherited). Workflow stages: `Agent::systemPrompt()`. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:1348`; `src/Agents/Agent.php:955`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `--agent <name>` makes the main thread use it; `initialPrompt` auto-submits a first turn. — _Evidence:_ cc:sub-agents#write-subagent-files
- **OpenCode 1.18.35** ✅ — `{file:./prompts/x.txt}` resolves relative to the config file. Markdown agents use their body as the prompt. Explore, compaction, title and summary ship their own prompts. — _Evidence:_ OC/session/llm/request.ts:60; OC/agent/agent.ts:197-258; DOCS/agents (Prompt)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Agent `prompt` (inline or {file:...}) replaces the model-family base prompt for that agent. 2.0.31 (✅): Agent `system` (Markdown body) replaces provider prompt; instructions/skills/references still added — _Evidence:_ V2D/agents (System)
- **OpenClaw 2026.9.8** ✅ — Each configured agent brings its own AGENTS/SOUL/IDENTITY/USER, skills allowlist and tools; role templates ship CLAW.md + workspace AGENTS.md. — _Evidence:_ `oc/docs/concepts/multi-agent.md`; `oc/docs/reference/templates/roles/`

</details>
<details><summary><b>PRM-12</b> Context-usage breakdown view (/context-style)</summary>

- **sugar-crush** ✅ — Local, calls no model. Server host command. Omits the tool-guidance and plan-mode layers (ISSUES). — _Evidence:_ `src/Commands/ContextCommand.php`; `src/Host/Commands/ContextHostCommand.php:33`
- **sugar-crush-web** 🟡 — Text only, no panel; arrives after the web re-snapshots. — _Evidence:_ live `command.exec name=context`
- **Claude Code 2.1.301** ✅ — Over-limit warning names the command that frees space; `/usage` warns about always-on context and large tool results (v2.1.299); `/doctor` estimates skill-listing cost. — _Evidence:_ cc:commands; cc:context-window#check-your-own-session; CL v2.1.299
- **OpenCode 1.18.35** 🔌 — No category breakdown of system, tools or messages. Plugins: IgorWarzocha Opencode-Context-Analysis-Plugin; DCP `/dcp context`. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx:17-44; awesome-opencode (Context Analysis)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🔌): Sidebar shows total tokens/% only; breakdown via plugins (Context Analysis plugin, DCP /dcp context). 2.0.31 (🟡): V1 plugins (Context Analysis, DCP) are v1-API only. — _Evidence:_ V2T/feature-plugins/sidebar/context.tsx:14-37; V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Reports system prompt (run vs estimate), Project Context, per-file, skills, tool list + schema JSON overhead, session tokens. — _Evidence:_ `oc/docs/concepts/context.md`

</details>
<details><summary><b>PRM-13</b> IDE/editor context injection (open file, selection, diagnostics)</summary>

- **sugar-crush** 🟡 — `promptCapabilities.embeddedContext: true`. — _Evidence:_ `src/Acp/AcpServer.php:281`, `:640-660`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Transcript shows `⧉ Selected N lines from <file>`; Read deny rules block it; `mcp__ide__executeCode` for Jupyter (always asks); `--ide` auto-connect. — _Evidence:_ cc:vs-code#the-built-in-ide-mcp-server; cc:jetbrains
- **OpenCode 1.18.35** ✅ — The TUI connects to `ws://127.0.0.1:$CLAUDE_CODE_SSE_PORT` (or `OPENCODE_EDITOR_SSE_PORT`, or a discovered lock file), speaking MCP protocol 2025-11-25, so Claude-Code IDE plugins work. In Zed terminals it reads the selection from Zed's SQLite DB. A label (`file.ts:L10-20`) shows in the prompt and is sent once with the next message; it can be dismissed or toggled (app_toggle_file_context). The VS Code extension adds `@File#L37-42` insertion. I saw no IDE diagnostics injection. — _Evidence:_ TUI/context/editor.ts:9,112-121,362-372; TUI/editor-zed.ts; TUI/component/prompt/index.tsx:178-205; DOCS/ide
- **OpenCode 2.0.31** ✅ — The TUI connects to `ws://127.0.0.1:$CLAUDE_CODE_SSE_PORT` (or `OPENCODE_EDITOR_SSE_PORT`, or a discovered lock file), speaking MCP protocol 2025-11-25, so Claude-Code IDE plugins work. In Zed terminals it reads the selection from Zed's SQLite DB. A label (`file.ts:L10-20`) shows in the prompt and is sent once with the next message; it can be dismissed or toggled (app_toggle_file_context). The VS Code extension adds `@File#L37-42` insertion. I saw no IDE diagnostics injection. — _Evidence:_ TUI/context/editor.ts:9,112-121,362-372; TUI/editor-zed.ts; TUI/component/prompt/index.tsx:178-205; DOCS/ide
- **OpenClaw 2026.9.8** ❌ — ACP bridge only flattens embedded resources/images in prompts into chat input. — _Evidence:_ `oc/docs/cli/acp.md` (Compatibility matrix)

</details>
<details><summary><b>PRM-14</b> Date/time awareness & knowledge-cutoff note</summary>

- **sugar-crush** 🟡 — — — _Evidence:_ `src/Context/EnvironmentBlock.php:1059`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Observed: "Today's date is 2026-10-16" and "Assistant knowledge cutoff is June 2026". — _Evidence:_ obs
- **OpenCode 1.18.35** 🟡 — Day-granular for cache stability. Date rollover changes the prefix on the next step, because the env block is rebuilt per step. — _Evidence:_ OC/session/system.ts:83
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): 'Today's date' (day granularity) in env block; no knowledge-cutoff note; no clock time. 2.0.31 (🟡): Date (day granularity) is an instruction source; rollovers inject 'Today's date is now:' — _Evidence:_ V2C/instructions/builtins.ts:22-29
- **OpenClaw 2026.9.8** ✅ — No knowledge-cutoff note documented. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Time handling); `oc/docs/concepts/timezone.md`

</details>
<details><summary><b>PRM-15</b> Prompt debugging (view raw request / dump system prompt)</summary>

- **sugar-crush** 🟡 — No `--debug` request log, no "show system prompt" command. — _Evidence:_ `src/Runtime.php:4253`; `tests/Prompt/PromptSnapshotDriftTest.php`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — SDK message streams omit reminders, so raw-body logging or a logging gateway is the documented way to see exactly what Claude received. — _Evidence:_ cc:agent-sdk/modifying-system-prompts#see-what-claude-received; cc:monitoring-usage
- **OpenCode 1.18.35** 🟡 — `--log-level DEBUG --print-logs`. `opencode debug agent <name>` shows agent config. `GET /experimental/tool?provider&model` lists tool JSON schemas. AI-SDK OpenTelemetry spans are available with `experimental.openTelemetry`. Nothing prints the final system prompt. — _Evidence:_ CLI: `opencode debug --help`; DOCS/server (Tools Experimental); CORE/v1/config/config.ts:173-175
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): No raw-request/system-prompt dump; debug logs, `opencode debug agent`, /experimental/tool schemas, OTLP spans. 2.0.31 (🟡): `opencode api` hits any server operation; `debug agents/config/paths`; still no system-prompt dump — _Evidence:_ V2CLI: `opencode api --help`, `opencode debug --help`
- **OpenClaw 2026.9.8** ✅ — Trajectory JSONL contains prompt, system prompt and tools sent; debug proxy records transport traffic with query presets; cache-trace/raw-stream logs (excluded for incognito). — _Evidence:_ `oc/docs/tools/trajectory.md`; `oc/docs/cli/proxy.md`; `oc/docs/plugins/hooks/reference.md`

</details>
<details><summary><b>PRM-N1</b> 🆕 Byte-stable prompt prefix engineered for provider prompt-cache hits (volatile state moved to a tail)</summary>

- **sugar-crush** ✅ — `SessionPromptMemo` primed in the parent, `<turn-context>` persisted, system rows past index 0 become `<system-notice>` user rows. Pinned by `PromptPrefixByteStabilityTest`. — _Evidence:_ `src/Context/SessionPromptMemo.php`; `docs/PROMPT_ENGINEERING.md` "Outside the system prompt"
- **sugar-crush-web** ✅ — `EngineBackend::beginSessionTurn()` runs in the parent before every turn, whichever driver started it, so standing layers stay byte-stable and live git state rides the tail row. Any compaction is a memo refresh point; served sessions compact with the heuristic route. The browser shows no cache readout (the status bar has context % and spend only). — _Evidence:_ `src/Backend/EngineBackend.php:1390`, `:2702-2719`, `:3507-3508`; `src/Host/TurnRunner.php:488-496`
- **Claude Code 2.1.301** ✅ — `--system-prompt-snapshot on` reuses the recorded prompt across requests and resumes; `--exclude-dynamic-system-prompt-sections` moves cwd/env/memory paths/git status to the first user message; CLAUDE.md frozen per session; cache warnings on model/effort switches. — _Evidence:_ cc:prompt-caching#how-the-cache-is-organized; help (`--system-prompt-snapshot`, `--exclude-dynamic-system-prompt-sections`)
- **OpenCode 1.18.35** 🟡 — Volatile items stay out of the system prompt: no git status, plan reminders go in the user turn. However AGENTS.md, URL instructions, skills and MCP instructions are rebuilt every step, so any change rewrites the prefix. Cache breakpoints sit on the first 2 system and last 2 messages, and `x-session-affinity` is sent. — _Evidence:_ OC/session/prompt.ts:1257-1267; OC/session/llm/request.ts:68-78,184,199; OC/provider/transform.ts:356-385
- **OpenCode 2.0.31** ✅ — Content-hashed instruction epochs, so edits never rewrite the system prefix. `compatibility.cacheFormat` / supportsPromptCacheKey. Optional `warming`. — _Evidence:_ specs/v2/session.md (Instructions Are Value Deltas); V2C/plugin/plan.ts (reminders near tail); V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — Stable sections (tooling, Project Context, skills, Tool Search directory) sit above an internal `OPENCLAW_CACHE_BOUNDARY`; time, channel, runtime line, delegation mode below; exec/sub-agent/media facts travel as user-role carrier messages so the history prefix also stays stable; MCP catalogs sorted deterministically; prompt snapshots drift-checked in CI. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/reference/prompt-caching.md`; `oc/docs/tools/tool-search.md`

</details>
<details><summary><b>PRM-N2</b> 🆕 Project references (extra local dirs / git repos advertised to the model via config)</summary>

- **sugar-crush** ❌ — The prompt advertises only the session root (EnvironmentBlock cwd) and that root's RepoMap or symbol map. The setting roster has no key that lists extra local directories or git repos for the model. The nearest key is `instructions` (StringList, prompt class, layered): files matching its globs "become authoritative system-prompt text", which is content injection, not a reference the model can explore. Read can open files outside the root (paths are not restricted; normal permission asks apply), but Grep and Glob are confined to the root. `serve` workspace hosts run separate sessions in other roots, but no single session can reference a second root. — _Evidence:_ `src/Config/Settings/Definitions/MemoryRuleSettings.php:124-131`; `src/Cli/ArgvParser.php:311-343` (`--root` only); `docs/PERMISSIONS.md:292`
- **sugar-crush-web** ❌ — `fs.listDirs` and `workspace.open` give each picked root its own host process and sessions. No session can see two roots. — _Evidence:_ `sugar-crush-web/src-web/components/DirectoryPicker.vue`; `src/Server/Workspace/Gateway.php`
- **Claude Code 2.1.301** ✅ — Added dirs grant file access and load their `.claude/skills`, commands and agents; their CLAUDE.md loads with `CLAUDE_CODE_ADDITIONAL_DIRECTORIES_CLAUDE_MD=1`; `DirectoryAdded` hook; listed in the environment block. — _Evidence:_ cc:permissions#additional-directories-grant-file-access-not-configuration; cc:memory#load-from-additional-directories; obs
- **OpenCode 1.18.35** ✅ — References are configured by alias (path, or repository + branch, plus description and hidden). Ones with a description are listed in the system prompt as `<available_references>`. Git references are cloned asynchronously into the repo cache. Reference directories are auto-allowed for external_directory. — _Evidence:_ DOCS/references; OC/session/system.ts:71-104; OC/agent/agent.ts:102-113
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): [NEW: Project references] `references` config: local dirs/git repos advertised in prompt, @alias autocomplete. 2.0.31 (✅): [NEW: Project references] still supported (`references` map), advertised with MCP/skill guidance — _Evidence:_ V2D/references; V2D/instructions (Ordering)
- **OpenClaw 2026.9.8** 🟡 — Registered checkouts and GitHub URLs appear in the Control UI Place picker and can be targeted by `sessions_spawn projectId/projectGitUrl` (visible sessions); sandboxed agents can get extra folders via `docker.binds` (ro/rw). Neither injects a project list into the model's context. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md` (Projects); `oc/docs/tools/subagents/tool-reference.md`; `oc/docs/gateway/sandboxing/multiple-folders-for-one-agent.md`

</details>
<details><summary><b>PRM-N3</b> 🆕 Behavioural completion contract (prompt sections / checks that the agent finishes promised work)</summary>

- **sugar-crush** ✅ — Every system prompt includes MaximsSection (added at `Runtime.php:4347`), with OpenClaw's two sections restated: "Keep going until the task is done or a real blocker stops it, and name the blocker", and "Saying you are checking or fixing something is a progress note, not an answer: take that action in the same turn … promise later work only when something you started will deliver it". It also says "Report outcomes faithfully". The base prompt adds "When a change is nearly done, prove it before you say so … if you cannot find a runner, say that rather than implying the change is verified". Checks: TodoReminder re-shows the open todo list every 6 steps and after a compaction. `/goal <condition>` (TUI) runs a strict JSON judge on the title backend after each turn; a claimed but unshown requirement does not count as met, and the agent is re-prompted with what is missing. User Stop hooks, and the autoTest Stop hook, can also force more work. Missing: OpenClaw's automatic one-shot self-check when a final answer leaves work unfinished. — _Evidence:_ `src/Context/Sections/MaximsSection.php:73-123`; `src/Runtime.php:4347`, `:5325-5330`; `src/Goal/GoalJudge.php:14-50`; `src/Todo/TodoReminder.php:40-43`; `src/Backend/EngineBackend.php:1741-1752`; `builtin-commands/3400-goal.php`
- **sugar-crush-web** 🟡 — The prompt clauses and between-step todo reminders apply to web sessions, and the Todo panel shows open items live. `/goal` and `/grind` are client-only (-32030 `ui_only`), so a browser user cannot start the evidence-checking judge loop. — _Evidence:_ `src/Context/Sections/MaximsSection.php:113-123`; `src/Protocol/Methods/CommandMethods.php`; `sugar-crush-web/src-web/components/panels/TodoPanel.vue`
- **Claude Code 2.1.301** ✅ — Goal = session-scoped prompt-based Stop hook (clears when met or judged impossible); Stop hooks `decision: block` keep Claude working (8-in-a-row cap); built-in commit instructions run a `verify`/`simplify` skill before commits when one exists. Opt-in rather than always-on. — _Evidence:_ cc:goal; cc:hooks#stop-decision-control; cc:skills#run-your-checks-before-each-commit
- **OpenCode 1.18.35** 🔌 — Native pieces only: model prompts such as beast.txt (“keep going until resolved”) and the max-steps summary. The goal plugin supports OpenCode 1.17.15 up to, but not including, 2. — _Evidence:_ OC/session/prompt/beast.txt; opencode-goal-plugin README (compat <2)
- **OpenCode 2.0.31** ❌ — v2 adds output-limit auto-continuation, which is not a completion check. — _Evidence:_ opencode-goal-plugin README; V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Prompt tells the model to act on actionable requests, never end on a plan when tools can act, and treat promises of later work as owned follow-through; if a saved progress card is unfinished at the final answer the runtime runs at most one self-check turn. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Structure); `oc/docs/tools/progress-card.md`

</details>

### CTX — Context-window management

<details><summary><b>CTX-01</b> Token counting method (provider-reported vs estimate)</summary>

- **sugar-crush** 🟡 — `TokenEstimate` (script-weighted). `ContextMeter::calibrationFrom()` scales by observed/estimated, clamped to [1,3]. `ContextPressure::measure()` includes system and tools. `ContextCompactor::countTokens()` counts only message `content` (ISSUES). — _Evidence:_ `src/Host/ContextMeter.php`; `src/Context/ContextPressure.php:88`; `src/Context/ContextCompactor.php:1398`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — Status-line `context_window` fields (`used_percentage`, `current_usage`); window correction for unknown/gateway model IDs. — _Evidence:_ cc:statusline#context-window-fields; cc:model-config#correct-the-window-for-a-gateway-or-custom-model-id
- **OpenCode 1.18.35** ✅ — `isOverflow` uses `tokens.total`, or input+output+cache.read+cache.write, from the last finished step. Sidebar % uses the same fields plus reasoning. — _Evidence:_ OC/session/overflow.ts:22-34; TUI/feature-plugins/sidebar/context.tsx:28-33
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Overflow decisions use provider-reported usage (total or in+out+cache); chars/4 estimates only for tail/prune sizing. 2.0.31 (✅): Runner estimates the complete model-visible request before each step; provider usage used for display — _Evidence:_ specs/v2/session.md (Compaction); V2CL v2.0.29 (lastAssistantWithUsage)
- **OpenClaw 2026.9.8** ✅ — Last valid assistant usage (totalTokens or input+output+cacheRead+cacheWrite) plus estimates for later messages; images estimated; /status falls back to transcript usage when live snapshot is sparse. — _Evidence:_ `oc/docs/concepts/compaction.md`; `oc/docs/concepts/usage-tracking.md`

</details>
<details><summary><b>CTX-02</b> Context usage display (% / tokens) in UI</summary>

- **sugar-crush** ✅ — — — _Evidence:_ `src/Renderer.php:3088-3105`, `:2662`; `src/Context/TurnContextBlock.php:69`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/StatusBar.vue`; `src-web/components/grid/SessionTile.vue`
- **Claude Code 2.1.301** ✅ — The default footer shows no % until near the limit; add a status line (`/statusline`) for always-on %. — _Evidence:_ cc:commands; cc:statusline#notifications-share-the-status-line-row; cc:vs-code
- **OpenCode 1.18.35** ✅ — The sidebar “Context” block shows N tokens, % used and $ spent. The subagent footer shows the child's context % and cost. `opencode stats` gives history. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx:39-44; TUI/routes/session/subagent-footer.tsx
- **OpenCode 2.0.31** ✅ — The sidebar “Context” block shows N tokens, % used and $ spent. The subagent footer shows the child's context % and cost. `opencode stats` gives history. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx:39-44; TUI/routes/session/subagent-footer.tsx
- **OpenClaw 2026.9.8** ✅ — Control UI meter labels the estimate honestly and switches to "Context window" after a model/cap change until a new run reports. — _Evidence:_ `oc/docs/concepts/context.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>CTX-03</b> Auto-compaction trigger (threshold)</summary>

- **sugar-crush** ✅ — `CompactorConfig::tierThreshold()` uses the lower of percent and cap. The reminder tier pre-fetches summaries in the background. Futile absolute tiers stand down. Caveat: the tiers ignore system and tool tokens (ISSUES). Caps are not validated for ascending order. — _Evidence:_ `src/Context/CompactorConfig.php:264`, `:679`; `src/Chat.php:11269-11290`; `src/Context/ContextBudget.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/stores/reducer.ts:521-530`
- **Claude Code 2.1.301** ✅ — `DISABLE_AUTO_COMPACT`; subagent `autoCompactWindow`; idle compaction compacts 200K+ conversations after ~54 min idle, before the 1h cache expires (`idleCompaction`, `CLAUDE_CODE_IDLE_COMPACT_MIN_TOKENS`). — _Evidence:_ cc:model-config#set-the-auto-compact-window; help (`--autocompact`); CL v2.1.299
- **OpenCode 1.18.35** ✅ — usable = limit.input − (`compaction.reserved` ?? min(20000, maxOutput)), else context − maxOutput. Checked after every step (`takeUntil` stops the stream) and before each step. Disable with `compaction.auto:false` or `OPENCODE_DISABLE_AUTOCOMPACT`. — _Evidence:_ OC/session/overflow.ts:8-34; OC/session/processor.ts:495; OC/session/prompt.ts:1161-1167
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Auto-compact when reported tokens ≥ usable (input limit − min(20k, maxOut)); runs mid-turn; `compaction.auto`. 2.0.31 (✅): Runner estimates the complete model-visible request before each step. V1 `reserved` → V2 `buffer`. — _Evidence:_ specs/v2/session.md (Compaction Rebuilds Active History); V2D/compaction (Settings)
- **OpenClaw 2026.9.8** ✅ — Required compaction runs before inference; optional maintenance (flush + compaction) runs after reply delivery using the turn's remaining time and is cancelled by a new message. `compaction.enabled: false` disables proactive compaction (overflow recovery and manual stay). `maxActiveTranscriptBytes` (e.g. "20mb") opt-in. — _Evidence:_ `oc/docs/concepts/compaction.md`

</details>
<details><summary><b>CTX-04</b> Manual compaction (/compact [instructions])</summary>

- **sugar-crush** ✅ — No host command, so `serve`/web report it as client-only. Automatic tiers still run server-side. — _Evidence:_ `builtin-commands/2000-compact.php`; `docs/SERVER.md:540-549`
- **sugar-crush-web** ❌ — A web user cannot compact a session manually. — _Evidence:_ live `command.exec name=compact`
- **Claude Code 2.1.301** ✅ — A free-form "Compact Instructions" section in CLAUDE.md also steers summaries. — _Evidence:_ cc:commands; cc:how-claude-code-works#when-context-fills-up
- **OpenCode 1.18.35** 🟡 — The TUI calls `session.summarize` with the current model only. There is no way to steer the summary except a plugin replacing the prompt. — _Evidence:_ TUI/routes/session/index.tsx:561-585; DOCS/tui (compact)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): /compact (alias /summarize, <leader>c) — no custom-instructions argument. 2.0.31 (🟡): `opencode api post /api/session/<id>/compact --data '{}'`; requests merge while pending; works with auto off. — _Evidence:_ V2D/compaction (Manual); V2T slash list (compact)
- **OpenClaw 2026.9.8** ✅ — Focus applied to both history and split-turn summaries; native Codex sessions use bare `/compact`; manual compaction keeps `keepRecentTokens`. — _Evidence:_ `oc/docs/concepts/compaction.md` (Manual compaction)

</details>
<details><summary><b>CTX-05</b> Compaction method (LLM summary prompt, what survives, file re-reads)</summary>

- **sugar-crush** ✅ — `COMPACT_SUMMARY_PROMPT`; derived headings filled mechanically. Cache-reusing summary request. Re-injection of ≤5 files (5k tokens each), latest plan, skill bodies, git and todos, within 10% of the window. Heuristic fallback. — _Evidence:_ `src/Host/CompactionService.php:622`; `src/Context/Compaction/StateSummaryTemplate.php`; `src/Context/Compaction/ReinjectionPlan.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/stores/reducer.ts:521-530`
- **Claude Code 2.1.301** ✅ — Files >5K tokens come back as `Referenced file` paths; still-running background work is listed; SessionStart(`compact`) hooks re-run; summary inherits thinking config; PreCompact/PostCompact hooks. — _Evidence:_ cc:context-window#what-survives-compaction; cc:prompt-caching#compacting-the-conversation
- **OpenCode 1.18.35** ✅ — Template sections: Objective, Important Details, Work State (Completed/Active/Blocked), Next Move, Relevant Files. Tool results are serialized to ≤2000 chars. A previous summary is passed as `<prior-summary>` with merge rules. The tail is kept verbatim: `preserve_recent_tokens` ?? min(15k, max(2k, 25% of usable)), optional `tail_turns`. The compaction user message is rendered as “What did we do so far?”. The run then appends “Continue if you have next steps…”, which plugins can disable. No file re-reads. — _Evidence:_ CORE/session/compaction.ts:14-55; OC/session/compaction.ts:115-269,531; OC/session/message-v2.ts:241
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Hidden compaction agent writes anchored 5-section summary; verbatim recent tail kept; prior summary merged; auto-continue. 2.0.31 (✅): `compaction.keep.tokens` (default 15000). OpenAI Responses models can use `native` compaction (opaque encrypted checkpoint). Summary omits instruction files/env. Instructions re-baseline after compaction. No separate compaction model. — _Evidence:_ V2D/compaction; V2C/session/compaction.ts:43-46,106-162,197-233
- **OpenClaw 2026.9.8** ✅ — Tool-call/result pairs never split; split-turn prefix summarized separately; read/modified file lists and latest unresolved user request preserved; summary capped (16k chars) with structure-preserving fit; identifierPolicy strict; post-compaction AGENTS.md excerpt; `compaction.model`; pluggable context engines (`plugins.slots.contextEngine`, e.g. Lossless Claw). — _Evidence:_ `oc/docs/concepts/compaction.md`; `oc/docs/concepts/context-engine.md`; `oc/docs/reference/session-management-compaction/compaction.md`

</details>
<details><summary><b>CTX-06</b> Tool-output clearing / micro-compaction / pruning old tool results</summary>

- **sugar-crush** ✅ — `EmergencyPrune`, `TurnStartPruning`, `PruningPolicy` (protects Task/Skill/Edit/Write, the newest 40k tokens and the last 2 prompts). — _Evidence:_ `src/Context/Pruning/*`; `src/Backend/EngineBackend.php:1965-1975`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Thresholds for the clearing pass are not documented. — _Evidence:_ cc:how-claude-code-works#when-context-fills-up; cc:prompt-caching#accumulating-many-images
- **OpenCode 1.18.35** 🟡 — Walks newest to oldest and skips the last 2 user turns. Protects 40k tokens of tool output and the `skill` tool. Applies only if more than 20k would be pruned. The stored output is kept for the UI. Runs after each finished loop. `OPENCODE_DISABLE_PRUNE` overrides. DCP plugin for more. — _Evidence:_ OC/session/compaction.ts:28-31,271-317; OC/session/message-v2.ts:304; OC/session/prompt.ts:1338
- **OpenCode 2.0.31** ❌ — 1.18.35 (🟡): Opt-in prune (`compaction.prune`, default false): old tool outputs → '[Old tool result content cleared]' beyond 40k. 2.0.31 (❌): No pruning: `compaction.prune`/`tail_turns` are ignored with a warning; recent tail just shortens long tool output — _Evidence:_ V2D/migrate-v1 (Compaction, Accepted but unsupported)
- **OpenClaw 2026.9.8** ✅ — After cache TTL (5 min, Anthropic plugin seeds 1 h) and ≥30% usage, results >4,000 chars keep head/tail 1,500; at ≥50% with ≥50k prunable chars results become `[Old tool result content cleared]`; last 3 assistant turns protected; projection persisted, transcript untouched. Direct Anthropic API-key requests delegate to `clear_tool_uses_20250919`. — _Evidence:_ `oc/docs/concepts/session-pruning.md`

</details>
<details><summary><b>CTX-07</b> Tool-output truncation at tool time (limits)</summary>

- **sugar-crush** ✅ — `toolOutputCapBytes`, `mcpResultCapBytes`, `toolSpillWindowPercent`. — _Evidence:_ `src/Support/ToolOutputSpill.php`; `docs/CONTEXT.md:324-346`
- **sugar-crush-web** ✅ — Up to 64 pages of 1 MiB are appended into the card. — _Evidence:_ `src-web/stores/session.ts:187-201`; `src-web/components/ToolCard.vue:67`
- **Claude Code 2.1.301** ✅ — Bash failures: 10K head+tail; `BASH_MAX_OUTPUT_LENGTH` ≤150K; `bashOutputMaxChars` ≤128K; 5 GB kill; MCP text >50K chars spilled to `tool-results/`; MCP errors trimmed to 5K+5K. — _Evidence:_ cc:tools-reference#output-limits; cc:mcp#mcp-output-limits-and-warnings; cc:hooks#json-output
- **OpenCode 1.18.35** ✅ — The Tool.define wrapper truncates (configurable `tool_output.max_lines/max_bytes`), writes the full output to the truncation dir (pre-allowed for external_directory), and tells the model to use Task/explore or Grep/Read with offset. Applied to MCP tools too. — _Evidence:_ OC/tool/truncate.ts:14-15,77-80; OC/agent/agent.ts:293-306; OC/session/tools.ts:196-206
- **OpenCode 2.0.31** ✅ — The Tool.define wrapper truncates (configurable `tool_output.max_lines/max_bytes`), writes the full output to the truncation dir (pre-allowed for external_directory), and tells the model to use Task/explore or Grep/Read with offset. Applied to MCP tools too. — _Evidence:_ OC/tool/truncate.ts:14-15,77-80; OC/agent/agent.ts:293-306; OC/session/tools.ts:196-206
- **OpenClaw 2026.9.8** ✅ — Plus per-tool caps (web_fetch 20k, memory_get 12k, sessions_history 80 KB); Tokenjuice plugin compacts noisy exec output. — _Evidence:_ `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (contextLimits); `oc/docs/tools/tokenjuice.md`

</details>
<details><summary><b>CTX-08</b> Large-file read handling (offset/limit, paging)</summary>

- **sugar-crush** ✅ — — — _Evidence:_ `src/Tools/BuiltIn/Read.php:41-49`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `CLAUDE_CODE_FILE_READ_MAX_OUTPUT_TOKENS`; observed in this session's Read tool. — _Evidence:_ cc:tools-reference#large-files; CL v2.1.296; obs
- **OpenCode 1.18.35** ✅ — Footer: “(Output capped at 50 KB. Showing lines a-b. Use offset=N to continue.)”. The description discourages tiny 30-line slices. — _Evidence:_ OC/tool/read.ts:13-17,345; OC/tool/read.txt
- **OpenCode 2.0.31** ✅ — Footer: “(Output capped at 50 KB. Showing lines a-b. Use offset=N to continue.)”. The description discourages tiny 30-line slices. — _Evidence:_ OC/tool/read.ts:13-17,345; OC/tool/read.txt
- **OpenClaw 2026.9.8** ✅ — Pages fit the model's tool-result budget; `optional` returns not_found instead of error; `ls` pages with an `after` cursor. — _Evidence:_ `oc/docs/tools/code-mode/guest-api.md` (Reading paginated file data); `oc/docs/gateway/sandboxing/what-gets-sandboxed.md`

</details>
<details><summary><b>CTX-09</b> Agent-controlled self-pruning (DCP-style tools)</summary>

- **sugar-crush** ✅ — Gated by `/pruning` mode. Ref tags `<ctx-ref r=N/>`. — _Evidence:_ `src/Tools/BuiltIn/Prune.php`; `Compress.php`; `Recall.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference
- **OpenCode 1.18.35** 🔌 — DCP rewrites the outbound message list through `experimental.chat.messages.transform` (fired every step) without touching storage. It exposes `compress` to the model. Both configs here load `@tarquinen/opencode-dcp` (repo pins 3.1.3, global @latest; `dcp.jsonc` present). — _Evidence:_ PLUG/index.ts:282-290; OC/session/prompt.ts:1255; LOCAL: ~/.config/opencode/{opencode.jsonc,tui.json,dcp.jsonc}, repo .opencode/opencode.jsonc
- **OpenCode 2.0.31** ❌ — 1.18.35 (🔌): Only via 🔌 opencode-dynamic-context-pruning (compress tool, dedup/purge strategies) — installed here. 2.0.31 (❌): `npm view @tarquinen/opencode-dcp` 3.2.0 depends on `@opencode-ai/plugin >=1.18.29` (V1). No v2 port found; the v2 `context` hook would allow one. — _Evidence:_ npm view @tarquinen/opencode-dcp dependencies; V2D/migrate-v1 (Plugins)
- **OpenClaw 2026.9.8** ❌ — Pruning/compaction are runtime policies only. — _Evidence:_ `oc/docs/concepts/session-pruning.md`

</details>
<details><summary><b>CTX-10</b> Rewind / drop messages from context</summary>

- **sugar-crush** ✅ — — — _Evidence:_ `src/Host/Commands/RewindCommand.php`; `src/Context/SessionPromptMemo.php:189`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/stores/session.ts:140-158`
- **Claude Code 2.1.301** ✅ — Rewind truncates to a cached prefix (cheap); originals stay in the transcript; `/clear` past-session entry. — _Evidence:_ cc:checkpointing#rewind-and-summarize; cc:prompt-caching#rewinding-the-conversation
- **OpenCode 1.18.35** ✅ — `revert(messageID, partID?)` hides later messages and restores files, and is itself undoable via unrevert. `cleanup` deletes the hidden messages on the next prompt. — _Evidence:_ OC/session/revert.ts:38-124; TUI/routes/session/index.tsx:507-660
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /undo, /timeline revert-to-message, /fork from any message; reverted messages soft-hidden then deleted. 2.0.31 (✅): /undo stages a rollback (prompt back in composer) committed on next submit; /redo cancels; Revert on any message — _Evidence:_ V2D/snapshots (Workflow)
- **OpenClaw 2026.9.8** ✅ — `sessions.rewind` (operator.admin), returns text to composer; disabled while the agent works; files/tool side-effects are not reverted. — _Evidence:_ `oc/docs/web/control-ui/chat.md` (transcript actions)

</details>
<details><summary><b>CTX-11</b> Side questions without polluting context (/btw-style)</summary>

- **sugar-crush** ✅ — Host command, works in server mode. — _Evidence:_ `src/Host/Commands/BtwHostCommand.php:65-105`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Protocol/Methods/CommandMethods.php:91-115`
- **Claude Code 2.1.301** ✅ — Runs while Claude works; cheap on a warm cache; overlay with history browsing and `c` copy. — _Evidence:_ cc:interactive-mode#side-questions-with-/btw
- **OpenCode 1.18.35** ❌ — A command with `subtask: true` runs in a child session and only its summary returns, which is the closest equivalent. — _Evidence:_ DOCS/commands (Subtask)
- **OpenCode 2.0.31** ✅ — 1.18.35 (❌): No /btw side-question mode (subtask commands are the closest). 2.0.31 (✅): Uses `session.generate` with current model; answer opens in a dialog (C copies). — _Evidence:_ V2T/feature-plugins/prompt/btw.tsx:54-78; V2D/cli/tui (Side questions)
- **OpenClaw 2026.9.8** ✅ — Codex harness runs it as an ephemeral side thread; Control UI keeps 24 side turns and supports images. — _Evidence:_ `oc/docs/tools/btw.md`; `oc/docs/tools/slash-commands.md`

</details>
<details><summary><b>CTX-12</b> Context warnings near limit</summary>

- **sugar-crush** ✅ — — — _Evidence:_ `src/Context/ContextCompactor.php:539`; `src/Context/Pruning/NudgePolicy.php`; `src/Backend/EngineBackend.php:135`
- **sugar-crush-web** ✅ — — — _Evidence:_ live snapshot; `src-web/stores/reducer.ts:532-541`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:statusline#notifications-share-the-status-line-row; CL (context-left warning fixes)
- **OpenCode 1.18.35** 🟡 — No toast or banner before auto-compaction. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx
- **OpenCode 2.0.31** 🟡 — No toast or banner before auto-compaction. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx
- **OpenClaw 2026.9.8** ✅ — `/status` shows "🧹 Compactions: N"; verbose shows auto-compaction complete. — _Evidence:_ `oc/docs/concepts/context.md`; `oc/docs/concepts/compaction.md`

</details>
<details><summary><b>CTX-13</b> Overflow recovery (prompt-too-long → compact & retry)</summary>

- **sugar-crush** ✅ — `ContextOverflow::matches()`; `$overflowRetried` per step. — _Evidence:_ `src/Backend/EngineBackend.php:2075-2128`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live snapshot (this audit)
- **Claude Code 2.1.301** ✅ — Reactive-only compaction available for unknown models (`CLAUDE_CODE_DISABLE_UNKNOWN_MODEL_WINDOW_ENFORCEMENT`). — _Evidence:_ cc:errors#automatic-retries; cc:how-claude-code-works#when-context-fills-up
- **OpenCode 1.18.35** ✅ — `ContextOverflowError` → `needsCompaction`, then compaction with overflow=true. The overflowing user message is replayed with media replaced by `[Attached mime: name]`. If the summary itself cannot fit: “Session too large to compact”. — _Evidence:_ OC/session/processor.ts:621-631; OC/session/compaction.ts:468-550
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Provider context-overflow error triggers overflow compaction and replays the last user msg with media stripped. 2.0.31 (✅): One overflow-triggered compaction + rebuild of the same step before durable output; second overflow is terminal — _Evidence:_ specs/v2/session.md (Compaction); V2D/compaction (Limits)
- **OpenClaw 2026.9.8** ✅ — Keeps model/account and original request, no replay of completed actions; requires settled tool results. — _Evidence:_ `oc/docs/concepts/compaction.md` (Auto-compaction)

</details>
<details><summary><b>CTX-N1</b> 🆕 Ahead-of-need compaction (summaries prepared in the background before the limit is hit)</summary>

- **sugar-crush** ✅ — Background summaries are spliced in when the auto tier fires if the history fingerprint still matches. `SummarisesWithCache` reuses the provider prefix cache. — _Evidence:_ `src/Chat.php:11273-11290`; `src/Backend/CacheReusingSummaryBackend.php`
- **sugar-crush-web** ❌ — `SessionHost::submit()` calls `TurnController::inlineTier()` with no `$summaries`; per its own comment, "a server session's tier always writes the heuristic lines". The background pre-fetch at the reminder tier lives only in `Chat`. Summaries inside a turn (EngineBackend step summaries) are still model-written. The browser shows the reducer's "History compacted (kind): before → after tokens" notice. — _Evidence:_ `src/Host/SessionHost.php:72-74`, `:769-812`; `src/Host/TurnController.php:1061-1072`; `src/Chat.php:11273-11290`; `sugar-crush-web/src-web/stores/reducer.ts:521-530`
- **Claude Code 2.1.301** 🟡 — Normal auto-compaction runs when the window fills (configurable earlier with `/autocompact`); idle compaction (v2.1.299) is the only ahead-of-need pass. — _Evidence:_ CL v2.1.299; cc:model-config#set-the-auto-compact-window
- **OpenCode 1.18.35** ❌ — Magic Context (cortexkit) advertises a background historian, but I could not find it on npm, so it is unverified. — _Evidence:_ OC/session/compaction.ts; awesome-opencode (Magic Context)
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ specs/v2/session.md (Compaction); V2D/compaction
- **OpenClaw 2026.9.8** 🟡 — In persistent Gateway sessions, optional flush/compaction start after delivery settles, using the turn's remaining time, and are cancelled by a new foreground message; required compaction still runs before inference. Summaries are not prepared speculatively ahead of the threshold. — _Evidence:_ `oc/docs/concepts/compaction.md`; `oc/docs/reference/session-management-compaction/compaction.md`

</details>
<details><summary><b>CTX-N2</b> 🆕 Idle compaction / resume-from-summary (compacts idle long chats before cache expiry)</summary>

- **sugar-crush** 🟡 — `Chat::submit()` calls `shouldPromptIdleCompaction()`, which is `IdleCompactionPolicy::shouldPrompt()`: true when the session has been idle longer than `compaction.idleOfferSeconds` (default 3600) AND the estimate is past the ENTIRE context window. The prompt is then not sent; a UI-only advisory row says the session outgrew its window and suggests /compact. Because the 85%/95% tiers fire earlier, this happens only when automatic compaction could not get back under the window, so it is rare and reactive (it fires on the next prompt). Nothing compacts while the session is idle, and nothing targets a provider cache TTL: the status bar shows the cache hit % and the age of the last report, and deliberately assumes no TTL. There is no resume-from-summary offer. TUI only; serve's copy is dormant (see SCW). — _Evidence:_ `src/Chat.php:11214-11215`, `:19777-19792`; `src/Context/IdleCompactionPolicy.php:36-46,105`; `src/Context/CompactorConfig.php:108`; `src/Renderer.php:2956-2960`
- **sugar-crush-web** 🧪 — The idle advisory is reached only from `Chat::submit()`. The host-side copy (`ContextMeter:182-189`) is exercised only by RuntimeTest; a grep finds no production caller. Not that the TUI version would help much here (see SC). — _Evidence:_ `src/Host/ContextMeter.php:172-189`; `tests/RuntimeTest.php:2002-2003`; `src/Chat.php:11214`
- **Claude Code 2.1.301** ✅ — Resume-from-summary dialog on Pro/Max for >100K sessions idle >1 h. — _Evidence:_ CL v2.1.299; cc:sessions#resume-from-a-summary
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/session/compaction.ts
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2D/warming; V2D/compaction
- **OpenClaw 2026.9.8** ❌ — Cache-TTL pruning works the other way (prunes only after the cache has expired); idle *resets* are opt-in (`session.reset.mode: idle`) and the startup prelude re-reads recent daily notes after `/new`/`/reset`; cold storage is a disk-level move, not context compaction. — _Evidence:_ `oc/docs/concepts/session-pruning.md`; `oc/docs/concepts/session.md`; `oc/docs/reference/session-management-compaction/maintenance.md`

</details>
<details><summary><b>CTX-N3</b> 🆕 Max-steps wrap-up (on the last allowed step tools are disabled and the agent must summarise)</summary>

- **sugar-crush** ✅ — `EngineBackend::runTurn()` loops `for ($step = 0; $step < $this->maxSteps; ...)`. When the loop runs out with tool results still pending (`stepsTruncated`), or the loop guard ended the turn, `summariseStoppedTurn()` sends `BUDGET_EXHAUSTED_SUMMARY_PROMPT`: "tools are now disabled. Do not call any tools. Reply to the user with a concise summary of: what has been done, what remains to be done, and the next step". It goes through `StepSummarizer::summaryStep` (no tools). An empty summary leaves the last step's prose in place. Chat then appends the `chat.turn.steps_exhausted` notice: say "continue", or raise `maxToolSteps`. No summary follows a spend-cap stop, because it would be another billed call. Served turns report `stopReason: max_steps`. Task sub-agents default to 200 steps, and delegated runs also get an 80% context wrap-up nudge and a 90% stop. — _Evidence:_ `src/Backend/EngineBackend.php:183-185`, `:405`, `:2485-2514`, `:2784-2800`; `src/Chat.php:2423`, `:19704-19707`; `src/Config/Settings/Definitions/AgentLoopSettings.php:55`; `README.md:2066`
- **sugar-crush-web** ✅ — EngineBackend's summary request runs for served turns. `TranscriptProjector::stopReason()` maps `stepsTruncated` to `max_steps`, and the reducer adds a warn notice. A turn the loop guard ended maps to `end_turn`, so it gets no harness notice; only the model's own summary says it was stopped. — _Evidence:_ `src/Host/TranscriptProjector.php:446-455`; `sugar-crush-web/src-web/stores/reducer.ts:507-513`, `:560-565`
- **Claude Code 2.1.301** ❌ — Nothing disables tools on the last step to force a summary. — _Evidence:_ cc:sub-agents#supported-frontmatter-fields; cc:cli-reference
- **OpenCode 1.18.35** ✅ — When `steps` is reached, an assistant-role MAX_STEPS_PROMPT is appended: tools disabled, respond with what was done, what remains and next steps. — _Evidence:_ OC/session/prompt.ts:1177-1178,1281; CORE/session/runner/max-steps.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): [NEW: Agent max-steps wrap-up] on the last allowed step tools are disabled and a summary-of-progress message is forced. 2.0.31 (✅): [NEW: Agent max-steps wrap-up] `steps` still forces a text summary; new user input resets the allowance — _Evidence:_ V2D/agents (Steps); specs/v2/session.md
- **OpenClaw 2026.9.8** 🟡 — Runs are bounded by time (`timeoutSeconds`, sub-agent `runTimeoutSeconds`), not step counts; the finalization pass composes an answer from settled tool results without re-running tools; stall recovery gives one continuation turn. — _Evidence:_ `oc/docs/concepts/agent-loop.md` (Reply shaping); `oc/docs/cli/agent.md`

</details>
<details><summary><b>CTX-N4</b> 🆕 Output-limit auto-continuation (a reply cut off at max output tokens is continued automatically)</summary>

- **sugar-crush** ✅ — In `EngineBackend::runTurn()`, a step whose reply has `lengthStopped()`, no tool results and continuable text is sent again, up to `ReplyContinuation::MAX_LENGTH_CONTINUATIONS` = 3 times, while the spend cap and any stop request allow it. Providers that implement AcceptsAssistantPrefill (sglang, vertex, bedrock) get the right-trimmed partial reply as an assistant prefill and continue that same message exactly; if the prefill is rejected, later attempts fall back to the other method. Other providers get the partial reply plus `LENGTH_PROMPT` with the last 64 chars quoted in `<already_delivered_tail>`. Each answer is merged into one reply. If it is still cut after 3 continuations, Chat appends the output-length-stopped notice and served turns report `stopReason: length`. A stream that drops after text was painted is resumed the same way (`RESUME_PROMPT`, Runtime stream retry), so the reply is not painted twice. A length stop on a step that called tools is not continued. — _Evidence:_ `src/Providers/ReplyContinuation.php:7-60`; `src/Backend/EngineBackend.php:1863-1880`, `:1950-1975`; `src/Runtime.php:1543-1569`; `src/Chat.php:2418`, `:19677`; `src/Providers/SglangProvider.php:34`
- **sugar-crush-web** ✅ — The continuation loop runs inside EngineBackend for every served turn, and the joined reply arrives as one `assistant.completed`, so its deltas stream on without a break. After 3 continuations, `TranscriptProjector::stopReason()` returns `length`, and the reducer adds the warn notice "Turn stopped: the reply hit the output limit." — _Evidence:_ `src/Backend/EngineBackend.php:1950-1975`; `src/Host/TranscriptProjector.php:450`; `sugar-crush-web/src-web/stores/reducer.ts:507-513`, `:566`
- **Claude Code 2.1.301** 🟡 — Main conversation: "Claude's response exceeded the … output token maximum" error; user replies `continue` (text kept) or raises `CLAUDE_CODE_MAX_OUTPUT_TOKENS`. Subagents: when a response is cut mid-stream with text but no tool calls, Claude Code prompts them to continue; since 2.1.288 non-interactive sessions and subagents continue from partial responses after mid-response API timeouts; context-limit 400s retried with a smaller `max_tokens`. — _Evidence:_ cc:errors#response-exceeded-the-output-token-maximum; cc:sub-agents#api-errors-in-subagents; CL v2.1.288; cc:errors#automatic-retries
- **OpenCode 1.18.35** ❌ — No length-finish continuation in the processor or loop. Added in v2.0.29. — _Evidence:_ OC/session/processor.ts; V2CL v2.0.29
- **OpenCode 2.0.31** ✅ — Not present in 1.18.35. 2.0.29: preserves partial output and continues with bounded retries, with recovery for truncated tool calls; incomplete streams add a synthetic continuation instruction. — _Evidence:_ V2CL v2.0.29; specs/v2/session.md (Retry)
- **OpenClaw 2026.9.8** 🟡 — Admitted tools finish, the partial call is never executed, and the run continues from recorded results (shares retry budget and run deadline). A plain text reply stopped with `stopReason: "length"` is not continued; it is routed to overflow/error handling. — _Evidence:_ `oc/docs/concepts/retry.md`; `oc/docs/concepts/agent-loop.md` (Compaction and retries); `oc/dist/embedded-agent-DsCaQmKI.mjs` (canContinueOutputLimit)

</details>

### SES — Sessions

<details><summary><b>SES-01</b> Session persistence on disk (format / location)</summary>

- **sugar-crush** ✅ — Transcript rows are interned into `checkpoint_blobs` so a save writes only what is new; flush at once on switch/branch/fork/quit. Legacy `messages`/`tool_calls` tables still created but never written (0 rows on the real DB). `-p` runs are NOT persisted at all (no session row, no transcript). — _Evidence:_ `src/Session/SessionStore.php:112-240`; `src/Session/EnhancedSessionStore.php:516-640,1761-1813`; `README.md:871-876`; repro: isolated HOME has no session.db after `-p`
- **sugar-crush-web** ✅ — A browser reload or a different device sees the same transcript; seq-numbered event log per session (20,000 kept) for replay. — _Evidence:_ `src-web/stores/sessions.ts:31-56`; `sugar-crush/docs/SERVER.md:607-628`
- **Claude Code 2.1.301** ✅ — `CLAUDE_CONFIG_DIR`, `CLAUDE_CODE_PROJECT_DIR_NAME`; subagent transcripts under `<session>/subagents/`; `--no-session-persistence` for `-p`; `CLAUDE_CODE_SKIP_PROMPT_HISTORY`. — _Evidence:_ cc:sessions#where-transcripts-are-stored
- **OpenCode 1.18.35** ✅ — Sessions, messages and parts live in SQLite (WAL). `opencode db [query] --format json/tsv` and `opencode db path`. This host's DB is 11,678,072,832 bytes. Snapshots and tool-output files sit alongside. — _Evidence:_ LOCAL: `ls -la ~/.local/share/opencode`; CLI: `opencode db --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): SQLite at ~/.local/share/opencode/opencode.db (Drizzle); `opencode db` sqlite shell; 11.6 GB observed here. 2.0.31 (✅): Durable session events + inbox rows; channel-specific DB names for non-stable channels (`OPENCODE_DB`). V2 core contains a V1 import migration (sessions/messages/parts), so the 11.6 GB V1 DB here would be read on first v2 start (not exercised). — _Evidence:_ V2 packages/cli/src/database-path.ts; V2C/database/v1-migration*.ts; V2C/database/database.ts:102-103
- **OpenClaw 2026.9.8** ✅ — Session rows + transcripts; legacy JSONL imported only by Doctor; incognito sessions stay in memory (24 h). — _Evidence:_ `oc/docs/concepts/session.md` (Where state lives); `oc/docs/reference/database-schemas.md`

</details>
<details><summary><b>SES-02</b> Continue most recent (--continue / -c)</summary>

- **sugar-crush** 🟡 — `latestResumableSession()` orders by updated_at with no cwd filter, so `-c` in project B reopens project A's conversation while tools are rooted at B (ISSUES P2). Refused with `-p`/`--resume` (exit 2). — _Evidence:_ `src/Session/EnhancedSessionStore.php:2146-2160`; `src/Cli/Bootstrap.php:4141-4151`; `src/Cli/ArgvParser.php:646-655`
- **sugar-crush-web** 🟡 — No explicit "continue latest" action; reopening the page restores tabs and the focused tile. — _Evidence:_ `src-web/stores/layout.ts:50-64`; `src-web/views/DashboardView.vue`
- **Claude Code 2.1.301** ✅ — Restores permission mode in most cases; `-p --continue` for scripts; `--fork-session` to copy instead. — _Evidence:_ cc:sessions#resume-a-session; help
- **OpenCode 1.18.35** ✅ — Also `-s <id>`. `--fork` requires `-c` or `-s`. — _Evidence:_ CLI: `opencode --help`, `opencode run --help`, `opencode attach --help`
- **OpenCode 2.0.31** ✅ — Also `-s <id>`. `--fork` requires `-c` or `-s`. — _Evidence:_ CLI: `opencode --help`, `opencode run --help`, `opencode attach --help`
- **OpenClaw 2026.9.8** ✅ — Sessions are reused until reset (no automatic reset by default); `openclaw agent --session-key/--session-id`. — _Evidence:_ `oc/docs/concepts/session.md`; `oc/docs/web/tui.md`; CLI: `openclaw resume --help`

</details>
<details><summary><b>SES-03</b> Resume picker (--resume, /resume, session list)</summary>

- **sugar-crush** ✅ — Unknown target → exit 2 before the TUI. CLI: `session list/show/rename/delete/pin/unpin/archive/unarchive` with JSON output. — _Evidence:_ `src/Cli/Bootstrap.php:4077-4092`; `src/Tui/SessionPicker.php:1334-1370`; `src/Cli/Subcommands.php:729-870`
- **sugar-crush-web** ✅ — Sessions of other workspace roots are merged in when dir-browse is on (first page only). — _Evidence:_ `src-web/components/SessionSidebar.vue`; `src-web/stores/sessions.ts:64-76`
- **Claude Code 2.1.301** ✅ — Background sessions marked `bg` (resuming one attaches); `--from-pr` filters by PR; groups branched copies. — _Evidence:_ cc:sessions#use-the-session-picker; help
- **OpenCode 1.18.35** ✅ — Debounced server search (title). Pinned sessions and quick-switch slots. ctrl+r rename, ctrl+d delete. `opencode session list -n N --format json` and `session delete`. — _Evidence:_ TUI/app.tsx:577-590; TUI/component/dialog-session-list.tsx:24-230; DOCS/cli (session)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /sessions (/resume,/continue, <leader>l) searchable picker with pins, rename, delete; `-s <id>`; `session list`. 2.0.31 (✅): /sessions + ctrl+o recent sessions & projects; `session list/delete/export/import` — _Evidence:_ V2D/cli/tui (Sessions); V2CLI: `opencode session --help`
- **OpenClaw 2026.9.8** ✅ — TUI picker lists up to 50 sessions from the last 7 days for the agent; `/session <key>` for older. — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/cli/sessions.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>SES-04</b> Auto session titles</summary>

- **sugar-crush** ✅ — `sessions.autoTitle` setting; `/rename --auto` or saving an empty name re-asks. Title model defaults to the main model (MOD-13). — _Evidence:_ `src/Session/TitleSource.php`; `src/Session/SessionStore.php:377-400`; `README.md:903-911`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/sessions.ts:107-110`
- **Claude Code 2.1.301** ✅ — Direct `-p` runs don't get titles. — _Evidence:_ cc:sessions#name-your-sessions
- **OpenCode 1.18.35** ✅ — `title()` runs on step 1 using the hidden `title` agent and small_model. Plugins such as opencode-smart-title (loaded here) can replace it. — _Evidence:_ OC/session/prompt.ts:1134; OC/agent/agent.ts:235-250; LOCAL: @tarquinen/opencode-smart-title in plugin list
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Hidden title agent (small model) names the session on the first step; `run --title`. 2.0.31 (✅): Title agent (model from small_model/agents.title.model); plugin `title` hook can supply the title — _Evidence:_ V2D/migrate-v1; V2D/build/plugins/index (Model requests: title)
- **OpenClaw 2026.9.8** ✅ — Retries once with primary model on failure; names worktree branches too. — _Evidence:_ `oc/docs/web/control-ui/chat.md`; `oc/docs/gateway/config-agents/models.md`

</details>
<details><summary><b>SES-05</b> Rename session</summary>

- **sugar-crush** ✅ — Refused in a read-only (locked elsewhere) window. — _Evidence:_ `builtin-commands/2700-rename.php`; `src/Cli/Subcommands.php:776-808`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/views/SessionView.vue:104-112`
- **Claude Code 2.1.301** ✅ — Names work as resume handles (cleaned, ≤200 chars). — _Evidence:_ cc:commands; cc:sessions
- **OpenCode 1.18.35** ✅ — Rename dialog. The title is also editable via the SDK. — _Evidence:_ TUI/config/keybind.ts:93; DOCS/server (PATCH /session/:id)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /rename or ctrl+r; PATCH /session/:id. 2.0.31 (✅): /rename; agent can rename via Code Mode `opencode.session_rename` — _Evidence:_ V2T slash list; V2D/tools (Sessions)
- **OpenClaw 2026.9.8** ✅ — `/name <title>`; click title in Control UI; `sessions` tool can set label/icon — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>SES-06</b> Fork / branch a session</summary>

- **sugar-crush** ✅ — `forkSession()` with SessionKind::Branch; picker nests branches under source. — _Evidence:_ `builtin-commands/2600-branch.php`; `builtin-commands/3000-fork.php`; `builtin-commands/3600-handoff.php`; `src/Session/EnhancedSessionStore.php:190`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `command.list` (fork, branch runsIn server); grep `src-web/`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:sessions#branch-a-session; cc:commands
- **OpenCode 1.18.35** ✅ — `dialog-fork-from-timeline` forks at any message. `--fork` with continue or session. — _Evidence:_ TUI/routes/session/index.tsx:540-560; TUI/routes/session/dialog-fork-from-timeline.tsx; DOCS/server
- **OpenCode 2.0.31** ✅ — `dialog-fork-from-timeline` forks at any message. `--fork` with continue or session. — _Evidence:_ TUI/routes/session/index.tsx:540-560; TUI/routes/session/dialog-fork-from-timeline.tsx; DOCS/server
- **OpenClaw 2026.9.8** ✅ — Fork keeps the local workspace; while running, forks from the last completed message. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>SES-07</b> Rewind conversation to an earlier message (with/without code restore)</summary>

- **sugar-crush** ✅ — File restore refused if HEAD moved; restores into the recorded work tree; excluded paths (vendor, .env, >2 MiB untracked…) never touched. — _Evidence:_ `README.md:948-981`; `src/Workspace/WorkspaceCheckpointer.php:268-330,719-764`
- **sugar-crush-web** 🟡 — After a built-in the store re-subscribes for a fresh snapshot. — _Evidence:_ `src-web/stores/session.ts:149-154`
- **Claude Code 2.1.301** ✅ — Bash-made changes, background-subagent edits and external edits aren't restored; 100 checkpoints/session. — _Evidence:_ cc:checkpointing
- **OpenCode 1.18.35** ✅ — /undo aborts a running turn, reverts files and messages back to the last user message, and puts that message's text and file attachments back in the input. /redo unreverts. Code restore needs a git project. Forking from the timeline is the conversation-only rewind. — _Evidence:_ TUI/routes/session/index.tsx:611-660; OC/snapshot/index.ts:167-168
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /undo reverts last turn's files (snapshots) and refills its prompt; /redo; timeline revert; fork = no code restore. 2.0.31 (✅): Snapshots scoped to the session's active directory; rollback rejected while running. — _Evidence:_ V2D/snapshots
- **OpenClaw 2026.9.8** ✅ — See CTX-10. — _Evidence:_ `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>SES-08</b> Export transcript (markdown / json / html)</summary>

- **sugar-crush** ✅ — Path jailed to the project root; Exporter::toMarkdown/Html/Json/Text. — _Evidence:_ `src/Commands/ShareCommand.php:54-160`; `src/Share/ShareSession.php:109-118`; `src/Cli/Subcommands.php:741-751`
- **sugar-crush-web** 🧪 — A browser user cannot download a transcript. — _Evidence:_ `sugar-crush/src/Protocol/Schema/MethodSchemas.php:177-180`; `sugar-crush/src/Host/Commands/ShareHostCommand.php`
- **Claude Code 2.1.301** 🟡 — No built-in Markdown or HTML export. — _Evidence:_ cc:sessions#export-and-locate-session-data
- **OpenCode 1.18.35** ✅ — `/export` (<leader>x) writes a Markdown transcript and opens $EDITOR. “Copy session transcript” goes to the clipboard. CLI `export [id]` emits JSON. `--sanitize` redacts sensitive transcript and file data. — _Evidence:_ DOCS/tui (export); TUI/routes/session/index.tsx:917-1013; CLI: `opencode export --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /export Markdown to $EDITOR, /copy transcript, `opencode export` JSON (--sanitize redacts). 2.0.31 (✅): /export with options (thinking, tools, sanitize, copy/export); `session export` JSON — _Evidence:_ V2T/ui/dialog-export-options.tsx:13-22; V2CLI: `opencode session --help`
- **OpenClaw 2026.9.8** ✅ — HTML export is owner-only and inside the workspace; Markdown keeps sender labels. — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`; `oc/docs/cli/transcripts.md`

</details>
<details><summary><b>SES-09</b> Share link (public URL)</summary>

- **sugar-crush** ❌ — Upload attempted only when `SUGARCRUSH_SHARE_UPLOAD_URL` is set, and then reports failure. `--help` still describes a "public default" upload host (docs drift, ISSUES). — _Evidence:_ `src/Share/ShareUploader.php`; `src/Commands/ShareCommand.php:87-97`; `lang/en.php:435-437`
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush/src/Host/Commands/ShareHostCommand.php`
- **Claude Code 2.1.301** 🟡 — Artifacts and `/team-onboarding` produce share links. — _Evidence:_ cc:claude-code-on-the-web#share-sessions; cc:artifacts
- **OpenCode 1.18.35** ✅ — The URL is copied to the clipboard. `share: "auto"` or `OPENCODE_AUTO_SHARE` shares every session. `run --share`. Enterprise can disable, SSO-restrict or self-host. — _Evidence:_ DOCS/share; OC/share/share-next.ts
- **OpenCode 2.0.31** ❌ — 1.18.35 had /share → opncd.ai/s/<id>. v2 has none; pairing links share a live server instead. — _Evidence:_ V2CL v2.0.30 (Remove the retired session-sharing backend)
- **OpenClaw 2026.9.8** ✅ — `/share/session?token=<opaque>` excludes tools, reasoning, files, images; best-effort credential redaction; new messages appear live. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md` (Share a session publicly); `oc/docs/plugins/session-share.md`

</details>
<details><summary><b>SES-10</b> Import session</summary>

- **sugar-crush** ❌ — Only MCP configs and memory notes have importers. — _Evidence:_ grep `importSession/importTranscript` → none
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:sessions; cc:claude-code-on-the-web
- **OpenCode 1.18.35** ✅ — Imports an exported JSON or a public share URL. — _Evidence:_ CLI: `opencode import --help`; DOCS/cli (import)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode import <file.json/share URL>`. 2.0.31 (✅): `opencode session import <file or URL>`; plus automatic V1 session import — _Evidence:_ V2CLI: `opencode session --help`; V2C/database/v1-migration.ts
- **OpenClaw 2026.9.8** ✅ — Adoption imports bounded visible history and resumes with `--fork-session`; Doctor imports legacy OpenClaw JSONL. — _Evidence:_ `oc/docs/nodes/session-catalogs.md`; `oc/docs/web/control-ui/settings.md` (Session sources)

</details>
<details><summary><b>SES-11</b> Search across past sessions</summary>

- **sugar-crush** 🟡 — `@session:<id>` mention can pull another session's conversation into a prompt. — _Evidence:_ `src/Session/SessionQuery.php:1-113`
- **sugar-crush-web** 🟡 — Also broken by the j/k hotkey while typing (ISSUES). — _Evidence:_ `src-web/components/SessionSidebar.vue:24-28,59-67`
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:sessions#use-the-session-picker
- **OpenCode 1.18.35** 🟡 — The CodeNomad desktop/web client (community) adds full-history search. — _Evidence:_ OC/session/session.ts:561,991-992; @neuralnomads/codenomad README
- **OpenCode 2.0.31** 🟡 — The CodeNomad desktop/web client (community) adds full-history search. — _Evidence:_ OC/session/session.ts:561,991-992; @neuralnomads/codenomad README
- **OpenClaw 2026.9.8** ✅ — Searches across configured agents within the caller's visible scope. — _Evidence:_ `oc/docs/concepts/session-search.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>SES-12</b> Multiple concurrent sessions in one UI (tabs)</summary>

- **sugar-crush** 🟡 — `cycleSessionTab()` swaps history wholesale via `switchToSession()`; refused while a turn is in flight. The web UI/serve can run several sessions at once. — _Evidence:_ `src/Chat.php:3527-3533,3645-3664,12114-12117`; `src/Tui/SessionTabs.php`
- **sugar-crush-web** ✅ — `client.viewing {narrate: true}` lets the server switch tiles between full and narration without resubscribing. Covered by e2e multi-session. — _Evidence:_ `src-web/stores/layout.ts:9-12,140-160`; `e2e/multi-session.spec.ts:20`
- **Claude Code 2.1.301** ✅ — Rows grouped by state (Needs input, Working, Completed) with Haiku summaries. — _Evidence:_ cc:agent-view; cc:desktop; cc:vs-code
- **OpenCode 1.18.35** 🟡 — Switching sessions in the TUI does not stop a running one, because sessions are server-owned. Quick-switch slots exist. Concurrent side-by-side views are in the web/desktop clients and community clients. — _Evidence:_ TUI/component/dialog-session-list.tsx:205; DOCS/web
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): TUI shows one session at a time but others keep running server-side; web/desktop have multi-project tabs. 2.0.31 (✅): Session tabs in the TUI (ctrl+tab, vertical/horizontal, unread status) and desktop (Option+↑/↓) — _Evidence:_ V2D/cli/tui (Sessions); V2D/cli/config (Tabs); V2CL v2.0.30
- **OpenClaw 2026.9.8** ✅ — TUI shows one session at a time with pickers. — _Evidence:_ `oc/docs/web/control-ui/chat.md` (Split view)

</details>
<details><summary><b>SES-13</b> Background / detached sessions</summary>

- **sugar-crush** ✅ — Results arrive as a user-role message (≤16k chars + stats line); index lives in the system temp dir (lost on reboot/temp clean); background sessions run in bypass-permissions and refuse every ASK. — _Evidence:_ `README.md:604-619`; `src/Sessions/BackgroundSupervisor.php`; `README.md:2197`
- **sugar-crush-web** ✅ — Daemons outlive the server and are re-adopted on restart (`bg.started adopted:true`). — _Evidence:_ `src-web/components/panels/BackgroundPanel.vue`; `sugar-crush/docs/SERVER.md:695-719`
- **Claude Code 2.1.301** ✅ — Survives sleep (not shutdown); each background session edits in its own worktree; `claude agents --json` for scripts. — _Evidence:_ help; cc:agent-view#how-background-sessions-are-hosted
- **OpenCode 1.18.35** ✅ — The TUI is just a client of an embedded server. With `opencode serve`, sessions keep running independently of any client. `POST /session/:id/prompt_async` returns 204 immediately. — _Evidence:_ DOCS/server (How it works, prompt_async); CLI: `opencode attach --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Sessions are owned by the server: `opencode serve` + attach/run --attach/prompt_async run detached from any client. 2.0.31 (✅): `opencode service start/stop/status`; `--standalone` for private server. Startup recovery resumes claimed top-level sessions with bounded attempts. — _Evidence:_ V2D/cli/index (Background service); specs/v2/session.md (Execution Is Process-Local, Recovery)
- **OpenClaw 2026.9.8** ✅ — Restart recovery replays interrupted turns (3-attempt budget). — _Evidence:_ `oc/docs/concepts/session.md` (Gateway restart recovery); `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>SES-14</b> Session handoff across devices (web ↔ CLI, teleport)</summary>

- **sugar-crush** 🟡 — Server reuses the same session store under HOME, so a TUI session on the server host is visible to the web UI; cross-machine needs `--allow-remote` + reverse proxy (no built-in TLS). — _Evidence:_ `src/Cli/Attach.php`; `src/Host/RemoteSessionHost.php`; `--help` serve/attach text
- **sugar-crush-web** 🟡 — `attach` runs slash commands locally on its own copy (documented gap). — _Evidence:_ `sugar-crush/docs/SERVER.md:857-910`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ help; cc:claude-code-on-the-web; cc:remote-control
- **OpenCode 1.18.35** 🟡 — `opencode attach http://host:4096` (basic auth) and `opencode import <share-url>` move work between devices. There is no cloud session handoff. — _Evidence:_ CLI: `opencode attach --help`; DOCS/web (Attaching a Terminal)
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): attach to remote server, share-URL import, TUI+web on one server; no one-click web↔CLI teleport. 2.0.31 (✅): `opencode pair` one-time links/QR for browser or iOS app; `pair --remote` via OpenTunnel E2E — _Evidence:_ V2D/cli/web; V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — "Continue in terminal" produces a handoff payload; Move session between Gateway, paired devices, cloud workers. — _Evidence:_ `oc/docs/concepts/session-attachment.md`; CLI: `openclaw resume --help`, `openclaw attach --help`

</details>
<details><summary><b>SES-15</b> Per-session cost/usage history</summary>

- **sugar-crush** 🟡 — `TokenTracker` is in-memory; `sessions` table has no cost column; `session list/show` print no cost; resuming an expensive session shows $0. `session_events` keeps usage events (capped) for server-hosted sessions. — _Evidence:_ `src/Util/TokenTracker.php:55-195`; `src/Host/SpendLedger.php:62-110`; `sqlite3` on real DB: 456 `usage.updated` events
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — Plan usage breakdown on subscriptions; prompt-cache statistics. — _Evidence:_ cc:costs#using-the-/usage-command
- **OpenCode 1.18.35** ✅ — Each assistant message stores cost and tokens. The session total is shown in the sidebar. — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx:17-44; CLI: `opencode stats --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Per-session cost/tokens in sidebar; `opencode stats` (days, models, tools, project). 2.0.31 (✅): Session + subagent family cost/tokens; `stats` with --cost/--tools/--models/--json — _Evidence:_ V2CL v2.0.28, v2.0.29; V2CLI: `opencode stats --help`
- **OpenClaw 2026.9.8** ✅ — Session-derived estimates plus provider-reported billing via Admin APIs. — _Evidence:_ `oc/docs/concepts/usage-tracking.md`

</details>
<details><summary><b>SES-16</b> Session locking (two clients on one session)</summary>

- **sugar-crush** ✅ — Read-only window refuses prompts and mutating commands, `/branch` forks out. Gap: `sugarcrush session delete` ignores the lock (ISSUES P3). — _Evidence:_ `src/Session/SessionLock.php:22-150`; `README.md:924-940`
- **sugar-crush-web** ✅ — First valid permission answer wins; e2e "two tabs on one session". — _Evidence:_ `sugar-crush/src/Protocol/SessionFeed.php:61,222`; `e2e/multi-client.spec.ts:3`
- **Claude Code 2.1.301** ✅ — Typed reply is saved and sent when the session next starts. — _Evidence:_ cc:agent-view#opening-a-session-says-the-conversation-is-already-open
- **OpenCode 1.18.35** 🟡 — A prompt sent while busy is persisted and the running loop picks it up as the new latest user message. Clients see the same state over SSE. There is no lease or ownership. — _Evidence:_ OC/session/prompt.ts:1082-1130
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): No explicit lock; many clients attach to one session via the server, which serializes runs (busy → queued). 2.0.31 (🟡): Process-local execution ownership with write-ahead claims; clients share the service; no cross-process lock — _Evidence:_ specs/v2/session.md (Execution Is Process-Local)
- **OpenClaw 2026.9.8** ✅ — Multi-user owner/participant model; ACP multi-client routing is best-effort. — _Evidence:_ `oc/docs/concepts/multi-user.md`; `oc/docs/cli/acp.md`

</details>
<details><summary><b>SES-17</b> Session retention / cleanup</summary>

- **sugar-crush** 🟡 — Prune SQL requires `name IS NULL OR name = ''`; the auto-titler fills `name` for every session with an exchange (title_source auto). Report counts the dead `messages` table (always 0). Pinned + sub-agent sessions exempt. — _Evidence:_ `src/Session/SessionStore.php:1107-1162`; `src/Cli/Bootstrap.php:9698-9735,9809-9827`; real DB: 12 auto-named + 33 named rows, all unprunable
- **sugar-crush-web** 🧪 — A web user cannot delete a session. — _Evidence:_ `src-web/stores/sessions.ts:90-93`
- **Claude Code 2.1.301** ✅ — `desktopSessionCleanupPeriodDays`; file-history snapshots swept too. — _Evidence:_ cc:claude-directory#cleaned-up-automatically; help (`purge`)
- **OpenCode 1.18.35** 🟡 — `time_archived` is supported (desktop archive). The 11.6 GB DB here shows nothing prunes sessions automatically. — _Evidence:_ OC/session/session.ts:115,562; OC/snapshot/index.ts:23,305
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Session archive + `session delete`; no auto-retention (snapshots gc 7 d, tool-output files swept after 7 d). 2.0.31 (🟡): Projects auto-archive when their directories vanish; sessions never auto-pruned; uninstall keeps session data — _Evidence:_ V2CL v2.0.27; V2D/cli/index (Uninstall)
- **OpenClaw 2026.9.8** ✅ — Daily/idle resets opt-in (`session.reset`); synthetic cron/hook/sub-agent rows disposable; pinned sessions protected. — _Evidence:_ `oc/docs/concepts/session.md` (Session maintenance)

</details>
<details><summary><b>SES-N1</b> 🆕 Incognito / ephemeral sessions (not persisted, no memory flush)</summary>

- **sugar-crush** ❌ — ArgvParser accepts only --prompt, --output-format, --root, --config, --model, --permission-mode, --continue and --resume, and no flag or command makes a session unsaved. The headless path wires the session store too. `memory.autoConsolidate` or `SUGARCRUSH_DISABLE_AUTO_MEMORY=1` turns auto-memory and the dream pass off for a launch. That covers only the "no memory flush" half; the transcript is still persisted and only pruned by session retention. — _Evidence:_ `src/Cli/ArgvParser.php:239-521`; `src/Cli/NonInteractive.php:274`; `src/Memory/AutoMemoryConsolidator.php:61-68`; `src/Config/Settings/Definitions/MemoryRuleSettings.php:98-106`
- **sugar-crush-web** ❌ — Nothing in `session.create` or the UI opts out of persistence or memory consolidation. — _Evidence:_ `src/Protocol/Schema/MethodSchemas.php`; `sugar-crush-web/src-web/stores/sessions.ts`
- **Claude Code 2.1.301** 🟡 — Auto memory can be disabled separately (`CLAUDE_CODE_DISABLE_AUTO_MEMORY`, `--bare`). — _Evidence:_ cc:sessions#where-transcripts-are-stored; help (`--no-session-persistence`)
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ CORE/database/database.ts:44-46; CORE/flag/flag.ts:47
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ packages/cli/src/database-path.ts; V2D/cli/index (Paths)
- **OpenClaw 2026.9.8** ✅ — Session entry, transcript and compaction state never touch disk; expire after 24 h or Gateway restart without archive; no automatic memory flush; content excluded from diagnostics, previews and payload logs; on multi-user Gateways visible only to admin connections. Tools can still write files. — _Evidence:_ `oc/docs/concepts/session.md` (Incognito sessions)

</details>

### AGT — Agents & sub-agents

<details><summary><b>AGT-01</b> Primary agent modes (build / plan / ask / ...)</summary>

- **sugar-crush** 🟡 — The session always runs as one main agent. There is no primary build/plan agent pair, and the main chat cannot run as a preset. The closest thing to a mode is `PermissionMode` (six cases). Plan mode restricts tools to read-only through the gate, and the agent leaves it with the `PlanExit` tool, which puts the plan to the user for approval. A preset's `permissionMode` applies only to delegated runs. — _Evidence:_ `src/Permissions/PermissionMode.php:13-18`; `src/Commands/KeyBindingRegistry.php:535` (Alt+M); `src/Tools/BuiltIn/PlanExitTool.php`
- **sugar-crush-web** 🟡 — Plan mode is a permission mode here, not an agent. — _Evidence:_ `src-web/components/StatusBar.vue:6`
- **Claude Code 2.1.301** ✅ — Plan accept lets you pick the follow-up mode; `/plan [task]`; plan re-injected after compaction. — _Evidence:_ cc:permission-modes
- **OpenCode 1.18.35** ✅ — build allows question and plan_enter. plan allows question and plan_exit, denies `task.general`, and denies edit except `.opencode/plans/*.md` and data/plans. `OPENCODE_EXPERIMENTAL_PLAN_MODE` swaps the classic read-only reminder for a 5-phase plan-file workflow with a plan_exit tool. — _Evidence:_ OC/agent/agent.ts:140-260; OC/session/reminders.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Primary: build (default), plan (edit-denied except plan files); hidden compaction/title/summary; experimental plan mode. 2.0.31 (✅): No experimental plan_exit flow; leaving plan = switching agent (a `leave` reminder is injected). — _Evidence:_ V2C/plugin/plan.ts:5-60; V2C/plugin/agent.ts:77-143
- **OpenClaw 2026.9.8** 🟡 — Closest to "plan": `read-only` permission mode (mutation tools omitted, exec denied). — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/tools/thinking.md`

</details>
<details><summary><b>AGT-02</b> Switch primary agent in UI (Tab, /agent)</summary>

- **sugar-crush** 🟡 — Alt+M is "toggle plan mode (between turns)". `/permissions mode <name>` switches to any mode, and the model is told. The Ctrl+P palette row labelled "Switch agent" dispatches `/agents`, which lists running delegations; it switches nothing (see ISSUES). — _Evidence:_ `lang/en.php:1159`; `src/Chat.php:17508`; `lang/en.php:844`
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush/src/Host/Commands/AgentsHostCommand.php`
- **Claude Code 2.1.301** 🟡 — `@<name>` shown in the startup header when `--agent` is active. — _Evidence:_ cc:interactive-mode; cc:sub-agents#invoke-subagents-explicitly
- **OpenCode 1.18.35** ✅ — The current agent (colored) is shown in the prompt bar. `default_agent` must be a visible primary agent; otherwise it falls back to build. — _Evidence:_ TUI/config/keybind.ts:130-131; DOCS/agents (Usage); OC/agent/agent.ts:328-342
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Tab / Shift+Tab cycle primary agents, <leader>a or /agents picker, `--agent`, `default_agent`. 2.0.31 (✅): Shift+Tab cycles agents (Tab no longer), <leader>a / /agents; a command `agent:` switches the session agent — _Evidence:_ V2D/cli/keybinds (agent.cycle shift+tab); V2D/commands (Execution)
- **OpenClaw 2026.9.8** ✅ — TUI Ctrl+G agent picker and `/agent <id>`; Control UI agent switcher; routing bindings per channel — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui/settings.md`

</details>
<details><summary><b>AGT-03</b> Built-in sub-agent types (explore, general, plan, ...)</summary>

- **sugar-crush** ✅ — `AgentDefinition` holds the six, registered between foreign imports and native presets. Their tool grants: coder Read/Edit/Bash/Skill (no Write, Grep or Glob); reviewer Read/Grep/`Bash(git *)`/Skill plus the php-best-practices and security-audit skills; debugger Read/Grep/Bash/Skill; architect Read/Grep/Glob/Skill; tester Read/Bash/Skill plus phpunit-master (no Write or Edit); devops Read/Bash/Glob/Skill. None lists `Task`, so the built-ins cannot nest. — _Evidence:_ `src/Agents/AgentDefinition.php:38-205`; `src/Cli/Bootstrap.php:2578`
- **sugar-crush-web** 🟡 — Roster from live `server.info`. — _Evidence:_ live probe
- **Claude Code 2.1.301** ✅ — Explore/Plan read-only, skip CLAUDE.md and git status; `CLAUDE_CODE_DISABLE_EXPLORE_PLAN_AGENTS`; observed catalog in this session. — _Evidence:_ cc:sub-agents#built-in-subagents; obs
- **OpenCode 1.18.35** ✅ — explore: `'*'` deny except grep, glob, list, bash, webfetch, websearch, read, with a read-only prompt and thoroughness levels. **Doc drift:** DOCS/agents and DOCS/cli (`OPENCODE_EXPERIMENTAL_SCOUT`) describe a `scout` subagent that exists neither in OC/agent/agent.ts at the tag nor in the installed binary. — _Evidence:_ OC/agent/agent.ts:183-218; DOCS/agents (Use scout); BIN: no 'clone a dependency repository' string
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Built-in subagents: general (full tools minus todo) and explore (read-only); 'scout' documented but absent in 1.18.35. 2.0.31 (✅): general (no subagents, no question) and explore (read/search/shell/web, external allowed); docs: no scout — _Evidence:_ V2C/plugin/agent.ts:87-118; V2D/agents (Builtins)
- **OpenClaw 2026.9.8** 🟡 — Role templates (coordinator, researcher, reviewer, writer) create full agents; internal sub-agents: Active Memory recall, exec reviewer, experience review. — _Evidence:_ `oc/docs/tools/subagents.md`; `oc/docs/cli/agents.md` (role templates)

</details>
<details><summary><b>AGT-04</b> Custom agent definitions (file format + locations)</summary>

- **sugar-crush** ✅ — `AgentPresetRegistry::list()` takes the first search path on a name conflict, and the project path comes first. Both tiers are anchored (no link out of the root or $HOME). A malformed file is skipped on its own, with a launch notice. The roster is foreign < built-in < native. Combined, a cloned repo's `.sugar-crush/agents/reviewer.md` replaces both your own reviewer and the built-in (confirmed by script; see ISSUES). — _Evidence:_ `src/Cli/Bootstrap.php:3017-3049`; `src/Agents/AgentPresetRegistry.php:208-262`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Recursive scan; file watcher (new dirs need restart); precedence managed > CLI > project > user > plugin. — _Evidence:_ cc:sub-agents#choose-the-subagent-scope; cc:sub-agents#write-subagent-files
- **OpenCode 1.18.35** ✅ — The file name becomes the agent name. `OPENCODE_CONFIG_DIR` adds another directory. This repo ships 13 agents in .opencode/agents/ (sugarcrush-orchestrator, -reviewer, -committer…). — _Evidence:_ DOCS/agents (Markdown); LOCAL: /home/sites/sugarcraft/.opencode/agents/
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Markdown w/ YAML frontmatter in .opencode/agents/, ~/.config/opencode/agents/ (or agent/), or JSON `agent` config. 2.0.31 (✅): .opencode/agents (also agent/, mode/, modes/), global dir, JSON `agents`; nested paths give IDs like team/reviewer — _Evidence:_ V2D/agents (Locations); V2D/migrate-v1 (Agent files)
- **OpenClaw 2026.9.8** ✅ — ACP agents via `runtime.type: "acp"`; created by CLI, Control UI or (with approval) by another agent. — _Evidence:_ `oc/docs/concepts/multi-agent.md`; `oc/docs/gateway/config-agents/entries-and-multi-agent.md`

</details>
<details><summary><b>AGT-05</b> Agent definition fields (model, tools, permissions, prompt, color, memory, ...)</summary>

- **sugar-crush** ✅ — Fields: name, description, tools, disallowedTools, model (`inherit`), permissionMode, maxTurns, skills, mcpServers, memory, background, effort, isolation, color, initialPrompt. The body is the prompt. Lists accept YAML or a comma string, natively. A foreign preset's permissionMode collapses to default. `effort` is honoured only on sglang (otherwise a note says it was ignored). `memory` and `color` are inert, and the launch says so (FrontmatterKeyAudit). `mcpServers` is only enforced when `tools:` is also declared (ISSUES). — _Evidence:_ `src/Agents/AgentPresetRegistry.php:534-552`; `src/Agents/Agent.php:283-319`; `src/Support/FrontmatterKeyAudit.php:56-107`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Also background, omitClaudeMd, color, initialPrompt, autoCompactWindow, experimental.cacheTtl; plugin agents ignore hooks/mcpServers/permissionMode; unknown fields logged with `--debug`. — _Evidence:_ cc:sub-agents#supported-frontmatter-fields
- **OpenCode 1.18.35** ✅ — Unknown keys pass through as provider options (e.g. reasoningEffort). `tools` is deprecated in favor of `permission`. `steps` caps the loop, after which MAX_STEPS_PROMPT forces a text summary. There are no memory, skills-preload or isolation fields. — _Evidence:_ OC/agent/agent.ts:270-290; DOCS/agents (Options); OC/session/prompt.ts:1281
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): description, mode, model, variant, prompt, temperature, top_p, steps, permission, color, hidden, disable, provider options. 2.0.31 (🟡): Docs warning: “The V2 session runner preserves these values but does not yet send them with model requests” — so per-agent temperature/top_p/provider options (V1 `temperature`, `top_p`, `options`) currently have no effect; configure on provider/model/variant instead. — _Evidence:_ V2D/agents (Request warning); V2D/migrate-v1 (Agents and modes)
- **OpenClaw 2026.9.8** ✅ — Also runtime (acp/cli), heartbeat, GitHub identity, exec node binding, delegationMode, allowAgents. — _Evidence:_ `oc/docs/gateway/config-agents/entries-and-multi-agent.md`; `oc/docs/tools/subagents/thread-bound-sessions.md`

</details>
<details><summary><b>AGT-06</b> Agents list UI (/agents) — HOW it is displayed</summary>

- **sugar-crush** 🟡 — With nothing running, `/agents` prints "No agents are working right now. 7 agent(s) registered and idle — use /agent <name> for details." (confirmed by script). Its unknown-name hint says "Use /agents to see available agents", which is circular. `/agent <name>` shows description, model, provider, status, skills and tools. It does not show source or path, permissionMode, maxTurns, isolation or background. The Agents pane, dashboard and strip show runs and background sessions only. No surface browses the roster. — _Evidence:_ `src/Commands/AgentsCommand.php:107-160`; `lang/en.php:843,851`; `src/Tui/Components/AgentDashboardPane.php:132-190`
- **sugar-crush-web** 🟡 — Also hangs under each Task card in the transcript. — _Evidence:_ `src-web/components/agents/AgentsPanel.vue`; `src-web/components/agents/SubAgentTree.vue`
- **Claude Code 2.1.301** 🟡 — Agents appear in the `@` typeahead (running ones with status), the subagent panel and `/tasks`; `claude agents` is agent view for background sessions, not definitions. — _Evidence:_ cc:commands; cc:sub-agents (quickstart note)
- **OpenCode 1.18.35** 🟡 — The `DialogAgent` fuzzy-select shows title=name and description = “native” for built-ins or the agent description. There is no view of subagents, their tools or their models. `opencode agent list` prints all agents from the CLI. — _Evidence:_ TUI/component/dialog-agent.tsx; TUI/app.tsx:680-690; CLI: `opencode agent --help`
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): /agents picker lists switchable primary agents (name + description/'native'); subagents only in @ menu; `agent list`. 2.0.31 (🟡): /agents picker; `opencode debug agents` lists all; subagents via @ and composer subagent picker — _Evidence:_ V2CLI: `opencode debug --help`; V2D/cli/keybinds (composer.subagent.*)
- **OpenClaw 2026.9.8** ✅ — `/agents` in chat lists thread-bound agents for the session. — _Evidence:_ `oc/docs/concepts/multi-agent.md`; CLI: `openclaw agents --help`

</details>
<details><summary><b>AGT-07</b> Agent creation wizard / AI-generated agent</summary>

- **sugar-crush** ❌ — No `/agents new` or generator exists. Presets are written by hand. — _Evidence:_ `builtin-commands/` (55 specs, none creates agents)
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `claude plugin init <name> --with agents` scaffolds plugin agents. — _Evidence:_ cc:sub-agents#quickstart-create-your-first-subagent; help (`plugin init`)
- **OpenCode 1.18.35** ✅ — Generation prompt in OC/agent/generate.txt. Unselected permissions are written as deny. — _Evidence:_ CLI: `opencode agent --help`; DOCS/agents (Create agents); OC/agent/generate.txt
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): `opencode agent create`: wizard asks scope + description, LLM generates name/prompt, pick allowed tools, writes .md. 2.0.31 (❌): `opencode agent create` wizard removed (no `agent` subcommand in v2 CLI) — _Evidence:_ V2CLI: `opencode --help` (no agent command)
- **OpenClaw 2026.9.8** ✅ — Agents can request new agents via the `openclaw` tool; creation waits for operator approval. — _Evidence:_ `oc/docs/web/control-ui.md`; `oc/docs/concepts/multi-agent.md` (Team preset)

</details>
<details><summary><b>AGT-08</b> Sub-agent spawn tool (Task / Agent)</summary>

- **sugar-crush** ✅ — The tool's guidance fragment, joined into the system prompt's tool-guidance layer, ends "Current agent roster: architect, coder, …" with names only. No agent description or tool list ever reaches the model, so a custom agent can only be chosen by its name. Unknown names are refused with the roster listed. Model resolution: call arg > preset > `subagentModel` > session. A model the provider cannot serve falls back with a `[model: …]` note. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:645-745,674-683,2480`; `src/Runtime.php:4770-4790`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/ToolCard.vue:38-42,75`
- **Claude Code 2.1.301** ✅ — Zero-tool configs fail with a named error; launching never prompts, the subagent's tool calls do. — _Evidence:_ cc:tools-reference#agent-tool-behavior
- **OpenCode 1.18.35** ✅ — Checks the depth guard and asks `task` permission for the subagent type (bypassed for @-mentions). Creates a child session titled “<desc> (@agent subagent)” with derived permissions. — _Evidence:_ OC/tool/task.ts:43-140; OC/tool/task.txt
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): task tool (description, prompt, subagent_type, task_id, command) runs a child session. 2.0.31 (✅): `subagent` tool (agent, description, prompt, optional model, sessionID resume, background) — _Evidence:_ V2C/tool/plugin/subagent.ts:17-66; V2D/tools (Subagent)
- **OpenClaw 2026.9.8** ✅ — Plus `subagents` (list/wait/cancel) and `sessions_yield`; default `runTimeoutSeconds` 900. — _Evidence:_ `oc/docs/tools/subagents/tool-reference.md`

</details>
<details><summary><b>AGT-09</b> Parallel sub-agents</summary>

- **sugar-crush** ✅ — Task is parallel-safe when it is bound to the engine. Each member is a forked process, and permission asks are relayed through the turn one at a time. Calls past a `subagentMaxConcurrent` cap wait on a "queued" row. Past a `subagentMaxActive` cap the call is refused, not queued. All `subagent*` keys are user-tier only. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:635-638`; `src/Runtime.php:2394,2495`; `src/Tools/ToolLimits.php:121,261`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/agents/tree.ts`
- **Claude Code 2.1.301** ✅ — Limit error tells Claude not to retry; `/subtask` forks take slots but aren't blocked. — _Evidence:_ cc:sub-agents#concurrent-subagent-limit
- **OpenCode 1.18.35** ✅ — AI-SDK parallel tool calls execute concurrently, each in its own child session. — _Evidence:_ OC/agent/agent.ts:184-185; OC/tool/task.ts
- **OpenCode 2.0.31** ✅ — AI-SDK parallel tool calls execute concurrently, each in its own child session. — _Evidence:_ OC/agent/agent.ts:184-185; OC/tool/task.ts
- **OpenClaw 2026.9.8** ✅ — Swarm collectors for scripted fan-out. — _Evidence:_ `oc/docs/tools/subagents/nesting.md`

</details>
<details><summary><b>AGT-10</b> Sub-agent isolated context window</summary>

- **sugar-crush** ✅ — The sub-agent prompt is the preset prompt, granted skill bodies and the skill listing, plus the task. The parent conversation is not shared. Context watch: `SUB_AGENT_WRAP_UP_PERCENT` 80, stop margin 10. — _Evidence:_ `src/Backend/EngineBackend.php:135-146`; `src/Agents/AgentManager.php:732-735`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Subagent window sized by its own model. — _Evidence:_ cc:sub-agents#what-loads-at-startup; cc:sub-agents#how-forks-differ-from-other-subagents
- **OpenCode 1.18.35** ✅ — The child runs the ordinary loop on `parentID`-linked session storage. — _Evidence:_ OC/tool/task.ts:140-225
- **OpenCode 2.0.31** ✅ — The child runs the ordinary loop on `parentID`-linked session storage. — _Evidence:_ OC/tool/task.ts:140-225
- **OpenClaw 2026.9.8** ✅ — Sub-agents are hard-denied gateway, cron, message, sessions_send, progress_card, etc. — _Evidence:_ `oc/docs/tools/subagents.md`; `oc/docs/tools/subagents/tool-policy.md`

</details>
<details><summary><b>AGT-11</b> Sub-agent results returned (summary only? transcript?)</summary>

- **sugar-crush** ✅ — `Context\DelegatedOutputFence`. A failure returns up to 12 KiB of tail. Spend counts toward the parent's cap. A finished run is stored as a child session named `<description> (@<agent>)`, and `session list --children` lists them. — _Evidence:_ `docs/AGENTS.md` "What comes back"; `src/Tools/BuiltIn/TaskTool.php:2380` (settle)
- **sugar-crush-web** ✅ — Strings arrive scrubbed of terminal escapes. — _Evidence:_ `src-web/components/agents/AgentView.vue:19-50`; `sugar-crush/docs/SERVER.md:723-734`
- **Claude Code 2.1.301** ✅ — Partial marking on maxTurns or cut-off; in auto mode `SubagentHandback` reports are classifier-reviewed. — _Evidence:_ cc:sub-agents#subagent-output-scanning; cc:tools-reference
- **OpenCode 1.18.35** ✅ — Errors become `<task_error>` / “Subagent failed (task_id…)”. The full child transcript stays navigable. — _Evidence:_ OC/tool/task.ts:64-79,346-353
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Only the child's final text returns, wrapped <task id state><task_result>; id enables resume. 2.0.31 (✅): Foreground returns final response + sessionID; background notifies parent on completion — _Evidence:_ V2C/tool/plugin/subagent.ts:19-27,52-60
- **OpenClaw 2026.9.8** ✅ — Empty/NO_REPLY results cannot satisfy completion ("(no output)" + recovery); results flow one level at a time. — _Evidence:_ `oc/docs/tools/subagents/announce.md`

</details>
<details><summary><b>AGT-12</b> Sub-agent progress visible live in UI</summary>

- **sugar-crush** ✅ — The live line is `└ ⠋ Grep … · 7 tools · 0:12 · 4.1K tok`. The strip (Alt+↓) lists live runs and keeps finished ones for 30 s. The dashboard pane takes c/r/s/q and Alt+1…9. Grandchild activity is relayed over the frame channel. Without pcntl the lines fill in only at turn end. — _Evidence:_ `src/Tui/AgentActivityLine.php`; `src/Tui/AgentStrip.php`; `src/Tui/Components/AgentDashboardPane.php`
- **sugar-crush-web** ✅ — Panel badge counts running runs. — _Evidence:_ `src-web/components/panels/SidePanels.vue:35-39`
- **Claude Code 2.1.301** ✅ — Finished rows clear; failed rows linger 30 s; `x` stops. — _Evidence:_ cc:sub-agents#observe-and-steer-running-forks; cc:statusline#subagent-status-lines
- **OpenCode 1.18.35** ✅ — The Task renderer syncs child messages and shows the current child tool, retry status, and on completion the tool count and duration. Child permission and question prompts bubble up to the parent view. — _Evidence:_ TUI/routes/session/index.tsx:200-240,2215-2300
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Task row shows subagent type, live '↳ <Tool> <title>' of child, tool count, duration; child footer 'Subagent N of M'. 2.0.31 (✅): Live child rows + composer subagent picker (↑/↓, Enter navigate, ctrl+d interrupt subagent) — _Evidence:_ V2D/cli/keybinds (composer.subagent.*, session.child.first)
- **OpenClaw 2026.9.8** ✅ — Control UI shows child transcripts live (view-only), unread child failures, running-tasks indicator; `/subagents log` — _Evidence:_ `oc/docs/web/control-ui.md`; `oc/docs/tools/subagents/slash-command.md`

</details>
<details><summary><b>AGT-13</b> Background sub-agents</summary>

- **sugar-crush** ✅ — The call returns `{"agent_id","status":"running"}`. Each turn carries an `<active-subagents>` row. The run uses the stricter of the daemon's and session's modes, and has no approver, so an ask is refused. It survives a TUI restart and is re-adopted. Only depth-0 calls may go to the background. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:940-1110`; `src/Sessions/BackgroundSupervisor.php`
- **sugar-crush-web** ✅ — Nested runs refuse (`agent_nested`). — _Evidence:_ `src-web/components/agents/AgentView.vue`
- **Claude Code 2.1.301** ✅ — Ctrl+B backgrounds a running task; bg subagents get a reduced tool set; their permission prompts surface in the main session. — _Evidence:_ cc:sub-agents#run-subagents-in-foreground-or-background
- **OpenCode 1.18.35** 🟡 — Without the flag the `background` parameter is hidden and rejected. With it: BACKGROUND_STARTED text (“DO NOT sleep, poll…”); ctrl+b (`session_background`) promotes running foreground tasks; completion injects `<task…state=completed><summary>Background task completed…` into the parent, starting a turn if it is idle. 🔌 opencode-background-agents offers an alternative. — _Evidence:_ OC/tool/task.ts:25-41,98-103,227-265,336; TUI/routes/session/index.tsx:218-230,1022-1033; OC/server/routes/instance/httpapi/handlers/experimental.ts:159-171
- **OpenCode 2.0.31** ✅ — 1.18.35 gated background behind OPENCODE_EXPERIMENTAL_BACKGROUND_SUBAGENTS. — _Evidence:_ V2C/tool/plugin/subagent.ts:19-27; V2D/commands (Background)
- **OpenClaw 2026.9.8** ✅ — `expectsCompletionMessage: false` = fire-and-forget. — _Evidence:_ `oc/docs/tools/subagents/slash-command.md`

</details>
<details><summary><b>AGT-14</b> Resume / continue a sub-agent</summary>

- **sugar-crush** ✅ — `SuspendedDelegations::MAX_AGE_SECONDS = 7*86400`. A resume gets a fresh step budget, and the model resumes with `Task {resume}`. A finished run can also be continued from the Agent View or with SendMessage. — _Evidence:_ `src/Agents/SuspendedDelegations.php:81`; `src/Tools/BuiltIn/TaskTool.php:2357`
- **sugar-crush-web** ✅ — Needs the engine backend; `not_resumable` otherwise. — _Evidence:_ `sugar-crush/docs/SERVER.md:736-749`
- **Claude Code 2.1.301** ✅ — Name-reuse guard; transcripts survive main compaction. — _Evidence:_ cc:sub-agents#resume-subagents
- **OpenCode 1.18.35** ✅ — Pass `task_id`; the child continues “instead of creating a fresh one”. — _Evidence:_ OC/tool/task.ts:49-54,134-140
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): task_id resumes the same child session with prior messages/tool outputs (works after success or failure). 2.0.31 (✅): Pass the returned sessionID to continue the same child conversation — _Evidence:_ V2C/tool/plugin/subagent.ts:38-42
- **OpenClaw 2026.9.8** ✅ — Visible persistent child sessions remain fully interactive. — _Evidence:_ `oc/docs/tools/subagents/tool-reference.md`; `oc/docs/concepts/subagent-yield-handoff.md`

</details>
<details><summary><b>AGT-15</b> Parent↔child messaging while running (SendMessage, mailboxes)</summary>

- **sugar-crush** ✅ — Per-run mailboxes live under `~/.sugar-crush/mailboxes/<session>/<agent>/` and are drained at step boundaries. User messages are HMAC-signed with a per-launch key, and agent messages are fenced as task direction that cannot approve anything. Messages flow hub-and-spoke only (never between siblings). All three tools are no-ask. — _Evidence:_ `src/Tools/BuiltIn/SendMessageTool.php:66`; `src/Tools/BuiltIn/SubagentsTool.php:41`; `src/Agents/Live/AgentInbox.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/agents/agents.ts:120-140`
- **Claude Code 2.1.301** ✅ — Delivered between tool calls; Ctrl+Enter delivers mid-work; agent messages never count as user approval. — _Evidence:_ cc:sub-agents; cc:cross-session-messaging
- **OpenCode 1.18.35** 🟡 — `background.extend` queues a prompt into a running child and returns BACKGROUND_UPDATED. There is no child→parent messaging before completion. The TUI hides the prompt input in child sessions, so the user cannot type into a child. — _Evidence:_ OC/tool/task.ts:36-41,267-282; TUI/routes/session/index.tsx:240
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Parent can push more context into a running background child via task_id (experimental); no mailbox/SendMessage. 2.0.31 (🟡): V1's experimental extend-a-running-background-task path is not documented for v2. — _Evidence:_ V2C/tool/plugin/subagent.ts:38-66; V2D/cli/keybinds (composer.subagent.interrupt)
- **OpenClaw 2026.9.8** ✅ — Inter-session messages carry provenance (`isUser=false`) and are treated as data. — _Evidence:_ `oc/docs/concepts/session-tool.md`; `oc/docs/tools/agent-send.md`

</details>
<details><summary><b>AGT-16</b> Agent teams / swarms / coordinators</summary>

- **sugar-crush** ✅ — `Bootstrap::agentManager()` wires `setTeamManager(new TeamManager())`, and the Team tool is offered on every launch. Teammates are background Tasks. Dependency cycles are refused. Dead-session claims are reclaimed. `max_teammates` is optional (null means no cap). — _Evidence:_ `src/Cli/Bootstrap.php:2463`; `src/Tools/BuiltIn/TeamTool.php:64,161,234`; `src/Agents/TaskList.php:181,302,402`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools (Team)
- **Claude Code 2.1.301** ✅ — Teams: lead + teammates (in-process or tmux/iTerm panes), shared task list, mailboxes, TeammateIdle/TaskCreated/TaskCompleted hooks; one team per session. Workflows: paid plans, 1,000 agents/run, resumable, savable as commands. — _Evidence:_ cc:agent-teams; cc:workflows
- **OpenCode 1.18.35** 🔌 — Core supports orchestration only via task and nesting. This repo builds a multi-agent pipeline from custom agents and commands (sugarcrush-orchestrator → phase-lead → reviewer → committer). — _Evidence:_ awesome-opencode (CrewBee, Oh My Opencode); DOCS/ecosystem; LOCAL: .opencode/agents, .opencode/commands/sugarcrush-build.md
- **OpenCode 2.0.31** ❓ — 1.18.35 (🔌): No native teams/swarms; 🔌 oh-my-openagent, opencode-workspace, CrewBee, subtask2 orchestration. 2.0.31 (❓): No native teams; V1 swarm plugins (oh-my-openagent pins @opencode-ai/plugin 1.18.31) don't run; v2 options unverified — _Evidence:_ npm view oh-my-openagent dependencies
- **OpenClaw 2026.9.8** ✅ — Team preset (coordinator + researcher/reviewer/writer), Swarm (experimental, Code Mode), `/think ultra` orchestration — _Evidence:_ `oc/docs/concepts/multi-agent.md`; `oc/docs/tools/swarm.md`; `oc/docs/concepts/parallel-specialist-lanes.md`

</details>
<details><summary><b>AGT-17</b> Nested sub-agents (sub-agent spawns sub-agent)</summary>

- **sugar-crush** ✅ — `TaskTool::MAX_DELEGATION_DEPTH = 3`. A nested Task is re-bound per child with `nestedFor()`. The six built-ins omit Task, so they never nest. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:259,1207-1216`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/agents/tree.ts`
- **Claude Code 2.1.301** ✅ — Parents wait for their background children before finishing. — _Evidence:_ cc:sub-agents#let-subagents-spawn-their-own-subagents
- **OpenCode 1.18.35** ✅ — The depth is counted by walking `parentID`. Error: “Subagent depth limit reached”. This host sets `subagent_depth: 4`. — _Evidence:_ OC/tool/task.ts:104-117; OC/agent/subagent-permissions.ts:14-28; DOCS/config (Subagent depth)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Nested subagents via `subagent_depth` (default 1 = none; children get task denied unless granted). 2.0.31 (✅): This host's `subagent_depth: 4` would be ignored on v2 unless moved under `experimental`. — _Evidence:_ V2C/tool/plugin/subagent.ts:129-133; V2D/migrate-v1 (Accepted but unsupported)
- **OpenClaw 2026.9.8** ✅ — Nested spawning to depth 5 by default (orchestrators vs leaves); cascade stop — _Evidence:_ `oc/docs/tools/subagents/nesting.md`

</details>
<details><summary><b>AGT-18</b> Worktree isolation for agents</summary>

- **sugar-crush** ✅ — The tree is created under `.sugar-crush/worktrees/<run id>/` on branch `agent-<id>-<time>`, with `.worktreeinclude` files copied in. `BashEscapeDenyHook` is registered. A clean tree is removed; a tree with work is kept and named in the result. A run that cannot be isolated is refused. — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:1253-1262,2111,2160`; `src/Cli/Bootstrap.php:9082`; `src/Agents/WorktreeManager.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Auto-cleaned if unchanged; based on default branch (`worktree.baseRef`). — _Evidence:_ cc:sub-agents#write-subagent-files; cc:worktrees
- **OpenCode 1.18.35** 🟡 — `/experimental/worktree` create/list/remove/reset “sandbox worktrees”. Workspace adapters (built-in `worktree`, plugins can register more). TUI `/workspaces`, `/warp`, `/move`. Subagents always share the parent's worktree. kdco opencode-worktree is installed in this repo's .opencode/plugins. — _Evidence:_ OC/worktree/index.ts; OC/control-plane/adapters/index.ts; OC/effect/runtime-flags.ts:50; LOCAL: .opencode/plugins/worktree.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Experimental workspaces/worktree API (OPENCODE_EXPERIMENTAL_WORKSPACES); no auto per-agent worktree (🔌 opencode-worktree). 2.0.31 (✅): Agents can create a worktree and move their session into it (worktree_remove needs edit approval). Not automatic per-subagent isolation. — _Evidence:_ V2D/tools (Worktrees, Sessions); V2C/tool/plugin/opencode.ts:106-190; V2CL v2.0.27
- **OpenClaw 2026.9.8** ✅ — `.worktreeinclude` + `.openclaw/worktree-setup.sh`; `openclaw worktrees` CLI; Control UI worktree toggle. — _Evidence:_ `oc/docs/concepts/managed-worktrees.md`

</details>
<details><summary><b>AGT-19</b> Import foreign agent definitions (e.g. .claude/agents read by another tool)</summary>

- **sugar-crush** 🟡 — `ForeignAgentPresetRegistry`: project beats user, and Claude beats opencode. The body becomes the prompt, and opencode `permission` rules collapse to tool lists. Gaps: `skills: a, b` becomes ONE bogus name, a `mcpServers:` mapping is kept as raw arrays, and `background: "false"` reads as true (all confirmed by script). Parse failures and `warnings()` go to `error_log()` only and never reach launch notices. Agents defined in opencode.json are not read. — _Evidence:_ `src/Agents/ForeignAgentPresetRegistry.php:284-311,442,498-529`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:commands; help (`import`)
- **OpenCode 1.18.35** ❌ — The only `.claude` reads in OC/ and CORE/ are ~/.claude/CLAUDE.md and the `.claude` skills dir. — _Evidence:_ `grep -rn '\.claude' OC/ CORE/` → instruction.ts:62, skill/index.ts:21
- **OpenCode 2.0.31** ❌ — The only `.claude` reads in OC/ and CORE/ are ~/.claude/CLAUDE.md and the `.claude` skills dir. — _Evidence:_ `grep -rn '\.claude' OC/ CORE/` → instruction.ts:62, skill/index.ts:21
- **OpenClaw 2026.9.8** 🟡 — Cursor `.cursor/agents` detected only; ACP harness can run Claude Code which keeps its own agents. — _Evidence:_ `oc/docs/plugins/bundles.md`; `oc/docs/install/migrating-claude.md`

</details>
<details><summary><b>AGT-20</b> @-mention an agent to invoke it</summary>

- **sugar-crush** ❌ — `ContextMentions` reserves only diff, session and http(s). There is no agent mention syntax. — _Evidence:_ `src/Attachments/ContextMentions.php:51-60`
- **sugar-crush-web** ❌ — — — _Evidence:_ `src-web/components/Composer.vue:34-43`
- **Claude Code 2.1.301** ✅ — Plugin agents by scoped name (`@agent-plugin:name`). — _Evidence:_ cc:sub-agents#invoke-subagents-explicitly
- **OpenCode 1.18.35** ✅ — The `@` autocomplete lists non-hidden, non-primary agents. The part becomes an agent part plus a synthetic instruction. If task permission denies it, the hint says “Invoked by user; guaranteed to exist.” — _Evidence:_ OC/session/prompt.ts:978-990,1223; TUI/component/prompt/autocomplete.tsx:402-415
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): @agent in prompt adds synthetic 'call the task tool with subagent X' (bypasses task deny; hidden agents excluded). 2.0.31 (✅): @agent mention adds an agent mention to the prompt (does not switch the active agent) — _Evidence:_ V2D/build/plugins/index (Prompt admission: agent mentions)
- **OpenClaw 2026.9.8** ❌ — Switch with `/agent` or ask the model to spawn with `agentId`. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md` (Command palette)

</details>
<details><summary><b>AGT-21</b> Navigate into a child agent's session view</summary>

- **sugar-crush** ✅ — It reads `~/.sugar-crush/subagents/<session>/` live and keeps a header pinned. The composer sends to that agent. Ctrl+G broadcasts to the batch. Finished runs open from the session picker (Ctrl+R, Tab). — _Evidence:_ `src/App/App.php:2598`; `src/Commands/KeyBindingRegistry.php:778-795`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/ToolCard.vue:44-46`
- **Claude Code 2.1.301** ✅ — Built-in commands still route to main (with confirm for /compact, /clear, /rewind). — _Evidence:_ cc:sub-agents#observe-and-steer-running-forks
- **OpenCode 1.18.35** ✅ — In a child: SubagentFooter (“Subagent N of M”, context %, cost) and no prompt input. Child permission asks appear in the parent. — _Evidence:_ TUI/config/keybind.ts:103-106; TUI/routes/session/index.tsx:1036-1080,1309-1311
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): <leader>↓ enters first child, ←/→ cycle siblings, ↑ to parent; clicking a Task row opens the child (read-only view). 2.0.31 (✅): Down opens the subagent picker in the composer; Enter navigates into the child session — _Evidence:_ V2D/cli/keybinds (session.child.first: down, composer.subagent.*)
- **OpenClaw 2026.9.8** ✅ — TUI `/session <key>`. — _Evidence:_ `oc/docs/tools/subagents.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>AGT-22</b> Multi-agent routing (different agents per channel / peer / workspace)</summary>

- **sugar-crush** ➖ — Not applicable to a single-user coding agent. — _Evidence:_ `docs/SERVER.md:768`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ❌ — Claude Tag (Slack, Team/Enterprise) is a separate product with channel-level memory and admin access. — _Evidence:_ cc:channels; cc:claude-tag
- **OpenCode 1.18.35** ➖ — Not a multi-channel assistant. — _Evidence:_ DOCS/config (Default agent)
- **OpenCode 2.0.31** ➖ — Not a multi-channel assistant. — _Evidence:_ DOCS/config (Default agent)
- **OpenClaw 2026.9.8** ✅ — Per-binding `session.groupScope`, identityLinks, access groups; `openclaw agents bind`. — _Evidence:_ `oc/docs/concepts/multi-agent.md`; `oc/docs/concepts/agent-bindings.md`; `oc/docs/channels/channel-routing.md`

</details>
<details><summary><b>AGT-N1</b> 🆕 Shared board / blackboard for a parallel batch of sub-agents (post/read results, asks, vetoes)</summary>

- **sugar-crush** ✅ — Two or more foreground Tasks in one message share a per-batch board. A member hears about posts addressed to it through `<shared-agent-board-notice>` on its next tool result (`BoardNoticeHook`). HOLD and VETO are advisory. The board is removed when the batch ends, and the main agent never sees it. — _Evidence:_ `src/Runtime.php:2394`; `src/Tools/BuiltIn/BoardPostTool.php`; `src/Hooks/BuiltIn/BoardNoticeHook.php`
- **sugar-crush-web** 🟡 — Runtime creates the per-batch board server-side, and BoardNoticeHook is on the shared chain. The main agent and the browser user never see the board as such; each member's posts are visible only as tool calls in that run's agent-view transcript. — _Evidence:_ `src/Runtime.php:2391-2396`; `src/Cli/Bootstrap.php:5992`; `sugar-crush-web/src-web/components/agents/AgentView.vue`
- **Claude Code 2.1.301** 🟡 — Workflows pass results between agents in script code rather than through a shared board. — _Evidence:_ cc:agent-teams; cc:workflows
- **OpenCode 1.18.35** ❌ — Children report only via their final task result. — _Evidence:_ OC/tool/task.ts
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2C/tool/plugin/subagent.ts
- **OpenClaw 2026.9.8** 🔌 — Agents get `workboard_list/read/create/link/claim/heartbeat/comment/complete/block/decompose/notify_*` tools and a Control UI Kanban; children see parent results in worker context. Not a per-batch blackboard with vetoes; native sub-agents are hub-and-spoke (siblings cannot message each other). — _Evidence:_ `oc/docs/plugins/workboard.md` (Agent tools); `oc/docs/tools/subagents/tool-policy.md`

</details>
<details><summary><b>AGT-N2</b> 🆕 Live run control of sub-agents (cancel / pause / resume / send to background from the UI)</summary>

- **sugar-crush** ✅ — Control lines are delivered through the HMAC-checked mailbox. Pause lasts at most 10 min, with a heartbeat to the parent watchdog. A second cancel within 3 s hard-stops: SIGTERM, then SIGKILL after 2 s. — _Evidence:_ `src/Commands/KeyBindingRegistry.php:787-793`; `docs/SERVER.md:721-760`
- **sugar-crush-web** ✅ — The buttons are shown only to clients with write scope. Background appears only for a run the conversation itself delegated (nested runs refuse with `agent_nested`). Messages go to the run's signed mailbox and show as "waiting for its next step" until read. — _Evidence:_ `sugar-crush-web/src-web/components/agents/AgentView.vue:71-115`; `sugar-crush-web/src-web/stores/agents/agents.ts:120-140`; `docs/SERVER.md:721-760`
- **Claude Code 2.1.301** ✅ — Open a running subagent's transcript and send follow-ups (Ctrl+Enter delivers mid-work); `/workflows` view pauses/resumes/saves runs; no pause for ordinary subagents. — _Evidence:_ cc:sub-agents#observe-and-steer-running-forks; cc:interactive-mode#keyboard-shortcuts; cc:commands (/workflows)
- **OpenCode 1.18.35** 🟡 — Background promotion needs OPENCODE_EXPERIMENTAL_BACKGROUND_SUBAGENTS. There is no per-child cancel or pause from the UI. — _Evidence:_ OC/session/run-state.ts; TUI/routes/session/index.tsx:1022-1033; OC/tool/task.ts:98
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2D/cli/keybinds (composer.subagent.*); V2C/tool/plugin/subagent.ts
- **OpenClaw 2026.9.8** 🟡 — Control UI Stop cancels the parent's sub-agents and Swarm collectors (and descendants); `/subagents list/info/log`; children are already background by default; persistent visible child sessions can be steered like any session. No pause control. — _Evidence:_ `oc/docs/web/control-ui/chat.md` (Stop); `oc/docs/tools/subagents/slash-command.md`; `oc/docs/tools/subagents/operations.md`

</details>
<details><summary><b>AGT-N3</b> 🆕 Cross-session messaging between independent top-level sessions (local or remote)</summary>

- **sugar-crush** 🟡 — SendMessage works hub-and-spoke inside one session: parent to its own sub-agents and back, "never a sibling, a grandchild or another session's run". `/bg`, `/fork` and `Task background:true` run separate daemon sessions. When one settles, its report arrives in the launching session as a user-role message ("[Background session <id> ('<name>') completed]" plus stats), queued behind any running turn. This is one-way and automatic. serve adds `bg.inject`: a client can send a settled background session's answer into any session as a prompt. There is no ListAgents-style discovery, no messaging between two foreground sessions, and nothing across machines. — _Evidence:_ `src/Tools/BuiltIn/SendMessageTool.php:37-45`; `docs/COMMANDS.md:435-442`; `src/Protocol/Methods/BgMethods.php:36-57`
- **sugar-crush-web** 🟡 — In serve, background results do not land in chat by themselves; `bg.inject` closes that gap manually and admits the report like a typed prompt (queued by default). Agents cannot message other sessions (SendMessage is intra-session). — _Evidence:_ `sugar-crush-web/src-web/components/panels/BackgroundPanel.vue:72`; `sugar-crush-web/src-web/stores/agents/background.ts:89-91`; `src/Protocol/Methods/BgMethods.php:36-57`
- **Claude Code 2.1.301** ✅ — Inbound policy accept/hold/refuse; can't approve anything. — _Evidence:_ cc:cross-session-messaging
- **OpenCode 1.18.35** ❌ — Only external scripts using the HTTP API/SDK can prompt another session. — _Evidence:_ DOCS/server
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2D/tools (Sessions)
- **OpenClaw 2026.9.8** ✅ — Session visibility rules apply; inter-session inputs carry provenance and are treated as data; `conversations_send/turn` reach channel conversations; the A2A plugin exposes an Agent Card and authenticated JSON-RPC tasks to remote agents. — _Evidence:_ `oc/docs/concepts/session-tool.md`; `oc/docs/tools/agent-send.md`; `oc/docs/channels/a2a.md`

</details>
<details><summary><b>AGT-N4</b> 🆕 Thread-bound sub-agent sessions (child gets its own chat thread / channel binding)</summary>

- **sugar-crush** 🟡 — The Agent View (a click, Enter, or `/agents <run>`) swaps in the run's live transcript under a pinned header. The composer then sends to that agent (an HMAC-signed mailbox, read at its next step boundary). Ctrl+G broadcasts to the batch, and Ctrl+X c/p/b cancel, pause or background the run. A finished Task run is stored as the child session `<description> (@<agent>)` with its whole conversation and listed under its parent in Ctrl+R; typing to it continues the run (resumable for 7 days). There are no external chat-channel threads, and no idle or max-age binding policy, because the product has no channels. — _Evidence:_ `docs/AGENTS.md:204-216`, `:256-262`; `src/App/App.php:2598`; `src/Agents/SuspendedDelegations.php`
- **sugar-crush-web** 🟡 — Clicking a tree row, including one under a Task card, opens that run's view in the side panel. Messaging a finished run continues it from its `resumeId` under the same preset. There are no channel threads. — _Evidence:_ `sugar-crush-web/src-web/components/agents/AgentView.vue:19-65`; `sugar-crush-web/src-web/components/ToolCard.vue:44-46`
- **Claude Code 2.1.301** 🟡 — Subagent/fork transcripts open in-place from the panel; channels deliver into one session, not per-subagent threads. — _Evidence:_ cc:agent-teams; cc:sub-agents#observe-and-steer-running-forks; cc:channels
- **OpenCode 1.18.35** 🟡 — Child sessions are viewable with <leader>↓, ←/→ and ↑. There is no Slack/Discord thread binding (the Slack sample is unpublished). — _Evidence:_ TUI/config/keybind.ts:103-106; packages/slack/README.md
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2D/agents (Modes); V2D/cli/keybinds
- **OpenClaw 2026.9.8** ✅ — Follow-up messages in that thread route to the child session while the parent conversation stays with the main agent; idle/max-age expiry (`/session idle`, `/session max-age`), `/session unbind`, per-channel `threadBindings`, auto-archive. Channels that cannot open threads reject it. — _Evidence:_ `oc/docs/tools/subagents/thread-bound-sessions.md`

</details>

### SKL — Skills

<details><summary><b>SKL-01</b> SKILL.md format support (frontmatter name/description)</summary>

- **sugar-crush** ✅ — A frontmatter block is required. Fields are typed through `SkillFrontmatter`, and a mistyped field skips that skill only. Bodies are scrubbed to valid UTF-8. Each file is capped at 1 MiB and its frontmatter at 64 KiB. Unknown or inert keys are named in one launch row. — _Evidence:_ `src/Skills/SkillFrontmatter.php`; `src/Skills/SkillFileReader.php:44,52`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Fields: name, description, when_to_use, argument-hint, arguments, disable-model-invocation, user-invocable, allowed-tools, disallowed-tools, model, effort, context, agent, background, hooks, paths, shell, metadata, license, compatibility. Unknown fields silently ignored; unparsable YAML loads with no fields. Uploads to claude.ai/Skills API accept only the six spec fields. — _Evidence:_ cc:skills#frontmatter-reference; cc:skills#using-skill-frontmatter-outside-claude-code
- **OpenCode 1.18.35** ✅ — Docs: name 1-64 chars matching `^[a-z0-9]+(-[a-z0-9]+)*$` and equal to the directory name; description 1-1024 chars. The loader only requires `name` to be a string. Skills without a description load but are not advertised. Duplicate names are reported. — _Evidence:_ DOCS/skills; OC/skill/index.ts:50-56,120-140,321-323
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): SKILL.md with name (dir-matching, kebab) + description; license/compatibility/metadata accepted, others ignored. 2.0.31 (✅): Accepts license/compatibility but ignores them; `disable-model-invocation`/`metadata.opencode/autoinvoke:false` hide from the model. — _Evidence:_ V2D/skills (Discovery, Frontmatter, IDs)
- **OpenClaw 2026.9.8** ✅ — YAML parsed first with single-line fallback; nested metadata re-parsed as JSON5; `{baseDir}` placeholder; legacy `metadata.clawdbot` honored. — _Evidence:_ `oc/docs/tools/skills.md` (SKILL.md format)

</details>
<details><summary><b>SKL-02</b> Discovery locations (user / project / plugin / bundled)</summary>

- **sugar-crush** ✅ — The user tier beats the project tier, and every shadowing is reported. Symlinks are followed but confined. The walk is capped at depth 7 and 2000 directories. — _Evidence:_ `src/Skills/SkillLoader.php:830-856`; `src/Skills/SkillOrigin.php:69-72`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Precedence enterprise > personal > project; plugin skills namespaced `/plugin:skill`; nested dupes exposed as `/apps/web:deploy`; claude.ai skills sync into `~/.claude/skills/synced/` (checked every ~10 min active / ~40 min idle); worktrees fall back to the main checkout's skills (v2.1.277+). Reserved names `synced`, `anthropic-skills`. — _Evidence:_ cc:skills#where-skills-live; cc:skills#how-synced-skills-behave
- **OpenCode 1.18.35** ✅ — Config directories are scanned with `{skill,skills}/**/SKILL.md`. Extra `skills.paths` directories are scanned with `**/SKILL.md`. `skills.urls` pull from a remote `index.json`, cached in ~/.cache/opencode/skills. The built-in `customize-opencode` is registered first so a disk skill can override it. — _Evidence:_ OC/skill/index.ts:20-34,175-230,272-286; OC/skill/discovery.ts:10-60
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): .opencode/skills (walk-up), ~/.config/opencode/skills, .claude/.agents skills, `skills.paths`, `skills.urls`, 1 built-in. 2.0.31 (✅): Precedence: built-ins < .claude < .agents < global opencode < project .opencode < explicit `skills` entries. — _Evidence:_ V2D/skills (Discovery, Sources, Precedence); V2C/plugin/skill.ts:31-41
- **OpenClaw 2026.9.8** ✅ — Execution-workspace `skills/` + `.agents/skills` appended; node-hosted skills from paired nodes; personal skill library on shared gateways (revisioned); grouped layouts up to 6 levels deep. — _Evidence:_ `oc/docs/tools/skills.md` (Loading order, Node-hosted skills)

</details>
<details><summary><b>SKL-03</b> Cross-tool locations read (.claude/skills, .agents/skills, .opencode/skills, ...)</summary>

- **sugar-crush** ✅ — Within a tier the order is spec < claude < opencode < native. Foreign skills are fully parsed at launch (no stage-1 manifest). The user tier is dropped when $HOME cannot be established. — _Evidence:_ `src/Skills/ForeignSkillDiscovery.php:21-140`; `src/Skills/SkillManager.php:109-161`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — `/import` copies Codex/Gemini/Cursor skills once; symlinking a shared folder into `.claude/skills/` works. — _Evidence:_ cc:skills#where-skills-live; cc:commands
- **OpenCode 1.18.35** ✅ — `OPENCODE_DISABLE_EXTERNAL_SKILLS` turns off both. This host has 14 global skills in ~/.config/opencode/skills and 8 in the repo's .opencode/skills. — _Evidence:_ OC/skill/index.ts:184-202; DOCS/skills; LOCAL: skills dirs
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Reads .claude/skills and .agents/skills (project walk-up to git root + home); OPENCODE_DISABLE_CLAUDE_CODE_SKILLS. 2.0.31 (✅): .claude/skills and .agents/skills (global + ancestor walk); CLAUDE env flags honored since 2.0.30 — _Evidence:_ V2D/skills; V2CL v2.0.30
- **OpenClaw 2026.9.8** 🟡 — Use `openclaw migrate claude` / `migrate codex` to copy them; Claude/Codex/Cursor/Agent-Plugins bundles map their skill roots. — _Evidence:_ `oc/docs/tools/skills.md`; `oc/docs/install/migrating-claude.md`

</details>
<details><summary><b>SKL-04</b> Progressive disclosure (listing in prompt, body loaded on use)</summary>

- **sugar-crush** ✅ — The listing sits under a preamble and a "MUST load" mandate, each line badged with its tier, escaped and clipped. `enabledSkills` (user config) bodies are standing system-prompt sections and are left out of the listing. `paths:` triggers `SkillPathNudge` inside tool results. Stage-3 `loadSkillAsset()` has no caller. — _Evidence:_ `src/Runtime.php:4706-4710`; `src/Skills/SkillListingSection.php:37-86`; `src/Cli/Bootstrap.php:5197`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `/context`
- **Claude Code 2.1.301** ✅ — Body is a single message that stays (no re-read); identical re-invoke adds an "already loaded" note; after compaction the latest invocation of each skill is re-attached (first 5K tokens, 25K total). `disable-model-invocation` keeps even the description out of context. — _Evidence:_ cc:skills#skill-content-lifecycle; cc:skills#control-who-invokes-a-skill
- **OpenCode 1.18.35** ✅ — The skill tool returns `<skill_content name>` containing the body, the base directory, “relative paths are relative to this base directory” and `<skill_files>` (ripgrep, limit 10). — _Evidence:_ OC/tool/skill.ts:34-66; OC/session/system.ts:107-119
- **OpenCode 2.0.31** ✅ — The skill tool returns `<skill_content name>` containing the body, the base directory, “relative paths are relative to this base directory” and `<skill_files>` (ripgrep, limit 10). — _Evidence:_ OC/tool/skill.ts:34-66; OC/session/system.ts:107-119
- **OpenClaw 2026.9.8** ✅ — Snapshot per session; budgeted list; small-context models get shortened descriptions. — _Evidence:_ `oc/docs/concepts/system-prompt.md` (Skills); `oc/docs/tools/skills.md` (Token impact)

</details>
<details><summary><b>SKL-05</b> Model auto-invocation (Skill tool)</summary>

- **sugar-crush** ✅ — The tool resolves the exact key, then a unique display name; an ambiguous name is refused. The result opens with a base-directory line. The tool is classed as read-only, so it never prompts. — _Evidence:_ `src/Tools/BuiltIn/SkillTool.php:214,226`; `src/Skills/SkillListingSection.php:86`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools (Skill)
- **Claude Code 2.1.301** ✅ — `disable-model-invocation: true` blocks and tells Claude not to reproduce steps; a `/name` typed after plain text grants permission without running it. — _Evidence:_ cc:skills#control-who-invokes-a-skill; cc:tools-reference
- **OpenCode 1.18.35** ✅ — `ctx.ask({permission:'skill', patterns:[name]})`. Skill outputs are protected from pruning. — _Evidence:_ OC/tool/skill.ts:27-32; OC/skill/index.ts:306-317; OC/session/compaction.ts:31
- **OpenCode 2.0.31** ✅ — `ctx.ask({permission:'skill', patterns:[name]})`. Skill outputs are protected from pruning. — _Evidence:_ OC/tool/skill.ts:27-32; OC/skill/index.ts:306-317; OC/session/compaction.ts:31
- **OpenClaw 2026.9.8** ✅ — Up to 8 explicit `$name` references per message; `disable-model-invocation: true` hides a skill from the model but keeps it user-invocable. — _Evidence:_ `oc/docs/tools/skills.md` (Reference a skill in a prompt)

</details>
<details><summary><b>SKL-06</b> User invocation (/skill-name)</summary>

- **sugar-crush** 🟡 — `SkillMentions` is resolved in `TurnController::userTurnMessage()`; disabled or non-user-invocable skills get a notice. Typing `/php-best-practices` (the Claude Code habit) sends the literal text to the model. The Ctrl+S picker says "Enabled skill 'x'." but changes only the shell App's state (ISSUES). — _Evidence:_ `src/Skills/SkillMentions.php:45-60`; `src/Host/TurnController.php:1004`; `src/App/App.php:2595,4377-4422`
- **sugar-crush-web** ❌ — `SessionHost::admit()`: "a `/word` that names none is prose". — _Evidence:_ `sugar-crush/src/Host/SessionHost.php:662-669`
- **Claude Code 2.1.301** ✅ — `user-invocable: false` hides from the menu; mid-prompt `/` completion; plugin skills match on bare name. — _Evidence:_ cc:skills#pass-arguments-to-skills; cc:commands
- **OpenCode 1.18.35** ✅ — Registered as `source:"skill"` commands with the base-directory note. — _Evidence:_ OC/command/index.ts:134-152
- **OpenCode 2.0.31** 🟡 — 1.18.35 registered every skill as a /command. v2 attaches selected skills to the prompt part list. — _Evidence:_ V2D/skills (Loading); V2T/component/prompt/autocomplete.tsx:427-436
- **OpenClaw 2026.9.8** ✅ — Names sanitized to a-z0-9_ (≤32, collisions suffixed); `command-dispatch: tool` runs a tool deterministically without the model. — _Evidence:_ `oc/docs/tools/slash-commands.md` (Skill commands)

</details>
<details><summary><b>SKL-07</b> Skills list UI — HOW shown (/skills, palette, panel)</summary>

- **sugar-crush** 🟡 — The pane shows the "enabled" list (the shell's own, which starts empty), or else the available names, dimmed. `/skills` and `/skills proposed` cover drafts only. The `/` popup lists no skills. Launch notices count skipped files, and `SUGARCRUSH_DEBUG_SKILLS=1` lists them. — _Evidence:_ `src/Tui/Components/SkillsPane.php:20-78`; `src/Host/Commands/SkillsHostCommand.php:38-59`; `src/Commands/KeyBindingRegistry.php:585`
- **sugar-crush-web** ❌ — Verified: "No skill drafts are waiting …". — _Evidence:_ live `command.exec name=skills`
- **Claude Code 2.1.301** ✅ — `/skill-doctor` (v2.1.252+, flag-gated) shows cost vs usage in the `/plugin` Stats tab; `/context` Skills row = post-budget listing size; synced skills grouped under `claude.ai sync`. — _Evidence:_ cc:commands; cc:skills#find-unused-skills; cc:skills#override-skill-visibility-from-settings
- **OpenCode 1.18.35** ✅ — A large DialogSelect titled “Skills”, placeholder “Search skills…”, data from `GET /app/skills`. There is no indication of which skills the model has loaded this session beyond the tool rows (“Loaded skill: X”). — _Evidence:_ TUI/component/dialog-skill.tsx; TUI/component/prompt/index.tsx:519-535; OC/tool/skill.ts:46
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /skills opens searchable dialog (name + description); selecting inserts '/skill '; `opencode debug skill`. 2.0.31 (✅): /skills dialog; skill loads render as dedicated timeline rows — _Evidence:_ V2T/component/dialog-skill.tsx; V2T/routes/session/index.tsx:1820
- **OpenClaw 2026.9.8** ✅ — `check` separates eligible / missing requirements / disabled / blocked; `/context detail` shows top skills by prompt size. — _Evidence:_ `oc/docs/tools/skills.md`; `oc/docs/cli/skills.md`

</details>
<details><summary><b>SKL-08</b> Bundled / built-in skills</summary>

- **sugar-crush** ✅ — All are PHP-centric. The monorepo's own skills sit in its project tier, not the built-ins. — _Evidence:_ `src/Skills/BuiltIn/`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Also /artifact-capabilities, /artifact-diagramming, /claude-in-chrome, /dataviz, /design, /design-sync, /fewer-permission-prompts, /run-skill-generator, /slides, /update-config, /workflow-authoring; plus bundled workflow `/deep-research`. `disableBundledSkills` turns them off. — _Evidence:_ cc:commands (Skill-marked rows); cc:skills#bundled-skills
- **OpenCode 1.18.35** 🟡 — Exists because “opencode hard-fails on invalid config”. It gives the model real schemas when it edits opencode config. — _Evidence:_ OC/skill/index.ts:26-34,272-286
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): One bundled skill: customize-opencode (config schemas, overridable by a same-name disk skill). 2.0.31 (🟡): Built-in skills: `OpenCode` (v2 docs index) and `Report` (file an issue); official Figma plugin ships 6 skills — _Evidence:_ V2C/plugin/skill.ts:31-41; V2C/plugin/skill/*.md; V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — `coding-agent` (opt-in) delegates to Claude Code, Codex or OpenCode as background workers; custodian skills (configure-channel, add-model-provider, diagnose-gateway, cloud-image-bake) only for the system agent. — _Evidence:_ `oc/skills/`; `oc/custodian-skills/`; `oc/docs/tools/custodian-skills.md`

</details>
<details><summary><b>SKL-09</b> Skill registry / marketplace (ClawHub, plugin marketplaces, skills.sh)</summary>

- **sugar-crush** ❌ — None.
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Third-party marketplaces via `claude plugin marketplace add`; Anthropic `pdf`/`xlsx` etc. always sync for claude.ai logins; `claude plugin eval` to measure. — _Evidence:_ local: claude-plugins-official marketplace.json; cc:plugins/overview; cc:skills#how-synced-skills-behave
- **OpenCode 1.18.35** 🟡 — A remote index lists skills and files, which are downloaded and cached. ocx (installed globally, registry `registry.kdco.dev` configured here) distributes profiles, plugins, agents and skills. — _Evidence:_ OC/skill/discovery.ts; LOCAL: ~/.config/opencode/ocx.jsonc; ocx package.json
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): `skills.urls` pulls skills from a remote index.json registry; no marketplace UI (🔌 ocx registry, skills CLI). 2.0.31 (🟡): HTTP catalogs via `skills` URLs (index.json + version-based cache refresh); no marketplace — _Evidence:_ V2D/skills (Catalogs)
- **OpenClaw 2026.9.8** ✅ — Installs from ClawHub, skills.sh refs (resolved via ClawHub, marked "Not scanned"), git, local; `clawhub` 0.23.3 publish/sync/scan/pin; `security.installPolicy` command gate. — _Evidence:_ `oc/docs/tools/skills.md` (Installing from ClawHub); `clawhub --help`

</details>
<details><summary><b>SKL-10</b> Skill-bundled scripts / resources</summary>

- **sugar-crush** 🟡 — Scripts and references are reached through ordinary tool calls, which go through the gate. The containment-checked stage-3 loader is not wired. — _Evidence:_ `src/Skills/SkillLoader.php` (loadSkillAsset, no production caller); `src/Tools/BuiltIn/SkillTool.php:226`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Same variables substituted inside `allowed-tools` Bash rules so bundled scripts run without prompts. — _Evidence:_ cc:skills#add-supporting-files; cc:skills#available-string-substitutions
- **OpenCode 1.18.35** ✅ — Skill directories are whitelisted in the agent default `external_directory` rules, so scripts and references can be read or run. — _Evidence:_ OC/agent/agent.ts:101-113; OC/tool/skill.ts:34-60
- **OpenCode 2.0.31** ✅ — Skill directories are whitelisted in the agent default `external_directory` rules, so scripts and references can be read or run. — _Evidence:_ OC/agent/agent.ts:101-113; OC/tool/skill.ts:34-60
- **OpenClaw 2026.9.8** ✅ — Managed bundles ≤256 files, 1 MiB/file, 8 MiB total; sandbox sync of supporting files. — _Evidence:_ `oc/docs/tools/skills.md` (Installer specs, Personal skills)

</details>
<details><summary><b>SKL-11</b> Skill-level tool restrictions (allowed-tools)</summary>

- **sugar-crush** ❌ — They are listed in `FrontmatterKeyAudit::INERT`, and no tool-scoping code reads them. — _Evidence:_ `src/Support/FrontmatterKeyAudit.php:95-101`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Grants clear at your next message; not gated by workspace trust (review repo skills); `allowManagedPermissionRulesOnly` ignores them (v2.1.282+). — _Evidence:_ cc:skills#pre-approve-tools-for-a-skill
- **OpenCode 1.18.35** ❌ — Unknown frontmatter fields are ignored (docs). — _Evidence:_ DOCS/skills (Write frontmatter)
- **OpenCode 2.0.31** ❌ — Unknown frontmatter fields are ignored (docs). — _Evidence:_ DOCS/skills (Write frontmatter)
- **OpenClaw 2026.9.8** ❌ — `command-dispatch: tool` binds a slash command to one tool, but skills cannot restrict the model's tool set. — _Evidence:_ `oc/docs/tools/skills.md` (Optional frontmatter keys)

</details>
<details><summary><b>SKL-12</b> Skill gating (requires bins / env / OS)</summary>

- **sugar-crush** ✅ — The binary check is a PATH stat walk with no subprocess. It runs once per launch. — _Evidence:_ `src/Skills/SkillRegistry.php:263`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — `shell: bash` fails the invocation on Windows without Git Bash. — _Evidence:_ cc:skills#frontmatter-reference
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ DOCS/skills
- **OpenCode 2.0.31** ❌ — 1.18.35 (❌): No requires-bins/env/OS gating (compatibility is free text). 2.0.31 (❌): No requirement gating; only `disable-model-invocation` / `autoinvoke:false` visibility control — _Evidence:_ V2D/skills (Frontmatter)
- **OpenClaw 2026.9.8** ✅ — Remote macOS node binaries can satisfy requirements; per-skill env/apiKey injected only for the host run. — _Evidence:_ `oc/docs/tools/skills.md` (Gating, Environment injection)

</details>
<details><summary><b>SKL-13</b> Hot reload of skills</summary>

- **sugar-crush** 🟡 — No watcher or reload exists. An accepted draft also takes effect from the next launch. — _Evidence:_ `src/Tools/BuiltIn/SkillTool.php:226`; `docs/SKILLS.md:659`
- **sugar-crush-web** ❓ — —
- **Claude Code 2.1.301** ✅ — SKILL.md text only; plugin-skill-folder components need `/reload-plugins`; not in bare mode. — _Evidence:_ cc:skills#live-change-detection; cc:commands
- **OpenCode 1.18.35** ❌ — Discovery lives in InstanceState. Only `PATCH /config` or `/instance/dispose` reload the instance; there is no file watcher on skill dirs. — _Evidence:_ OC/skill/index.ts:257-286; OC/server/routes/instance/httpapi/handlers/config.ts:20
- **OpenCode 2.0.31** ✅ — 1.18.35 (❌): Skills scanned once per instance; new/edited skills need restart (or instance reload via API config change). 2.0.31 (✅): Skill sources are watched; changes reload without restart — _Evidence:_ V2C/config/plugin/skill.ts (Watcher); V2D/plugins (Reload)
- **OpenClaw 2026.9.8** ✅ — Library revisions stay pinned until explicit refresh. — _Evidence:_ `oc/docs/tools/skills.md` (Snapshots and refresh)

</details>
<details><summary><b>SKL-14</b> Skill-creation helper</summary>

- **sugar-crush** 🟡 — Drafts are written to `~/.sugar-crush/skills-proposed/`. `accept` validates with the loader, and it is user-only (no tool calls it). — _Evidence:_ `src/Skills/ProposedSkills.php:240`; `src/Host/Commands/SkillsHostCommand.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Host/Commands/SkillsHostCommand.php`
- **Claude Code 2.1.301** ✅ — `claude plugin init <name>` scaffolds `~/.claude/skills/<name>/` as `<name>@skills-dir`; skill-creator runs evals/benchmarks. — _Evidence:_ help (`plugin init`); cc:skills#run-evals-with-skill-creator; obs (anthropic-skills:skill-creator listed)
- **OpenCode 1.18.35** 🟡 — Its description: “Also use when creating or fixing opencode agents, subagents, skills, plugins, MCP servers…”. — _Evidence:_ OC/skill/index.ts:31-34
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): No skill wizard; built-in customize-opencode skill guides the model when creating skills/agents/config. 2.0.31 (🟡): Built-in `OpenCode` skill indexes v2 docs (incl. skills/plugins) for the model; no wizard — _Evidence:_ V2C/plugin/skill/opencode.md
- **OpenClaw 2026.9.8** ✅ — Self-learning default `auto`: detached review after ≥10-iteration turns, immediate repair patches with scanner + rollback; `propose` mode for review-first. — _Evidence:_ `oc/docs/tools/skill-workshop.md`; `oc/docs/tools/self-learning.md`; `oc/skills/skill-creator/SKILL.md`

</details>
<details><summary><b>SKL-15</b> Skill run in forked / sub-agent context</summary>

- **sugar-crush** 🧪 — `isContextFork()` is consulted only by uncalled App methods and by the picker's status text. — _Evidence:_ `src/App/App.php:2213-2240,2409`; `src/Support/FrontmatterKeyAudit.php:100`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Not a conversation fork (no history); waits in `-p`/SDK. — _Evidence:_ cc:skills#run-skills-in-a-subagent
- **OpenCode 1.18.35** ❌ — Skill-as-command has no subtask flag. Custom commands can use `subtask: true` instead. — _Evidence:_ OC/command/index.ts:134-152
- **OpenCode 2.0.31** ❌ — Skill-as-command has no subtask flag. Custom commands can use `subtask: true` instead. — _Evidence:_ OC/command/index.ts:134-152
- **OpenClaw 2026.9.8** ❌ — The model may decide to delegate via `sessions_spawn`, but skills themselves can't request it. — _Evidence:_ `oc/docs/tools/skills.md`

</details>

### CMD — Slash commands / custom commands

<details><summary><b>CMD-01</b> Built-in slash commands (count + notable ones)</summary>

- **sugar-crush** ✅ — Full list: /new /new-picker /sessions /session-pin /session-delete /model /share /docs /exit(quit) /theme /settings(config) /agents(agent) /mcp /keys /help /permissions /notices /context(tokens) /sweep /pruning /compress /decompress /recompress /rules /newrule /pane /layout /pane-dock-left /pane-dock-right /editor /compact /clear /budget /workflow /memory /skills /init /branch /rename /rewind /undo /redo /diff /bg(background) /fork /websearch /goal /grind /btw /handoff /generate /generate-form /sendto /play /rendermode. Absent: /loop /schedule /hooks /review /status /cost (/budget instead) /plan (Alt+M instead). Dispatch is table-driven, and slash commands are refused mid-turn except /exit and /btw. — _Evidence:_ `src/Commands/Specs/BuiltInCommands.php:44-120`; `src/Chat.php:12747-12780`
- **sugar-crush-web** 🟡 — Server-run (verified): agents bg branch btw clear context decompress diff fork generate handoff mcp memory notices permissions play pruning recompress redo rename rendermode rewind rules sendto share skills sweep undo websearch workflow. — _Evidence:_ live `command.list`; `sugar-crush/src/Protocol/Methods/CommandMethods.php`
- **Claude Code 2.1.301** ✅ — Notable: /agents (reminder only), /autocompact, /background, /batch, /branch, /fork, /subtask, /code-review (+ultra), /security-review, /doctor, /effort, /fast, /insights, /mcp, /memory, /model, /plugin, /permissions, /remote-control, /schedule, /skills, /tasks, /teleport, /tui, /usage, /voice, /workflows. Some run immediately mid-turn (/status, /tasks, /usage, /model, /effort, /fast, /permissions). Availability varies by plan/provider. — _Evidence:_ cc:commands#all-commands
- **OpenCode 1.18.35** ✅ — TUI: sessions, new/clear, models, agents, mcps, variants, connect, org, status, debug, themes, help, exit, editor, skills, move, share, unshare, rename, timeline, fork, compact, undo, redo, timestamps, thinking, copy, export, diff; plus workspaces and warp (experimental). Server: init, review. Custom commands override built-ins of the same name. — _Evidence:_ TUI/app.tsx:577-833; TUI/component/prompt/index.tsx:427-553; TUI/routes/session/index.tsx:472-1013; TUI/feature-plugins/system/diff-viewer.tsx:1056-1068; OC/command/index.ts:46-88
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): ~33 built-in slash commands: /init /review /compact /undo /redo /fork /timeline /share /sessions /models /agents /diff.... 2.0.31 (✅): v2 slash list: agents, btw, cd, clear, compact, connect, copy, debug, diff, editor, exit, export, fork, help, highlights, mcps, models, new, open, pair, plugins, redo, reload, rename, restart, sessions, settings, skills, stats, status, terminal, themes, timeline, undo, update, variants, worktrees; server: init, review. — _Evidence:_ V2T (grep slash names); V2C/plugin/command.ts:28-55
- **OpenClaw 2026.9.8** ✅ — Plus /skill /learn /subagents /acp /config /mcp /plugins /debug /restart /update /tts /export-session /export-trajectory /diagnostics /openclaw; plugin commands (/dreaming /pair /voice /codex /active-memory); TUI-local /settings /question /gateway-status /auth /browser-setup. Directives (/think /fast /verbose /trace /reasoning /elevated /exec /model /queue) are stripped before the model sees the text. — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>CMD-02</b> Custom commands (markdown files, locations)</summary>

- **sugar-crush** ✅ — Merge order: built-in < user < project, by name. Only the 9 CONTROL_PLANE names are protected. A project file can replace /undo, /notices, /mcp and others with no notice (confirmed by script; ISSUES). Depth cap 4. — _Evidence:_ `src/Commands/CommandLoader.php:502-536`; `src/Commands/CommandRegistry.php:73`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/session.ts:140-158`
- **Claude Code 2.1.301** ✅ — Project, personal, enterprise, plugin; skill wins over a same-named command file; a local skill can replace a built-in command (not its aliases). — _Evidence:_ cc:skills; cc:skills#resolve-skills-that-share-a-name
- **OpenCode 1.18.35** ✅ — The file name becomes the command name. This repo ships 4 (review, sugarcrush-build, sugarcrush-feat-build, sugarcrush-feat-review). — _Evidence:_ DOCS/commands; LOCAL: .opencode/commands
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Markdown in .opencode/commands/ or ~/.config/opencode/commands/ (command/ legacy), or JSON `command` config. 2.0.31 (✅): Markdown in commands/ (nested paths → /team/review) or JSON `commands`; hot-reloaded — _Evidence:_ V2D/commands (Markdown, Loading)
- **OpenClaw 2026.9.8** 🟡 — `openclaw migrate claude` turns `.claude/commands/*.md` into skills with `disable-model-invocation: true`; Claude/Cursor bundle `commands/` load as skill roots; plugins use `registerCommand()`. — _Evidence:_ `oc/docs/install/migrating-claude.md`; `oc/docs/plugins/bundles.md`

</details>
<details><summary><b>CMD-03</b> Arguments ($ARGUMENTS, $1, ...)</summary>

- **sugar-crush** ✅ — An expansion that comes out empty is refused rather than sent as an empty turn. — _Evidence:_ `src/Commands/CommandSpec.php:116,522`; `src/Chat.php:11180-11190`
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** ✅ — `argument-hint` shown in autocomplete. — _Evidence:_ cc:skills#available-string-substitutions
- **OpenCode 1.18.35** ✅ — `hints()` extracts `$N` / `$ARGUMENTS` for display. — _Evidence:_ OC/command/index.ts:36-44; OC/session/prompt.ts:1390-1391; DOCS/commands (Arguments)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): $ARGUMENTS plus positional $1..$n; hints shown in autocomplete. 2.0.31 (✅): $ARGUMENTS, quoted $1..$n (last consumes rest), args appended when no placeholder — _Evidence:_ V2D/commands (Arguments, Positions, Fallback)
- **OpenClaw 2026.9.8** 🟡 — Tool dispatch receives `{ command, commandName, skillName }` (`command-arg-mode: raw`). — _Evidence:_ `oc/docs/tools/skills.md` (command-arg-mode)

</details>
<details><summary><b>CMD-04</b> Frontmatter (description, model, agent, allowed-tools)</summary>

- **sugar-crush** 🟡 — Display fields are sanitized (PaneLabel). Inert and unknown keys are named in a launch row. — _Evidence:_ `src/Commands/CommandSpec.php:288-352`; `src/Support/FrontmatterKeyAudit.php:66,102-105`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/Composer.vue:95-109`
- **Claude Code 2.1.301** ✅ — Command files accept all skill fields except `name` and `paths`. — _Evidence:_ cc:skills#frontmatter-reference
- **OpenCode 1.18.35** 🟡 — When `agent` is a subagent the command runs as a subtask unless `subtask:false`. After a subtask the loop appends “Summarize the task tool output above and continue with your task.” — _Evidence:_ OC/command/index.ts:22-32,90-103; OC/session/prompt.ts:1438-1452
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Frontmatter: description, agent, model, subtask; no allowed-tools/argument-hint fields. 2.0.31 (✅): Frontmatter: description, agent, model#variant, subagent (background child; `subtask` alias) — _Evidence:_ V2D/commands (Fields, Background)
- **OpenClaw 2026.9.8** 🟡 — Skill frontmatter: user-invocable, disable-model-invocation, command-dispatch/tool/arg-mode; no model/agent/allowed-tools — _Evidence:_ `oc/docs/tools/skills.md`

</details>
<details><summary><b>CMD-05</b> Shell interpolation in commands (!`cmd`)</summary>

- **sugar-crush** ✅ — Expansion is forked off the UI thread. An untrusted project form is replaced by a notice. A spent budget refuses later forms without consulting the gate. — _Evidence:_ `src/Commands/CommandSpec.php:143,155,522-600`; `src/Chat.php:11158`
- **sugar-crush-web** ✅ — — — _Evidence:_ `sugar-crush/src/Host/SessionHost.php:645-659`
- **Claude Code 2.1.301** ✅ — Never prompt: checked against permission rules (deny aborts; non-allow aborts outside auto mode); failure aborts the whole invocation; `disableSkillShellExecution`; not run for claude.ai-synced skills locally. — _Evidence:_ cc:skills#inject-dynamic-context
- **OpenCode 1.18.35** ✅ — `bashRegex = /!\`([^\`]+)\`/g`; commands run before the prompt is sent. — _Evidence:_ OC/session/prompt.ts:1395-1407,1592
- **OpenCode 2.0.31** ✅ — `bashRegex = /!\`([^\`]+)\`/g`; commands run before the prompt is sent. — _Evidence:_ OC/session/prompt.ts:1395-1407,1592
- **OpenClaw 2026.9.8** ❌ — No !`cmd` shell interpolation in command/skill bodies — _Evidence:_ `oc/docs/tools/skills.md`

</details>
<details><summary><b>CMD-06</b> File references in commands (@file)</summary>

- **sugar-crush** ✅ — Resolved by tier. `@file` mentions in the expansion are NOT re-read as user mentions. — _Evidence:_ `src/Commands/CommandSpec.php:879`; `src/Chat.php:11160-11175`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Synced skills get them as literal text; `CLAUDE_CODE_DISABLE_FILE_MENTIONS` sends paths instead (v2.1.299). — _Evidence:_ cc:skills#how-claude-code-handles-the-body-of-a-synced-skill; CL v2.1.299
- **OpenCode 1.18.35** ✅ — `resolvePromptParts(template)` turns @refs into file parts, deduped against user-attached files. — _Evidence:_ OC/session/prompt.ts:1431-1437; DOCS/commands (File references)
- **OpenCode 2.0.31** ❌ — 1.18.35 resolved @file in templates through the Read pipeline. — _Evidence:_ V2D/commands (Attachments)
- **OpenClaw 2026.9.8** ❌ — TUI `@` completion inserts paths into prompt text only. — _Evidence:_ `oc/dist/tui-sA-O8ceh.mjs` (createTuiAutocompleteProvider)

</details>
<details><summary><b>CMD-07</b> Autocomplete popup for /</summary>

- **sugar-crush** ✅ — `slashCommandRows()` merges both sources; a custom command overrides the same-named built-in. The popup shows name, description and hint but not the tier, so an overridden /undo looks unchanged. — _Evidence:_ `src/Chat.php:16624-16670`; `src/Renderer.php:5706`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/Composer.vue:34-43,60-72`
- **Claude Code 2.1.301** ✅ — Ghost-text completion with `+N` count outside fullscreen; plugin skills match by bare name. — _Evidence:_ cc:commands#how-the-command-menu-matches-what-you-type; cc:interactive-mode#complete-a-command-mid-prompt
- **OpenCode 1.18.35** ✅ — fuzzysort over display names and descriptions; tab completes; aliases shown. — _Evidence:_ TUI/component/prompt/autocomplete.tsx:92,448-510; TUI/keymap.tsx:272-286
- **OpenCode 2.0.31** ✅ — fuzzysort over display names and descriptions; tab completes; aliases shown. — _Evidence:_ TUI/component/prompt/autocomplete.tsx:92,448-510; TUI/keymap.tsx:272-286
- **OpenClaw 2026.9.8** ✅ — ACP advertises commands via `available_commands_update`. — _Evidence:_ `oc/docs/tools/slash-commands.md` (Surface notes); `oc/docs/cli/acp.md`

</details>
<details><summary><b>CMD-08</b> Command palette (Ctrl+P etc.)</summary>

- **sugar-crush** 🟡 — Root items are `PaletteAction` rows derived from the spec files that carry a palette label. — _Evidence:_ `src/Chat.php:17166-17174`; `src/Palette/PaletteAction.php:24-45`; `src/Commands/KeyBindingRegistry.php:491,584`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/panels/CommandPalette.vue:41-75,142-147`
- **Claude Code 2.1.301** 🟡 — Desktop/VS Code have their own command surfaces. — _Evidence:_ cc:interactive-mode#keyboard-shortcuts
- **OpenCode 1.18.35** ✅ — `command_list: ctrl+p`. Palette entries carry categories (Session, Agent, Prompt, VCS…). The which-key overlay (ctrl+alt+k) shows pending leader groups. — _Evidence:_ DOCS/keybinds; TUI/component/command-palette.tsx; TUI/feature-plugins/system/which-key.tsx
- **OpenCode 2.0.31** ✅ — `command_list: ctrl+p`. Palette entries carry categories (Session, Agent, Prompt, VCS…). The which-key overlay (ctrl+alt+k) shows pending leader groups. — _Evidence:_ DOCS/keybinds; TUI/component/command-palette.tsx; TUI/feature-plugins/system/which-key.tsx
- **OpenClaw 2026.9.8** ✅ — TUI pickers: Ctrl+L model, Ctrl+G agent, Ctrl+P session. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md` (Command palette); `oc/docs/web/tui.md`

</details>
<details><summary><b>CMD-09</b> MCP prompts exposed as commands</summary>

- **sugar-crush** ❌ — See MCP-07. — _Evidence:_ `docs/MCP.md`; `../sugar-mcp-client/src/StdioMcpServer.php:399`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Prompts from a server named `anthropic-skills` are hidden. — _Evidence:_ cc:mcp#use-mcp-prompts-as-commands
- **OpenCode 1.18.35** ✅ — `source:"mcp"`; the template is resolved lazily via `getPrompt`. — _Evidence:_ OC/command/index.ts:105-132
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): MCP prompts become slash commands (arguments mapped to $1..$n). 2.0.31 (✅): MCP prompts become `/<server>:<prompt>` commands (args positional); refreshed on prompts/list_changed — _Evidence:_ V2C/plugin/command.ts:16-80; V2D/mcp-servers (Names)
- **OpenClaw 2026.9.8** ❌ — MCP prompts are model-facing tools (`prompts_list`/`prompts_get`), not slash commands — _Evidence:_ `oc/docs/cli/mcp/registry.md`

</details>
<details><summary><b>CMD-10</b> Commands from plugins</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Manifest-defined commands; mods' `$.command.register`. — _Evidence:_ cc:plugins/components#commands; cc:plugins/mods/api#add-a-command
- **OpenCode 1.18.35** 🟡 — Plugins such as subtask2 and the goal plugin mutate `config.command` or intercept `command.execute.before` to rewrite parts. TUI plugins register palette and slash commands via `api.keymap.registerLayer`. — _Evidence:_ PLUG/index.ts:225,262-265; PLUG/tui.ts:87-120; opencode-goal-plugin README
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): No command manifest for plugins; plugins add commands via `config` hook or TUI keymap layers; command.execute.before. 2.0.31 (✅): Plugins register commands via `ctx.command.transform` (first-class) — _Evidence:_ V2D/build/plugins/index (Commands)
- **OpenClaw 2026.9.8** ✅ — Plugins register commands (`registerCommand`); bundled plugin commands; bundle command packs via skills — _Evidence:_ `oc/docs/tools/slash-commands.md` (Command list); `oc/docs/plugins/sdk-overview/tools-and-commands.md`

</details>
<details><summary><b>CMD-11</b> Bang-shell from the prompt (!cmd)</summary>

- **sugar-crush** ✅ — It holds the turn slot. Typed mid-turn, it waits in the queue until the turn ends. — _Evidence:_ `src/Chat.php:11096-11132`; `src/Commands/BangShell.php`
- **sugar-crush-web** 🟡 — The browser shows a red error and never the output until a re-snapshot (ISSUES P2). — _Evidence:_ live probe; `sugar-crush/src/Protocol/Methods/TurnMethods.php:104`
- **Claude Code 2.1.301** ✅ — History and path autocomplete; Ctrl+B backgrounding; runs outside the sandbox (except strict sandbox mode); pasted `!` enters shell mode. — _Evidence:_ cc:interactive-mode#shell-mode-with-prefix
- **OpenCode 1.18.35** ✅ — Shell mode via `!`. Recorded with a synthetic “The following tool was executed by the user”. Also `POST /session/:id/shell`. — _Evidence:_ OC/session/prompt.ts:451-597; DOCS/tui (Bash commands)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): !cmd from prompt runs a shell command; output stored as a user-executed tool result the model sees. 2.0.31 (✅): `!` shell mode; user shells run immediately as background work, output kept in the session — _Evidence:_ V2D/cli/tui (Shell); specs/v2/session.md
- **OpenClaw 2026.9.8** ✅ — Chat bash backgrounds after 2 s (`bashForegroundMs`), `!poll`/`!stop`; TUI local shell is fresh and non-interactive with `OPENCLAW_SHELL=tui-local`. — _Evidence:_ `oc/docs/web/tui.md` (Local shell commands); `oc/docs/tools/slash-commands.md`

</details>
<details><summary><b>CMD-12</b> Per-command keybindings</summary>

- **sugar-crush** ❌ — There is no keybindings file or config key. — _Evidence:_ `src/Commands/KeyBindingRegistry.php`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — Mods' Buttons can trigger keybinding actions. — _Evidence:_ cc:keybindings; cc:plugins/mods/reference
- **OpenCode 1.18.35** 🟡 — Bindings take strings, arrays or objects (`key`, `event`, `preventDefault`, `fallthrough`). Leader key with `leader_timeout`. — _Evidence:_ DOCS/keybinds; TUI/config/keybind.ts
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): ~150 built-in actions rebindable in tui.json; custom markdown commands have no keybind field. 2.0.31 (🟡): Keybinds in global cli.json; custom commands still have no keybind field — _Evidence:_ V2D/cli/keybinds
- **OpenClaw 2026.9.8** ❌ — Fixed TUI and Control UI shortcuts. — _Evidence:_ `oc/docs/web/tui.md` (Keyboard shortcuts)

</details>
<details><summary><b>CMD-N1</b> 🆕 Import foreign custom-command files (.claude/commands, .opencode/commands, etc.)</summary>

- **sugar-crush** ❌ — `CommandLoader` walks only the two `.sugar-crush/commands` trees. — _Evidence:_ `src/Commands/CommandLoader.php:565-574`
- **sugar-crush-web** ❌ — As SC (a2 CMD-X1). — _Evidence:_ `src/Commands/CommandLoader.php:565-574`; `sugar-crush-web/src-web/components/Composer.vue:34-43`
- **Claude Code 2.1.301** 🟡 — `claude import --dry-run` previews. — _Evidence:_ cc:skills; cc:commands (/import); help (`import`)
- **OpenCode 1.18.35** ❌ — Only .opencode/command(s) and the JSON `command` key are read. — _Evidence:_ OC/config/; DOCS/commands
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2D/commands (Markdown)
- **OpenClaw 2026.9.8** ✅ — Converted commands get `disable-model-invocation: true` (user-invoked only); overwrites back up the target skill. Project `.claude/commands` is not read live; no `.opencode/commands` import. — _Evidence:_ `oc/docs/install/migrating-claude.md`; `oc/docs/plugins/bundles.md`

</details>

### HK — Hooks

<details><summary><b>HK-01</b> Hook system exists</summary>

- **sugar-crush** ✅ — `HookManager` chain: built-ins, then hooks.yaml entries, then PermissionGateHook last. — _Evidence:_ `src/Hooks/HookManager.php:180-191`; `src/Cli/Bootstrap.php:5909`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Handlers run in parallel; identical handlers dedup across settings files; hooks also run inside subagents (with `agent_id`/`agent_type`). — _Evidence:_ cc:hooks; cc:plugins/mods/overview
- **OpenCode 1.18.35** 🟡 — No `hooks` key in config. A hook means writing a plugin module (local file or npm). — _Evidence:_ PLUG/index.ts:221-335; DOCS/plugins
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Hooks exist only as in-process JS/TS plugin hooks; no declarative shell-command hook config. 2.0.31 (🟡): V1 plugin implementations do not run in V2. — _Evidence:_ V2D/build/plugins/index; V2D/migrate-v1 (Plugins)
- **OpenClaw 2026.9.8** ✅ — "These are separate systems"; diagnostic events are a third, observe-only stream. — _Evidence:_ `oc/docs/automation/hooks.md`; `oc/docs/plugins/hooks.md`

</details>
<details><summary><b>HK-02</b> Events supported (list them)</summary>

- **sugar-crush** ✅ — All have production dispatch sites. UserPromptSubmit and SessionStart are TUI/serve only, not `-p` or background sessions. SessionEnd fires on TUI exit and successful `-p`. There is no Notification or PermissionRequest event; naming one in hooks.yaml stops the launch. — _Evidence:_ `src/Hooks/HookEvent.php:24-67`; `src/Runtime.php:3007,3274`; `src/Backend/EngineBackend.php:1549,1578,2277`; `src/Chat.php:6862,14269,14306`; `bin/sugarcrush:576`; `src/Cli/NonInteractive.php:434`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Full list: SessionStart, Setup, UserPromptSubmit, UserPromptExpansion, PreToolUse, PermissionRequest, PermissionDenied, PostToolUse, PostToolUseFailure, PostToolBatch, Notification, MessageDisplay, SubagentStart, SubagentStop, TaskCreated, TaskCompleted, Stop, StopFailure, TeammateIdle, InstructionsLoaded, ConfigChange, CwdChanged, DirectoryAdded, FileChanged, WorktreeCreate, WorktreeRemove, PreCompact, PostCompact, PreModelSwitch, PostModelSwitch, Elicitation, ElicitationResult, SessionEnd. — _Evidence:_ cc:hooks#hook-lifecycle
- **OpenCode 1.18.35** ✅ — Full set: event, config, tool, auth, provider, chat.message, chat.params, chat.headers, permission.ask (declared, see HK-05), command.execute.before, tool.execute.before/after, shell.env, experimental.chat.messages.transform, experimental.chat.system.transform, experimental.provider.small_model, experimental.session.compacting, experimental.compaction.autocontinue, experimental.text.complete, tool.definition, dispose. Bus events via `event`: session.*, message.*, permission.asked/replied, file.edited, file.watcher.updated, lsp.*, todo.updated, command.executed, tui.*, installation.updated, server.connected. — _Evidence:_ PLUG/index.ts:221-335; DOCS/plugins (Events)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Hooks: chat.message/params/headers, tool.execute.before/after, shell.env, command.execute.before, transforms, compaction, event. 2.0.31 (✅): Domain hooks: `ctx.session.hook(...)`, `ctx.permission.hook("evaluate")`, `ctx.shell.hook("create.before")`, `ctx.tool.hook("execute.before/after")`, `ctx.event.subscribe()`, plus transforms for agents, commands, tools, providers, models, skills, MCP, integrations, references, worktrees. No direct equivalents for experimental.compaction.autocontinue, experimental.provider.small_model, experimental.text.complete. — _Evidence:_ V2D/build/plugins/index (Hooks); V2D/build/plugins/migrate-v1 (mapping table)
- **OpenClaw 2026.9.8** ✅ — Plugin hooks include before_model_resolve, agent_turn_prepare, before_prompt_build, before_agent_run, before_agent_reply, before_agent_finalize, agent_end, llm_input/output, before/after_tool_call, resolve_exec_env, tool_result_persist, before_message_write, inbound_claim, message_received/sending/sent, reply_dispatch, session_start/end, before/after_compaction, before_reset, gateway_start/stop, cron_changed, before_install, skill_changed. — _Evidence:_ `oc/docs/automation/hooks/event-types.md`; `oc/docs/plugins/hooks/reference.md`

</details>
<details><summary><b>HK-03</b> Hook kinds (shell command / LLM prompt / HTTP / in-process JS/PHP plugin)</summary>

- **sugar-crush** 🟡 — Built-ins include protect-files, confirm-rm, audit, lint, diagnostics, auto-commit, auto-test, board-notice, sub-agent-grant and repeat-call guards. — _Evidence:_ `src/Hooks/ScriptHook.php`; `src/Hooks/BuiltIn/`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Plus mods (JS/TS functions in-process). Command hooks: exec or shell form, `shell: powershell`, `async: true` background. Defaults: 600 s command/http/mcp_tool, 30 s prompt, 60 s agent (30 s on UserPromptSubmit/model-switch). — _Evidence:_ cc:hooks#hook-handler-fields; cc:hooks#run-hooks-in-the-background
- **OpenCode 1.18.35** 🟡 — Plugin context gives `client` (SDK), `$` (Bun shell), project, directory and worktree. TUI plugins are a separate kind (Solid components). — _Evidence:_ DOCS/plugins (Basic structure); PLUG/index.ts:55-62
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): In-process JS/TS functions only (can shell out via Bun $); no shell/HTTP/LLM-prompt hook types. 2.0.31 (🟡): In-process Effect or Promise plugin code (server) or CLI plugins (TUI); no shell/HTTP/LLM-prompt hook kinds — _Evidence:_ V2D/build/plugins/index; V2D/build/plugins/effect
- **OpenClaw 2026.9.8** 🟡 — Internal hooks are "trusted code, not sandboxed scripts" running in the Gateway process. — _Evidence:_ `oc/docs/automation/hooks.md`; `oc/docs/automation/hooks/writing-hooks.md`

</details>
<details><summary><b>HK-04</b> Matchers (by tool name / pattern)</summary>

- **sugar-crush** ✅ — A bare `*` is refused at launch. Claude Code session-source matchers such as `startup` never match. — _Evidence:_ `src/Hooks/HookConfig.php:94-107`; `src/Hooks/HookRegistry.php:201-236`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Lists separated by commas or pipe characters; `if: "Bash(git *)"` checks each subcommand including `$()`; MCP tools `mcp__server__tool`. — _Evidence:_ cc:hooks#matcher-patterns; cc:hooks#common-fields
- **OpenCode 1.18.35** 🟡 — e.g. `if (input.tool === "bash")`. — _Evidence:_ DOCS/plugins (.env protection example)
- **OpenCode 2.0.31** 🟡 — e.g. `if (input.tool === "bash")`. — _Evidence:_ DOCS/plugins (.env protection example)
- **OpenClaw 2026.9.8** ✅ — Internal hooks subscribe to event families or exact names; no priority. — _Evidence:_ `oc/docs/plugins/hooks/reference.md`

</details>
<details><summary><b>HK-05</b> Blocking / decision control (deny, modify input, inject context)</summary>

- **sugar-crush** ✅ — A UserPromptSubmit deny discards the prompt. A PostToolUse deny withholds the output. A rewrite is re-gated. On turn and compaction events an ask counts as a deny. — _Evidence:_ `src/Hooks/ScriptHook.php:737-767,1297-1457`; `src/Chat.php:6903-6915`; `src/Runtime.php:3085,3289`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/docs/SERVER.md:575-578`; `src-web/components/PermissionCard.vue`
- **Claude Code 2.1.301** ✅ — Stop/SubagentStop block keeps Claude working (8-consecutive cap); PermissionRequest auto-answers; PermissionDenied `retry`; MessageDisplay rewrites display only; `defer` (print mode) pauses a tool for an external UI. — _Evidence:_ cc:hooks#decision-control; cc:hooks#defer-a-tool-call-for-later
- **OpenCode 1.18.35** ✅ — `tool.execute.before` mutates `output.args` or throws (the error is returned to the model). `tool.execute.after` rewrites title, output and metadata. `chat.message` edits parts. The transforms add system or message content. **`permission.ask` is declared in the Hooks type but never triggered in 1.18.35**: no `trigger("permission.ask")` anywhere in OC/ or CORE/, and the permission service has no plugin dependency. — _Evidence:_ PLUG/index.ts:261,266-281; `grep trigger(` over OC/ (11 hook names, none permission.ask); OC/permission/index.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): before-hooks can mutate args or throw to block; after-hooks rewrite output; transforms inject context. permission.ask unwired. 2.0.31 (✅): The V1 gap (permission.ask declared but never fired) is closed: v2 `evaluate` hooks run for allow and ask decisions; configured deny stays final. Plugins can also `ctx.permission.assert` to use native prompts. — _Evidence:_ V2D/build/plugins/index (Permissions); V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Permission gates `allowConversationAccess` and `allowPromptInjection` for non-bundled plugins. — _Evidence:_ `oc/docs/plugins/hooks.md`; `oc/docs/plugins/hooks/tool-policy.md`

</details>
<details><summary><b>HK-06</b> Config location / scopes</summary>

- **sugar-crush** 🟡 — The user file must be private and owned by you. Hook files are read once per launch. New project entries are quarantined when your own policy files changed since the last launch. — _Evidence:_ `src/Cli/Bootstrap.php:6071-6150,6271-6446,4568-4615`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Merge across levels; `allowManagedHooksOnly`, `allowedHttpHookUrls`, `httpHookAllowedEnvVars`; cloud sessions don't read `~/.claude/settings.json`. — _Evidence:_ cc:hooks#hook-locations
- **OpenCode 1.18.35** ✅ — Load order: global config npm list, then project npm list, then global plugin dir, then project plugin dir. Hooks run sequentially in that order. Local plugin dependencies come from `package.json` in the config dir, installed with `bun install`. `--pure` / `OPENCODE_PURE` skip external plugins. — _Evidence:_ DOCS/plugins (Load order, Dependencies); OC/plugin/index.ts:284-297
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): .opencode/plugins, ~/.config/opencode/plugins, npm `plugin` list in global/project config; fixed load order. 2.0.31 (✅): `plugins` array (packages, git, paths, options objects; `-id`/wildcards disable) + .opencode/plugins discovery — _Evidence:_ V2D/plugins
- **OpenClaw 2026.9.8** ✅ — Global registry; `--agent` only selects the workspace to inspect; hybrid hot reload. — _Evidence:_ `oc/docs/automation/hooks/configuration.md`

</details>
<details><summary><b>HK-07</b> Claude-Code-compatible hook config</summary>

- **sugar-crush** ❌ — stdin is closed immediately and the payload is in `CRUSH_TOOL_NAME/TOOL_INPUT/...` env vars. A Claude Code `hooks` block pasted into hooks.yaml is refused (unknown nested key). Only the exit-0 JSON envelope and exit-2-on-Pre/Stop/Prompt carry over. — _Evidence:_ `src/Hooks/ScriptHook.php:598-623,681`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:hooks
- **OpenCode 1.18.35** ❌ — No `.claude/settings.json` parsing. — _Evidence:_ `grep '\.claude'` (see AGT-19); awesome-opencode (Froggy, Oh My Opencode)
- **OpenCode 2.0.31** 🔌 — 1.18.35 (❌): Claude Code settings.json hooks not read (🔌 opencode-froggy / oh-my-openagent offer CC-style hook layers). 2.0.31 (🔌): CC settings.json hooks not read natively; 🔌 opencode-froggy 1.4.2 (v2 plugin API) adds Claude-Code-style hooks — _Evidence:_ npm view opencode-froggy dependencies (@opencode/plugin ^2.0.5); awesome-opencode (Froggy)
- **OpenClaw 2026.9.8** ❌ — "OpenClaw refuses to execute hooks ... automatically." — _Evidence:_ `oc/docs/plugins/bundles.md`; `oc/docs/install/migrating-claude.md`

</details>
<details><summary><b>HK-08</b> Hook output surfaced in UI</summary>

- **sugar-crush** 🟡 — Exit-0 stdout (up to 10 KB) is appended to the tool result for the model. Stop feedback becomes the next prompt. — _Evidence:_ `src/Hooks/HookManager.php:529-540`; `src/Cli/NonInteractive.php:445-452`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/ToolCard.vue:60`
- **Claude Code 2.1.301** ✅ — `additionalContext` becomes a system-reminder invisible in chat; `terminalSequence` OSC notifications; `--include-hook-events` in stream-json; 10K-char cap with file spill. — _Evidence:_ cc:hooks#exit-code-output; cc:hooks#json-output; cc:hooks#add-context-for-claude
- **OpenCode 1.18.35** 🟡 — Also `/tui/show-toast` and `client.app.log` for structured logs. — _Evidence:_ DOCS/plugins (Logging); DOCS/server (TUI show-toast)
- **OpenCode 2.0.31** 🟡 — Also `/tui/show-toast` and `client.app.log` for structured logs. — _Evidence:_ DOCS/plugins (Logging); DOCS/server (TUI show-toast)
- **OpenClaw 2026.9.8** 🟡 — No general hook-output panel. — _Evidence:_ `oc/docs/automation/hooks/writing-hooks.md` (Reply delivery); `oc/docs/tools/slash-commands.md` (/trace)

</details>
<details><summary><b>HK-09</b> Hook timeout / failure handling</summary>

- **sugar-crush** ✅ — Hooks run in the forked turn child, so the TUI does not freeze when pcntl is present. — _Evidence:_ `src/Hooks/ScriptHook.php:704-732`; `src/Hooks/HookRegistry.php:387-389`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Timed-out PreToolUse hooks don't block unless onFailure; stop-hook continuation cap; cwd fallback when dir deleted. — _Evidence:_ cc:hooks#timeouts; cc:hooks#block-the-action-when-a-hook-fails; CL v2.1.295
- **OpenCode 1.18.35** 🟡 — `Plugin.trigger` loops over hooks with `Effect.promise(async () => fn(input, output))`. A hung hook stalls the step and a rejection propagates. — _Evidence:_ OC/plugin/index.ts:284-297
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Hooks awaited sequentially with no timeout; a throw aborts that tool call/request. 2.0.31 (🟡): Rejected Promise hooks/tools now become normal tool failures attributed to their call — _Evidence:_ V2CL v2.0.28, v2.0.29
- **OpenClaw 2026.9.8** ✅ — Internal hooks: no timeout; sequential; thrown errors logged and later handlers continue. — _Evidence:_ `oc/docs/plugins/hooks/reference.md`; `oc/docs/automation/hooks.md` (Best practices)

</details>
<details><summary><b>HK-10</b> Hooks management UI (/hooks)</summary>

- **sugar-crush** ❌ — Not among the 55 spec files. — _Evidence:_ `builtin-commands/`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — File watcher applies edits live; `disableAllHooks`; 🔌 `hookify` creates hooks from markdown rules. — _Evidence:_ cc:hooks#the-/hooks-menu; cc:commands (/update-config)
- **OpenCode 1.18.35** 🟡 — Palette entries “Plugins” and “Install plugin” (shift+i), with `plugins.toggle` on space. — _Evidence:_ TUI/feature-plugins/system/plugins.tsx:205-255
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): TUI Plugins dialog toggles/installs plugins; no per-hook management UI. 2.0.31 (🟡): /plugins dialog; `opencode plugin list/check/update/remove` — _Evidence:_ V2CLI: `opencode plugin --help`
- **OpenClaw 2026.9.8** 🟡 — No `/hooks` chat command or dedicated hooks page. — _Evidence:_ CLI: `openclaw hooks --help`; `oc/docs/cli/hooks.md`

</details>

### PLG — Plugins & extensibility

<details><summary><b>PLG-01</b> Plugin system exists (format)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Also a skill folder with a manifest loads as `<name>@skills-dir`; `--plugin-dir` / `--plugin-url` session-only loading (.zip ok). — _Evidence:_ cc:plugins/overview; help (`--plugin-dir`, `--plugin-url`)
- **OpenCode 1.18.35** ✅ — Server plugin: an async function `(ctx) => Hooks`. TUI plugin: slots (app, app_bottom, home_logo, home_prompt(_right), home_bottom, home_footer, session_prompt(_right), sidebar_title/content/footer), routes, keymap layers, dialogs, toasts, attention sounds and themes. The built-in sidebar and diff viewer are themselves feature-plugins. — _Evidence:_ PLUG/index.ts; PLUG/tui.ts:455-486,600-634; TUI/feature-plugins/
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Server plugins (JS/TS modules returning hooks) + TUI plugins (tui.json, OpenTUI/Solid slots, routes, keymaps). 2.0.31 (✅): Effect/Promise server plugins (setup, transforms, hooks, RPC contracts) + CLI plugins in cli.json; V1 plugins don't run — _Evidence:_ V2D/build/plugins/index; V2D/build/plugins/cli; V2D/plugins (Terminal)
- **OpenClaw 2026.9.8** ✅ — Third-party capability consent; bundled vs official external vs community; 162 plugin reference pages. — _Evidence:_ `oc/docs/plugins/architecture.md`; `oc/docs/plugins/bundles.md`; `oc/docs/plugins/manifest.md`

</details>
<details><summary><b>PLG-02</b> Plugin marketplace / install command</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Official marketplace auto-added on first interactive run; scopes user/project/local; `plugin details` shows token cost; `prune`, `tag`, `validate`, `eval`, `configure`; org allowlists/force-install. — _Evidence:_ help (`plugin`, `plugin marketplace`, `plugin install`); cc:plugins/install; cc:plugins/org
- **OpenCode 1.18.35** ✅ — Unpinned plugins are re-resolved from npm on startup and cached under ~/.cache/opencode/packages. No curated marketplace: the ecosystem page, awesome-opencode and opencode.cafe, plus the ocx extension manager with registries. — _Evidence:_ CLI: `opencode plugin --help`; DOCS/plugins (How plugins are installed); LOCAL: ~/.cache/opencode/packages
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode plugin <npm> [-g]` installs + edits config; TUI plugin manager (shift+i); npm auto-install via Bun. 2.0.31 (✅): `opencode plugin add` from npm or git (incl. private/subdir), `check`/`update`; background install at startup — _Evidence:_ V2D/plugins (Manage, Reload)
- **OpenClaw 2026.9.8** ✅ — `/plugins install` from chat (owner); provenance `--force` for non-ClawHub sources; installs apply to the running Gateway without restart. — _Evidence:_ CLI: `openclaw plugins --help`; `oc/docs/cli/plugins/marketplace.md`; `oc/docs/tools/slash-commands.md` (/plugins)

</details>
<details><summary><b>PLG-03</b> What plugins can add (commands, agents, skills, hooks, MCP, tools, themes, providers)</summary>

- **sugar-crush** ➖ — —
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Plus userConfig options and dependencies; plugin agents can't set hooks/mcpServers/permissionMode. — _Evidence:_ cc:plugins/components; cc:plugins/dependencies
- **OpenCode 1.18.35** ✅ — Plus workspace adapters (`registerAdapter`) and `shell.env` injection. A plugin tool with a built-in's name overrides it. — _Evidence:_ PLUG/index.ts:221-231; OC/control-plane/adapters/index.ts:36-41; DOCS/plugins
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Tools, hooks, auth methods, provider model lists, config mutation (agents/commands/MCP), TUI routes/slots/themes/sounds. 2.0.31 (✅): Transforms for providers, models, agents, commands, skills, tools, MCP, integrations, references, worktrees; hooks — _Evidence:_ V2D/build/plugins/index (API)
- **OpenClaw 2026.9.8** ✅ — Also gateway HTTP routes, CLI backends, MCP and LSP servers (bundles), web search providers. — _Evidence:_ `oc/docs/tools/index.md`; `oc/docs/plugins/sdk-overview/capabilities.md`

</details>
<details><summary><b>PLG-04</b> Custom tools defined in code</summary>

- **sugar-crush** 🟡 — The tool catalog is a fixed attribute scan of `src/Tools/BuiltIn`. `Chat::registerTool()` has no caller in `bin/`. — _Evidence:_ `src/Backend/EngineBackend.php:741`; `src/Chat.php:10546`; `src/Tools/Catalog/ToolCatalog.php:49-51`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Mod tools can opt out of deferral (`isDeferred:false`, v2.1.293). — _Evidence:_ cc:plugins/mods/api#add-a-tool; cc:agent-sdk/custom-tools; CL v2.1.293
- **OpenCode 1.18.35** ✅ — File tools are named `<file>` or `<file>_<export>`. The execute context includes directory and worktree. — _Evidence:_ OC/tool/registry.ts:183-203; DOCS/custom-tools
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): .opencode/tools/*.ts (or tool/) and plugin `tool` map using tool() + zod schema; override built-ins. 2.0.31 (✅): Tools via `ctx.tool.transform(...)`; may enforce permissions with `ctx.permission.assert` — _Evidence:_ V2D/build/plugins/index (Tools); V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Tools join normal policy, sandbox and Code Mode surfaces. — _Evidence:_ `oc/docs/plugins/tool-plugins.md`; `oc/docs/plugins/sdk-entrypoints/define-tool-plugin.md`

</details>
<details><summary><b>PLG-05</b> Programmatic SDK</summary>

- **sugar-crush** 🟡 — The protocol schema is generated (`scripts/gen-protocol-schema.php`). — _Evidence:_ `docs/ARCHITECTURE.md:208`; `README.md:2179-2218`
- **sugar-crush-web** 🟡 — `generated.ts` (1,586 lines) is produced from the PHP-generated JSON Schema. — _Evidence:_ `src-web/protocol/client.ts`; `src-web/protocol/generated.ts`
- **Claude Code 2.1.301** ✅ — Built on the CLI; TS v2 preview; session storage adapters. — _Evidence:_ cc:agent-sdk/overview; cc:agent-sdk/typescript; cc:agent-sdk/python
- **OpenCode 1.18.35** ✅ — `createOpencode()` starts server plus client. Types are generated from OpenAPI. — _Evidence:_ DOCS/sdk; awesome-opencode (Official Repositories)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): @opencode-ai/sdk (JS/TS, createOpencode/createOpencodeClient); official Go and Python SDKs. 2.0.31 (✅): @opencode/sdk embeds the whole server in-process (incl. Cloudflare Durable Objects); @opencode/client for network — _Evidence:_ V2D/build/sdk/index; V2D/build/sdk/cloudflare; V2D/build/client/index
- **OpenClaw 2026.9.8** ✅ — Gateway WS protocol documented for client authors. — _Evidence:_ `oc/docs/plugins/sdk-overview.md`; `oc/docs/gateway/protocol.md`

</details>
<details><summary><b>PLG-06</b> Agent Client Protocol (ACP) support</summary>

- **sugar-crush** ✅ — Same SessionHub and hooks as serve. `session/new` ignores the editor's mcpServers. There is no `available_commands_update`. — _Evidence:_ `src/Cli/Subcommands.php:100`; `src/Acp/AcpServer.php:72,293-362`; `docs/SERVER.md:912-990`
- **sugar-crush-web** ➖ — — — _Evidence:_ `sugar-crush/docs/SERVER.md:912-992`
- **Claude Code 2.1.301** ❌ — Not mentioned anywhere in the docs; editor integrations are the VS Code/JetBrains extensions and `/ide`. (A third-party ACP adapter for Zed exists outside Anthropic's docs — not verified here.) — _Evidence:_ grep of all 228 cc: pages for "Agent Client Protocol"/"ACP" (no hits)
- **OpenCode 1.18.35** ✅ — Supports tools, MCP, AGENTS.md, agents and permissions. Permission asks map to ACP permission requests. — _Evidence:_ DOCS/acp; OC/acp/permission.ts; CLI: `opencode acp --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode acp` (JSON-RPC stdio) for Zed, JetBrains, Avante.nvim, CodeCompanion; /undo /redo unsupported. 2.0.31 (✅): `opencode acp` (stdio JSON-RPC) with child sessions, questions, compaction, usage; `--login` — _Evidence:_ V2CLI: `opencode acp --help`; V2D/cli/acp
- **OpenClaw 2026.9.8** ✅ — `/acp spawn`, `sessions_spawn runtime:"acp"`; ACPX is an official plugin. — _Evidence:_ `oc/docs/cli/acp.md`; `oc/docs/tools/acp-agents.md`

</details>
<details><summary><b>PLG-07</b> Headless server / HTTP API</summary>

- **sugar-crush** ✅ — Token or cookie auth, an allowed-IPs list, and TLS behind a proxy. — _Evidence:_ `src/Cli/Subcommands.php:98-99`; `docs/SERVER.md:18-300`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/protocol/client.ts`
- **Claude Code 2.1.301** 🟡 — Remote Control relays through Anthropic's API (outbound only); routines expose per-routine HTTP trigger endpoints in the cloud; `claude gateway` is an enterprise auth/telemetry gateway, not a session API. — _Evidence:_ cc:headless; cc:remote-control#connection-and-security; cc:routines; help
- **OpenCode 1.18.35** ✅ — Endpoints for sessions (fork, abort, share, revert, summarize, prompt_async, command, shell, permissions), files and find, config, providers, MCP, agents, the TUI control channel, and experimental tools and worktrees. — _Evidence:_ DOCS/server
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode serve`: OpenAPI 3.1 HTTP API (/doc) + SSE; TUI itself is a client of this server. 2.0.31 (✅): New v2 HTTP API (breaking) served by the background service or `serve`; `opencode api` client — _Evidence:_ V2D/migrate-v1 (Server API and clients); services/www/openapi.json (142 operations)
- **OpenClaw 2026.9.8** ✅ — Gateway WS RPC (scoped), OpenAI-compatible `/v1/chat/completions`, OpenResponses, tools-invoke HTTP, admin HTTP RPC — _Evidence:_ `oc/docs/gateway/openai-http-api.md`; `oc/docs/gateway/openresponses-http-api.md`; `oc/docs/gateway/tools-invoke-http-api.md`

</details>
<details><summary><b>PLG-08</b> Event bus / subscription API</summary>

- **sugar-crush** ✅ — `subagent.*`, `bg.completed`, `assistant.*` and others, with gap-free seq numbers per session. — _Evidence:_ `docs/SERVER.md:462-512`; `src/Events/`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/protocol/cursor.ts`; `src-web/stores/connection.ts:98-114`
- **Claude Code 2.1.301** ✅ — Mods events cover tool calls, prompts, turns, rendering, agent spawns, model requests. — _Evidence:_ cc:hooks; cc:plugins/mods/events; cc:monitoring-usage
- **OpenCode 1.18.35** ✅ — The first SSE event is `server.connected`. — _Evidence:_ DOCS/server (Events); PLUG/index.ts:224
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /event and /global/event SSE streams; plugin `event` hook receives every bus event. 2.0.31 (✅): Durable session event log with replay (`sessions.log`) + live instance event stream; `ctx.event.subscribe` — _Evidence:_ specs/v2/session.md (Durable Events); specs/v2/event-stream-architecture.md
- **OpenClaw 2026.9.8** ✅ — Gateway WS event streams (chat, tool events, sessions, presence) + typed plugin hooks + diagnostic events — _Evidence:_ `oc/docs/gateway/protocol/rpc-bootstrap-and-events.md`; `oc/docs/logging.md`

</details>
<details><summary><b>PLG-09</b> Custom providers via plugin</summary>

- **sugar-crush** 🟡 — This is a command-backend contract, not a plugin. — _Evidence:_ `README.md:1948`; `docs/ENVIRONMENT.md` (BACKEND_CMD rows)
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ❌ — Mods can alter requests (e.g. send one request to a different model) but not add non-Claude providers. — _Evidence:_ cc:llm-gateway-protocol#api-formats; cc:plugins/mods/overview
- **OpenCode 1.18.35** ✅ — Built-in auth plugins for azure, cerebras, cloudflare, digitalocean, github-copilot, modal, openai, snowflake and xai are disabled by `OPENCODE_DISABLE_DEFAULT_PLUGINS`. — _Evidence:_ OC/plugin/*.ts; PLUG/index.ts:88-219; DOCS/ecosystem
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Plugin `auth`/`provider` hooks (e.g. gemini/antigravity auth); any AI-SDK npm package as custom provider. 2.0.31 (✅): `ctx.provider/model/integration.transform` add providers, models and auth methods — _Evidence:_ V2D/build/plugins/index (Providers, Models, Integrations)
- **OpenClaw 2026.9.8** ✅ — `openclaw models auth login --provider <id>` runs plugin-shipped flows. — _Evidence:_ `oc/docs/plugins/sdk-provider-plugins.md`; `oc/docs/concepts/oauth.md`

</details>
<details><summary><b>PLG-10</b> Notable ecosystem plugins (names)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Also commit-commands, pr-review-toolkit, claude-md-management, remember, ralph-loop, serena, context7, exa, playwright, github, telegram/discord/imessage, explanatory/learning-output-style, session-report, skill-creator, mcp-server-dev, 13 LSP plugins. — _Evidence:_ local: claude-plugins-official marketplace.json; local: installed_plugins.json
- **OpenCode 1.18.35** ✅ — Installed or configured here: @tarquinen/opencode-dcp, opencode-agent-memory, opencode-agent-skills, opencode-handoff, @tarquinen/opencode-smart-title, opencode-conductor-plugin, @spoons-and-mirrors/subtask2, @plannotator/opencode, @zenobius/opencode-skillful, opencode-pty, @gotgenes/opencode-agent-identity, @franlol/opencode-md-table-formatter, cc-safety-net, oh-my-openagent (cached), kdco worktree/notify/background-agents (local). Global npm: opencode-goal-plugin 0.10.0, opencode-pty 0.3.6, ocx 2.0.15, CodeNomad 0.20.1 (needs v2). — _Evidence:_ LOCAL: ~/.config/opencode/opencode.jsonc; ~/.cache/opencode/packages; repo .opencode/plugins; global npm package.jsons
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): DCP, oh-my-openagent, opencode-pty, goal plugin, agent-memory, supermemory, subtask2, plannotator, worktree, wakatime…. 2.0.31 (🟡): npm deps: v2-only opencode-agent-memory 0.3.0 (@opencode/plugin ^2.0.12, needs v2.0.12+), opencode-froggy 1.4.2; dual v1+v2 @plannotator/opencode 0.28.22, opencode-supermemory 2.0.17; V1-only @tarquinen/opencode-dcp 3.2.0, opencode-pty 0.4.0, subtask2 0.3.5, oh-my-openagent 5.1.34, smart-title, skillful, handoff, conductor, agent-skills, agent-identity, wakatime, scheduler, gpt-imagegen. opencode-goal-plugin README: OpenCode 2 not supported. — _Evidence:_ npm view <pkg> dependencies; opencode-goal-plugin README
- **OpenClaw 2026.9.8** ✅ — Also policy, vault/1password, cua-computer, code-mode-quickjs, exa/tavily/brave/searxng/firecrawl search. — _Evidence:_ `oc/docs/plugins/reference.md`; `oc/docs/plugins/community.md`

</details>
<details><summary><b>PLG-N1</b> 🆕 In-process UI mods/plugins that draw panes, rewrite events, add commands/tools</summary>

- **sugar-crush** ❌ — There is no plugin loader. Hooks are out-of-process scripts that can deny, ask or rewrite tool input but cannot draw UI. Command files add slash commands, MCP adds tools, and `statusLine` (a user-tier Map) runs a command whose output fills a status-bar segment. `Chat::registerTool()` exists for PHP embedders but nothing in `bin/` calls it. Nothing can add a pane, rewrite TUI events or add a tool in-process. — _Evidence:_ `src/Chat.php:10546`; `src/Config/Settings/Definitions/InterfaceSettings.php:65-72`; `src/Tools/Catalog/ToolCatalog.php`
- **sugar-crush-web** ❌ — — — _Evidence:_ `src/Server/Http/Responses.php:29-36`; `sugar-crush-web/dist/`
- **Claude Code 2.1.301** ✅ — Built-ins: cc-plugin-agents-md, -diff, -you-should-know, -telemetry, -sec-default, -plugin-authoring; `claude plugin test`. — _Evidence:_ cc:plugins/mods/overview
- **OpenCode 1.18.35** ✅ — The built-in sidebar, diff viewer and notifications are themselves TUI feature-plugins. — _Evidence:_ PLUG/tui.ts:455-486; TUI/feature-plugins/; PLUG/index.ts:221-335
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ V2D/build/plugins/cli; V2D/build/plugins/index
- **OpenClaw 2026.9.8** ✅ — Bundled plugins' native UI always on; user-installed plugin UI behind Settings → Labs → Custom plugin UI (experimental, trusted authors only); hooks like `message_sending`, `before_message_write`, `before_tool_call` rewrite events; `registerCommand`/`registerTool`. — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Custom plugin UI); `oc/docs/plugins/feature-plugins.md`; `oc/docs/plugins/hooks.md`

</details>
<details><summary><b>PLG-N2</b> 🆕 Plugin evaluation / cost measurement tooling</summary>

- **sugar-crush** ❌ — Nothing compares to `claude plugin eval`: no baseline, no A/B run, no scoring. The closest measurement is `/context`, which breaks the prompt into sections (project-instructions, available-skills, project-memory …) with token estimates and reports tool-schema tokens, so you can see what a skill set or MCP server costs per request. Launch notices flag inert or unknown frontmatter keys in skills, agents, commands and rules. — _Evidence:_ `src/Host/Commands/ContextHostCommand.php`; `README.md:2106` (diagnostics)
- **sugar-crush-web** ❌ — — — _Evidence:_ `src/Host/Commands/ContextHostCommand.php`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ help (`plugin eval`, `plugin details`); cc:plugin-evals
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ DOCS/plugins
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2CLI: `opencode stats --help`
- **OpenClaw 2026.9.8** 🟡 — Agent runs report `costUsd`, assistant turns and tool summaries in `--json`; the Code Mode model matrix (`pnpm qa:code-mode-models`) measures pass rate, latency and cost but only from a source checkout. — _Evidence:_ `oc/docs/tools/skill-workshop/authoring.md`; `oc/docs/plugins/sdk-testing.md`; `oc/docs/cli/agent.md`

</details>

### MCP — Model Context Protocol

<details><summary><b>MCP-01</b> Client: stdio</summary>

- **sugar-crush** ✅ — Servers start once at launch and are shared across forked turns behind a cross-process lock. A shutdown hook stops them. — _Evidence:_ `src/MCP/McpClient.php:161,524`; `src/MCP/StdioMcpServer.php`; `src/Cli/Bootstrap.php:8408,8794`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Process-tree shutdown; `MCP_TIMEOUT` startup; 30-min idle tool timeout default for stdio. — _Evidence:_ help (`mcp add`); cc:mcp#option-3-add-a-local-stdio-server
- **OpenCode 1.18.35** ✅ — Options: command, environment, cwd, enabled, timeout (tool fetch, default 5 s per docs; 30 s connect default in code). — _Evidence:_ DOCS/mcp-servers (Local); OC/mcp/index.ts:38,345-360
- **OpenCode 2.0.31** ✅ — Options: command, environment, cwd, enabled, timeout (tool fetch, default 5 s per docs; 30 s connect default in code). — _Evidence:_ DOCS/mcp-servers (Local); OC/mcp/index.ts:38,345-360
- **OpenClaw 2026.9.8** ✅ — stdio servers (command/args/env/cwd) with owned process-group cleanup — _Evidence:_ `oc/docs/tools/mcp.md`; `oc/docs/plugins/bundles.md` (Transports)

</details>
<details><summary><b>MCP-02</b> Client: SSE</summary>

- **sugar-crush** ❌ — Only that one entry is lost. Import does not warn about it. — _Evidence:_ `src/MCP/McpClient.php:577`; `src/MCP/McpForeignTranslate.php:39`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Docs keep it as an option alongside HTTP. — _Evidence:_ cc:mcp#option-2-add-a-remote-sse-server
- **OpenCode 1.18.35** ✅ — The transports list is [StreamableHTTP, SSE], tried in order. — _Evidence:_ OC/mcp/index.ts:268-300
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): Remote servers fall back to SSE transport if Streamable HTTP fails. 2.0.31 (❌): SSE transport removed: remote servers use Streamable HTTP only — _Evidence:_ V2C/mcp/client.ts:10,272-277; V2D/mcp-servers (Remote)
- **OpenClaw 2026.9.8** ✅ — SSE (the default when a URL server omits `transport`) — _Evidence:_ `oc/docs/plugins/bundles.md` (Transports)

</details>
<details><summary><b>MCP-03</b> Client: Streamable HTTP</summary>

- **sugar-crush** 🟡 — Re-initialises on 404 and sends a session DELETE on stop. Advertises protocol 2024-11-05. — _Evidence:_ `src/MCP/HttpMcpServer.php:310,359,379`; `src/MCP/McpClient.php:109`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Two client runtimes (MCP TS SDK 1.x / 2.0 with protocol 2026-07-28); 16 MiB response cap; discovery cache opt-in. — _Evidence:_ cc:mcp#option-1-add-a-remote-http-server; cc:mcp#mcp-client-runtimes
- **OpenCode 1.18.35** ✅ — Headers go via requestInit. — _Evidence:_ OC/mcp/index.ts:268-283; DOCS/mcp-servers (Remote)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): type remote: Streamable HTTP tried first, custom headers. 2.0.31 (✅): Streamable HTTP; optional MCP 2026-07-28 protocol via `protocol: auto/2026-07-28` — _Evidence:_ V2D/mcp-servers (Protocol version)
- **OpenClaw 2026.9.8** ✅ — `transport: "streamable-http"` with headers (${ENV}), TLS/mTLS, timeouts — _Evidence:_ `oc/docs/tools/mcp.md`

</details>
<details><summary><b>MCP-04</b> OAuth for remote MCP servers</summary>

- **sugar-crush** 🟡 — Tokens refresh before expiry. No RFC 8707 resource parameter, no scope, and no pre-registered client_id. A 401 at start is swallowed silently. — _Evidence:_ `src/MCP/OAuthLoopbackFlow.php:110,215`; `src/Commands/McpAuthCommand.php:150`; `src/MCP/HttpMcpServer.php:699`
- **sugar-crush-web** ❓ — — — _Evidence:_ `sugar-crush/src/Host/Commands/McpAuthHostCommand.php`
- **Claude Code 2.1.301** ✅ — Re-auth prompt when a server asks for more scope (v2.1.288); tokens per endpoint; claude.ai connectors pre-authenticated. — _Evidence:_ cc:mcp#authenticate-with-remote-mcp-servers; help (`mcp login`); CL v2.1.288
- **OpenCode 1.18.35** ✅ — Status `needs_client_registration` / `needs_auth`. `oauth:false` disables it. `opencode mcp debug <name>` tests connectivity and discovery. — _Evidence:_ DOCS/mcp-servers (OAuth); OC/mcp/oauth-provider.ts; CLI: `opencode mcp --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Auto OAuth on 401 with DCR (RFC 7591) or pre-registered client; `mcp auth/logout/debug`; tokens in mcp-auth.json. 2.0.31 (✅): OAuth with PKCE via pre-registered client, CIMD, or DCR; snake_case oauth fields; /mcps sign-in — _Evidence:_ V2D/mcp-servers (OAuth)
- **OpenClaw 2026.9.8** ✅ — Per-requester identity servers supported. — _Evidence:_ `oc/docs/tools/mcp.md` (An HTTP server needs authorization)

</details>
<details><summary><b>MCP-05</b> MCP tools</summary>

- **sugar-crush** ✅ — Collision-free name escaping. Plan mode denies, auto mode asks. destructiveHint and outputSchema are ignored. — _Evidence:_ `src/Tools/McpToolBridge.php:160,214,272`; `src/Permissions/PermissionGate.php:862,1452`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Long calls auto-background after 2 min; per-tool approval via `requiresUserInteraction`; permission rules by tool name. — _Evidence:_ cc:mcp; cc:mcp#automatic-backgrounding-of-long-tool-calls
- **OpenCode 1.18.35** ✅ — Server `instructions` are injected into the system prompt as `<mcp_instructions>`, unless all of that server's tools are denied. Tool lists refresh on `tools/list_changed`. — _Evidence:_ OC/mcp/index.ts:462; OC/mcp/catalog.ts:42-64; OC/session/system.ts:121-137
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Tools exposed as <server>_<tool>; permission/tools globs (e.g. mymcp_*); progress resets timeouts. 2.0.31 (✅): Session ID sent in `_meta["ai.opencode/sessionID"]`. — _Evidence:_ V2D/mcp-servers (Names, Permissions, Context)
- **OpenClaw 2026.9.8** ✅ — Dynamic list changes refresh; failing servers back off (30 s doubling to 10 min); Codex MCP approvals approve/prompt/auto + Allow Always grants. — _Evidence:_ `oc/docs/tools/mcp.md`; `oc/docs/cli/mcp/registry.md`

</details>
<details><summary><b>MCP-06</b> MCP resources (@-mention / read)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `../sugar-mcp-client/src/` (no resources/*)
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — MCP Apps `ui://` resources excluded from suggestions. — _Evidence:_ cc:mcp#use-mcp-resources; cc:tools-reference
- **OpenCode 1.18.35** ✅ — Text resources are inlined as synthetic text. pdf/png/jpeg/gif/webp blobs are attached; others are omitted with a note. — _Evidence:_ TUI/component/prompt/autocomplete.tsx:372-390; OC/session/tools.ts:27-39,225-330; OC/session/prompt.ts:703-775
- **OpenCode 2.0.31** ✅ — Text resources are inlined as synthetic text. pdf/png/jpeg/gif/webp blobs are attached; others are omitted with a note. — _Evidence:_ TUI/component/prompt/autocomplete.tsx:372-390; OC/session/tools.ts:27-39,225-330; OC/session/prompt.ts:703-775
- **OpenClaw 2026.9.8** 🟡 — No user @-mention of resources. MCP Apps (`ui://` HTML, opt-in) render in sandboxed double iframes. — _Evidence:_ `oc/docs/cli/mcp/registry.md`; `oc/docs/cli/mcp/apps.md`

</details>
<details><summary><b>MCP-07</b> MCP prompts (as slash commands)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Arguments passed positionally. — _Evidence:_ cc:mcp#use-mcp-prompts-as-commands
- **OpenCode 1.18.35** ✅ — See CMD-09. — _Evidence:_ OC/command/index.ts:105-132
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): MCP prompts listed as slash commands. 2.0.31 (✅): Prompts as `/<server>:<prompt>` commands — _Evidence:_ V2D/mcp-servers (Names)
- **OpenClaw 2026.9.8** 🟡 — Fetched prompts present role-labeled messages incl. images. — _Evidence:_ `oc/docs/cli/mcp/registry.md`

</details>
<details><summary><b>MCP-08</b> Elicitation / sampling</summary>

- **sugar-crush** ❌ — The client advertises empty capabilities. — _Evidence:_ `../sugar-mcp-client/src/StdioMcpServer.php:399,1143-1157`
- **sugar-crush-web** ❓ — — — _Evidence:_ `sugar-crush/docs/protocol/sugarcrush.v1.schema.json`
- **Claude Code 2.1.301** 🟡 — No mention of sampling anywhere in docs or changelog. — _Evidence:_ cc:mcp#respond-to-mcp-elicitation-requests; cc:hooks#elicitation
- **OpenCode 1.18.35** ❌ — CLIENT_OPTIONS.capabilities has `sampling`, `elicitation` and `tasks` commented out. `roots` = project dir. — _Evidence:_ OC/mcp/index.ts:38-49,78
- **OpenCode 2.0.31** 🟡 — 1.18.35 disabled both. — _Evidence:_ V2C/mcp/client.ts:151,188-212; V2D/mcp-servers (Input requests)
- **OpenClaw 2026.9.8** 🟡 — Verified no elicitation/sampling handlers in `oc/dist/agent-bundle-mcp-*.mjs`; `codexPlugins.allow_destructive_actions` governs Codex app elicitations. — _Evidence:_ `oc/docs/gateway/config-extensions.md`; `oc/dist/agent-bundle-mcp-runtime-shared-BFBNCZj0.mjs`

</details>
<details><summary><b>MCP-09</b> Config scopes (user / project / local, .mcp.json)</summary>

- **sugar-crush** 🟡 — Also `disabledMcpServers` globs, `mcp.enabled`, `SUGARCRUSH_MCP_DISABLE`, and a user-only claude-mcp grant. — _Evidence:_ `src/Cli/Bootstrap.php:7841,8069,8258-8503`; `src/MCP/McpTrustPins.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `${VAR}` / `${VAR:-default}` expansion; `--strict-mcp-config`; `claude mcp reset-project-choices`. — _Evidence:_ cc:mcp#mcp-installation-scopes; help (`mcp`)
- **OpenCode 1.18.35** 🟡 — Org `.well-known/opencode` can ship servers with `enabled:false` for opt-in. `POST /mcp` adds a server at runtime (not persisted). — _Evidence:_ DOCS/mcp-servers (Overriding remote defaults); DOCS/server (MCP)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): `mcp` key in global/project/remote/managed config; no .mcp.json and no local/private scope. 2.0.31 (🟡): `mcp.servers` in global/project/managed config; `mcp add [--global]`; still no .mcp.json — _Evidence:_ V2D/mcp-servers (Setup, Config)
- **OpenClaw 2026.9.8** 🟡 — Composer can enable a server "This session" vs "Everywhere" (definition is still global); node-hosted MCP servers. — _Evidence:_ `oc/docs/tools/mcp.md`; `oc/docs/nodes/mcp-and-skills.md`

</details>
<details><summary><b>MCP-10</b> Management UI (/mcp, status, reconnect)</summary>

- **sugar-crush** 🟡 — `list` renders the declared servers, up/down state, tool counts and "config changed since launch". Under `--root` it reads the cwd's project (ISSUES). — _Evidence:_ `builtin-commands/0900-mcp.php`; `src/Commands/McpAuthCommand.php:126-175`; `src/Tui/McpPanel.php`
- **sugar-crush-web** 🟡 — Verified output: "MCP Project Config … Status: none". — _Evidence:_ live `command.exec name=mcp`
- **Claude Code 2.1.301** ✅ — Statuses: Connected, Needs authentication, Failed (with Issue detail), Pending approval, Disabled; config warnings (hidden whitespace, duplicate names, reserved names). — _Evidence:_ cc:mcp#managing-your-servers; help (`mcp list`, `mcp get`)
- **OpenCode 1.18.35** ✅ — The sidebar MCP panel and home footer show connected counts and errors. — _Evidence:_ TUI/app.tsx:690-700; TUI/feature-plugins/sidebar/mcp.tsx; CLI: `opencode mcp --help`
- **OpenCode 2.0.31** ✅ — The sidebar MCP panel and home footer show connected counts and errors. — _Evidence:_ TUI/app.tsx:690-700; TUI/feature-plugins/sidebar/mcp.tsx; CLI: `opencode mcp --help`
- **OpenClaw 2026.9.8** ✅ — `mcp doctor --probe` proves reachability; backoff state shown in tool inventory. — _Evidence:_ `oc/docs/tools/mcp.md`; CLI: `openclaw mcp --help`

</details>
<details><summary><b>MCP-11</b> Per-agent MCP enable/disable</summary>

- **sugar-crush** 🟡 — `resolveGrantedTools()` returns null (full set) before the MCP filter, and no per-call check exists. `McpClient::setAgentPreset()` routing is dormant. — _Evidence:_ `src/Agents/AgentManager.php:1478-1489,1604`; `src/Tools/BuiltIn/TaskTool.php:1207`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Ignored for plugin agents; inline servers from untrusted repos skipped. — _Evidence:_ cc:sub-agents#scope-mcp-servers-to-a-subagent
- **OpenCode 1.18.35** ✅ — Pattern: deny globally, allow in a specific agent. This repo denies context7_*, exa_* and gh_grep_* globally and allows them for `researcher`. — _Evidence:_ DOCS/mcp-servers (Per agent); LOCAL: .opencode/opencode.jsonc
- **OpenCode 2.0.31** ✅ — Pattern: deny globally, allow in a specific agent. This repo denies context7_*, exa_* and gh_grep_* globally and allows them for `researcher`. — _Evidence:_ DOCS/mcp-servers (Per agent); LOCAL: .opencode/opencode.jsonc
- **OpenClaw 2026.9.8** ✅ — Per-agent tools.allow/deny with server globs (`docs__*`); sandbox gate needs `bundle-mcp`; session denials — _Evidence:_ `oc/docs/gateway/config-tools/tool-policy.md`

</details>
<details><summary><b>MCP-12</b> Tool search / deferred MCP tool loading</summary>

- **sugar-crush** ❌ — There is no cap on tool count. — _Evidence:_ `src/Cli/Bootstrap.php:9181-9220`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `ENABLE_TOOL_SEARCH` true/auto/auto:N/false; `alwaysLoad` exempts a server; falls back to upfront loading on old models, non-1P base URLs, Azure Foundry; `WaitForMcpServers` when off. — _Evidence:_ cc:mcp#scale-with-mcp-tool-search; obs (deferred tools reminder)
- **OpenCode 1.18.35** 🟡 — `OPENCODE_EXPERIMENTAL_CODE_MODE`: “Run a confined orchestration script with access to connected MCP tools”, with a catalog description grouped by server, via @opencode-ai/codemode. Otherwise all MCP tool schemas are sent every request; docs warn about context bloat. — _Evidence:_ OC/tool/code-mode.ts:12-20,188-240; OC/tool/registry.ts:118-119,276-286; DOCS/mcp-servers (Caveats)
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): No deferred tool search; experimental code mode exposes MCP tools via one `execute` sandbox tool. 2.0.31 (✅): Equivalent to deferred tool loading: full MCP schemas are not sent per request; catalog listings are selected under a token budget. — _Evidence:_ V2D/tools (Execute); V2C/codemode/catalog.ts:60-130
- **OpenClaw 2026.9.8** ✅ — When `tools.toolSearch` is unset, embedded and Copilot runs expose `tool_search`, `tool_describe`, `tool_call` plus a policy-filtered capability directory (sorted, ≤18,000 chars, above the cache boundary); core coding primitives and direct-only tools stay directly visible; untrusted MCP names/descriptions never enter the system prompt. `tools.toolSearch: false` restores direct schemas. Code Mode (off by default) exposes `MCP.<server>.<tool>()` with `API.read` declarations. Codex harness uses native deferred tools. — _Evidence:_ `oc/docs/tools/tool-search.md`; `oc/docs/tools/code-mode.md`; `oc/docs/tools/index.md`

</details>
<details><summary><b>MCP-13</b> Act as an MCP server (expose self)</summary>

- **sugar-crush** ❌ — README and MCP.md overclaim a server mode (ISSUES). — _Evidence:_ `src/MCP/GitMcpServer.php`; `docs/MCP.md:619-627`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Usable from Claude Desktop and other clients. — _Evidence:_ help (`mcp serve`); cc:mcp#use-claude-code-as-an-mcp-server
- **OpenCode 1.18.35** ❌ — The TUI is an MCP **client** to IDEs for selection context only. — _Evidence:_ DOCS/acp, DOCS/server; TUI/context/editor.ts
- **OpenCode 2.0.31** ❌ — The TUI is an MCP **client** to IDEs for selection context only. — _Evidence:_ DOCS/acp, DOCS/server; TUI/context/editor.ts
- **OpenClaw 2026.9.8** ✅ — claude-cli backend also receives allowed OpenClaw tools through a grant-scoped MCP server. — _Evidence:_ `oc/docs/cli/mcp/serve.md`; `oc/docs/cli/attach.md`; `oc/docs/gateway/cli-backends.md`

</details>
<details><summary><b>MCP-14</b> MCP output limits / truncation</summary>

- **sugar-crush** 🟡 — The embedded `resource.text` is discarded. — _Evidence:_ `src/Tools/McpToolBridge.php:696`; `src/Tools/ToolLimits.php:266`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/stores/session.ts:187-201`
- **Claude Code 2.1.301** ✅ — Error text trimmed to first/last 5K chars; images still bounded by the token limit. — _Evidence:_ cc:mcp#mcp-output-limits-and-warnings
- **OpenCode 1.18.35** ✅ — `truncate.output()` is applied in SessionTools for MCP tools. `experimental.mcp_timeout` sets the request timeout. — _Evidence:_ OC/session/tools.ts:196-206,279-289; CORE/v1/config/config.ts:182-184
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): MCP results pass through the same 2000-line/50 KB truncation-to-file; resource blobs capped at 10 MB. 2.0.31 (✅): Per-server timeouts startup/catalog/execution (12 h default execution); large outputs shortened to managed file — _Evidence:_ V2D/mcp-servers (Timeouts); V2D/tools
- **OpenClaw 2026.9.8** ✅ — Repeated request failures pause a server briefly. — _Evidence:_ `oc/docs/cli/mcp/registry.md`; `oc/docs/cli/mcp/apps.md`; `oc/docs/tools/mcp.md`

</details>
<details><summary><b>MCP-15</b> Import MCP config from other tools (Claude Desktop etc.)</summary>

- **sugar-crush** 🟡 — It covers Claude Desktop and the top level of ~/.claude.json, but not per-project entries. — _Evidence:_ `src/Cli/Subcommands.php:1321`; `src/MCP/McpForeignTranslate.php`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Can also parse setup instructions written for other clients. — _Evidence:_ help (`mcp add-from-claude-desktop`, `add-json`, `import`); cc:mcp#add-a-server-from-setup-instructions-written-for-another-client
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ CLI: `opencode mcp add --help`
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ CLI: `opencode mcp add --help`
- **OpenClaw 2026.9.8** ✅ — Codex/Hermes migrations too. — _Evidence:_ `oc/docs/install/migrating-claude.md`; `oc/docs/cli/migrate.md`

</details>
<details><summary><b>MCP-N1</b> 🆕 MCP server trust pinning (config fingerprints; re-prompt when a server definition changes)</summary>

- **sugar-crush** ✅ — A server is pinned on first use; a changed entry is refused until re-approved. — _Evidence:_ `src/MCP/McpTrustPins.php`; `src/Cli/Bootstrap.php:8459`
- **sugar-crush-web** 🟡 — Pins are checked when the workspace (or a workspace host) starts project MCP. Trust grants cannot be written with `settings.set`, so a browser user can neither see the refused server in any panel nor re-approve it. — _Evidence:_ `src/Cli/Bootstrap.php:8098-8099`, `:8435-8440`; `src/MCP/McpTrustPins.php:7-50`; `docs/SERVER.md:86-91`
- **Claude Code 2.1.301** 🟡 — Approvals in `enabledMcpjsonServers`/`disabledMcpjsonServers`; `claude mcp reset-project-choices`; repo can't self-approve; headersHelper waits for trust. — _Evidence:_ cc:mcp#project-server-approvals-and-workspace-trust; help (`mcp reset-project-choices`)
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/mcp/index.ts
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2D/mcp-servers (Config)
- **OpenClaw 2026.9.8** ❌ — Durable Codex MCP grants are keyed by agent + configured server name + tool (any arguments); third-party plugins need capability consent and installs pass `security.installPolicy`, but a changed `mcp.servers` entry is not re-approved. — _Evidence:_ `oc/docs/tools/exec-approvals.md` (MCP tool grants); `oc/docs/tools/mcp.md`

</details>

### TL — Built-in tools

<details><summary><b>TL-01</b> Read file</summary>

- **sugar-crush** ✅ — Returns `N: text` lines plus a footer naming the next offset; a long file read without offset/limit also gets an LSP or regex outline (at most 200 rows / 8 KiB); a single overlong line is clipped and marked. Refuses non-regular files (FIFOs, devices). Jailed via `PathJail::resolve` (realpath, NUL refused, root itself allowed) with one read-only exception for spilled tool-output files. Records mtime/size/inode/xxh128 into the shared ReadLedger. Surfaces nested CLAUDE.md/AGENTS.md and scoped skill/rule nudges the first time a directory is touched. Limits rebind per turn (`readMaxBytes`, `readPageLines`, `readPageBytes`). Read class, so it runs unasked in every mode. Gaps: no image/PDF/notebook support (binary files come back as U+FFFD-scrubbed mojibake), and every call scans and hashes the whole file whatever its size. — _Evidence:_ `src/Tools/BuiltIn/Read.php:27,41,49,57,72,341-372,382,647-788`; `src/Tools/PathJail.php:94-138`; `src/Runtime.php:3480` (UTF-8 scrub)
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/ToolCard.vue`
- **Claude Code 2.1.301** ✅ — Absolute paths; empty-file and past-EOF notices; refuses directories; `cat`/`head`/`sed -n`/`rg` on one file also count as reads. — _Evidence:_ cc:tools-reference#read-tool-behavior
- **OpenCode 1.18.35** ✅ — Rejects binary. `*.env` asks by default. Triggers nested AGENTS.md. — _Evidence:_ OC/tool/read.ts; OC/tool/read.txt
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): read: files, directories (listing), images, PDFs; offset/limit paging. 2.0.31 (✅): read: files, non-recursive dir listings, images/PDF ≤20 MiB; listings also trigger nested AGENTS.md — _Evidence:_ V2D/tools (Read); V2C/tool/plugin/read.ts
- **OpenClaw 2026.9.8** ✅ — Pages fit the model's tool-result budget; BOM/Windows decoding; credential redaction; "did you mean" sibling filename hints. — _Evidence:_ `oc/docs/tools/code-mode/guest-api.md`; `oc/dist/tools-BEx53dKD.mjs` (read tool)

</details>
<details><summary><b>TL-02</b> Write file</summary>

- **sugar-crush** ✅ — `resolveForCreate` anchors containment at the nearest existing ancestor, normalises `..` lexically first and refuses dangling symlinks. An existing file needs `overwrite: true`. `AtomicFileWriter::replace` writes temp+fsync+rename, keeps mode/uid/gid and writes hard-linked files in place. ReadLedger stale check runs if the file was read before. The result carries a unified diff of the overwrite (diff bound applies). — _Evidence:_ `src/Tools/BuiltIn/Write.php:49,146,197-208,233-279,286,299`; `src/Tools/PathJail.php:181-254`; `src/Support/AtomicFileWriter.php:184-220`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/DiffView.vue`; `e2e/session.spec.ts:27`
- **Claude Code 2.1.301** ✅ — Refuses writing through symlinks; won't write back U+FFFD garbled text. — _Evidence:_ cc:tools-reference#write-tool-behavior
- **OpenCode 1.18.35** ✅ — Gated by the `edit` permission; the diff is shown in the ask dialog. — _Evidence:_ OC/tool/write.ts:18,74-90; DOCS/tools (write)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): write: create/overwrite; formatter + LSP errors for the file and up to 5 others. 2.0.31 (✅): write creates/replaces files, may format; no LSP diagnostics — _Evidence:_ V2D/tools (Write); V2C/tool/plugin/write.ts:44
- **OpenClaw 2026.9.8** ✅ — Memory-flush turns get an append-only write; `tools.fs.workspaceOnly` optional containment. — _Evidence:_ `oc/dist/tools-BEx53dKD.mjs` (file-write-verification); `oc/docs/gateway/permission-modes.md`

</details>
<details><summary><b>TL-03</b> Edit (exact string replace)</summary>

- **sugar-crush** ✅ — `old_string` must be unique. `replace_all` applies to exact matches only, and a loose match never applies to more than one place. On failure the error names the matching lines or the closest lines. Output: `File updated: path (+a -r lines)` plus a diff payload for the TUI. Spilled tool-output paths are refused. — _Evidence:_ `src/Tools/BuiltIn/Edit.php:28,38,156-430`; `src/Tools/Edit/EditMatcher.php:71-155`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/ToolCard.vue:55,60-65`
- **Claude Code 2.1.301** ✅ — Read-before-edit (newer models may skip when a read needs no prompt); stale files editable if the match is still exact; UTF-16LE BOM ok, other non-UTF-8 refused. — _Evidence:_ cc:tools-reference#edit-tool-behavior
- **OpenCode 1.18.35** ✅ — An empty oldString creates a file but refuses existing ones. A disproportionate-match guard refuses huge spans. — _Evidence:_ OC/tool/edit.ts:58-215,682-737
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): edit: oldString/newString/replaceAll with 9-stage fuzzy replacer chain; diff shown in ask dialog. 2.0.31 (✅): edit: exact match, then quote/dash/space-variant and trailing-whitespace retries; CRLF/mixed endings kept — _Evidence:_ V2C/tool/plugin/edit.ts:165-223
- **OpenClaw 2026.9.8** ✅ — Fuzzy fallback described in EDT-01; formatting-only edits (trailing spaces, Unicode quotes/dashes) still applied. — _Evidence:_ `oc/docs/tools/index.md`; `oc/dist/tools-BEx53dKD.mjs` (edit.ts)

</details>
<details><summary><b>TL-04</b> Multi-edit / batch edit</summary>

- **sugar-crush** ✅ — Each entry in `edits` applies to the text the previous ones produced. One failure writes nothing, and the error says which edit failed and how the line numbers shifted. ApplyPatch covers the multi-file case. — _Evidence:_ `src/Tools/BuiltIn/Edit.php:134-146,173,318-346,438-469`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — MultiEdit doesn't appear in tools reference or hooks inputs. — _Evidence:_ cc:tools-reference
- **OpenCode 1.18.35** ❌ — The registry built-ins have no multiedit or batch. The config key is schema-only. — _Evidence:_ OC/tool/registry.ts:209-249; CORE/v1/config/config.ts:172
- **OpenCode 2.0.31** ❌ — The registry built-ins have no multiedit or batch. The config key is schema-only. — _Evidence:_ OC/tool/registry.ts:209-249; CORE/v1/config/config.ts:172
- **OpenClaw 2026.9.8** ✅ — Legacy single `oldText`/`newText` still accepted. — _Evidence:_ `oc/dist/tools-BEx53dKD.mjs` (edit args normalization)

</details>
<details><summary><b>TL-05</b> Apply patch (unified diff / V4A)</summary>

- **sugar-crush** ✅ — `@@` anchor lines narrow the search. A hunk is matched by text, exact first and then through the EditMatcher chain. Unified-diff hunk headers are accepted but their line numbers are ignored; it is not a `git apply` of a plain diff. Every path goes through the jail, the read ledger and protect-files. Duplicate targets are refused, and a write failure rolls back every file already changed. — _Evidence:_ `src/Tools/BuiltIn/ApplyPatch.php:59,152-186,194-277,449-557`; `src/Tools/Edit/PatchParser.php:1-40`
- **sugar-crush-web** ✅ — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference
- **OpenCode 1.18.35** 🟡 — Add, update, delete and move. LSP diagnostics per touched file. Paths are relative to the project root. — _Evidence:_ OC/tool/registry.ts:297-300; OC/tool/apply_patch.ts; DOCS/tools (apply_patch)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): apply_patch (Codex *** Begin Patch format) — only offered to gpt-* models, replacing edit/write. 2.0.31 (🟡): `patch` tool (renamed from apply_patch) only for supported GPT models — _Evidence:_ V2D/tools (Patch)
- **OpenClaw 2026.9.8** ✅ — `tools.exec.applyPatch.enabled/allowModels/workspaceOnly`; session permission mode overrides containment. — _Evidence:_ `oc/docs/tools/apply-patch.md`

</details>
<details><summary><b>TL-06</b> Glob</summary>

- **sugar-crush** ✅ — Default prune list plus .gitignore (`include_ignored` lifts it). Symlinked directories are not followed and the result says so. A pattern with a `..` segment is refused, and a second pass drops any match whose realpath is outside the root. Cap is `globMaxMatches`; a truncated list says it is partial. If `fd` is on PATH the description only mentions it. — _Evidence:_ `src/Tools/BuiltIn/Glob.php:25,59,306-313,946-969`
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** 🟡 — Restored via `--tools`/`--allowedTools`, when Bash is denied, or in subagents listing it without Bash; mtime-sorted, 100-file cap; ignores .gitignore unless `CLAUDE_CODE_GLOB_NO_IGNORE=false`. — _Evidence:_ cc:tools-reference#glob-tool-behavior
- **OpenCode 1.18.35** ✅ — `.ignore` with `!node_modules/` re-includes ignored paths. — _Evidence:_ DOCS/tools (glob, Internals)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): glob via ripgrep, sorted by mtime, respects .gitignore/.ignore. 2.0.31 (✅): glob with path/hidden/limit (default 100, 30 s timeout) — _Evidence:_ V2D/tools (Glob)
- **OpenClaw 2026.9.8** 🟡 — pi SDK `find`/`grep` definitions are present in the bundle but not registered by OpenClaw's core tool factories or policy groups (verified in dist); TUI uses `fd` only for @ completion. — _Evidence:_ `oc/docs/gateway/config-tools/tool-policy.md` (Tool groups); `oc/dist/core-tool-factory-descriptors-DYpD_U2M.mjs`; `oc/dist/core-coding-tools-BmfxDnfH.mjs`

</details>
<details><summary><b>TL-07</b> Grep (ripgrep)</summary>

- **sugar-crush** 🟡 — Option injection is closed (`-e PATTERN`, `--include=`, `--`, `-r` not `-R`). Never opens .env, .envrc, .env.* or key files. .gitignore rules are applied in post-filtering. There is no case-insensitive, context-lines, count or files-only mode. `rgAvailable` only adds a hint to "run rg via Bash". `runCaptured(... timeout null ...)` plus a heartbeat means a lone Grep is never cut off. — _Evidence:_ `src/Tools/BuiltIn/Grep.php:28,223-227,345-384,821`
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** 🟡 — Same restore rules as Glob; modes files_with_matches/content/count, glob/type filters, multiline; respects .gitignore. — _Evidence:_ cc:tools-reference#grep-tool-behavior
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ OC/tool/grep.ts; DOCS/tools (grep)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): grep via ripgrep with regex + include filters. 2.0.31 (✅): grep with literal/include/caseSensitive/limit (default 100 lines, 30 s timeout) — _Evidence:_ V2D/tools (Grep)
- **OpenClaw 2026.9.8** 🟡 — Same evidence as TL-06. — _Evidence:_ `oc/dist/core-tool-factory-descriptors-DYpD_U2M.mjs`

</details>
<details><summary><b>TL-08</b> List directory</summary>

- **sugar-crush** 🟡 — `ls`/`tree` are on the read-only auto-allow list in default and accept-edits. `src/StreamingDirectoryLister.php` (a gum ReadDirIter port) has no callers. — _Evidence:_ `src/Permissions/ReadOnlyCommands.php`; `src/StreamingDirectoryLister.php:15`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference#read-tool-behavior
- **OpenCode 1.18.35** 🟡 — The `list` permission is still documented and still in explore's allow list. — _Evidence:_ OC/tool/registry.ts:228-249; OC/tool/read.txt; DOCS/agents (permission table)
- **OpenCode 2.0.31** 🟡 — The `list` permission is still documented and still in explore's allow list. — _Evidence:_ OC/tool/registry.ts:228-249; OC/tool/read.txt; DOCS/agents (permission table)
- **OpenClaw 2026.9.8** ✅ — Hidden when a custom sandbox backend lacks readDirectory; never falls back to host FS. — _Evidence:_ `oc/docs/gateway/sandboxing/what-gets-sandboxed.md`

</details>
<details><summary><b>TL-09</b> Bash / shell</summary>

- **sugar-crush** ✅ — Command is `bash -c escapeshellarg(cd-guard + command)`, run as `setsid -w /bin/sh -c ...`. stdout is returned; stderr is appended only on non-zero exit, otherwise replaced by a marker. `interactive: true` gives the program a private pty. An optional bubblewrap wrapper applies. The description tells the model a `cd` does not persist and steers it to Read/Grep/Glob. A `<git_commits>` guidance block (with attribution trailers) is added to the prompt. Timeout bounds rebind per turn. — _Evidence:_ `src/Tools/BuiltIn/Bash.php:45,191-233,297-349,402-511,520-545`; `src/Support/ProcessContainment.php:81-131,207-217,266-276`
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** ✅ — cwd carries over inside the project; env vars don't; shell aliases/functions snapshotted at start; `CLAUDE_ENV_FILE`. — _Evidence:_ cc:tools-reference#bash-tool-behavior; cc:tools-reference#powershell-tool
- **OpenCode 1.18.35** ✅ — The description embeds OS, shell, tmp dir and a “# Git and GitHub” policy (commit only when asked, no force-push, use gh). — _Evidence:_ OC/tool/shell/prompt.ts:15-25; OC/tool/shell/shell.txt; OC/tool/shell.ts:378-413
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): bash: command, timeout ms, workdir; tree-sitter parse for per-command permissions; Git/GitHub guidance in desc. 2.0.31 (✅): `shell` tool (bash renamed): command, workdir, timeout (0 disables), background — _Evidence:_ V2C/tool/plugin/shell.ts:44-60; V2D/tools (Shell)
- **OpenClaw 2026.9.8** ✅ — Bash/zsh startup snapshot sourced per command; PowerShell 7 on Windows; `OPENCLAW_SHELL=exec`; host rejects PATH/LD_* overrides. — _Evidence:_ `oc/docs/tools/exec.md`

</details>
<details><summary><b>TL-10</b> Background shell + output polling / monitor</summary>

- **sugar-crush** ❌ — `cmd &` with inherited stdout blocks until the timeout and is then group-killed. With output redirected, the child is orphaned and untracked; a timeout kill also misses it once the shell has exited (verified-by-repro). Background work exists only as Task sub-agents. — _Evidence:_ `src/Tools/Concerns/CapturesProcessOutput.php:225-281,350-365`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ✅ — Output to a file Claude reads; `/tasks` + `TaskStop`; bg time limits only in unattended runs (30 min default, 2 h max); Monitor watches 5–30 min, not on Bedrock/Vertex/Foundry or with telemetry off. — _Evidence:_ cc:interactive-mode#background-bash-commands; cc:tools-reference#monitor-tool
- **OpenCode 1.18.35** 🔌 — opencode-pty: spawn, send input, read with regex filters, exit notifications, web UI. — _Evidence:_ opencode-pty README (global npm 0.3.6); OC/tool/shell/prompt.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (🔌): Native bash is synchronous only; background processes via 🔌 opencode-pty (installed here). 2.0.31 (✅): Native background shell: returns immediately, streams output to a managed file, notifies on completion — _Evidence:_ V2C/tool/plugin/shell.ts:96-98,201-266
- **OpenClaw 2026.9.8** ✅ — `notifyOnExit` enqueues a system event and wakes the agent (no polling); sub-agents must poll themselves; Control UI operator terminal. — _Evidence:_ `oc/docs/tools/exec.md`; `oc/docs/gateway/background-process.md`

</details>
<details><summary><b>TL-11</b> Todo / task-list tool</summary>

- **sugar-crush** ✅ — No-ask class, so it runs in every mode including plan and dont-ask. `TodoReminder` feeds nudges. — _Evidence:_ `src/Tools/BuiltIn/Todo.php:37-48`; `src/Todo/TodoReminder.php`
- **sugar-crush-web** ✅ — e2e "the todo panel follows the agent's Todo tool live". — _Evidence:_ `src-web/stores/agents/todos.ts`; `e2e/panels.spec.ts:9`
- **Claude Code 2.1.301** 🟡 — Newer models get no task tools unless `CLAUDE_CODE_ENABLE_TODO_TOOLS=1` or named in `--tools`/`--allowedTools`; always on in background/cloud sessions; `CLAUDE_CODE_TASK_LIST_ID` shares lists. — _Evidence:_ cc:tools-reference#task-tool-availability; cc:interactive-mode#task-list
- **OpenCode 1.18.35** ✅ — Shown in the sidebar todo panel. `todo.updated` event. There is no separate todoread tool in the 1.18.35 registry. — _Evidence:_ OC/tool/todo.ts; OC/agent/subagent-permissions.ts:18-26; TUI/feature-plugins/sidebar/todo.tsx
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): todowrite (full-list replace, pending/in_progress/completed/cancelled, priority); denied to subagents by default. 2.0.31 (❌): No todo tool plugin in V2C/tool/plugin; 2.0.28 removed a stale `todowrite` label. V1 had todowrite + sidebar todo panel. — _Evidence:_ V2C/tool/plugin/ (no todo); V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — Promised-work self-check if a saved plan is unfinished at final answer; legacy `update_plan` maps to it. — _Evidence:_ `oc/docs/tools/progress-card.md`; `oc/docs/gateway/config-tools/built-in-tools.md` (tools.updatePlan)

</details>
<details><summary><b>TL-12</b> Web fetch</summary>

- **sugar-crush** 🟡 — Resolves once and pins the dial address. Blocks loopback, RFC1918, CGNAT/Tailscale, link-local/metadata, NAT64, 6to4, Teredo, ULA and multicast. Host header and SNI are kept. Ask class: asks in default/accept-edits/plan, denied in dont-ask. Rules support `WebFetch(domain:host)`. The description marks content as untrusted. Pages come back as raw HTML, which wastes context. — _Evidence:_ `src/Tools/BuiltIn/WebFetch.php:74,79-160,244-265,552-647,828-900`
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** ✅ — Refuses localhost/dotless hosts; no cross-host redirect following; domain allow rules; preapproved docs domains; `CLAUDE_CODE_DISABLE_WEB_FETCH`. — _Evidence:_ cc:tools-reference#webfetch-tool-behavior
- **OpenCode 1.18.35** ✅ — Permission patterns match the URL. — _Evidence:_ OC/tool/webfetch.ts:14-21
- **OpenCode 2.0.31** ✅ — Permission patterns match the URL. — _Evidence:_ OC/tool/webfetch.ts:14-21
- **OpenClaw 2026.9.8** ✅ — `web_fetch` (local, readability, optional Firecrawl, 20k chars, 15-min cache, redirects/SSRF limits) — _Evidence:_ `oc/docs/tools/web-fetch.md`; `oc/docs/gateway/config-tools/built-in-tools.md`

</details>
<details><summary><b>TL-13</b> Web search</summary>

- **sugar-crush** 🟡 — Endpoint comes from `SUGARCRUSH_SEARCH_ENDPOINT` or `webSearchEndpoint`. Ask class. A `/websearch` command also exists. — _Evidence:_ `src/Tools/BuiltIn/WebSearch.php:29,46,49,164`; `src/Tools/ToolLimits.php:87`
- **sugar-crush-web** ✅ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Not on Bedrock; on Agent Platform only newer models; backend not configurable (use MCP for others). — _Evidence:_ cc:tools-reference#websearch-tool-behavior
- **OpenCode 1.18.35** 🟡 — Parameters: query, numResults (8), type auto/fast/deep, context chars. Uses `https://mcp.exa.ai/mcp` (EXA_API_KEY optional) or `search.parallel.ai/mcp`. — _Evidence:_ OC/tool/registry.ts:58-65,294; OC/tool/websearch.ts:11-34; OC/tool/mcp-websearch.ts:5-7
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): websearch (Exa/Parallel hosted MCP, no key) only on OpenCode/Go providers or with OPENCODE_ENABLE_EXA/PARALLEL. 2.0.31 (✅): websearch with Exa, Firecrawl, Parallel, Tavily, TinyFish (key or /connect); provider chosen on first use — _Evidence:_ V2D/websearch
- **OpenClaw 2026.9.8** ✅ — `x_search` for X posts; Control UI Settings → Search shows the effective route per agent/model; most search providers are installable official plugins. — _Evidence:_ `oc/docs/tools/web.md`

</details>
<details><summary><b>TL-14</b> External code search (e.g. Exa)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `src/Tools/BuiltIn/` listing
- **sugar-crush-web** ❌ — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** 🔌 — — — _Evidence:_ local: claude-plugins-official catalog
- **OpenCode 1.18.35** 🟡 — Both configs here add context7, exa and gh_grep remote MCPs. — _Evidence:_ DOCS/mcp-servers (Context7, Grep by Vercel); LOCAL: opencode.jsonc `mcp`
- **OpenCode 2.0.31** 🟡 — Both configs here add context7, exa and gh_grep remote MCPs. — _Evidence:_ DOCS/mcp-servers (Context7, Grep by Vercel); LOCAL: opencode.jsonc `mcp`
- **OpenClaw 2026.9.8** 🔌 — Exa via `@openclaw/exa-plugin` (search provider); also Perplexity/Tavily/Parallel plugins; no code-search API built in — _Evidence:_ `oc/docs/plugins/reference/exa.md`; `oc/docs/tools/exa-search.md`

</details>
<details><summary><b>TL-15</b> Notebook (Jupyter) edit</summary>

- **sugar-crush** ❌ — No ipynb handling anywhere in src. — _Evidence:_ grep `ipynb` = 0 hits
- **sugar-crush-web** ❌ — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ✅ — UTF-8 only; `Edit(...)` permission paths; IDE `executeCode` runs cells (asks). — _Evidence:_ cc:tools-reference#notebookedit-tool-behavior
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/tool/ (no notebook tool)
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ OC/tool/ (no notebook tool)
- **OpenClaw 2026.9.8** ❌ — No Jupyter notebook tool — _Evidence:_ `oc/docs/tools/index.md`

</details>
<details><summary><b>TL-16</b> LSP tool (definition, references, hover)</summary>

- **sugar-crush** ✅ — Lines and columns are zero-indexed, and the description says so. With no server configured every call returns an error (never an empty result). The tool is offered on every launch either way. — _Evidence:_ `src/Tools/BuiltIn/LspTool.php:59,140-176`; `src/LSP/LspLauncher.php:9-62`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** 🔌 — Official LSP plugins: typescript-lsp, pyright-lsp, php-lsp, gopls-lsp, rust-analyzer-lsp, clangd-lsp, jdtls-lsp, kotlin-lsp, csharp-lsp, swift-lsp, ruby-lsp, lua-lsp, liquid-lsp; you install the server binary; not started in cloud sessions. — _Evidence:_ cc:tools-reference#lsp-tool-behavior; cc:plugins/code-intelligence
- **OpenCode 1.18.35** 🟡 — Also needs `lsp` enabled in config. — _Evidence:_ OC/tool/registry.ts:247; DOCS/tools (lsp experimental)
- **OpenCode 2.0.31** ❌ — 1.18.35 (🟡): Experimental lsp tool (OPENCODE_EXPERIMENTAL_LSP_TOOL): definition, references, hover, symbols, call hierarchy. 2.0.31 (❌): No LSP tool: v2 has no LSP runtime (`lsp` config accepted but inert) — _Evidence:_ V2D/migrate-v1 (Supported fields: lsp); V2C/tool/plugin/edit.ts:205
- **OpenClaw 2026.9.8** 🔌 — stdio servers only; select with `bundle-lsp` / `group:plugins` / `lsp_*`; no diagnostics tool. — _Evidence:_ `oc/docs/plugins/bundles.md` (Embedded OpenClaw LSP); `oc/dist/agent-bundle-lsp-runtime-DcB6yrNC.mjs`

</details>
<details><summary><b>TL-17</b> Ask-user structured question tool</summary>

- **sugar-crush** ✅ — Keys: y = first choice, 1-6 = pick, r = own words, n = decline. With no user (`-p`, dont-ask) the model is told to decide and state its assumption. Withheld from sub-agents. Over serve it becomes `permission.requested`. — _Evidence:_ `src/Tools/BuiltIn/AskUserTool.php:64,71,95-107`; `docs/PERMISSIONS.md` (AskUser/PlanExit)
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/PermissionCard.vue:12-34`
- **Claude Code 2.1.301** ✅ — Never auto-continues in background, screen-reader or Remote Control sessions; removed from subagents. — _Evidence:_ cc:tools-reference#askuserquestion-tool-behavior
- **OpenCode 1.18.35** ✅ — Defaults to deny for other agents. Rejection stops the loop. Child-session questions surface in the parent view. — _Evidence:_ OC/tool/registry.ts:207; OC/agent/agent.ts:119-160; TUI/routes/session/question.tsx
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): question tool: header, options, multi-select, custom answer; enabled for TUI/desktop/app clients (build & plan). 2.0.31 (✅): question tool (header, prompt, choices, multiple, free-form); dismiss cancels — _Evidence:_ V2D/tools (Question)
- **OpenClaw 2026.9.8** ✅ — Main session only; `/question` reopens; `secrets` tool collects credentials through masked prompts. — _Evidence:_ `oc/docs/tools/ask-user.md`; `oc/docs/web/tui.md` (Questions); `oc/docs/tools/secrets.md`

</details>
<details><summary><b>TL-18</b> Plan-mode exit / plan tool</summary>

- **sugar-crush** ✅ — Refuses outside plan mode or when nobody can answer. Plan mode returns to the mode it was entered from. Alt+M also leaves plan mode. — _Evidence:_ `src/Tools/BuiltIn/PlanExitTool.php:62,97-110`; `src/Permissions/PermissionGate.php:187,1031-1073`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/PermissionCard.vue:53-56`
- **Claude Code 2.1.301** ✅ — ExitPlanMode removed from subagents unless their mode is plan; plan re-injected after compaction. — _Evidence:_ cc:tools-reference; cc:permission-modes#analyze-before-you-edit-with-plan-mode
- **OpenCode 1.18.35** 🟡 — The experimental flow writes a plan file then calls plan_exit to switch to build. The classic flow has the user press Tab. — _Evidence:_ OC/tool/registry.ts:248; OC/tool/plan.ts; OC/session/reminders.ts:51-89
- **OpenCode 2.0.31** ❌ — 1.18.35 (🟡): plan_exit tool only with OPENCODE_EXPERIMENTAL_PLAN_MODE in CLI; default plan mode is just the plan agent. 2.0.31 (❌): No plan-exit tool; plan mode ends only when the user switches agent — _Evidence:_ V2C/plugin/plan.ts:5-18
- **OpenClaw 2026.9.8** ❌ — progress_card is the plan surface; `read-only` permission mode restricts tools. — _Evidence:_ `oc/docs/gateway/permission-modes.md`

</details>
<details><summary><b>TL-19</b> Image / screenshot read</summary>

- **sugar-crush** 🟡 — `FileMentions::sniffImage` recognises PNG/JPEG/GIF/WebP. Read returns raw bytes. — _Evidence:_ `src/Attachments/FileMentions.php:37-54,232-240`; `src/Tools/BuiltIn/Read.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/MediaCard.vue`
- **Claude Code 2.1.301** ✅ — Resized to model limits. — _Evidence:_ cc:tools-reference#read-tool-behavior
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ OC/tool/read.ts:19,304-306
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ OC/tool/read.ts:19,304-306
- **OpenClaw 2026.9.8** ✅ — Harness-native loaders replace it when present. — _Evidence:_ `oc/docs/tools/index.md`

</details>
<details><summary><b>TL-20</b> PDF read</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `src/Tools/BuiltIn/Read.php`
- **sugar-crush-web** ❓ — —
- **Claude Code 2.1.301** ✅ — Needs poppler for range reads. — _Evidence:_ cc:tools-reference#read-tool-behavior
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ OC/tool/read.ts:306
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ OC/tool/read.ts:306
- **OpenClaw 2026.9.8** ✅ — `pdf` tool (needs PDF-capable model/auth) — _Evidence:_ `oc/docs/tools/pdf.md`

</details>
<details><summary><b>TL-21</b> Browser control</summary>

- **sugar-crush** ❌ — PtyRun can drive a terminal program only.
- **sugar-crush-web** ❌ — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ✅ — Pauses on logins/CAPTCHAs; tab group per session; not in WSL; 🔌 alternatives playwright, browser-use, chrome-devtools-mcp. — _Evidence:_ cc:chrome; help (`--chrome`)
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ OC/tool/registry.ts (no browser tool); awesome-opencode
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🔌): No browser tool; via MCP (e.g. Playwright) or plugins like oh-my-openagent. 2.0.31 (🟡): Desktop-only `browser` Code Mode namespace drives the desktop app's attached browser (snapshots, clicks, Lighthouse) — _Evidence:_ V2D/tools (Browser)
- **OpenClaw 2026.9.8** ✅ — browser-automation skill; Control UI Browser panel; local browser control works in `agent exec`. — _Evidence:_ `oc/docs/tools/browser.md`; `oc/docs/plugins/reference/browser.md`

</details>
<details><summary><b>TL-22</b> Cron / scheduling tool</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `src/Tools/BuiltIn/` listing
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Session crons restored on resume; RemoteTrigger needs claude.ai plan. — _Evidence:_ cc:tools-reference; cc:scheduled-tasks
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ DOCS/ecosystem (opencode-scheduler)
- **OpenCode 2.0.31** ❓ — 1.18.35 (🔌): No cron tool; 🔌 opencode-scheduler (launchd/systemd). 2.0.31 (❓): No cron tool; opencode-scheduler is V1-API (@opencode-ai/plugin) — v2 support unverified — _Evidence:_ npm view opencode-scheduler dependencies
- **OpenClaw 2026.9.8** ✅ — Prompt tells the model to use cron instead of sleep loops for "check back later". — _Evidence:_ `oc/docs/automation/cron-jobs.md`; `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>TL-23</b> Messaging tools (send to a channel / other session)</summary>

- **sugar-crush** 🟡 — No messaging to channels or other sessions. — _Evidence:_ `src/Tools/BuiltIn/SendMessageTool.php:65`; `TeamTool.php:63`; `BoardPostTool.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ✅ — Channel plugins add reply tools for Telegram/Discord/iMessage. — _Evidence:_ cc:tools-reference; cc:cross-session-messaging; cc:channels
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/tool/
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ OC/tool/
- **OpenClaw 2026.9.8** ✅ — Prompt forbids shell/HTTP workarounds for channel replies. — _Evidence:_ `oc/docs/tools/agent-send.md`; `oc/docs/concepts/session-tool.md`

</details>
<details><summary><b>TL-24</b> Canvas / visual output</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Mods can draw panes/bands inside the TUI. — _Evidence:_ cc:artifacts; cc:commands
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/tool/
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ OC/tool/
- **OpenClaw 2026.9.8** ✅ — Canvas plugin (macOS widget panel, A2UI), `show_widget`, `dashboard`, `portal`, `screen` (arrange Control UI) — _Evidence:_ `oc/docs/plugins/reference/canvas.md`; `oc/docs/tools/show-widget.md`; `oc/docs/tools/screen.md`; `oc/docs/web/dashboards.md`

</details>
<details><summary><b>TL-25</b> Computer use (desktop control)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — Not on Team/Enterprise; not in `-p`. — _Evidence:_ cc:computer-use; cc:desktop
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/tool/
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ OC/tool/
- **OpenClaw 2026.9.8** ✅ — `computer` tool via paired nodes (macOS app), included cua-computer plugin, Codex computer use; Desktop viewer — _Evidence:_ `oc/docs/nodes/computer-use.md`; `oc/docs/plugins/codex-computer-use.md`

</details>
<details><summary><b>TL-26</b> Parallel tool calls in one turn</summary>

- **sugar-crush** ✅ — `parallelToolCalls` (default true) and `parallelToolDeadlineSeconds`. ParallelSafe: Read, Glob, Grep, Lsp, WebFetch, WebSearch, Task, Workflow, MCP bridges, BoardRead. Parallel Task members relay permission asks to the modal. — _Evidence:_ `src/Config/Settings/Definitions/AgentLoopSettings.php:34`; `src/Tools/ParallelSafe.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/reducer.ts` (tool.started/finished)
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:hooks#posttoolbatch
- **OpenCode 1.18.35** ✅ — Prompts encourage parallel reads (“Call this tool in parallel…”). — _Evidence:_ OC/tool/edit.ts:35-45; OC/tool/read.txt
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Parallel tool calls executed concurrently; per-file semaphore serializes edits. 2.0.31 (✅): Parallel local tool calls start eagerly; Code Mode `execute` can run independent calls in parallel — _Evidence:_ specs/v2/session.md; V2D/tools (Execute)
- **OpenClaw 2026.9.8** ✅ — Steering before the checkpoint skips all prepared calls; MCP `supportsParallelToolCalls` hint. — _Evidence:_ `oc/docs/concepts/queue-steering.md`

</details>
<details><summary><b>TL-27</b> Mode-scoped tool sets (read-only tools in plan mode)</summary>

- **sugar-crush** 🟡 — `turnTools()` filters only the ledger tools. Agent presets narrow `tools`/`disallowedTools`. A trusted project's `disabledTools` can remove tools. — _Evidence:_ `src/Backend/EngineBackend.php:3010-3115`; `docs/PERMISSIONS.md` (plan: "tool list is the same in every mode")
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — `--restricted` removes code-running tools. — _Evidence:_ cc:permission-modes; cc:sub-agents#available-tools; help (`--restricted`)
- **OpenCode 1.18.35** ✅ — `experimental.primary_tools` restricts tools to primary agents. — _Evidence:_ OC/permission/index.ts:204-219; OC/agent/agent.ts:156-230; CORE/v1/config/config.ts:176-178
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Permission rules scope tools per agent; '*'-deny removes tools from schema (plan, explore, compaction). 2.0.31 (✅): Ordered `permissions` rules; '*' deny removes tools; deny `execute` removes Code Mode — _Evidence:_ V2D/permissions (Actions)
- **OpenClaw 2026.9.8** ✅ — `read-only` mode omits mutation tools and denies exec; profiles minimal/coding/messaging/full; depth policies — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/gateway/config-tools/tool-policy.md`

</details>
<details><summary><b>TL-28</b> Image generation / media tools</summary>

- **sugar-crush** ✅ — Refuses when `sd.baseUrl` is unset. Saved artifacts carry their parameters. Also reachable as `/generate` in the TUI and over serve. — _Evidence:_ `src/Tools/BuiltIn/GenerateImage.php:104,149`; `GenerateVideo.php:87`; `docs/MEDIA.md`
- **sugar-crush-web** 🟡 — `canGenerateImage`/`canGenerateVideo` are computed from `server.hello` but no component reads them. — _Evidence:_ `src-web/stores/connection.ts:76-78`; `e2e/media.spec.ts`
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ awesome-opencode (GPT Imagegen)
- **OpenCode 2.0.31** ❓ — 1.18.35 (🔌): No media generation; 🔌 opencode-gpt-imagegen. 2.0.31 (❓): No media generation; opencode-gpt-imagegen is V1-API — v2 support unverified — _Evidence:_ npm view opencode-gpt-imagegen dependencies
- **OpenClaw 2026.9.8** ✅ — `image_generate`, `music_generate`, `video_generate`, `tts` with provider failover — _Evidence:_ `oc/docs/tools/image-generation.md`; `oc/docs/tools/video-generation.md`; `oc/docs/tools/music-generation.md`

</details>
<details><summary><b>TL-N1</b> 🆕 Scripted PTY driver tool (run interactive programs on a pty and replay keystrokes)</summary>

- **sugar-crush** ✅ — Up to 64 steps, named keys (`<Enter>`, `<C-x>`), `\xHH` escapes, substring or `/regex/` expects, cols/rows, 64 KiB default capture (the model can raise it to 1 MiB), transcript or final-screen output, TERM-then-KILL reaping. Unique among the five products, but the steps escape every safety layer (see ISSUES P0/P1). — _Evidence:_ `src/Tools/BuiltIn/PtyRun.php:45-170,334-374,382-463`; `src/Support/PtyDriver.php`
- **sugar-crush-web** ✅ — The tool is in the served roster (`server.info`). Its permission card shows the raw JSON arguments, steps included. The unseen-keystrokes P0 from a4 applies equally. — _Evidence:_ `src/Tools/BuiltIn/PtyRun.php`; `sugar-crush-web/src-web/components/ToolCard.vue`; `sugar-crush-web/src-web/components/PermissionCard.vue:53-56`
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference#bash-tool-behavior
- **OpenCode 1.18.35** 🔌 — Native bash is non-PTY. opencode-pty 0.4.0 depends on @opencode-ai/plugin (V1). — _Evidence:_ opencode-pty README; npm view opencode-pty dependencies
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2C/persistent-pty/; npm view opencode-pty dependencies
- **OpenClaw 2026.9.8** ✅ — The model drives interactive programs step by step (key tokens, bracketed paste, Enter); `waitingForInput` after 15 s idle; bundled `tmux` skill as an alternative. No recorded keystroke-script replay. — _Evidence:_ `oc/docs/gateway/background-process.md`; `oc/docs/tools/exec.md`

</details>
<details><summary><b>TL-N2</b> 🆕 Tool-loop / doom-loop detection (identical repeated tool calls detected and interrupted)</summary>

- **sugar-crush** ✅ — ToolCallLoopGuard keeps one ledger per turn, keyed by tool name + canonicalised arguments (map keys sorted recursively) + an xxh128 hash of the result. A poll whose output changes therefore never counts, and counting is per signature across the turn, so an A/B ping-pong is caught. It runs on the hook chain. RepeatCallCountHook (PostToolUse) appends "[Repeat-call loop guard: identical call #n …]" to the result from the 3rd call. RepeatCallGuardHook (PreToolUse) refuses from the 5th, and refused attempts keep counting. EngineBackend ends the turn at the 8th and asks for a no-tools summary that must say the turn was stopped; the TUI also adds the `chat.turn.loop_guard` notice. It is registered every turn, even when hooks are disabled, and sub-agents get it too. Limits: five identical calls in ONE message are all admitted (batch gating), and the thresholds cannot be configured. — _Evidence:_ `src/Backend/ToolCallLoopGuard.php:7-61`, `:93-160`; `src/Backend/EngineBackend.php:1525`, `:2495-2506`, `:5382-5383`; `src/Chat.php:2427-2429`, `:19721-19727`; `README.md:2066`
- **sugar-crush-web** ✅ — The hooks are registered per turn inside EngineBackend, the path every served turn takes. The appended warning text and the refusal reason render in the cards. — _Evidence:_ `src/Backend/EngineBackend.php:5382-5383`; `src/Host/TranscriptProjector.php:446-455`
- **Claude Code 2.1.301** ❌ — Workflow schema retries stop after 5; auto mode pauses after 3 consecutive / 20 total classifier blocks. — _Evidence:_ cc:how-claude-code-works#when-context-fills-up; cc:hooks#stop-decision-control; cc:permission-modes#when-auto-mode-falls-back
- **OpenCode 1.18.35** ✅ — DOOM_LOOP_THRESHOLD=3. Defaults to ask. This host sets `doom_loop: allow` everywhere. — _Evidence:_ OC/session/processor.ts:29,356-373; DOCS/permissions (Available Permissions)
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): [NEW: Doom-loop guard] same tool call repeated 3× with identical input triggers a `doom_loop` permission ask. 2.0.31 (❌): [NEW: Doom-loop guard] removed — `doom_loop` is not a v2 permission action — _Evidence:_ V2D/permissions (Actions note)
- **OpenClaw 2026.9.8** 🟡 — `tools.loopDetection.enabled: true` watches rolling tool history for no-progress patterns and unknown-tool retries; the post-compaction guard aborts a run that repeats the same (tool, args, result) after a compaction retry. — _Evidence:_ `oc/docs/tools/loop-detection.md`; `oc/docs/gateway/config-tools/built-in-tools.md`

</details>
<details><summary><b>TL-N3</b> 🆕 Code-mode execution tool (a sandboxed script tool that calls other tools / MCP namespaces and combines results)</summary>

- **sugar-crush** ❌ — The 30 built-in tools include no sandbox that runs a script able to call other agent tools or MCP namespaces and combine their results in one step. `Workflow` composes whole sub-agent stages declaratively (`{{stage.output}}` interpolation), and every stage is its own agentic run, so it does not save round trips the way code mode does. Bash runs real scripts but cannot reach the agent's tools or MCP servers. Batched parallel tool calls in one message are the only round-trip saver. — _Evidence:_ `src/Tools/BuiltIn/WorkflowTool.php:59`; `src/Tools/BuiltIn/Bash.php:45`; `src/Tools/BuiltIn/` (roster)
- **sugar-crush-web** ❌ — As SC. The Workflows panel can start, list, pause and resume workflow runs. — _Evidence:_ `src/Tools/BuiltIn/WorkflowTool.php:59`; `sugar-crush-web/src-web/components/panels/WorkflowPanel.vue`
- **Claude Code 2.1.301** 🟡 — Workflows (`agent()`, `parallel()`, `pipeline()`, schema-validated outputs) execute in a background runtime and return one consolidated result, but each step is an LLM subagent, not a direct tool/MCP function call; mods can register custom tools in JS. — _Evidence:_ cc:workflows; cc:tools-reference (Workflow); cc:plugins/mods/api#add-a-tool
- **OpenCode 1.18.35** 🟡 — MCP tools only. Off by default. — _Evidence:_ OC/tool/code-mode.ts:12-20,188-240; OC/tool/registry.ts:118-119
- **OpenCode 2.0.31** ✅ — Not present in 1.18.35. Default home of MCP tools plus `opencode.*` utilities (session_rename/move, worktree_*, models) and desktop `browser.*`. — _Evidence:_ V2D/tools (Execute, Sessions, Worktrees, Browser)
- **OpenClaw 2026.9.8** ✅ — When engaged the model mostly sees `exec`/`wait`; tools sit behind a guest catalog bridge with `API.list`/`API.read` declarations; Swarm `agents.run` fans out sub-agents with schema outputs. Default `node` executor uses `node:vm` (trusted host, not isolation); `quickjs` (bundled `code-mode-quickjs`) is the hardened guest. Enable via `tools.codeMode` (`"auto"` per model) or `agent exec --code-mode`. — _Evidence:_ `oc/docs/tools/code-mode.md`; `oc/docs/gateway/config-tools/tool-policy.md` (tools.codeMode); `oc/docs/tools/code-mode/executors.md`; `oc/docs/tools/swarm.md`

</details>

### EDT — Editing & code intelligence

<details><summary><b>EDT-01</b> Edit fuzzy / whitespace-tolerant matching</summary>

- **sugar-crush** ✅ — Disproportionately large or small loose matches are refused. new_string is re-indented to the shift, CRLF is kept by the fuzzy stages, and the result names the stage that matched. Failure hints list the closest lines. ApplyPatch uses the same chain. — _Evidence:_ `src/Tools/Edit/EditMatcher.php:43-213`; `src/Tools/Edit/IndentShift.php:50-53`; `src/Tools/Edit/*Matcher.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ❌ — One whitespace difference misses; Claude retries with more context. — _Evidence:_ cc:tools-reference#edit-tool-behavior
- **OpenCode 1.18.35** ✅ — Block-anchor: ≥3 lines, trimmed first and last lines as anchors, similarity threshold 0.65. A candidate must be unique unless replaceAll. Refuses if the matched span is ≥2× the lines or >4× the chars of oldString. — _Evidence:_ OC/tool/edit.ts:217-701
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): Replacers: exact, line-trimmed, block-anchor (Levenshtein), whitespace, indentation, escape, boundary, context, multi. 2.0.31 (🟡): Only typographic-variant and trailing-whitespace fallbacks (v1 had a 9-stage replacer chain) — _Evidence:_ V2C/tool/plugin/edit.ts:223
- **OpenClaw 2026.9.8** ✅ — Mismatch errors list up to 3 closest lines with similarity and indentation/escaping hints. — _Evidence:_ `oc/docs/tools/index.md`; prior report `11-openclaw.md` §6.1 (re-checked against `oc/dist/tools-BEx53dKD.mjs`)

</details>
<details><summary><b>EDT-02</b> Read-before-write enforcement / stale-file detection</summary>

- **sugar-crush** 🟡 — ReadLedger records the hash of the bytes Read saw and of what Edit/Write/Patch wrote. A later write of a file whose bytes changed is refused with "Read it again" (a plain `touch` does not count). At most 256 entries, merged across parallel and turn forks. A file never read is never refused. — _Evidence:_ `src/Tools/ReadLedger.php:12-70,203-220`; `Edit.php:295-302`; `Write.php:273-279`; `ApplyPatch.php:245`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/ToolCard.vue:60`
- **Claude Code 2.1.301** ✅ — File-changed system-reminder when a read file changes; symlink write refusal. — _Evidence:_ cc:tools-reference#edit-tool-behavior; cc:prompt-caching#editing-files-in-your-repository
- **OpenCode 1.18.35** ❌ — edit.txt line 4: “This tool will error if you attempt an edit without reading the file.” No such check exists in edit.ts, write.ts or apply_patch.ts at the tag; only the external-directory assertion. — _Evidence:_ OC/tool/edit.txt:4; OC/tool/edit.ts (no FileTime/mtime check)
- **OpenCode 2.0.31** ❌ — 1.18.35 (❌): No read-before-edit or stale-file check despite edit.txt claiming one; only a per-file semaphore. 2.0.31 (❌): Still no read-before-edit/stale check; the false claim was removed from the edit description — _Evidence:_ V2C/tool/plugin/edit.ts:223
- **OpenClaw 2026.9.8** ❌ — Per-file mutation queue serializes writes and writes are read-back verified, but an unread or externally changed file can be edited/overwritten. — _Evidence:_ `oc/dist/tools-BEx53dKD.mjs` (no staleness check found)

</details>
<details><summary><b>EDT-03</b> Diff preview before applying (approval)</summary>

- **sugar-crush** 🟡 — `permissionSubjectLines` hands non-shell calls to `Message::describeToolCall`, which clips every argument at 80 chars (verified-by-repro: a Write's content and a long WebFetch URL are both clipped). After the write, the tool row renders the diff (`diffPreviewRows`), and `/diff` exists. — _Evidence:_ `src/Renderer.php:6613-6648,5160-5175`; `src/Message.php:367,383-414`
- **sugar-crush-web** 🟡 — A Write/Edit ask in the TUI-style flow is approved from JSON. — _Evidence:_ `src-web/components/PermissionCard.vue:53-56`
- **Claude Code 2.1.301** ✅ — acceptEdits/auto skip the prompt; for file edits "Yes, and don't ask again" lasts until session end. — _Evidence:_ cc:permissions#permission-system; cc:vs-code
- **OpenCode 1.18.35** ✅ — The diff is passed in permission metadata and rendered with theme colors and line numbers. ctrl+f expands to fullscreen. — _Evidence:_ TUI/routes/session/permission.tsx:33-83; OC/tool/edit.ts
- **OpenCode 2.0.31** ✅ — The diff is passed in permission metadata and rendered with theme colors and line numbers. ctrl+f expands to fullscreen. — _Evidence:_ TUI/routes/session/permission.tsx:33-83; OC/tool/edit.ts
- **OpenClaw 2026.9.8** ❌ — Approvals cover exec, plugins and OpenClaw config changes; `read-only` mode removes mutation tools entirely. — _Evidence:_ `oc/docs/web/control-ui/feature-reference.md`; `oc/docs/tools/exec-approvals.md`

</details>
<details><summary><b>EDT-04</b> Auto-format after edit</summary>

- **sugar-crush** ❌ — No formatter setting or hook. Formatting changes made outside the agent show up as stale-file refusals. — _Evidence:_ `src/Hooks/BuiltIn/` listing
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:hooks-guide#auto-format-code-after-edits
- **OpenCode 1.18.35** ✅ — prettier, biome, ruff, gofmt, rustfmt, pint (PHP) and others. Custom formatter commands use `$FILE`. — _Evidence:_ DOCS/formatters; OC/format/index.ts:120-140; OC/tool/edit.ts:112,156
- **OpenCode 2.0.31** ✅ — prettier, biome, ruff, gofmt, rustfmt, pint (PHP) and others. Custom formatter commands use `$FILE`. — _Evidence:_ DOCS/formatters; OC/format/index.ts:120-140; OC/tool/edit.ts:112,156
- **OpenClaw 2026.9.8** ❌ — No auto-format after edit — _Evidence:_ `oc/docs/tools/index.md`

</details>
<details><summary><b>EDT-05</b> Lint feedback loop after edit</summary>

- **sugar-crush** ✅ — Runs on PostToolUse for Write/Edit/ApplyPatch through the bounded spawn path, 30 s per lint, and never refuses the call. A quoted `{file}` placeholder makes it injectable (ISSUES P2). — _Evidence:_ `src/Cli/Bootstrap.php:5920-5928`; `src/Lint/LintRunner.php:45-92,166-200`; `src/Hooks/BuiltIn/PostEditLintHook.php:66`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:hooks#exit-code-2; cc:plugins/code-intelligence
- **OpenCode 1.18.35** 🟡 — Docs recommend putting lint commands in AGENTS.md instead. — _Evidence:_ DOCS/lsp (Best Practices)
- **OpenCode 2.0.31** ❌ — 1.18.35 (🟡): No lint-command loop; lint feedback only via LSP servers like eslint/oxlint when LSP is enabled. 2.0.31 (❌): No LSP → no lint feedback; docs say run lint/typecheck commands instead — _Evidence:_ V2D/migrate-v1 (lsp)
- **OpenClaw 2026.9.8** ❌ — No lint feedback loop (only a Python shell-syntax preflight on exec) — _Evidence:_ `oc/docs/tools/exec.md`

</details>
<details><summary><b>EDT-06</b> LSP diagnostics after edit fed back to the model</summary>

- **sugar-crush** ✅ — Uses the same memoised servers as the Lsp tool. Only registered when `lsp` is configured. — _Evidence:_ `src/Hooks/BuiltIn/PostEditDiagnosticsHook.php:50,53,79,162`; `src/Cli/Bootstrap.php:5936-5939`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🔌 — IDE alternative: `mcp__ide__getDiagnostics` via `/ide`. — _Evidence:_ cc:tools-reference#lsp-tool-behavior; cc:vs-code#the-built-in-ide-mcp-server
- **OpenCode 1.18.35** ✅ — touchFile → wait for publishDiagnostics (150 ms debounce, 5 s doc timeout) → “LSP errors detected in this file, please fix: <diagnostics>”. LSP is **disabled by default**; this host sets `lsp: true`. — _Evidence:_ OC/tool/edit.ts:197-200; OC/lsp/lsp.ts:151; DOCS/lsp
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): With `lsp` enabled: waits for fresh diagnostics, appends ≤20 ERRORs per file to edit/write/patch results. 2.0.31 (❌): No LSP diagnostics after edits (TODO comments in edit/write) — _Evidence:_ V2C/tool/plugin/edit.ts:205; V2C/tool/plugin/write.ts:44; V2C/file-mutation.ts:131
- **OpenClaw 2026.9.8** ❌ — Bundle LSP tools are hover/definition/references only. — _Evidence:_ `oc/dist/agent-bundle-lsp-runtime-DcB6yrNC.mjs`

</details>
<details><summary><b>EDT-07</b> LSP server auto-detect / auto-install</summary>

- **sugar-crush** ❌ — Up to 8 servers, a 2 s handshake each, started once per launch and shut down at exit. Problems are reported in the transcript. — _Evidence:_ `src/LSP/LspLauncher.php:9-62`; `src/Cli/Bootstrap.php:8839-8863`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — Servers start lazily on first matching edit; restartOnCrash. — _Evidence:_ cc:plugins/code-intelligence#accept-or-dismiss-the-recommendation-dialog
- **OpenCode 1.18.35** ✅ — Includes PHP Intelephense, auto-installed for PHP projects. Custom servers via `lsp.<name>.command`. — _Evidence:_ DOCS/lsp (Built-in table)
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): 35+ LSP servers auto-detected by extension/markers, many auto-installed (OPENCODE_DISABLE_LSP_DOWNLOAD). 2.0.31 (❌): No LSP servers are started in v2 — _Evidence:_ V2D/migrate-v1 (lsp)
- **OpenClaw 2026.9.8** ❌ — LSP config only from Claude bundles. — _Evidence:_ `oc/docs/plugins/bundles.md`

</details>
<details><summary><b>EDT-08</b> Repo map / symbol index</summary>

- **sugar-crush** ✅ — Cache lives under `~/.sugar-crush/cache/repomap/`. ctags runs with absolute paths and `--options=NONE`. `repoMap.enabled` adds a prompt block. — _Evidence:_ `src/Tools/BuiltIn/RepoMapTool.php:54`; `src/RepoMap/CtagsSymbolExtractor.php:411-427`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `server.info` tools
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference; local: catalog (serena)
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ DOCS/server (Files); DOCS/tools (lsp)
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ DOCS/server (Files); DOCS/tools (lsp)
- **OpenClaw 2026.9.8** ❌ — No repo map or symbol index — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>EDT-09</b> External file-change watcher</summary>

- **sugar-crush** 🟡 — `ReadLedger::notice()` feeds `TurnContextBlock::changedSinceRead` every turn. `AiCommentWatcher` (user-tier, off by default) polls every 2 s while idle, at most 5000 files. — _Evidence:_ `src/Tools/ReadLedger.php:230-283`; `src/Runtime.php:5551`; `src/Backend/EngineBackend.php:1730`; `src/Support/AiCommentWatcher.php:49-75`
- **sugar-crush-web** ❓ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:agent-sdk/modifying-system-prompts; cc:hooks#filechanged
- **OpenCode 1.18.35** 🟡 — `watcher.ignore` globs. `OPENCODE_EXPERIMENTAL_FILEWATCHER` watches the whole dir; `…DISABLE_FILEWATCHER` turns it off. — _Evidence:_ DOCS/config (Watcher); DOCS/plugins (File Events)
- **OpenCode 2.0.31** 🟡 — `watcher.ignore` globs. `OPENCODE_EXPERIMENTAL_FILEWATCHER` watches the whole dir; `…DISABLE_FILEWATCHER` turns it off. — _Evidence:_ DOCS/config (Watcher); DOCS/plugins (File Events)
- **OpenClaw 2026.9.8** 🟡 — No model notification of external edits; skills watcher covers SKILL.md only. — _Evidence:_ `oc/docs/web/control-ui.md`

</details>
<details><summary><b>EDT-10</b> Line-ending / encoding preservation</summary>

- **sugar-crush** 🟡 — Verified-by-repro: Edit `old_string:"line two"` with new_string `"line two\nX"` on a CRLF file writes `line two\nX\r\n` (mixed endings). Write and ApplyPatch Add write the model's bytes as given. — _Evidence:_ `src/Tools/Edit/IndentShift.php:50-53`; `src/Tools/Edit/ExactMatcher.php:266-280`; `src/Support/AtomicFileWriter.php:184-220`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:tools-reference#non-utf-8-files
- **OpenCode 1.18.35** ✅ — Detects and normalizes line endings, then converts back. BOM is split and re-joined. — _Evidence:_ OC/tool/edit.ts:20-33,97-111
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): edit preserves BOM and CRLF/LF line endings. 2.0.31 (✅): Preserves BOM and mixed CRLF/LF endings per edited region — _Evidence:_ V2C/tool/plugin/edit.ts:42,105-185,301-308; V2CL v2.0.28
- **OpenClaw 2026.9.8** 🟡 — Read normalizes BOM/CRLF for matching; apply_patch preserves nearby line endings; edit CRLF round-trip unverified — _Evidence:_ `oc/dist/core-coding-tools-BmfxDnfH.mjs` (preferredEnding); `oc/dist/tools-BEx53dKD.mjs`

</details>
<details><summary><b>EDT-N1</b> 🆕 Auto-test reflection loop (run tests after edits and feed failures back automatically)</summary>

- **sugar-crush** ✅ — Both keys are user-tier. The run is beat-aware within the turn's idle ceiling. — _Evidence:_ `src/Hooks/BuiltIn/AutoTestHook.php:64`; `src/Cli/Bootstrap.php:5977-5984`
- **sugar-crush-web** 🟡 — `Bootstrap::hooks()` registers AutoTestHook for the served workspace. The run is a Stop hook, so the browser sees only the follow-up step a failure triggers, not a card for the test run. `settings.set` refuses Exec-class keys. — _Evidence:_ `src/Cli/Bootstrap.php:1978`, `:5968-5984`; `src/Config/Settings/Definitions/ToolSettings.php:93-108`; `src/Config/Settings/RiskClass.php:46`
- **Claude Code 2.1.301** 🟡 — Exit-2 PostToolUse hooks put stderr in front of Claude immediately; LSP plugins report type errors after edits. — _Evidence:_ cc:hooks#run-tests-after-file-changes; cc:skills#run-and-verify-your-app
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ OC/tool/edit.ts:197-200
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2C/tool/plugin/edit.ts:205
- **OpenClaw 2026.9.8** ❌ — Execution Bias tells the model to verify before finalizing, but nothing runs tests automatically. — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>EDT-N2</b> 🆕 AI-comment watch mode (`AI!` / `AI?` comments in saved files trigger the agent)</summary>

- **sugar-crush** ✅ — Comments already present at startup are ignored. Each fires once. Dispatched through the normal submit path, so hooks and the gate apply. — _Evidence:_ `src/Support/AiCommentWatcher.php:9-75`; `src/Chat.php:18472-18477`
- **sugar-crush-web** ❌ — The `watchFiles` setting shows in the Settings form, but nothing in SessionHost or the server reads it. — _Evidence:_ `src/Chat.php:18472-18477`; `src/Config/Settings/Definitions/InterfaceSettings.php:100-107`
- **Claude Code 2.1.301** ❌ — `FileChanged` hooks could be scripted to react to file saves, but nothing triggers the agent from code comments. — _Evidence:_ cc:hooks#filechanged
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ❌ — Watchers exist only for skills and config. — _Evidence:_ `oc/docs/tools/skills.md` (Skills watcher); `oc/docs/gateway/configuration/hot-reload.md`

</details>

### SH — Shell execution

<details><summary><b>SH-01</b> PTY-backed execution</summary>

- **sugar-crush** 🟡 — The interactive mode never accepts input and is killed once its output stops. Unavailable without ext-ffi and `/dev/ptmx`. — _Evidence:_ `src/Tools/Concerns/CapturesProcessOutput.php:379,428-555`; `src/Support/ProcessContainment.php:512-527`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ❌ — No PTY mode is documented; output is captured to a file and read back (5 GB kill, 30K inline); Windows notes confirm a stdin-waiting child gets EOF. — _Evidence:_ cc:tools-reference#bash-tool-behavior; cc:tools-reference#windows-encoding-and-exit-codes
- **OpenCode 1.18.35** ❌ — `/pty` routes and CORE/pty back the interactive terminal panel, not the bash tool. — _Evidence:_ OC/tool/shell.ts:293-310; CORE/pty/; OC/server/routes/instance/httpapi/groups/pty.ts
- **OpenCode 2.0.31** ❌ — 1.18.35 (❌): Agent bash runs as plain child processes (no PTY); PTY API exists only for user terminals in web/desktop. 2.0.31 (❌): Agent shell still non-PTY; persistent PTYs back the user /terminal panel (survive restarts) — _Evidence:_ V2C/persistent-pty/; V2T slash /terminal
- **OpenClaw 2026.9.8** ✅ — Default is non-PTY pipes. — _Evidence:_ `oc/docs/tools/exec.md` (pty)

</details>
<details><summary><b>SH-02</b> Persistent shell session (cwd/env persist between calls)</summary>

- **sugar-crush** ❌ — Every call starts at the root through a cd guard. — _Evidence:_ `src/Tools/BuiltIn/Bash.php:200-202,428`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — `CLAUDE_BASH_MAINTAIN_PROJECT_WORKING_DIR`; `CLAUDE_ENV_FILE` and SessionStart hooks to persist env; subagents never carry cwd. — _Evidence:_ cc:tools-reference#what-persists-between-commands
- **OpenCode 1.18.35** ❌ — The description says “Use workdir instead of cd”. — _Evidence:_ OC/tool/shell/prompt.ts:19-23; PLUG/index.ts:270-273
- **OpenCode 2.0.31** ❌ — The description says “Use workdir instead of cd”. — _Evidence:_ OC/tool/shell/prompt.ts:19-23; PLUG/index.ts:270-273
- **OpenClaw 2026.9.8** ❌ — Secret-looking variables excluded from the snapshot. — _Evidence:_ `oc/docs/tools/exec.md` (Notes)

</details>
<details><summary><b>SH-03</b> Timeouts (default / max)</summary>

- **sugar-crush** ✅ — `bashTimeoutSeconds` / `bashMaxTimeoutSeconds` rebind per turn. On timeout the output so far is kept with a marker. LLM calls are separate and have no total timeout. — _Evidence:_ `src/Tools/BuiltIn/Bash.php:96-104,520-545`; `src/Tools/Concerns/CapturesProcessOutput.php:308-365`; `src/Tools/BuiltIn/PtyRun.php:55-57`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Background commands: unlimited interactively, 30 min/2 h in unattended runs. — _Evidence:_ cc:tools-reference#timeout-and-output-limits
- **OpenCode 1.18.35** ✅ — On timeout the process is killed and the result says “retry with a larger timeout value”. — _Evidence:_ OC/tool/shell.ts:347,540-565,615-618
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Default 2 min (OPENCODE_EXPERIMENTAL_BASH_DEFAULT_TIMEOUT_MS); model may pass larger timeout; no max cap. 2.0.31 (✅): Foreground default 2 min; `timeout: 0` disables; background has no timeout by default — _Evidence:_ V2C/tool/plugin/shell.ts:44-60,201
- **OpenClaw 2026.9.8** ✅ — Expiry kills even backgrounded processes; approval-backed runs post a running notice after 10 s. — _Evidence:_ `oc/docs/tools/exec.md`; `oc/docs/gateway/config-tools/built-in-tools.md`

</details>
<details><summary><b>SH-04</b> Background processes & kill</summary>

- **sugar-crush** ❌ — Verified-by-repro: `sleep 61 & echo x` with timeout 3 reports "process group were killed", yet `sleep 61` is still alive afterwards. The group id is read from the leader pid after the leader has been reaped. — _Evidence:_ `src/Tools/Concerns/CapturesProcessOutput.php:272-276,350-365`; `src/Support/ProcessContainment.php:444-457`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/panels/BackgroundPanel.vue`
- **Claude Code 2.1.301** ✅ — Memory-pressure reaping after 30 min idle; 5 GB output kill. — _Evidence:_ cc:interactive-mode#background-bash-commands
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ OC/tool/shell.ts:308,550-554
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): No background jobs natively; abort kills the (detached) process group with 3 s force-kill; 🔌 opencode-pty. 2.0.31 (✅): Native background jobs with completion notification; running commands can be moved to background — _Evidence:_ V2C/tool/plugin/shell.ts:254-266
- **OpenClaw 2026.9.8** ✅ — Process sessions scoped per agent; `!poll`/`!stop` in chat. — _Evidence:_ `oc/docs/tools/exec.md`; `oc/docs/gateway/background-process.md`

</details>
<details><summary><b>SH-05</b> Interactive command handling</summary>

- **sugar-crush** ✅ — sudo/ssh/git credential prompts fail on stderr instead of hanging. — _Evidence:_ `src/Support/ProcessContainment.php:81-99,165-217`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ❌ — Not documented as a feature; Windows docs state a child waiting on stdin gets EOF instead of hanging; Monitor can watch output but not answer prompts. — _Evidence:_ cc:tools-reference#bash-tool-behavior; cc:tools-reference#windows-encoding-and-exit-codes
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ opencode-pty README
- **OpenCode 2.0.31** ❌ — 1.18.35 (❌): No stdin interaction with running commands (🔌 opencode-pty sends input). 2.0.31 (❌): No stdin interaction with agent commands; opencode-pty is V1-API (user /terminal is interactive) — _Evidence:_ npm view opencode-pty dependencies; V2T slash /terminal
- **OpenClaw 2026.9.8** ✅ — Login/interactive shell wrappers require human approval; channel logins refused. — _Evidence:_ `oc/docs/tools/exec.md`; `oc/skills/tmux/SKILL.md`

</details>
<details><summary><b>SH-06</b> OS sandbox for shell (bubblewrap / Seatbelt / containers)</summary>

- **sugar-crush** 🟡 — `--unshare-all --die-with-parent`. `.git/hooks`, `.git/config`, `.sugar-crush` and `.mcp.json` are ro-bound. Fails closed when bwrap cannot start (probed once). User tier only; restart to apply. It is a write jail, not a read jail. PtyRun, hooks, lint/test, LSP and MCP all run unsandboxed (PtyRun breaks the docs' "every command" claim). — _Evidence:_ `src/Tools/Sandbox/Bubblewrap.php:50-244`; `src/Tools/BuiltIn/Bash.php:463-482`; `src/Tools/BuiltIn/PtyRun.php:163-170`; `docs/PERMISSIONS.md:1165-1212`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Writes limited to cwd/added dirs/temp; protected paths denied inside; `sandbox.credentials` masks secrets; built on open-source sandbox-runtime; `dangerouslyDisableSandbox` retry goes through permissions. — _Evidence:_ cc:sandboxing
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ DOCS/ecosystem
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ DOCS/ecosystem
- **OpenClaw 2026.9.8** ✅ — Modes off/non-main/all, per-agent, creator-role requirements; Docker defaults network none, read-only root, cap-drop ALL. — _Evidence:_ `oc/docs/gateway/sandboxing.md`; `oc/docs/gateway/sandboxing/docker-backend.md`

</details>
<details><summary><b>SH-07</b> Network restriction for shell</summary>

- **sugar-crush** 🟡 — Without the sandbox there is no network control apart from the auto-mode classifier and asking for WebFetch. — _Evidence:_ `src/Tools/Sandbox/Bubblewrap.php:97-104,139-149`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — WebFetch domain rules honored by the sandbox; `allowManagedDomainsOnly`. — _Evidence:_ cc:sandboxing#network-isolation; cc:settings-reference
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — Host (unsandboxed) exec has no network restriction. — _Evidence:_ `oc/docs/gateway/sandboxing/docker-backend.md`; `oc/docs/gateway/secrets/secret-store-and-egress.md`

</details>
<details><summary><b>SH-08</b> Output streaming to UI</summary>

- **sugar-crush** 🟡 — The heartbeat only keeps the turn's idle watchdog alive. Only Task streams live activity. — _Evidence:_ `src/Tools/AcceptsHeartbeat.php:7-42`; `src/Tools/StreamsActivity.php:7-30`
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` (no tool.progress)
- **Claude Code 2.1.301** ✅ — Ctrl+O / click expands collapsed results. — _Evidence:_ cc:tools-reference#output-limits; cc:interactive-mode#shell-mode-with-prefix
- **OpenCode 1.18.35** ✅ — `metadata.output` is updated as output arrives. — _Evidence:_ OC/tool/shell.ts:440-590
- **OpenCode 2.0.31** ✅ — `metadata.output` is updated as output arrives. — _Evidence:_ OC/tool/shell.ts:440-590
- **OpenClaw 2026.9.8** ✅ — Partial output streams into the same tool card (TUI, Control UI terminal-style rows) — _Evidence:_ `oc/docs/web/tui.md` (Tool output); `oc/docs/web/control-ui/feature-reference.md`

</details>
<details><summary><b>SH-09</b> Output truncation</summary>

- **sugar-crush** ✅ — `toolOutputCapBytes`, `toolSpillWindowPercent` (30%), `toolSpillMinCapBytes`. The cap reserves room for stderr first. MCP has its own `mcpResultCapBytes`. — _Evidence:_ `src/Tools/Concerns/TruncatesOutput.php:152`; `src/Support/ToolOutputSpill.php:57-87`; `src/Tools/ToolLimits.php:53-150`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/session.ts:187-201`
- **Claude Code 2.1.301** ✅ — Configurable up to 150K read-back / 128K inline. — _Evidence:_ cc:tools-reference#output-limits
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ OC/tool/shell.ts:440-590; OC/tool/truncate.ts
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ OC/tool/shell.ts:440-590; OC/tool/truncate.ts
- **OpenClaw 2026.9.8** ✅ — Output bounded by live tool-result caps (16k–64k chars by window); Tokenjuice plugin compacts noisy output — _Evidence:_ `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`; `oc/docs/tools/tokenjuice.md`

</details>
<details><summary><b>SH-10</b> Dangerous-command detection</summary>

- **sugar-crush** ✅ — The breaker reads quote-removed words, prefixes and abbreviated flags. ConfirmRemoveHook catches rm -r/-f, find -delete, shred and dd of=, and stands down in bypass. All of them look at `command` only, so PtyRun steps pass through unseen. — _Evidence:_ `src/Permissions/PermissionGate.php:605,1170-1365`; `src/Hooks/BuiltIn/ConfirmRemoveHook.php:57-94`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/PermissionCard.vue:52`
- **Claude Code 2.1.301** ✅ — Exec wrappers always prompt; wrappers like `timeout`/`nice` stripped before matching; `curl`/`wget` never auto-approved. — _Evidence:_ cc:permissions#compound-commands; cc:permission-modes#critical-paths; cc:security
- **OpenCode 1.18.35** 🟡 — `BashArity` derives “always” patterns (git=2, npm run=3…). cc-safety-net is cached on this host. — _Evidence:_ OC/tool/shell.ts:257-290,378-413; OC/permission/arity.ts; LOCAL: ~/.cache/opencode/packages/cc-safety-net@latest
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Commands parsed per sub-command for permission prefixes + external-path checks; no danger classifier (🔌 cc-safety-net). 2.0.31 (🟡): Tree-sitter scanner (or experimental portable scanner) infers commands/dirs; no danger classifier — _Evidence:_ V2D/permissions (Scanner)
- **OpenClaw 2026.9.8** ✅ — Three consecutive reviewer denials escalate to a human; reviewer-directed text in commands is denied back. — _Evidence:_ `oc/docs/tools/exec.md` (Modes, Inline eval)

</details>
<details><summary><b>SH-N1</b> 🆕 Child-process environment hardening (credential stripping, setsid detach, non-interactive env)</summary>

- **sugar-crush** ✅ — SECRET_ENV_PATTERNS plus provider key names, overridable with `secretEnvAllowlist`. `tools/check-child-lifetimes.php` currently reports 60 libs, 28 proc_open sites, 0 problems. — _Evidence:_ `src/Support/ProcessContainment.php:81-143,266-276`; `tools/check-child-lifetimes.php`
- **sugar-crush-web** ✅ — Workspace-host processes also get their secrets on stdin, not argv (a6). — _Evidence:_ `src/Support/ProcessContainment.php:56`, `:234-276`; `src/Server/Workspace/WorkspaceHostProcess.php`
- **Claude Code 2.1.301** ✅ — Sandbox `credentials` deny/mask modes (sentinel swapped at the proxy); `CLAUDE_CODE_SCRIPT_CAPS`; detached children of stopped tasks are killed; claude-code-action enables the scrub automatically for untrusted users. — _Evidence:_ cc:env-vars#what-the-subprocess-environment-scrub-removes; cc:sandboxing#protect-credentials; cc:interactive-mode#how-backgrounding-works
- **OpenCode 1.18.35** 🟡 — `shell.env` lets plugins add env. Nothing is removed from the inherited environment. — _Evidence:_ OC/tool/shell.ts:293-310; PLUG/index.ts:270-273
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2C/shell.ts:305; V2D/build/plugins/index (Shell)
- **OpenClaw 2026.9.8** 🟡 — `GITHUB_TOKEN` cleared for gateway exec, SecretRef credentials reach processes only as sentinels via the egress proxy, `OPENCLAW_SHELL` marker, pager normalization, process-group cleanup confirmation. Docs warn plain env credentials not moved to SecretRefs "remain plain" in the inherited environment. — _Evidence:_ `oc/docs/tools/exec.md`; `oc/docs/gateway/secrets/runtime-model.md`; `oc/docs/gateway/config-tools/github-identity.md`

</details>

### PER — Permissions & safety

<details><summary><b>PER-01</b> Permission modes (default / acceptEdits / plan / bypass / auto)</summary>

- **sugar-crush** ✅ — The order is breaker, then rules (first match), then the mode's evaluator. Read-only shell lines run unasked in default and accept-edits. accept-edits grants in-root Edit/Write/ApplyPatch and scoped mkdir/touch/rmdir. — _Evidence:_ `src/Permissions/PermissionMode.php:11-193`; `src/Permissions/PermissionGate.php:587-1108`; `src/Cli/Bootstrap.php:178-180`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/StatusBar.vue:6`; `sugar-crush/docs/SERVER.md:602-605`
- **Claude Code 2.1.301** ✅ — Auto = classifier reviews actions (server-side review on 1P); deny rules apply in every mode; auto needs supported models (Opus/Sonnet 4.6+, Haiku 5.5, Fable). — _Evidence:_ cc:permission-modes#available-modes; cc:permission-modes#eliminate-prompts-with-auto-mode
- **OpenCode 1.18.35** 🟡 — Defaults are permissive: `'*'` allow, with doom_loop, external_directory and *.env read on ask. Docs say *.env is **deny**; code says **ask**. — _Evidence:_ OC/agent/agent.ts:119-135; DOCS/permissions (Defaults)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): allow/ask/deny rules + `--auto` (approve all not denied); plan agent; no named acceptEdits-style modes. Default: allow. 2.0.31 (🟡): Ordered allow/ask/deny `permissions` array + `--auto`; per-project auto-accept (desktop mod+shift+a) — _Evidence:_ V2D/permissions; V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — Without sandbox or config the default is full access (host exec `mode=full`). — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/tools/exec.md`

</details>
<details><summary><b>PER-02</b> Mode toggle in UI (Shift+Tab etc.)</summary>

- **sugar-crush** ✅ — A switch applies between turns, keeps rules and session grants, and is never written to settings. Every prompt names the current mode and how to change it. — _Evidence:_ `docs/PERMISSIONS.md:19-60`; `src/Permissions/PermissionGate.php:302-331`
- **sugar-crush-web** ✅ — Offers all six even when the server will refuse two. — _Evidence:_ `src-web/views/SessionView.vue:88-94`
- **Claude Code 2.1.301** ✅ — VS Code/Desktop mode selectors. — _Evidence:_ cc:interactive-mode#keyboard-shortcuts; cc:permissions#permission-system
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/permissions (Auto mode)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Palette 'Enable/Disable auto-approve permissions' (muted 'auto' tag); Tab switches build↔plan. 2.0.31 (🟡): Per-project auto-accept toggle (desktop mod+shift+a), `--auto` flag; Shift+Tab cycles agents (plan) — _Evidence:_ V2CL v2.0.28; V2CLI: `opencode --help`
- **OpenClaw 2026.9.8** ✅ — No keyboard mode-cycle in the TUI. — _Evidence:_ `oc/docs/gateway/permission-modes.md` (Change permissions during a task)

</details>
<details><summary><b>PER-03</b> Allow / deny / ask rules with patterns (e.g. Bash(git:*))</summary>

- **sugar-crush** ✅ — An allow rule must cover every segment and fails closed on substitution or a writing redirect. A deny/ask rule fires on any segment and also binds Bash and PtyRun to each other. Patterns written with operators are matched by structure. Verified-by-repro: deny `Bash(rm:*)` lets `rm -rf build` through (allow `Bash(git:*)` grants nothing). — _Evidence:_ `src/Permissions/PermissionRule.php:195-207,385-468,691-942,1012-1105`
- **sugar-crush-web** 🧪 — — — _Evidence:_ `sugar-crush/src/Protocol/Methods/PermissionMethods.php:145-162`
- **Claude Code 2.1.301** ✅ — Parameter rules (e.g. `Agent(model:opus)`) for deny/ask; compound commands split; read-only command allowlist. — _Evidence:_ cc:permissions#permission-rule-syntax; cc:tools-reference#configure-tools-with-permission-rules-and-hooks
- **OpenCode 1.18.35** ✅ — `*` and `?` wildcards; `~` / `$HOME` expansion. An unmatched rule defaults to ask. — _Evidence:_ OC/permission/index.ts:27-37; DOCS/permissions
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Per-tool pattern objects (bash parsed commands, file globs, URLs, subagent/skill names), wildcards, last-match-wins. 2.0.31 (✅): Actions renamed: bash→shell, task→subagent, write/patch→edit; doom_loop and lsp actions gone. — _Evidence:_ V2D/permissions (Rules, Matching, Actions)
- **OpenClaw 2026.9.8** ✅ — Deny wins; `allow` and `alsoAllow` mutually exclusive per scope. — _Evidence:_ `oc/docs/gateway/config-tools/tool-policy.md`; `oc/docs/tools/exec-approvals.md`

</details>
<details><summary><b>PER-04</b> Rule scopes (user / project / local / managed / session)</summary>

- **sugar-crush** 🟡 — A trusted project can only add `disabledTools`, never grants. Session grants travel with `--resume` and tabs. — _Evidence:_ `src/Config/LayeredSettings.php:819-860`; `docs/PERMISSIONS.md:552-576`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Protocol/Methods/PermissionMethods.php:61-66`
- **Claude Code 2.1.301** ✅ — "Don't ask again" saves to `.claude/settings.local.json` at the repo root (worktree-aware). — _Evidence:_ cc:permissions#settings-precedence
- **OpenCode 1.18.35** 🟡 — The always dialog says “This will allow X until OpenCode is restarted.” Approved rules live in InstanceState only (v2 adds persisted approvals). — _Evidence:_ TUI/routes/session/permission.tsx:140-144; OC/permission/index.ts:23-60
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Remote, global, project, managed, OPENCODE_PERMISSION; 'always' approvals in-memory until restart (not persisted). 2.0.31 (✅): Saved approvals are durable, project-scoped allow rules (v1: in-memory until restart) — _Evidence:_ V2D/permissions (Approvals)
- **OpenClaw 2026.9.8** ✅ — No project-file scope. — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/tools/exec-approvals.md`

</details>
<details><summary><b>PER-05</b> Per-agent permission overrides</summary>

- **sugar-crush** ✅ — The preset's gate rides the reserved `subagent-grant` hook, which cannot be disabled. Foreign presets fall back to default. — _Evidence:_ `docs/PERMISSIONS.md:85-110`; `src/Hooks/BuiltIn/SubAgentGrantHook.php:95`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Parent bypass/acceptEdits/auto override a subagent's mode; plugin agents can't set permissionMode. — _Evidence:_ cc:sub-agents#permission-modes
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ OC/agent/subagent-permissions.ts; DOCS/agents (Permissions, Task permissions)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Agent `permission` overrides; subagents inherit parent deny/external_directory rules; task perms per subagent. 2.0.31 (✅): V2 permissions doc: “A custom subagent uses its own permissions, not a subset of its parent's permissions.” The parent's `subagent` rules control which children it may launch. — _Evidence:_ V2D/permissions (Agents); V2D/agents (Modes)
- **OpenClaw 2026.9.8** ✅ — Per-agent tools/sandbox/exec policy; sub-agents hard-denied gateway/cron/message/sessions_send etc. — _Evidence:_ `oc/docs/tools/multi-agent-sandbox-tools.md`; `oc/docs/tools/subagents/tool-policy.md`

</details>
<details><summary><b>PER-06</b> Approval UI options (once / session / always / edit / explain)</summary>

- **sugar-crush** ✅ — Pipelines are granted one pattern per part. A leading in-project `cd` is ignored. Hook asks and security asks never offer "always". `/permissions revoke` removes grants. The full shell command is shown (control and bidi characters made visible, max 12 rows with head and tail kept). — _Evidence:_ `docs/PERMISSIONS.md:736-1000`; `src/Permissions/SessionPermissionMemo.php`; `src/Renderer.php:6658-6684`
- **sugar-crush-web** ✅ — Same cards in the approvals drawer and grid tiles. — _Evidence:_ `src-web/components/PermissionCard.vue`
- **Claude Code 2.1.301** ✅ — "No" without comment stops the turn; one-time-only prompts when a rule couldn't be shown fully; numbered lists in screen-reader mode. — _Evidence:_ cc:permissions#permission-system; cc:permissions#add-a-comment-when-you-answer-a-permission-prompt
- **OpenCode 1.18.35** ✅ — Reject → “Tell OpenCode what to do differently” → a CorrectedError carries the feedback to the model. Reject cascades to the session's other pending asks. Rejection stops the loop unless `experimental.continue_loop_on_deny`. — _Evidence:_ TUI/routes/session/permission.tsx:402-490; CORE/v1/config/config.ts:179-181
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Allow once / Allow always (bash arity prefix, e.g. 'git checkout *') / Reject with feedback; diff; fullscreen. 2.0.31 (✅): Allow once / Allow always (saved per project) / Reject (+feedback; cascades to pending) — _Evidence:_ V2D/permissions (Approvals); V2T/routes/session/permission.tsx:56,170,328
- **OpenClaw 2026.9.8** ✅ — Free-text "yes" never authorizes; `openclaw approvals resolve`. — _Evidence:_ `oc/docs/cli/approvals.md`; `oc/docs/gateway/permission-modes.md`

</details>
<details><summary><b>PER-07</b> Auto-mode classifier (AI decides what is safe)</summary>

- **sugar-crush** 🟡 — Verified-by-repro (gate verdicts): `bash <(curl …)`, download-then-`sh`, `wget … piped to python3`, `git clean -fdx` and exfiltration through GET URL paths are all Allow. — _Evidence:_ `src/Permissions/SafetyClassifier.php:79-430`; `src/Permissions/PermissionGate.php:853-927`; `src/Permissions/ExecReviewer.php:50`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/docs/SERVER.md:575-578`
- **Claude Code 2.1.301** ✅ — `/auto-mode-setup`, `claude auto-mode config/defaults/critique/reset`; 3-in-a-row / 20-total blocks fall back to prompts; Recently-denied tab with retry; reviews SendMessage and subagent handbacks. — _Evidence:_ cc:permission-modes#how-auto-mode-evaluates-actions; help (`auto-mode`)
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — Bounded, redacted, origin-labelled transcript excerpt as untrusted evidence; reviewer model/thinking/fastMode/timeout configurable; human fallback. — _Evidence:_ `oc/docs/tools/exec.md` (Modes)

</details>
<details><summary><b>PER-08</b> Protected paths (.git, .env, outside workspace)</summary>

- **sugar-crush** ✅ — Bash is deliberately not jailed. Path checks are spelling-based (`cat .e?v` and `$var` indirection get through, as the docs admit). PtyRun is not covered at all. — _Evidence:_ `src/Hooks/BuiltIn/ProtectFilesHook.php:139-293,400,449-512`; `src/Permissions/WritePathScope.php:101`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Read deny rules also block Edit/Write; network paths not followed; symlink checks. — _Evidence:_ cc:permission-modes#protected-paths; cc:permission-modes#critical-paths
- **OpenCode 1.18.35** 🟡 — Truncation, tmp and skill dirs are pre-allowed. — _Evidence:_ OC/agent/agent.ts:108-135
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): external_directory and *.env reads default to ask; .git and other paths not protected; edits allowed by default. 2.0.31 (🟡): Docs conflict: agents.mdx says build asks outside the workspace, but permissions.mdx/migrate-v1 and source say allow. — _Evidence:_ V2C/plugin/agent.ts:116 (explore), base policy in V2D/permissions (Defaults); V2D/migrate-v1 warning
- **OpenClaw 2026.9.8** 🟡 — `tools.fs.workspaceOnly` optional; symlink-escape checks; no specific .git/.env protection; `full` is unrestricted. — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/tools/apply-patch.md`; `oc/docs/gateway/security/tool-permissions.md`

</details>
<details><summary><b>PER-09</b> Secret redaction / env scrubbing</summary>

- **sugar-crush** 🟡 — Not scrubbed: *_PASSWORD, PGPASSWORD, MYSQL_PWD, DATABASE_URL, *_DSN, SSH_AUTH_SOCK. `env` and `printenv` run unasked. — _Evidence:_ `src/Support/ProcessContainment.php:115-131,340-353`; `src/Memory/SecretRedactor.php:7-55`
- **sugar-crush-web** ✅ — — — _Evidence:_ `sugar-crush/docs/SERVER.md:666-668`
- **Claude Code 2.1.301** 🟡 — No general secret redaction of transcripts or model context. — _Evidence:_ cc:sandboxing#protect-credentials; cc:data-usage
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ CLI: `opencode export --help`; DOCS/ecosystem
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ CLI: `opencode export --help`; DOCS/ecosystem
- **OpenClaw 2026.9.8** ✅ — Log/transcript redaction always on; secret-free shell snapshots; egress-proxy sentinels; redacted history/share — _Evidence:_ `oc/docs/gateway/security/secrets-and-storage.md`; `oc/docs/tools/exec.md`

</details>
<details><summary><b>PER-10</b> Prompt-injection defenses (web content, tool results)</summary>

- **sugar-crush** 🟡 — MCP results and file contents are not tagged as untrusted. Memory, instructions and env are fenced. — _Evidence:_ `src/Runtime.php:5370-5378`; `src/Tools/BuiltIn/WebFetch.php:244-252`; `docs/MEMORY.md:425-440`
- **sugar-crush-web** ✅ — e2e "model and tool text cannot inject markup". — _Evidence:_ `src-web/lib/markdown.ts:11,34-40`; `e2e/session.spec.ts:17`
- **Claude Code 2.1.301** ✅ — Hook context/synced-skill text escaped to stop fake `<system-reminder>` tags. — _Evidence:_ cc:security#protect-against-prompt-injection; cc:sub-agents#subagent-output-scanning; cc:terminal-config#how-claude-treats-pasted-text
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — Docs call it prompt hardening, not an authorization boundary. — _Evidence:_ `oc/docs/gateway/security/prompt-injection.md`; `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>PER-11</b> Workspace-trust dialog</summary>

- **sugar-crush** 🟡 — Entries must be absolute and are frozen per process; a refusal is announced on stderr and in the transcript. Instruction files and skills load without any trust step. — _Evidence:_ `docs/PERMISSIONS.md:1097-1163`; `src/Cli/Bootstrap.php:4567-4623`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Home-dir trust is per-session only; `-p`/SDK never show it. — _Evidence:_ cc:permissions#project-allow-rules-and-workspace-trust; cc:security
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ see INS-19
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ see INS-19
- **OpenClaw 2026.9.8** ❌ — DM pairing of unknown senders is the analogous gate for inbound channel content. — _Evidence:_ `oc/docs/channels/pairing.md`

</details>
<details><summary><b>PER-12</b> Skip-all-permissions flag</summary>

- **sugar-crush** ✅ — No `--dangerously-skip-permissions` alias. An unknown mode value exits with code 2. — _Evidence:_ `src/Cli/ArgvParser.php:443-480`; `docs/PERMISSIONS.md:186-191`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live probe; `sugar-crush/src/Protocol/Methods/BgMethods.php:72-99`
- **Claude Code 2.1.301** ✅ — Deny/ask rules, protected/critical paths and `EndConversation` exemptions still apply; managed policy can forbid. — _Evidence:_ help; cc:permission-modes#skip-all-checks-with-bypasspermissions-mode
- **OpenCode 1.18.35** ✅ — Headless `run` without `--auto` auto-**rejects** asks. — _Evidence:_ CLI: `opencode --help`; OC/cli/cmd/run.ts:801-822
- **OpenCode 2.0.31** ✅ — Headless `run` without `--auto` auto-**rejects** asks. — _Evidence:_ CLI: `opencode --help`; OC/cli/cmd/run.ts:801-822
- **OpenClaw 2026.9.8** ✅ — `full` session mode (admin) / exec `mode: full` — the default for unsandboxed host exec; elevated full — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/tools/exec-approvals.md` (YOLO mode)

</details>
<details><summary><b>PER-13</b> Managed / enterprise policy enforcement</summary>

- **sugar-crush** ❌ — — — _Evidence:_ grep managed policy = 0
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `/status` shows setting sources; managed hooks can't be disabled by users. — _Evidence:_ cc:managed-settings; cc:server-managed-settings; cc:settings-reference
- **OpenCode 1.18.35** ✅ — Global policies beat project policies. — _Evidence:_ OC/config/managed.ts; DOCS/config (Managed settings); DOCS/policies
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Managed config (/etc/opencode, ProgramData, macOS MDM plist) overrides all; experimental.policies provider.use. 2.0.31 (✅): `policies` (top-level, stable) incl. tool.use; Console-managed policies have final authority — _Evidence:_ V2D/policies; V2CL v2.0.29; specs/v2/provider-policy.md
- **OpenClaw 2026.9.8** 🟡 — `security.installPolicy` command fails closed for installs; creator-role sandbox requirements; host approval floors. — _Evidence:_ `oc/docs/cli/policy.md`; `oc/docs/gateway/operator-scopes.md`

</details>
<details><summary><b>PER-14</b> Permission audit log</summary>

- **sugar-crush** ✅ — The excerpt is written only after PostToolUse hooks allow the output. Lines are injection-safe. The log sits in tmp (lost on reboot) and is unredacted. — _Evidence:_ `src/Hooks/BuiltIn/AuditHook.php:7-60,195-330`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:monitoring-usage; CL v2.1.301
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/plugins (Permission Events); OC/permission/index.ts (logInfo evaluated)
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ DOCS/plugins (Permission Events); OC/permission/index.ts (logInfo evaluated)
- **OpenClaw 2026.9.8** ✅ — Execution identity opt-in, 30-day retention; command-logger hook for /new /reset /stop. — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Activity tab); `oc/docs/gateway/audit.md`

</details>
<details><summary><b>PER-15</b> Remote approval (approve from phone / web / chat)</summary>

- **sugar-crush** ✅ — "Always" grants behave as in the TUI, `remember: project` is refused, and a cascade reject stops the turn. The web UI answers these. — _Evidence:_ `docs/PERMISSIONS.md` (serve bullet); `docs/SERVER.md`
- **sugar-crush-web** ✅ — `permission.asked` reaches every client even for unfollowed sessions; opt-in desktop notifications. — _Evidence:_ `src-web/stores/approvals.ts:113-141`; `e2e/multi-session.spec.ts:61`
- **Claude Code 2.1.301** ✅ — Background-subagent prompts surface in the main session; channels relay messages but not approvals. — _Evidence:_ cc:remote-control#mobile-push-notifications; cc:sub-agents
- **OpenCode 1.18.35** ✅ — 🔌 kimaki (Discord) built on the SDK. — _Evidence:_ DOCS/server (permissions endpoint); DOCS/ecosystem (kimaki)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Approve from web UI, desktop, an attached TUI, ACP editor or SDK (POST permission reply). 2.0.31 (✅): Approve from TUI, web/desktop, iOS app via pairing, ACP editors or API — _Evidence:_ V2D/cli/web; V2D/permissions (Approvals)
- **OpenClaw 2026.9.8** ✅ — Approve from chat channels (native buttons or `/approve`), Control UI, macOS/iOS apps, web push — _Evidence:_ `oc/docs/web/notifications.md`; `oc/docs/plugins/sdk-channel-plugins/approvals.md`

</details>
<details><summary><b>PER-N1</b> 🆕 Policy self-grant floor (agent cannot edit its own permission / hook / agent config)</summary>

- **sugar-crush** ✅ — Blocks a model from granting itself trust (an end-to-end self-grant was measured). PtyRun bypasses this floor entirely (ISSUES P1). — _Evidence:_ `src/Hooks/BuiltIn/ProtectFilesHook.php:227-293,469-512`
- **sugar-crush-web** ✅ — Writes to hooks.yaml, config.json, agent presets and `.git/hooks` are refused in every mode. Remote `settings.set` is limited to cosmetic, tuning and narrowing keys. — _Evidence:_ `src/Hooks/BuiltIn/ProtectFilesHook.php:227-293`; `src/Config/Settings/RiskClass.php:46`; `docs/SERVER.md:670-686`
- **Claude Code 2.1.301** ✅ — Only bypassPermissions writes them silently; `--restricted` lets only a person or the permission handler approve such writes; agent messages can't change settings; `ConfigChange` hooks can block edits. — _Evidence:_ cc:permission-modes#protected-paths; help (`--restricted`); cc:hooks#configchange
- **OpenCode 1.18.35** ❌ — A user could add `edit` deny rules for config paths manually. — _Evidence:_ OC/skill/index.ts:26-34; OC/agent/agent.ts:119-135
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2D/policies; V2D/permissions (Policies)
- **OpenClaw 2026.9.8** 🟡 — Restricted runs need human approval for delegated changes; host approval files can only tighten policy; sub-agents are denied `gateway`; the exec reviewer denies "disabling security controls". In default `full` mode, exec can still edit `~/.openclaw` directly. — _Evidence:_ `oc/docs/gateway/permission-modes.md` (Delegated setup and repair); `oc/docs/tools/exec-approvals.md`; `oc/docs/tools/subagents/tool-policy.md`

</details>
<details><summary><b>PER-N2</b> 🆕 Restricted / safe mode flag (drop code-running tools, confine file tools)</summary>

- **sugar-crush** 🟡 — No CLI flag drops tools. `--permission-mode plan` runs reads and allow-listed read-only shell lines only: the only write allowed is a Markdown plan into `.sugar-crush/plans`, and WebFetch/WebSearch ask. `dont-ask` runs read-only tools and denies everything else, Bash included. Tools stay in the schema in both modes; the gate denies them rather than removing them. `disabledTools` and `allowedTools` (user tier) do remove tools, and `SUGARCRUSH_MCP_DISABLE=1` silences project MCP. Project hooks, MCP, commands and settings stay off unless listed in `trustedProject*`, so the "disable customisations" half is already the default. File tools are not confined: Read can read outside the project. — _Evidence:_ `src/Cli/ArgvParser.php:239-521`; `src/Permissions/PermissionMode.php:173-190`; `src/Config/Settings/Definitions/ToolSettings.php:57`, `:67`; `src/Config/Settings/Definitions/PermissionSettings.php:117`; `docs/ENVIRONMENT.md:61`; `docs/PERMISSIONS.md:292`
- **sugar-crush-web** 🟡 — The browser user can drop a session to `plan` (reads plus read-only shell) for its next turns. Tool roster changes (`disabledTools`) are not writable remotely. — _Evidence:_ `sugar-crush-web/src-web/components/StatusBar.vue:6`; `docs/SERVER.md:602-605`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ help
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/cli (OPENCODE_PERMISSION, --pure); OC/agent/agent.ts:157-180
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2D/permissions; V2C/plugin/plan.ts
- **OpenClaw 2026.9.8** ✅ — Switchable live from the Control UI permissions picker; `/exec security=deny` per message; `guarded` confines file tools to the session root with human-reviewed exec. — _Evidence:_ `oc/docs/gateway/permission-modes.md`; `oc/docs/gateway/config-tools/tool-policy.md`

</details>

### GIT — Git, checkpoints, review

<details><summary><b>GIT-01</b> Git awareness (branch / status)</summary>

- **sugar-crush** ✅ — Values are fence-escaped and capped. GitRunner adds `--no-optional-locks`. The session picker can filter by branch. — _Evidence:_ `src/Context/EnvironmentBlock.php:1290-1340`; `src/Tui/SessionPicker.php:304,1372`
- **sugar-crush-web** ❌ — — — _Evidence:_ grep `src-web/`
- **Claude Code 2.1.301** ✅ — Badge refreshes after push or gh/glab PR commands. — _Evidence:_ obs; cc:interactive-mode#pr-review-status
- **OpenCode 1.18.35** 🟡 — `GET /vcs` returns VCS info. — _Evidence:_ OC/session/system.ts:81; TUI/feature-plugins/home/footer.tsx:17-24
- **OpenCode 2.0.31** 🟡 — `GET /vcs` returns VCS info. — _Evidence:_ OC/session/system.ts:81; TUI/feature-plugins/home/footer.tsx:17-24
- **OpenClaw 2026.9.8** 🟡 — Execution Bias tells the model to live-check git. — _Evidence:_ `oc/docs/concepts/system-prompt.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>GIT-02</b> Automatic commits</summary>

- **sugar-crush** ✅ — User tier only. Never uses `--no-verify`. Author is the user; the trailer comes from `attribution.commit`. The session's own commits are recorded for `/undo`. — _Evidence:_ `src/Workspace/AutoCommitter.php:7-90,575-596`; `src/Hooks/BuiltIn/AutoCommitHook.php:67`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:agent-view; cc:commands (/batch)
- **OpenCode 1.18.35** ❌ — Snapshots are shadow trees, not commits. — _Evidence:_ OC/snapshot/index.ts
- **OpenCode 2.0.31** ❌ — Snapshots are shadow trees, not commits. — _Evidence:_ OC/snapshot/index.ts
- **OpenClaw 2026.9.8** ❌ — New workspaces are git-initialized; memory backups are manual. — _Evidence:_ `oc/docs/concepts/agent-workspace.md` (Git backup)

</details>
<details><summary><b>GIT-03</b> Commit-message generation / /commit</summary>

- **sugar-crush** 🟡 — The model's reply is sanitised to a single type-prefixed line, with a fallback. Bash guidance teaches heredoc commit messages. — _Evidence:_ `src/Workspace/CommitMessageWriter.php:7-60`; `src/Tools/BuiltIn/Bash.php:297-349`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Runs a `verify`/`simplify` skill before commits when one exists (v2.1.286+); `includeGitInstructions:false` removes the guidance. — _Evidence:_ cc:skills#run-your-checks-before-each-commit; cc:settings-reference#attribution; local: catalog
- **OpenCode 1.18.35** ❌ — This host has a user `/commit` command in ~/.config/opencode/command/commit.md. — _Evidence:_ OC/tool/shell/shell.txt; LOCAL: ~/.config/opencode/command/commit.md
- **OpenCode 2.0.31** ❌ — This host has a user `/commit` command in ~/.config/opencode/command/commit.md. — _Evidence:_ OC/tool/shell/shell.txt; LOCAL: ~/.config/opencode/command/commit.md
- **OpenClaw 2026.9.8** ❌ — Co-author trailers are mentioned in the Runtime line for shared sessions; agent commits via exec. — _Evidence:_ `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>GIT-04</b> PR creation / GitHub integration</summary>

- **sugar-crush** 🟡 — — — _Evidence:_ `src/Tools/BuiltIn/Bash.php:338-341`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — GitLab MR badge via `glab`; GitHub Enterprise Server support. — _Evidence:_ cc:commands; cc:sessions; cc:github-enterprise-server
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CLI: `opencode pr --help`; DOCS/github
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): `opencode pr <n>` checks out a PR; GitHub agent opens branches/PRs; bash desc guides gh usage. 2.0.31 (🟡): No `opencode pr` or `github` commands in the v2 CLI; agent uses gh via shell — _Evidence:_ V2CLI: `opencode --help`
- **OpenClaw 2026.9.8** ✅ — Device-flow OAuth or PAT, per-agent identities and git author; PR chips with CI monitoring. — _Evidence:_ `oc/docs/gateway/config-tools/github-identity.md`; `oc/docs/gateway/team-server.md`

</details>
<details><summary><b>GIT-05</b> Checkpoints of file edits</summary>

- **sugar-crush** ✅ — .env*, binaries, build output and untracked files over 2 MiB are excluded. The capture is budgeted at 15 s and refused in $HOME, Desktop, Documents and Downloads. A failure never fails the turn. — _Evidence:_ `src/Workspace/WorkspaceCheckpointer.php:9-140`
- **sugar-crush-web** 🟡 — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Not for Bash-made changes or most subagent edits. — _Evidence:_ cc:checkpointing#how-checkpoints-work
- **OpenCode 1.18.35** ✅ — The snapshot is taken before streaming starts. A per-turn diff is computed for the diff viewer. — _Evidence:_ OC/session/processor.ts:102,425-440; OC/session/summary.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Shadow-git snapshot before/after every step; per-step patch parts list changed files. 2.0.31 (✅): Snapshot before model call and at step end, scoped to the active directory; packed object store — _Evidence:_ V2D/snapshots (Capture, Scope, Storage); V2CL v2.0.26
- **OpenClaw 2026.9.8** 🟡 — No per-edit checkpoints for a normal workspace. — _Evidence:_ `oc/docs/concepts/managed-worktrees.md`; `oc/docs/gateway/cloud-workers.md`

</details>
<details><summary><b>GIT-06</b> Undo / redo / rewind code</summary>

- **sugar-crush** ✅ — The restore deletes files created since the checkpoint and checks out the changed ones via a scratch index. — _Evidence:_ `builtin-commands/2800-rewind.php`, `2810-undo.php`, `2820-redo.php`; `src/Workspace/WorkspaceCheckpointer.php:268-335`; `src/Workspace/AutoCommitter.php:71-77`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — Symlinks/hard links skipped with a warning. — _Evidence:_ cc:checkpointing#rewind-and-summarize
- **OpenCode 1.18.35** ✅ — Files absent from the snapshot are deleted on revert. — _Evidence:_ OC/session/revert.ts:38-100; OC/snapshot/index.ts:167-168
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /undo /redo and timeline revert restore files (git projects only); unrevert supported. 2.0.31 (✅): Staged rollback (/undo) committed on submit, /redo cancels; rejects while running — _Evidence:_ V2D/snapshots
- **OpenClaw 2026.9.8** 🟡 — No code undo: Rewind/Fork are context-only; `openclaw worktrees restore` restores a removed worktree snapshot — _Evidence:_ `oc/docs/web/control-ui/chat.md`; CLI: `openclaw --help` (worktrees)

</details>
<details><summary><b>GIT-07</b> Snapshot mechanism (shadow git etc.)</summary>

- **sugar-crush** ✅ — The user's stash list, index and branch are never touched. GitRunner uses `-c core.hooksPath=/dev/null` and `core.fsmonitor=false`, scrubs GIT_* env and avoids gpg. Old checkpoints are garbage-collected. — _Evidence:_ `src/Workspace/WorkspaceCheckpointer.php:61`; `src/Workspace/ShadowRepo.php`; `src/Workspace/GitRunner.php:28-83`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:checkpointing; cc:claude-directory
- **OpenCode 1.18.35** ✅ — Mirrors info/exclude and uses index v4 / manyFiles. `snapshot:false` disables it for huge repos. — _Evidence:_ OC/snapshot/index.ts:23-24,195-233,305,341; DOCS/config (Snapshot)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Shadow git dir under data/snapshot/<project>/<hash>, alternates to repo objects, write-tree, gc 7 days, skip >2 MB. 2.0.31 (✅): Separate internal git object DB, crash/concurrency-safe capture, background packing; `snapshots` key — _Evidence:_ V2D/snapshots; V2CL v2.0.26; V2D/migrate-v1 (Snapshots)
- **OpenClaw 2026.9.8** 🟡 — Git-based snapshots only for managed worktrees and cloud checkpoints; no shadow-git per turn — _Evidence:_ `oc/docs/concepts/managed-worktrees.md`

</details>
<details><summary><b>GIT-08</b> Worktree support</summary>

- **sugar-crush** 🟡 — Inside the worktree the tools are re-jailed and the sandbox root moves; BashEscapeDenyHook is registered (Bash only). — _Evidence:_ `src/Tools/BuiltIn/TaskTool.php:1246-1260,2105-2170`; `src/Agents/WorktreeManager.php`; `src/Backend/EngineBackend.php:1318`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — `WorktreeCreate/Remove` hooks for non-git VCS; `.worktreeinclude`; cleanup sweeps. — _Evidence:_ help (`--worktree`); cc:worktrees
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ see AGT-18
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Experimental worktree/workspace API + /workspaces, /warp; desktop uses worktrees; 🔌 opencode-worktree. 2.0.31 (✅): Built-in worktree tools + /worktrees, /cd, session move — _Evidence:_ V2D/tools (Worktrees); V2T slash list
- **OpenClaw 2026.9.8** ✅ — Branches `openclaw/<name>`; `.worktreeinclude`, setup script, GC targets. — _Evidence:_ `oc/docs/concepts/managed-worktrees.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>GIT-09</b> Diff review UI (session diff)</summary>

- **sugar-crush** 🟡 — — — _Evidence:_ `src/Host/Commands/DiffCommand.php:12-63`; `src/Workspace/CheckpointDiff.php:7-40`
- **sugar-crush-web** 🧪 — — — _Evidence:_ `sugar-crush/src/Protocol/Methods/FilesMethods.php`; grep `src-web/`
- **Claude Code 2.1.301** ✅ — Built-in `cc-plugin-diff` mod; auto-opens on wide terminals. — _Evidence:_ cc:interactive-mode#review-changes-with-/diff
- **OpenCode 1.18.35** ✅ — `/session/:id/diff?messageID`. — _Evidence:_ TUI/feature-plugins/system/diff-viewer.tsx; TUI/feature-plugins/sidebar/files.tsx
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /diff viewer (file tree, git or session mode, split/stacked) + sidebar Modified Files with +/- counts. 2.0.31 (✅): /diff opens docked beside the session (f toggles full screen); desktop per-turn changed files — _Evidence:_ V2CL v2.0.30
- **OpenClaw 2026.9.8** ✅ — `sessions.diff` server-side; Files tab with changed-file filter; Diffs plugin for standalone viewers. — _Evidence:_ `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>GIT-10</b> Code-review command (/review, security review)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `docs/COMMANDS.md:4,74-80`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Ultrareview: research preview, 3 free runs on Pro/Max; Code Review GitHub service for Team/Enterprise. — _Evidence:_ cc:code-review-local; cc:ultrareview; cc:commands
- **OpenCode 1.18.35** 🟡 — The template is review.txt. It runs as a subagent so the main context stays clean. This repo overrides it with its own review.md. — _Evidence:_ OC/command/index.ts:79-88; OC/command/template/review.txt; LOCAL: .opencode/commands/review.md
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): /review (subtask) for uncommitted/commit/branch/PR; no security-review command. 2.0.31 (🟡): /review now runs in the current session (no longer a subtask); no security review — _Evidence:_ V2C/plugin/command.ts:41-52
- **OpenClaw 2026.9.8** 🔌 — No native /review or security-review command. — _Evidence:_ `oc/docs/plugins/codex-harness/commands.md`

</details>
<details><summary><b>GIT-11</b> CI integration (GitHub Actions / GitLab)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ grep `github action` = 0
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — `claude ultrareview` CLI for CI; `-p` with exit codes. — _Evidence:_ cc:github-actions; cc:gitlab-ci-cd; help (`ultrareview`)
- **OpenCode 1.18.35** ✅ — Events: issue_comment, PR review comments, issues, pull_request, schedule, workflow_dispatch. Options: model, agent, share, prompt, mentions, variant, OIDC/GITHUB_TOKEN. — _Evidence:_ DOCS/github; DOCS/gitlab
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): GitHub Action anomalyco/opencode/github (`opencode github install`); GitLab CI component + GitLab Duo. 2.0.31 (🟡): GitHub Action (repo github/action.yml) still installs v1 and runs `opencode github run`; no v2 CI docs — _Evidence:_ github/action.yml:61-70; V2D (no github/gitlab pages)
- **OpenClaw 2026.9.8** 🟡 — No official GitHub Action/GitLab recipe; `agent exec` is CI-friendly; UI shows GitHub Actions steps — _Evidence:_ `oc/docs/cli/agent.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>GIT-12</b> PR comment / issue-triage bot</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:github-actions; cc:code-review; cc:claude-code-on-the-web#auto-fix-pull-requests
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/github (Supported Events, Examples)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): /opencode or /oc mentions in issues/PR (incl. line comments); auto PR review & issue triage workflows. 2.0.31 (🟡): Issue/PR comment bot only via the V1-based GitHub Action — _Evidence:_ github/action.yml
- **OpenClaw 2026.9.8** ✅ — Needs git + gh (GH_TOKEN); GitHub reader panel; Team Reports plugin. — _Evidence:_ `oc/skills/gh-issues/SKILL.md`; `oc/docs/plugins/github.md`

</details>

### UI — TUI / GUI UX

<details><summary><b>UI-01</b> Full-screen TUI vs inline rendering</summary>

- **sugar-crush** ✅ — `bin/sugarcrush` boots `App`, a pane shell that hosts the `Chat` model as one component tree. It enters the alternate screen (`\e[?1049h`), so the transcript is in-app scrollback, not terminal scrollback. Frame = menu bar row (menus + pane tabs + "Currently: X"), optional session-tab strip, left/right docked pane columns, a bordered chat pane and a one-row status bar. Frame is clipped to `rows` and every line is fitted to `cols` (no overflow seen at 140, 80 or 50 columns). There is no inline/no-alt-screen mode like Claude Code's default. — _Evidence:_ `src/App/App.php`; `src/Tui/Renderer.php:133-210`; `src/Renderer.php:1918-1966`; `src/Chat.php:10603-10608` (alt screen); README.md:186-190
- **sugar-crush-web** ➖ — — — _Evidence:_ `src-web/App.vue`
- **Claude Code 2.1.301** ✅ — `/tui fullscreen/default` relaunches with the conversation intact; fullscreen keeps memory flat, adds mouse, fixed input box, in-app search; screen-reader mode forces classic. — _Evidence:_ cc:fullscreen#fullscreen-by-default; cc:commands (/tui)
- **OpenCode 1.18.35** ✅ — Mini mode keeps scrollback immutable, with a repaintable footer for prompt, permission and question. It supports `--no-replay` / `--replay-limit`. — _Evidence:_ OC/cli/cmd/tui.ts:123-175; OC/cli/cmd/run/footer.ts:1-26
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Full-screen OpenTUI app by default; `--mini` / `run -i` inline split-footer mode writing to native scrollback. 2.0.31 (✅): Full-screen TUI by default; `opencode mini` inline interface — _Evidence:_ V2D/cli/index (Mini); V2CLI: `opencode mini --help`
- **OpenClaw 2026.9.8** ✅ — `TuiMainScreen`: "renders into the terminal's main screen and scrollback" (alt-screen variant exists in pi-tui but OpenClaw's TUI uses main screen). Plus native desktop/mobile apps. — _Evidence:_ `oc/node_modules/@earendil-works/pi-tui/dist/tui-main-screen.d.ts`; `oc/dist/tui-sA-O8ceh.mjs`; `oc/docs/web/tui.md`

</details>
<details><summary><b>UI-02</b> Themes (count) & custom themes</summary>

- **sugar-crush** 🟡 — `/theme`, palette "Switch theme" or the settings view. Persisted to `config.json`. The `adaptive` theme follows the terminal background (OSC 11 reply, `COLORFGBG`, `SUGARCRUSH_BACKGROUND`). Every chrome colour is projected along HSL lightness until it clears WCAG contrast against the detected background *and* the row-highlight fill, so even `light` stays readable on a dark terminal. No user theme files or overrides: `Theme::byName()` throws for any name not in the fixed list. The picker always opens on `dark`, not on the current theme. — _Evidence:_ `src/Theme.php:60,139-180,237-330`; `src/Tui/TerminalBackground.php`; live: `/theme`, palette
- **sugar-crush-web** 🟡 — Several token pairs miss WCAG AA (ISSUES). — _Evidence:_ `src-web/styles/tokens.css`; `src-web/stores/settings.ts:30-32`
- **Claude Code 2.1.301** ✅ — `/theme` → "New custom theme…" token editor; `custom:<slug>`. — _Evidence:_ cc:settings-reference#theme; cc:terminal-config#create-a-custom-theme
- **OpenCode 1.18.35** ✅ — Truecolor is recommended. `/themes` (<leader>t). — _Evidence:_ TUI/theme/assets/*.json (33 files); DOCS/themes
- **OpenCode 2.0.31** ✅ — Truecolor is recommended. `/themes` (<leader>t). — _Evidence:_ TUI/theme/assets/*.json (33 files); DOCS/themes
- **OpenClaw 2026.9.8** ✅ — Claw (default), Knot, Dash, Absolutely, Tide, Beacon (WCAG AAA), Phosphor, CRT, Manuscript, Rosé, Miami; accent/typography overrides synced to the user profile. TUI only light/dark (`OPENCLAW_THEME`). — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Appearance themes); `oc/docs/tools/theme.md`; `oc/docs/web/tui.md` (Terminal colors)

</details>
<details><summary><b>UI-03</b> Markdown rendering</summary>

- **sugar-crush** ✅ — `Renderer::renderHistory()` builds `new Markdown($theme->markdown, wrapWidth: $width)`. Settled turns are memoised per (source, width, theme). Streaming text goes through `SectionStream`, so a growing reply does not re-render the whole body every frame. Only assistant text is Markdown. User and system rows are painted raw (sanitised). Links are OSC 8 hyperlinks (seen live in an error row). — _Evidence:_ `src/Renderer.php:446-520,4085-4110`; `sugar-markdown/src/Renderer.php`, `sugar-markdown/src/Render/SectionStream.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/lib/markdown.ts`
- **Claude Code 2.1.301** ✅ — Optional response-width cap for wide terminals; clickable `owner/repo#123` links. — _Evidence:_ cc:terminal-config#cap-response-width-in-wide-terminals; cc:interactive-mode#issue-reference-links; CL v2.1.301
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/config/keybind.ts:149
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/config/keybind.ts:149
- **OpenClaw 2026.9.8** ✅ — TUI Markdown with hyperlinks; Control UI rich Markdown, tables, Mermaid, collapsible details, JSON viewer — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui/chat.md` (Markdown tables, Mermaid diagrams)

</details>
<details><summary><b>UI-04</b> Syntax highlighting</summary>

- **sugar-crush** 🟡 — sugar-markdown `SyntaxHighlighter` wraps sugar-core `RegexHighlighter`. It covers PHP, JS, TS, JSON, Python, Go, Bash and SQL, with a 1 MB DoS cap. Unknown languages fall back to plain code style. Diffs and tool output are not syntax-highlighted. Not comparable to Shiki/tree-sitter-grade highlighting. — _Evidence:_ `sugar-markdown/src/SyntaxHighlighter.php:1-60`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/lib/highlight.ts:192,356-374`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#theme-and-display
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/parsers-config.ts
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/parsers-config.ts
- **OpenClaw 2026.9.8** ✅ — Syntax highlighting for code blocks, shell commands and diffs (Control UI); TUI markdown code highlighting — _Evidence:_ `oc/docs/web/control-ui/feature-reference.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-05</b> Diff rendering (side-by-side / unified)</summary>

- **sugar-crush** 🟡 — Edit/Write results build a unified diff (`BuildsUnifiedDiff`). The renderer paints it boxed with a `DiffGutter`, capped at `diffPreviewRows` (default 24), and `Ctrl+O` expands it. No side-by-side view, no word-level or syntax highlighting. Headers use the absolute path (`--- a//tmp/...`). **The permission modal shows no diff** for an Edit/Write awaiting approval, only a one-line argument summary (see ISSUES P1). `/diff [n]` prints a checkpoint patch. — _Evidence:_ `src/Tui/DiffGutter.php`; `src/Renderer.php:289-297,5170`; `src/Tools/Concerns/BuildsUnifiedDiff.php:151`; live: Edit turn
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/DiffView.vue`; `src-web/lib/diff.ts`
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:interactive-mode#review-changes-with-/diff; cc:vs-code; CL (word-level diff display)
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/tui (diff_style); TUI/routes/session/permission.tsx:33-80
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/tui (diff_style); TUI/routes/session/permission.tsx:33-80
- **OpenClaw 2026.9.8** ✅ — Control UI unified and split diffs (session diff + inline edit/write diffs); TUI edit cards show diffs — _Evidence:_ `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-06</b> Multiline input</summary>

- **sugar-crush** ✅ — The composer is sugar-forms `TextArea`, with cursor motion, word motion/delete and ↑/↓ between rows. `App::init` pushes the Kitty progressive-keyboard protocol (DISAMBIGUATE) so terminals that support it report Shift+Enter distinctly. Wide-character wrapping was verified live with CJK and emoji. The box shrink-wraps its content: it starts 5 columns wide and grows as you type. It follows the transcript tail rather than being pinned to the bottom of the frame. — _Evidence:_ `src/Commands/KeyBindingRegistry.php:450`; `src/App/App.php:2477-2501`; `src/Renderer.php:7140-7200`; live
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/Composer.vue:73-77`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#multiline-input; cc:terminal-config#enter-multiline-prompts
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/keybinds (input_*)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/keybinds (input_*)
- **OpenClaw 2026.9.8** ✅ — TUI Shift+Enter / Ctrl+J newline; Control UI configurable send shortcut — _Evidence:_ `oc/docs/web/tui.md` (Keyboard shortcuts); `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-07</b> Vim mode</summary>

- **sugar-crush** ❌ — `sugar-forms/src/Vim/*` (VimKeyHandler, operators, text objects) is wired into `TextInput`, but the chat composer is `TextArea`, which has no vim layer. No setting or command toggles one. — _Evidence:_ `sugar-forms/src/Vim/`; `sugar-forms/src/TextInput/TextInput.php`; `src/Chat.php:16514-16515`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `/config` → Editor mode; remaps only from user/managed settings. — _Evidence:_ cc:interactive-mode#vim-editor-mode; CL v2.1.301
- **OpenCode 1.18.35** ❌ — No vim keymap in TUI sources (the only “vim” hit is a parser config). — _Evidence:_ TUI/ (grep vim)
- **OpenCode 2.0.31** ❌ — No vim keymap in TUI sources (the only “vim” hit is a parser config). — _Evidence:_ TUI/ (grep vim)
- **OpenClaw 2026.9.8** ❌ — pi-tui editor offers an emacs-style kill ring. — _Evidence:_ `oc/node_modules/@earendil-works/pi-tui/dist/kill-ring.js`; `oc/docs/web/tui.md`

</details>
<details><summary><b>UI-08</b> External editor ($EDITOR)</summary>

- **sugar-crush** ✅ — The editor is run from a Cmd and the result returns to the draft. No dedicated hotkey: TextArea's own Ctrl+O "open in editor" is shadowed because Chat binds Ctrl+O to tool-output expand. — _Evidence:_ `src/Commands/EditorCommand.php`; `builtin-commands/1900-editor.php`; README.md:333-337
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `v` in transcript viewer opens the conversation in $EDITOR. — _Evidence:_ cc:interactive-mode#keyboard-shortcuts
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/tui (editor, export)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/tui (editor, export)
- **OpenClaw 2026.9.8** ❌ — Control UI can open local workspace files in native editors. — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>UI-09</b> Input history & search (Ctrl+R)</summary>

- **sugar-crush** 🟡 — History is persisted per user and the first ↑ of a new launch recalls the previous session's last prompt. Editing a recalled prompt ends the walk. Ctrl+R opens the **session picker**, not a history search, and no other chord searches prompt history. — _Evidence:_ `src/Chat.php:3200-3202`; README.md:225-226 (Keys table); `KeyBindingRegistry.php` chat.recall, chat.session-picker
- **sugar-crush-web** ❌ — — — _Evidence:_ `src-web/stores/layout.ts:51`
- **Claude Code 2.1.301** ✅ — Ctrl+S stash; Up restores a Ctrl+C-cleared draft (v2.1.288). — _Evidence:_ cc:interactive-mode#command-history; CL v2.1.288
- **OpenCode 1.18.35** 🟡 — Prompt stash: push, pop and list. — _Evidence:_ DOCS/keybinds (history_*, prompt_stash*); LOCAL: ~/.local/state/opencode/prompt-history.jsonl
- **OpenCode 2.0.31** 🟡 — Prompt stash: push, pop and list. — _Evidence:_ DOCS/keybinds (history_*, prompt_stash*); LOCAL: ~/.local/state/opencode/prompt-history.jsonl
- **OpenClaw 2026.9.8** 🟡 — TUI up/down input history, no Ctrl+R search; Control UI ⌘F transcript search and ⌘K thread search — _Evidence:_ `oc/dist/tui-sA-O8ceh.mjs` (editor.addToHistory); `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-10</b> @file mention autocomplete / fuzzy file picker</summary>

- **sugar-crush** 🟡 — Typing `@sr` shows nothing. Tab completes to the unique match (`@src/`), descends directories and stops at a common prefix, with no candidate list shown (verified live). Mentions are attached on Enter (snapshot, max 20 files, 256 KiB each). Keyword mentions inject `<context>` blocks. `$name` attaches a skill body for one turn, also Tab-completable. — _Evidence:_ README.md:368-427; `KeyBindingRegistry.php` chat.mention-complete; live
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Protocol/Methods/TurnMethods.php:80-90`
- **Claude Code 2.1.301** ✅ — Custom `fileSuggestion` command setting; mouse selection in fullscreen. — _Evidence:_ cc:interactive-mode#quick-commands; cc:mcp#use-mcp-resources; cc:sub-agents
- **OpenCode 1.18.35** ✅ — @file#L10-20 ranges are supported; a single line expands to the enclosing LSP symbol. — _Evidence:_ TUI/component/prompt/autocomplete.tsx:280-430; OC/session/prompt.ts:826-858
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): @ fuzzy picker (frecency) for files/dirs, subagents, MCP resources, configured references. 2.0.31 (✅): @ picker: files (with #start-end ranges), skills, agents, references — _Evidence:_ V2D/cli/tui (Context); V2T/component/prompt/autocomplete.tsx:47,427-436
- **OpenClaw 2026.9.8** ✅ — Control UI's `@` picker is for people, not files. — _Evidence:_ `oc/dist/tui-sA-O8ceh.mjs` (createTuiAutocompleteProvider); `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-11</b> Large-paste collapse</summary>

- **sugar-crush** ❌ — Bracketed paste is enabled and a paste that is only an image path becomes a mention, but text is never folded into a "[Pasted N lines]" chip. The input box has no height cap: the renderer's tail clip keeps the box and drops transcript rows, so the transcript disappears and the box's own top is clipped. There is no clear-line chord: Ctrl+U types `u`, and clearing took ~1,200 Ctrl+W presses. — _Evidence:_ `src/Renderer.php:7178-7181`; `src/Chat.php:9182`; live 300-line paste
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Delete/restore whole placeholders; recalled history resends content. — _Evidence:_ cc:terminal-config#paste-large-content
- **OpenCode 1.18.35** ✅ — `experimental.disable_paste_summary` turns it off. — _Evidence:_ DOCS/keybinds; CORE/v1/config/config.ts:171
- **OpenCode 2.0.31** ✅ — `experimental.disable_paste_summary` turns it off. — _Evidence:_ DOCS/keybinds; CORE/v1/config/config.ts:171
- **OpenClaw 2026.9.8** ✅ — TUI collapses big pastes to `[paste #N +X lines]`; Control UI turns pasted text into "Pasted text" chips — _Evidence:_ `oc/node_modules/@earendil-works/pi-tui/dist/components/editor.js`; `oc/docs/web/control-ui/chat.md` (Source previews)

</details>
<details><summary><b>UI-12</b> Image paste / drag-drop</summary>

- **sugar-crush** ✅ — The image is saved to a private temp dir and inserted as an `@` mention. A missing tool or no image gives a notice. Images are checked by magic bytes, max 5 MiB, and sent as provider-native parts. A non-vision model gets a text placeholder plus a notice. — _Evidence:_ `KeyBindingRegistry.php` chat.paste-image; `src/ClipboardImagePastedMsg.php`; README.md:29,383-404
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Remote Control attachments from phone/browser. — _Evidence:_ cc:interactive-mode#keyboard-shortcuts; CL (dragged image paths honor Read deny)
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/clipboard.ts:32-56; TUI/component/prompt/index.tsx:378-384
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/clipboard.ts:32-56; TUI/component/prompt/index.tsx:378-384
- **OpenClaw 2026.9.8** ✅ — Control UI paste/drop images, Take photo, attachments; channels accept media; TUI only previews images — _Evidence:_ `oc/docs/web/control-ui.md` (Take a photo); `oc/docs/web/tui.md` (Image previews)

</details>
<details><summary><b>UI-13</b> Message queueing while agent busy</summary>

- **sugar-crush** ✅ — Queued count shows in the status bar (`N queued`). A `!cmd` typed mid-turn waits in the queue. Slash commands are refused mid-turn except `/btw` and `/exit`. — _Evidence:_ `KeyBindingRegistry.php:448-449`; README.md:333-366; lang `tui.status.queued`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/Composer.vue:121-131`; `src-web/components/QueueStrip.vue`
- **Claude Code 2.1.301** ✅ — Commands and `!` queue until the turn ends; Up takes the queue back. — _Evidence:_ cc:interactive-mode#queue-messages-while-claude-works
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/routes/session/index.tsx:1450; OC/session/prompt.ts:1092-1130
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Messages typed while busy are queued (QUEUED badge) and picked up at the next step. 2.0.31 (✅): Durable inbox: steer delivers at next safe step boundary, queue waits for idle. — _Evidence:_ V2D/cli/tui; specs/v2/session.md (Prompt Admission)
- **OpenClaw 2026.9.8** ✅ — Control UI queue items can be reordered (drag/arrow keys) and edited in place; Cmd/Ctrl+Enter sends the opposite of the Enter action (queue vs steer). — _Evidence:_ `oc/docs/concepts/queue.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-14</b> Interrupt (Esc) / mid-turn steering</summary>

- **sugar-crush** ✅ — The status bar walks through `step N · Esc to stop, Esc Esc to cancel` and then `stopping after step N · Esc to cancel now`. A cancelled call reads "Cancelled by the user (Esc)". `doubleEscSeconds` is configurable. — _Evidence:_ README.md:207-208; `src/Renderer.php:2648-2675`; `src/Backend/CancellationToken.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/Composer.vue:78-87`
- **Claude Code 2.1.301** ✅ — Ctrl+B backgrounds current tools; Ctrl+X Ctrl+K stops all background subagents. — _Evidence:_ cc:interactive-mode#keyboard-shortcuts; cc:how-claude-code-works#interrupt-and-steer
- **OpenCode 1.18.35** ✅ — `session_interrupt: escape`. Aborting cancels child jobs too. — _Evidence:_ DOCS/keybinds; OC/session/prompt.ts:1092
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Esc interrupts; queued messages steer mid-turn because the loop reloads history each step. 2.0.31 (✅): Interrupt + steer by default; interrupt keeps pending input — _Evidence:_ specs/v2/session.md
- **OpenClaw 2026.9.8** ✅ — Unstarted calls are skipped with "Skipped to process an incoming message." — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/tools/steer.md`; `oc/docs/concepts/queue-steering.md`

</details>
<details><summary><b>UI-15</b> Edit / resubmit an earlier message</summary>

- **sugar-crush** 🟡 — The checkpoint restore puts the draft and caret back, and you resend from there. You cannot select an arbitrary earlier user message in the transcript to edit (unlike CC's Esc Esc message picker). — _Evidence:_ README.md:948-981; `src/Chat.php:12838,16081`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:checkpointing#rewind-and-summarize
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ TUI/routes/session/index.tsx:611-645
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ TUI/routes/session/index.tsx:611-645
- **OpenClaw 2026.9.8** ✅ — Control UI rewind returns a message to the composer for edit + resend; Fork from here; queued items editable — _Evidence:_ `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-16</b> Copy message / code block to clipboard</summary>

- **sugar-crush** 🟡 — The status bar confirms `✓ copied N chars`. Only the transcript text column is selectable, so borders never land on the clipboard. There is no "copy last reply" or "copy code block" command or key. — _Evidence:_ README.md:257-277; `src/Tui/TextSelection.php`; `src/Support/SystemClipboard.php`; `src/Chat.php:2869-2892`
- **sugar-crush-web** ❌ — — — _Evidence:_ `src-web/components/MessageMarkdown.vue:35`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:commands (/copy); cc:fullscreen#use-the-mouse
- **OpenCode 1.18.35** ✅ — `OPENCODE_EXPERIMENTAL_DISABLE_COPY_ON_SELECT`. — _Evidence:_ DOCS/keybinds (messages_copy); TUI/routes/session/index.tsx:877-940
- **OpenCode 2.0.31** ✅ — `OPENCODE_EXPERIMENTAL_DISABLE_COPY_ON_SELECT`. — _Evidence:_ DOCS/keybinds (messages_copy); TUI/routes/session/index.tsx:877-940
- **OpenClaw 2026.9.8** ✅ — TUI relies on terminal selection. — _Evidence:_ `oc/docs/web/control-ui/chat.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>UI-17</b> Mouse support (click, scroll, select)</summary>

- **sugar-crush** ✅ — Zones are registered during the render pass so clicks hit what is drawn. Click and drag are discriminated. The settings view also takes clicks and wheel. Verified live: clicking the Todo tab undocked it and the wheel scrolled the transcript. `SUGARCRUSH_DISABLE_MOUSE=1` or the `mouse` setting turns it off. — _Evidence:_ README.md:257-331; `src/Tui/PaneDragController.php`; live SGR clicks
- **sugar-crush-web** ✅ — —
- **Claude Code 2.1.301** ✅ — `/scroll-speed`; mouse capture can be turned off to keep native selection. — _Evidence:_ cc:fullscreen#use-the-mouse
- **OpenCode 1.18.35** ✅ — Scroll acceleration option. — _Evidence:_ DOCS/tui (mouse, scroll_*)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Mouse click, scroll, selection (tui `mouse`, OPENCODE_DISABLE_MOUSE). 2.0.31 (✅): Mouse + clickable OSC 8 hyperlinks (FORCE_HYPERLINK=1) — _Evidence:_ V2CL v2.0.26
- **OpenClaw 2026.9.8** ✅ — Control UI full mouse/touch (drag sessions into split panes, dividers, hovercards); TUI keyboard-first — _Evidence:_ `oc/docs/web/control-ui/chat.md` (Split view)

</details>
<details><summary><b>UI-18</b> Scrollback / transcript viewer</summary>

- **sugar-crush** ✅ — The scroll window slides over transcript plus input box, so the **input box scrolls off-screen** and typing while scrolled edits an invisible draft (ISSUES P2). `/share md` exports the transcript. No in-transcript search. — _Evidence:_ `src/Renderer.php:1952-1966`; lang `tui.status.scrolled`; live
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/Transcript.vue`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#transcript-viewer; cc:fullscreen#search-and-review-the-conversation
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/keybinds (messages_*); TUI/routes/session/dialog-timeline.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/keybinds (messages_*); TUI/routes/session/dialog-timeline.tsx
- **OpenClaw 2026.9.8** ✅ — TUI loads 200 history entries; Control UI pages back up to 1,000 messages per request with prefetch — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui/feature-reference.md`

</details>
<details><summary><b>UI-19</b> Sidebar / panels (files, todos, agents, LSP, MCP)</summary>

- **sugar-crush** ✅ — Menu-bar pane tabs show the state (muted = undocked, bright = docked, bold-underline = focused). `/pane dock`, `/pane toggle`, `/layout reset`. Panes auto-dock the first time they have content (Tools, Todo, Agents, Files), each side taking a third of the window: at 140 cols chat shrank to 49 columns, at 80 cols to 23 (ISSUES P2). Panes are content-height, so most of a side column is often blank. MCP has no pane: `/mcp` prints into the transcript. — _Evidence:_ `src/App/App.php:1793-1796,2036-2087`; `src/Tui/Components/*Pane.php`; `src/Tui/Renderer.php:1677-1720`; live
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/panels/SidePanels.vue`
- **Claude Code 2.1.301** 🟡 — No general sidebar for files/LSP/MCP; Desktop app adds file editor/terminal panes. — _Evidence:_ cc:interactive-mode#diff-panel; cc:sub-agents; cc:plugins/mods/interface
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/feature-plugins/sidebar/*.tsx; PLUG/tui.ts:476-486
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): Sidebar (<leader>b): context, MCP, LSP, todo, modified files; plugins can add via slots. 2.0.31 (🟡): Sidebar: context + MCP only (no LSP, todo or modified-files panels); diff viewer separate — _Evidence:_ V2T/feature-plugins/sidebar/{context,mcp,footer}.tsx
- **OpenClaw 2026.9.8** ✅ — Control UI sidebar (agents, groups, people) + side panel tabs: Files, Review, Browser, Terminal, Desktop, Side chat, Dashboard — _Evidence:_ `oc/docs/web/control-ui/panels.md`; `oc/docs/web/control-ui/sessions-and-sidebar.md`

</details>
<details><summary><b>UI-20</b> Todo-list display</summary>

- **sugar-crush** ✅ — Auto-docks on the first Todo write. The list survives compaction and `/resume`. In the transcript the Todo tool card shows raw JSON-escaped arguments (`Todo(todos: "[{\"content\":...`), not a rendered list. — _Evidence:_ `src/Tui/Components/TodoPane.php`; README.md:139-146 (pane docking section); live
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/panels/TodoPanel.vue`
- **Claude Code 2.1.301** 🟡 — Expanded state restored on resume. — _Evidence:_ cc:interactive-mode#task-list; cc:tools-reference#task-tool-availability
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/feature-plugins/sidebar/todo.tsx; TUI/component/todo-item.tsx
- **OpenCode 2.0.31** ❌ — 1.18.35 (✅): Todo list rendered in sidebar from todowrite. 2.0.31 (❌): No todo list (no todo tool) — _Evidence:_ V2C/tool/plugin/
- **OpenClaw 2026.9.8** ✅ — `progress_card` shown as a durable plan/status card in the Control UI; goal state in the TUI footer — _Evidence:_ `oc/docs/tools/progress-card.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>UI-21</b> Tool-call display (collapsible, running → done)</summary>

- **sugar-crush** ✅ — Each card: `🔧 tool: Bash ✓ ok — <description>` plus `… N lines hidden (ctrl+o)`. Expanding shows `$ command` or `key: value` invocation, then output. `Ctrl+O` walks older rows within 3 s. Tools without a `description` argument (Todo, Prune) show escaped JSON. A model's optional `Prune` that does nothing renders as a red `✗ error` card, which is noise. The Tools pane mirrors calls (newest first, sub-agent calls prefixed `coder ›`). — _Evidence:_ `src/Message.php:383-414`; `src/Renderer.php:5300-5345`; README.md:753-765; live
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/ToolCard.vue:16-57`
- **Claude Code 2.1.301** ✅ — Hooks `statusMessage` on spinner; LSP "Found N new diagnostic issues (ctrl+o to expand)". — _Evidence:_ cc:interactive-mode#keyboard-shortcuts; cc:commands (/focus)
- **OpenCode 1.18.35** ✅ — Read shows ↳ Loaded files; Task shows live child progress; Bash streams output. — _Evidence:_ TUI/routes/session/index.tsx:2149-2300
- **OpenCode 2.0.31** ✅ — Read shows ↳ Loaded files; Task shows live child progress; Bash streams output. — _Evidence:_ TUI/routes/session/index.tsx:2149-2300
- **OpenClaw 2026.9.8** ✅ — Skipped calls marked "Skipped" even after reload. — _Evidence:_ `oc/docs/web/tui.md` (Tool output); `oc/docs/web/control-ui.md`

</details>
<details><summary><b>UI-22</b> Thinking display toggle</summary>

- **sugar-crush** ✅ — No global "always show/hide thinking" setting. Thought rows are per message. — _Evidence:_ `src/Renderer.php:4554`; README.md:762-765; live
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/ReasoningFold.vue`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:model-config#extended-thinking
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/tui (thinking)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): /thinking toggles reasoning display. 2.0.31 (✅): cli.json `session.thinking: show/hide` (no /thinking command) — _Evidence:_ V2D/cli/config (Sessions)
- **OpenClaw 2026.9.8** ✅ — TUI Ctrl+T thinking visibility and /settings; `/reasoning`; Control UI "Keep commentary" — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>UI-23</b> Status line (customizable / scriptable)</summary>

- **sugar-crush** 🟡 — Refreshes every 2 s (configurable slower, up to 1 h). Output is stripped of ANSI, zone sentinels and newlines and fitted last (lowest priority). The command gets no session context: stdin is closed and only the inherited env is passed (no model/cost/context JSON like CC). The `run()` call blocks the event loop for up to ~1.5 s per tick (ISSUES P2). — _Evidence:_ `src/Config/StatusLineCommand.php:85-160,396-470`; `src/Chat.php:2628-2641`; `src/Renderer.php:2779-2800`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — Multi-line, OSC 8 links, refresh interval, `subagentStatusLine` per-subagent rows; managed lock under `allowManagedHooksOnly`. — _Evidence:_ cc:statusline
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ PLUG/tui.ts:455-486
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): No scriptable status line; TUI plugins can render into slots (app_bottom, sidebar_footer, prompt_right). 2.0.31 (🟡): No scriptable status line; CLI plugins (cli.json `plugins`) can extend the TUI — _Evidence:_ V2D/build/plugins/cli; V2D/cli/config (Plugins)
- **OpenClaw 2026.9.8** 🟡 — TUI status line (connection/run state) and footer (agent, session, model, goal, toggles, tokens, deliver) are not configurable. `/usage full` appends a footer to replies whose layout can be replaced by an `openclaw.usageBar.v1` template (fields like model, reasoning, fast mode, context %, cost; meters/aliases; file watched). Declarative, not a script hook. — _Evidence:_ `oc/docs/web/tui.md` (What you see); `oc/docs/concepts/usage-tracking.md` (Custom /usage full footer)

</details>
<details><summary><b>UI-24</b> Cost / token display</summary>

- **sugar-crush** ✅ — Estimates carry a `~` and the provider's billed figures carry a `$`; the two are never summed. Before anything is reported a capped session shows `$?`. A narrow bar degrades through a form ladder (`0% context`), dropping the model name first. — _Evidence:_ lang/en.php:2297-2322; `src/Renderer.php:2252-2730`; README.md:768-806
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/StatusBar.vue`
- **Claude Code 2.1.301** ✅ — Usage-limit banner and reset times. — _Evidence:_ cc:costs#track-your-costs; cc:costs#read-the-token-count-beside-the-spinner
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/feature-plugins/sidebar/context.tsx
- **OpenClaw 2026.9.8** ✅ — `/usage off/tokens/full/cost` footers, TUI token counts, Control UI context ring with plan-usage bars, Usage page — _Evidence:_ `oc/docs/concepts/usage-tracking.md`

</details>
<details><summary><b>UI-25</b> Keybinding customization</summary>

- **sugar-crush** ❌ — The registry drives the `?` reference and the drift tests but cannot be overridden from config. sugar-forms has a `HasKeyOverrides` seam (`'ctrl+u' => action`) that sugar-crush does not expose. — _Evidence:_ `src/Commands/KeyBinding.php:8-16`; `src/Commands/KeyBindingRegistry.php:440-520`; `sugar-forms/src/TextArea/TextArea.php:175-179`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Readline word-edit keys not remappable. — _Evidence:_ cc:keybindings
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/keybinds
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): tui.json keybinds (~150 actions, leader key, multi-binding objects, 'none' to disable). 2.0.31 (✅): Keybinds in global cli.json (migrated from tui.json) — _Evidence:_ V2D/cli/keybinds; V2D/migrate-v1 (Terminal client configuration)
- **OpenClaw 2026.9.8** 🟡 — Only the Control UI send-shortcut and follow-up default are configurable; no keybinding file — _Evidence:_ `oc/docs/web/control-ui/chat.md`; `oc/docs/web/control-ui/settings.md` (Side panel keyboard shortcuts)

</details>
<details><summary><b>UI-26</b> Notifications (bell / desktop / when done)</summary>

- **sugar-crush** ✅ — Sent as a `Cmd::raw()`, never from view(). Under tmux only `bell` arrives (no OSC passthrough). README calls it a live setting; code and SETTINGS.md say it applies at restart (ISSUES P3). — _Evidence:_ `src/Tui/TerminalNotifier.php:1-40`; `src/Config/Settings/Definitions/InterfaceSettings.php:88-97`; `src/Chat.php:3951`
- **sugar-crush-web** ✅ — Silently unavailable on a plain-HTTP remote IP (ISSUES P3). — _Evidence:_ `src-web/components/approvals/attention.ts:185-234`
- **Claude Code 2.1.301** ✅ — Phone push via Remote Control; `PushNotification` tool. — _Evidence:_ cc:terminal-config#get-a-terminal-bell-or-notification; CL v2.1.295
- **OpenCode 1.18.35** ✅ — Distinct `subagent_done` sound. Volume and sound packs are configurable. Disabled by default. Plugins such as kdco notify (installed here) add OS notifications. — _Evidence:_ DOCS/tui (Attention); TUI/feature-plugins/system/notifications.ts; LOCAL: ~/.config/opencode/plugins/notify.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Opt-in `attention`: desktop notification when terminal blurred + sounds for done/permission/question/error. 2.0.31 (✅): cli.json `attention` (notifications, sounds) + terminal program-status notifications — _Evidence:_ V2D/cli/config (Alerts); V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Permission requested on first send. — _Evidence:_ `oc/docs/web/notifications.md`

</details>
<details><summary><b>UI-27</b> Terminal image rendering (sixel / kitty / iTerm2)</summary>

- **sugar-crush** ✅ — Tool results carrying images are labelled and painted inline. Live image-generation preview slots. Capability probe runs at launch (DA1 + XTWINOPS), but **in cooked mode**: replies are echoed to the primary screen and appear as garbage after exit, and detection can fail (ISSUES P2). — _Evidence:_ `src/ToolResult.php:440-470`; `sugar-image/src/Mosaic.php:80-125`; `sugar-image/src/Detect.php:49-150`; `src/Commands/RenderModeCommand.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/MediaCard.vue`
- **Claude Code 2.1.301** 🟡 — `CLAUDE_CODE_FORCE_TERMINAL_IMAGES`. — _Evidence:_ cc:env-vars; cc:plugins/mods/gallery
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ TUI/app.tsx:199
- **OpenCode 2.0.31** 🟡 — 1.18.35 (❌): No inline image rendering (sixel/kitty graphics); only kitty keyboard protocol. 2.0.31 (🟡): TUI diff viewer renders changed PNG/JPEG/WebP/GIF via OpenTUI image element (terminal-dependent) — _Evidence:_ V2T/feature-plugins/system/diff-viewer-image.tsx:3-45
- **OpenClaw 2026.9.8** ✅ — TUI inline image previews on Kitty/iTerm2/Ghostty (static thumbnails ≤60×20 cells); off in tmux/screen; no sixel — _Evidence:_ `oc/docs/web/tui.md` (Image previews)

</details>
<details><summary><b>UI-28</b> Help screen / shortcuts overlay</summary>

- **sugar-crush** ✅ — The reference covers chat, panes, palette, session picker, folder picker, permission prompt, agent view/strip/transcript, skill picker, settings view, media form, menu bar and mouse. A test presses every listed row. The overlay is confined to the chat pane, so with panes docked descriptions are hard-cut with no ellipsis. `??` types a literal `?`. — _Evidence:_ `src/Commands/KeyBindingRegistry.php`; `src/Renderer.php:6230-6290`; `builtin-commands/1000-keys.php`, `1100-help.php`; live
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#quick-commands; cc:commands
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/app.tsx:814; TUI/feature-plugins/system/which-key.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/app.tsx:814; TUI/feature-plugins/system/which-key.tsx
- **OpenClaw 2026.9.8** ✅ — Control UI shortcuts overlay (⌘/ or Ctrl+/); `/help`, `/commands` — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md`; `oc/docs/tools/slash-commands.md`

</details>
<details><summary><b>UI-29</b> Onboarding / first-run setup</summary>

- **sugar-crush** 🟡 — The notice is a transcript row (Message::notice). Provider selection is the palette's "Switch model" list. No guided key entry or provider login flow in the TUI. — _Evidence:_ `src/Chat.php:10685-10706`; lang/en.php:2609
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/views/LoginView.vue`
- **Claude Code 2.1.301** ✅ — `/powerup`, `/init`, `/terminal-setup`, `/mobile` QR. — _Evidence:_ cc:quickstart; cc:fullscreen#fullscreen-by-default; cc:plugins/code-intelligence
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/index (Configure); TUI/component/dialog-provider.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/index (Configure); TUI/component/dialog-provider.tsx
- **OpenClaw 2026.9.8** ✅ — Bare `openclaw` picks onboarding, doctor guidance, gateway TUI or local TUI automatically; `openclaw setup` repair assistant. — _Evidence:_ `oc/docs/start/onboarding.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>UI-30</b> Accessibility (screen reader, reduced motion, colour-blind)</summary>

- **sugar-crush** 🟡 — Reduced motion only freezes the agent-live spinner (the main spinner never animates anyway). Colour-blind palettes and a screen-reader/plain-text mode are absent. All strings go through `Lang::t()`, but only `en` ships. — _Evidence:_ docs/ENVIRONMENT.md:62; `src/Theme.php:139-147,410-480`; `sugar-core/src/Util/ColorProfile.php`; `lang/en.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/styles/tokens.css:132`; `src-web/views/SessionView.vue:125`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:accessibility
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/keybinds (app_toggle_animations)
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ DOCS/keybinds (app_toggle_animations)
- **OpenClaw 2026.9.8** ✅ — TUI keeps terminal default foreground for readability. — _Evidence:_ `oc/docs/web/control-ui/settings.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>UI-31</b> Spinner / progress / elapsed time</summary>

- **sugar-crush** 🟡 — The `⠴ assistant is thinking…` row and the status bar glyph are hard-coded strings that never advance, and the main turn shows no elapsed timer while it runs. A stalled request looks the same as a working one. `StallDetector` is wired only for background sessions. Sub-agent live lines (`└ ⠋ Grep … · 7 tools · 0:12 · 4.1K tok`) do animate. — _Evidence:_ `src/Renderer.php:1825,2648-2675`; `src/Tui/AgentActivityLine.php`; `src/Sessions/BackgroundSupervisor.php:190-232`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/StatusBar.vue:17-25`
- **Claude Code 2.1.301** ✅ — `spinnerVerbs`, `spinnerTipsOverride`, `prefersReducedMotion`. — _Evidence:_ cc:costs#read-the-token-count-beside-the-spinner; cc:settings-reference
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/component/spinner.tsx; TUI/component/bg-pulse.tsx; TUI/routes/session/index.tsx:1481-1570
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/component/spinner.tsx; TUI/component/bg-pulse.tsx; TUI/routes/session/index.tsx:1481-1570
- **OpenClaw 2026.9.8** ✅ — TUI run-state line (connecting/running/streaming/idle/error); Control UI working indicator, durations, running-tasks indicator — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>UI-32</b> Compact / verbose transcript toggle</summary>

- **sugar-crush** 🟡 — There is no single chord that flips the whole transcript between compact and verbose (CC: Ctrl+O transcript mode; OC: /details). — _Evidence:_ `src/Config/Settings/Definitions/InterfaceSettings.php:252-260`; `KeyBindingRegistry.php` chat.tool-output
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `viewMode` setting. — _Evidence:_ cc:interactive-mode; cc:commands (/focus); help
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ TUI/config/keybind.ts:101,149-150
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Separate toggles for tool details, generic tool output, timestamps, concealment; no single compact mode. 2.0.31 (✅): `session.verbosity` low/high groups tools, thoughts and instruction loads into summaries — _Evidence:_ V2T/routes/session/group-view.tsx:201-240; V2CL v2.0.26
- **OpenClaw 2026.9.8** ✅ — `/verbose on/full/off`, TUI Ctrl+O, Control UI collapsed "Worked" groups — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>UI-33</b> Startup banner content (model, cwd, loaded files, tips)</summary>

- **sugar-crush** ❌ — Nothing shows version, cwd, provider/model, or which CLAUDE.md/AGENTS.md, memory, skills, agents or MCP servers loaded. The model appears only at the right end of the status bar, truncated (`Qwen3.8-Flash-Ne…`). Launch notices appear only when something degraded (dropped rules, echo backend, …). `/context` later shows `project-instructions ×2` without file names. — _Evidence:_ `src/Renderer.php:4085`; `src/Chat.php:10600-10670`; live startup capture
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/views/DashboardView.vue`
- **Claude Code 2.1.301** ✅ — Header names a model pinned by project/managed settings; `@agent` shown with `--agent`; warnings for big CLAUDE.md, >15K-token agent descriptions, unknown model IDs. — _Evidence:_ cc:model-config#adjust-effort-level; cc:settings-reference (companyAnnouncements); CL (welcome banner fixes)
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ TUI/feature-plugins/home/footer.tsx; TUI/feature-plugins/home/tips-view.tsx
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Home: logo, prompt (agent·model·variant), tips, footer dir:branch, MCP count, version; no loaded-files list. 2.0.31 (🟡): Home shows release highlights once after update (/highlights); still no loaded-files list — _Evidence:_ V2CL v2.0.28
- **OpenClaw 2026.9.8** 🟡 — TUI header shows connection URL, agent and session only; no loaded-files/tips banner — _Evidence:_ `oc/docs/web/tui.md` (What you see)

</details>
<details><summary><b>UI-34</b> Prompt suggestions / tips</summary>

- **sugar-crush** ✅ — It costs an extra title-model call per turn and is disabled with `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1` or the setting. Verified live ("commit the change (→ to accept)"). No rotating tips. — _Evidence:_ `src/Config/Settings/Definitions/InterfaceSettings.php` (promptSuggestions); `KeyBindingRegistry.php` chat.accept-suggestion; live
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Suggestions skipped on cold cache/plan mode/near limits; flag-gated. — _Evidence:_ cc:interactive-mode#prompt-suggestions; cc:interactive-mode#you-should-know-suggestions
- **OpenCode 1.18.35** ✅ — Tips include “Use instructions in config…” and “Commit your project's AGENTS.md…”. — _Evidence:_ TUI/feature-plugins/home/tips-view.tsx:256,279
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): ~60 rotating home tips (<leader>h) and prompt placeholders. 2.0.31 (🟡): Rotating home tips removed; release highlights shown once after updates (/highlights) — _Evidence:_ V2T (no tips view); V2CL v2.0.28
- **OpenClaw 2026.9.8** 🟡 — `suggest_task` follow-up chips, ClawHub recommendation cards; no rotating prompt tips — _Evidence:_ `oc/docs/gateway/config-tools/tool-policy.md` (suggest_task); `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-N1</b> 🆕 Contrast-aware themes (colours adjusted against the detected terminal background)</summary>

- **sugar-crush** ✅ — `Theme::pair()` projects each chrome token (labels, borders, success/warn/error) along HSL lightness until it is legible on both the real background (OSC 11 / COLORFGBG / env override) and the row-highlight fill. The OSC 11 query is sent asynchronously from `App::init`, so the first frames may re-colour once the reply lands. — _Evidence:_ `src/Theme.php:237-480`; `src/Tui/TerminalBackground.php`
- **sugar-crush-web** ❌ — The web has no counterpart of `Theme::pair()`; the stylesheets contain no contrast logic. — _Evidence:_ `sugar-crush-web/src-web/styles/tokens.css`; `sugar-crush-web/src-web/stores/settings.ts:14-31`
- **Claude Code 2.1.301** 🟡 — Custom themes override tokens by hand. — _Evidence:_ cc:settings-reference#theme; cc:terminal-config#match-the-color-theme
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/themes (System theme)
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2T/theme/system.ts:1-20
- **OpenClaw 2026.9.8** 🟡 — Override with `OPENCLAW_THEME=light/dark`; no per-colour contrast adjustment. Control UI has a WCAG AAA (Beacon) theme and honors increased-contrast/forced-colors. — _Evidence:_ `oc/docs/web/tui.md` (Terminal colors); `oc/docs/web/control-ui/settings.md`

</details>
<details><summary><b>UI-N2</b> 🆕 Menu bar (F10-style menus / clickable chrome)</summary>

- **sugar-crush** ✅ — Menus are built from `CommandRegistry` categories. Rows fall back to bare command names (`compact`, `clear`, `rewind`, `bg`) beside Title-case labels (ISSUES P3). Below ~100 columns the tabs collapse to icons and the menus drop names. — _Evidence:_ `src/Tui/Components/MenuBar.php`; `src/Commands/CommandSpec.php:359-362`; live
- **sugar-crush-web** ✅ — The top bar has a sessions drawer toggle, approvals button, settings link, theme cycle, notify and sign out. The palette covers sessions, commands, panels and new session. — _Evidence:_ `sugar-crush-web/src-web/App.vue:77-87`; `sugar-crush-web/src-web/components/panels/CommandPalette.vue`
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:interactive-mode; cc:desktop
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ APP/desktop-menu.ts
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ packages/desktop; packages/app/src/shell
- **OpenClaw 2026.9.8** 🟡 — TUI is keyboard-driven (Ctrl+L/G/P pickers, `/settings`); Control UI has menus, context menus, palette; macOS app adds native titlebar/menu-bar commands. — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/web/control-ui/settings.md`; `oc/docs/platforms/mac/menu-bar.md`

</details>
<details><summary><b>UI-N3</b> 🆕 In-UI media generation (image / video generation forms inside the client)</summary>

- **sugar-crush** ✅ — Media artifacts render with sugar-image, with live preview slots while generating. No competitor has an equivalent inside the TUI. — _Evidence:_ `src/Tui/MediaForm/`; `src/Tui/VideoPlayer/`; README.md:31; docs/MEDIA.md
- **sugar-crush-web** 🧪 — `canGenerateImage/Video` are computed from `server.hello`, but no component reads them, and the web never calls `generate.*`. Typed `/generate <prompt> [flags]` runs txt2img server-side; a bare `/generate` (which opens the TUI form) is refused as client-only. — _Evidence:_ `sugar-crush-web/src-web/stores/connection.ts:77-78`; `src/Host/SessionHost.php:973-980`; `src/Host/Commands/GenerateHostCommand.php`; `sugar-crush-web/src-web/components/MediaCard.vue`
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:tools-reference
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** 🟡 — `image_generate`/`video_generate`/`music_generate` with provider failover; `openclaw infer image generate` for the CLI. — _Evidence:_ `oc/docs/tools/image-generation.md`; `oc/docs/cli/infer.md`

</details>
<details><summary><b>UI-N4</b> 🆕 Session recap after inactivity (one-line "where were we" summary)</summary>

- **sugar-crush** ❌ — A grep of src/ and lang/en.php for recap, "where were we" or "welcome back" finds nothing. Prompt suggestions (a title-model call after each turn) propose your next message, Ctrl+R shows session previews, and `/handoff` starts a new session seeded with a state summary. Nothing fires after the terminal loses focus or after time passes. — _Evidence:_ `src/Host/TitleService.php:95`; `src/Host/Commands/HandoffHostCommand.php:7-25`
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush-web/src-web/stores/layout.ts:140-160`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#session-recap
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2CL v2.0.28
- **OpenClaw 2026.9.8** ✅ — Generated by the utility model, shared across clients and restarts, refreshed as work happens; not shown as an in-chat "welcome back" line. — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Activity tab)

</details>
<details><summary><b>UI-N5</b> 🆕 Prompt hygiene (strip invisible Unicode before sending; spellcheck)</summary>

- **sugar-crush** 🟡 — sugar-core's InputReader sanitises bracketed paste (`ProgramOptions::$sanitizePaste`, default true, which applies `Sanitize::untrusted`). That removes every ESC/CSI/OSC/DCS sequence, C0 controls except TAB/LF/CR, DEL and C1. The Renderer draws the in-progress input and user rows through `untrustedForDisplay()` → `markInvisibleFormatting()`, so these become visible `<U+XXXX>` markers: U+200B, U+202A-202E, U+2066-2069, U+2060 and U+FEFF always, and ZWJ/ZWNJ/LRM/RLM/ALM unless they follow a non-ASCII character. That makes Trojan-Source text visible. `untrusted()` keeps these characters for paste fidelity, so they reach the model unchanged (CC strips them). There is no spellcheck. — _Evidence:_ `sugar-core/src/ProgramOptions.php:160`; `sugar-core/src/Util/Sanitize.php:239-262`, `:320-380`; `src/Renderer.php:84-89`, `:1628`; `src/Chat.php:2798-2800`
- **sugar-crush-web** 🟡 — The composer `<textarea>` sets no `spellcheck` attribute, so the browser default applies (on for textareas in Chrome and Firefox). `session.send` passes the text through unchanged. — _Evidence:_ `sugar-crush-web/src-web/components/Composer.vue:109-119`; `src/Protocol/Methods/TurnMethods.php:56-67`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:interactive-mode#invisible-characters-in-prompts; cc:interactive-mode#check-spelling-as-you-type
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ TUI/component/prompt/ (grep)
- **OpenCode 2.0.31** ❌ — — — _Evidence:_ V2T/component/prompt/ (grep)
- **OpenClaw 2026.9.8** ❌ — Related hygiene elsewhere: progress-card text drops invisible/bidi characters, macOS approval panels show visible escapes for invisible characters, Control UI strips leaked control tokens from assistant output. — _Evidence:_ `oc/docs/tools/progress-card.md`; `oc/docs/tools/exec-approvals.md`; `oc/docs/web/control-ui/chat.md`

</details>
<details><summary><b>UI-N6</b> 🆕 Cross-session attention model (server-wide approvals inbox, badge counts, title/desktop notifications)</summary>

- **sugar-crush** 🟡 — `notify` (TerminalNotifier: off by default, or bell, or osc9; applies at restart) signals the end of a turn and a waiting approval for the CURRENT session. The tab strip (Ctrl+Tab) swaps the single live session and is refused mid-turn, so there is no other running session to badge. Background sessions never ask (asks are refused) and report back as messages. The Agents strip and pane show live sub-agents. The window title shows no count. The cross-session inbox exists only in the web UI (server-scope `permission.asked`), and `sugarcrush attach` follows one session. — _Evidence:_ `src/Tui/TerminalNotifier.php:9-40`; `src/Config/Settings/Definitions/InterfaceSettings.php:88`; `src/Chat.php:2157`, `:3645`
- **sugar-crush-web** ✅ — Every open question of every open session, oldest first, grouped by session, answerable in place; re-seeded on every handshake and every `server.tick`. — _Evidence:_ `src-web/components/approvals/ApprovalsDrawer.vue`; `src-web/stores/approvals.ts`
- **Claude Code 2.1.301** ✅ — Notifications via `preferredNotifChannel` and `Notification` hook types `agent_needs_input`/`agent_completed`; OSC 7501 per-session status in terminals; phone push via Remote Control; background-subagent prompts surface in the main session. — _Evidence:_ cc:agent-view#monitor-sessions-with-agent-view; cc:terminal-config#see-session-status-in-your-terminal; cc:remote-control#mobile-push-notifications
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/tui (Attention); TUI/routes/session/index.tsx:231-240
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ V2D/cli/config (Tabs, Alerts); V2CL v2.0.29, v2.0.30
- **OpenClaw 2026.9.8** ✅ — Pending approvals and failing automations/expiring auth add chips above the sidebar footer; per-person running/open counts; Web Push for approvals and mentions. — _Evidence:_ `oc/docs/web/control-ui/settings.md`; `oc/docs/web/control-ui.md`; `oc/docs/web/notifications.md`

</details>

### WEB — Web, remote, multi-surface

<details><summary><b>WEB-01</b> Web UI</summary>

- **sugar-crush** ✅ — Static files are GET/HEAD only, dotfiles and `..` refused, realpath-contained, hashed assets cached immutable; with no bundle installed `/` explains how to get it. — _Evidence:_ `sugar-crush/src/Server/Http/StaticFiles.php:83-162`; `sugar-crush/docs/SERVER.md:994-1004`
- **sugar-crush-web** ✅ — — — _Evidence:_ `sugar-crush-web/README.md`
- **Claude Code 2.1.301** ✅ — Diff pane, model/effort controls, attachments. — _Evidence:_ cc:claude-code-on-the-web; cc:remote-control
- **OpenCode 1.18.35** ✅ — `OPENCODE_DISABLE_EMBEDDED_WEB_UI` turns it off. — _Evidence:_ DOCS/web; CLI: `opencode web --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode web` serves the embedded web app (sessions, server picker, terminal panel). 2.0.31 (✅): Web UI from the same server, password-protected by default, opened via `opencode pair`; installable PWA — _Evidence:_ V2D/cli/web; V2CL v2.0.27
- **OpenClaw 2026.9.8** ✅ — Default `http://<host>:18789/`; `openclaw dashboard` opens it with the current token; installable PWA. — _Evidence:_ `oc/docs/web/control-ui.md`; `oc/docs/web/dashboard.md`

</details>
<details><summary><b>WEB-02</b> Server mode (HTTP / WebSocket API)</summary>

- **sugar-crush** ✅ — Durable events are logged before broadcast with gap-free per-session `seq` (20,000 kept) → resume/replay; live deltas carry byte offsets; backpressure outbox (1 MiB soft / 16 MiB hard, `server.overflow`, close 1013); 50 req/s burst 200 (verified: 59 of 260 burst requests refused -32011). Schema + doc tables generated from PHP and drift-tested (`--check` up to date). — _Evidence:_ `sugar-crush/src/Protocol/Dispatcher.php`; `sugar-crush/docs/protocol/sugarcrush.v1.schema.json`; `sugar-crush/tests/Protocol/ProtocolSchemaDriftTest.php`
- **sugar-crush-web** ✅ — Unused: agents.list, files.changed/diff/read, generate.status/submit, permission.rules, server.health/info/shutdown, session.close/export/fork/get/queue, workflow.status, workspace.close. — _Evidence:_ grep `src-web/` vs `sugar-crush/docs/protocol/sugarcrush.v1.schema.json`
- **Claude Code 2.1.301** 🟡 — `claude mcp serve` (stdio MCP); self-hosted environments run cloud sessions on your infra. — _Evidence:_ cc:headless; cc:remote-control#connection-and-security; cc:self-hosted-environments
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/server; CORE/flag/flag.ts (OPENCODE_EXPERIMENTAL_WEBSOCKETS)
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): HTTP OpenAPI 3.1 + SSE event stream; experimental websockets flag. 2.0.31 (✅): Default ports 49374 (macOS/Linux/WSL), 45278 (Windows). — _Evidence:_ V2D/cli/web; V2D/cli/index
- **OpenClaw 2026.9.8** ✅ — Gateway WebSocket RPC (versioned, scoped) + OpenAI-compatible and OpenResponses HTTP APIs + tools-invoke HTTP — _Evidence:_ `oc/docs/gateway/protocol.md`; `oc/docs/gateway/openai-http-api.md`

</details>
<details><summary><b>WEB-03</b> Multiple clients attached to the same session</summary>

- **sugar-crush** ✅ — The gateway fans a workspace host's events out per client with per-client cursors; a TUI holding the session is refused `session_locked`/read-only. Verified live: an unsubscribed second client heard `permission.asked`; second answer got `already_resolved` with the winner. — _Evidence:_ `sugar-crush/src/Protocol/Methods/PermissionMethods.php:53-126`; live probe
- **sugar-crush-web** ✅ — — — _Evidence:_ `e2e/multi-client.spec.ts`
- **Claude Code 2.1.301** ✅ — Cross-session messaging also spans machines. — _Evidence:_ cc:remote-control
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/web (Attaching a Terminal)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/web (Attaching a Terminal)
- **OpenClaw 2026.9.8** ✅ — Many clients on one session (Control UI tabs, TUI, apps, channels) with live sync and presence — _Evidence:_ `oc/docs/concepts/session-attachment.md`; `oc/docs/web/control-ui.md`

</details>
<details><summary><b>WEB-04</b> Remote control from phone / browser</summary>

- **sugar-crush** 🟡 — Host check (DNS rebinding), Origin check, `--allowed-ips`, trusted-proxy X-Forwarded-For/Proto (Secure cookie only behind a trusted HTTPS proxy). Pre-auth slowloris stalls the listener (ISSUES). — _Evidence:_ `sugar-crush/docs/SERVER.md:1144-1270`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/App.vue:180-207`; Playwright probe
- **Claude Code 2.1.301** ✅ — Outbound-only, short-lived creds, Trusted Devices option; unavailable on 3P providers, ZDR, HIPAA. — _Evidence:_ cc:remote-control
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/web; DOCS/ecosystem (portal, CodeNomad)
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): LAN access via --hostname 0.0.0.0/mDNS + basic auth; phone use via community Portal/CodeNomad. 2.0.31 (✅): `opencode pair --remote` exposes the server via OpenTunnel (E2E encrypted, password); QR for phones — _Evidence:_ V2D/cli/web (Remote access); V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Remote via Tailscale, stable HTTPS URL, Cloudflare Access, trusted proxy; `tui --url`; phone apps; chat apps — _Evidence:_ `oc/docs/gateway/remote.md`; `oc/docs/gateway/tailscale.md`

</details>
<details><summary><b>WEB-05</b> Desktop app</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Dispatch from phone (Pro/Max); `/desktop` hands off a CLI session. — _Evidence:_ cc:desktop; cc:desktop-linux
- **OpenCode 1.18.35** ✅ — Can connect to a remote server URL instead. — _Evidence:_ DOCS/troubleshooting (Desktop app); packages/desktop
- **OpenCode 2.0.31** ✅ — Can connect to a remote server URL instead. — _Evidence:_ DOCS/troubleshooting (Desktop app); packages/desktop
- **OpenClaw 2026.9.8** ✅ — macOS menu-bar app (bundled gateway), Windows Hub (WinUI), Linux companion — _Evidence:_ `oc/docs/platforms/macos.md`; `oc/docs/platforms/windows.md`; `oc/docs/platforms/linux.md`

</details>
<details><summary><b>WEB-06</b> VS Code extension</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — Built-in IDE MCP server for selection/diagnostics. — _Evidence:_ cc:vs-code
- **OpenCode 1.18.35** ✅ — Ctrl+Esc opens a split terminal. Shares selection and tab context. — _Evidence:_ DOCS/ide; OC/ide/index.ts:6-40
- **OpenCode 2.0.31** ✅ — Ctrl+Esc opens a split terminal. Shares selection and tab context. — _Evidence:_ DOCS/ide; OC/ide/index.ts:6-40
- **OpenClaw 2026.9.8** ❌ — Only a third-party Copilot Proxy VS Code extension for model access. — _Evidence:_ `oc/docs/providers/github-copilot.md`

</details>
<details><summary><b>WEB-07</b> JetBrains plugin</summary>

- **sugar-crush** 🟡 — ACP stdio agent sharing the TUI's SessionHub/session store; `session/request_permission` for asks. — _Evidence:_ `sugar-crush/docs/SERVER.md:912-992`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:jetbrains
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/acp (JetBrains IDEs)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/acp (JetBrains IDEs)
- **OpenClaw 2026.9.8** ❌ — No JetBrains plugin — _Evidence:_ `oc/docs/cli/acp.md`

</details>
<details><summary><b>WEB-08</b> Other editors (Zed, Neovim, Emacs via ACP etc.)</summary>

- **sugar-crush** ✅ — initialize/new/load/prompt/cancel/set_mode mapped; diffs as ACP `diff` blocks; ids echoed exactly. Documented, not run here. — _Evidence:_ `sugar-crush/docs/SERVER.md:912-992`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:terminal-config; cc:commands
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/acp; DOCS/ecosystem
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/acp; DOCS/ecosystem
- **OpenClaw 2026.9.8** ✅ — `openclaw acp` ACP bridge with documented Zed setup — _Evidence:_ `oc/docs/cli/acp.md` (Zed editor setup)

</details>
<details><summary><b>WEB-09</b> Chat-channel integrations (Slack, Telegram, WhatsApp, Discord, iMessage, ...)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Channels need Bun and an open session; Team/Ent must enable channels. — _Evidence:_ cc:slack; cc:claude-tag; cc:channels
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ packages/slack/README.md; DOCS/ecosystem (kimaki)
- **OpenCode 2.0.31** ❌ — 1.18.35 (🟡): Repo ships an unpublished Slack bot sample (packages/slack); Discord via community kimaki. 2.0.31 (❌): The Slack bot sample (packages/slack) is gone from the v2 tree; community kimaki targets v1 SDK — _Evidence:_ V2 packages/ (no slack)
- **OpenClaw 2026.9.8** ✅ — Most are official installable plugins (`clawhub:@openclaw/whatsapp`, `/discord`, `/slack`...); per-channel threads, rich messages, voice. — _Evidence:_ `oc/docs/channels/index.md`; `oc/docs/plugins/reference/telegram.md`; `oc/docs/plugins/reference/whatsapp.md`

</details>
<details><summary><b>WEB-10</b> Mobile app</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush-web/dist/manifest.json`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:mobile
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ DOCS/index
- **OpenCode 2.0.31** 🟡 — 1.18.35 (❌): No mobile app. 2.0.31 (🟡): Evidence: pairing docs mention scanning the QR “from the OpenCode app”; desktop renders iOS voice delegations. — _Evidence:_ V2D/cli/web; V2CL v2.0.30; packages/app/src/session/timeline/voice-delegation.ts
- **OpenClaw 2026.9.8** ✅ — iOS/watchOS and Android apps as nodes (chat, Talk, camera/location/screen, push) — _Evidence:_ `oc/docs/platforms/ios.md`; `oc/docs/platforms/android.md`

</details>
<details><summary><b>WEB-11</b> Web UI auth & security model</summary>

- **sugar-crush** ✅ — Plus Host allow-list, Origin check on every upgrade and non-GET (absent Origin → only bearer/subprotocol accepted), Sec-Fetch-Site cross-site refused, CSP/nosniff/DENY/no-referrer/COOP/CORP, logs never record query/headers/bodies, token rotation signs everyone out (4001). Gaps (ISSUES): cookie shared across ports, logout leaves sockets, per-address lockout blocks the valid token, slowloris before the IP guard. — _Evidence:_ `sugar-crush/src/Server/Auth/*.php`; `sugar-crush/src/Server/Http/AuthMiddleware.php`; `sugar-crush/src/Server/Http/HostAndOriginGuard.php`; live curl/ws probes
- **sugar-crush-web** ✅ — Detail in DEEP-DIVE security review and ISSUES. — _Evidence:_ `src-web/protocol/auth.ts`; `sugar-crush/src/Server/Http/AuthMiddleware.php`
- **Claude Code 2.1.301** ✅ — Transcript stored server-side while RC is connected; Trusted Devices enrollment policy. — _Evidence:_ cc:remote-control#connection-and-security; cc:claude-code-on-the-web
- **OpenCode 1.18.35** 🟡 — The docs warn that the server is unsecured without a password. No TLS, tokens or per-user accounts. — _Evidence:_ DOCS/web (Authentication); DOCS/server
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Optional HTTP basic auth (OPENCODE_SERVER_PASSWORD); unsecured by default on 127.0.0.1; CORS allowlist. 2.0.31 (✅): Generated server password, one-time 5-min pairing links, 30-day cookies, OpenTunnel E2E for remote — _Evidence:_ V2D/cli/web
- **OpenClaw 2026.9.8** ✅ — Public transcripts use an opaque capability token; no Gateway access. — _Evidence:_ `oc/docs/web/control-ui/security-model.md`; `oc/docs/gateway/authentication.md`; `oc/docs/gateway/security/index.md`

</details>
<details><summary><b>WEB-12</b> Cloud / hosted execution (sandboxed cloud sessions)</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `--cloud`, `/teleport`, setup scripts, environments; Projects coordinate many cloud sessions. — _Evidence:_ cc:claude-code-on-the-web; cc:self-hosted-environments; cc:claude-projects
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ DOCS/ecosystem (opencode-daytona); OC/control-plane/adapters
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🔌): No hosted execution; 🔌 opencode-daytona sandboxes / experimental workspace adapters. 2.0.31 (🟡): No hosted sessions, but @opencode/sdk/workerd embeds OpenCode in Cloudflare Durable Objects — _Evidence:_ V2D/build/sdk/cloudflare
- **OpenClaw 2026.9.8** ✅ — Transcript stays on the Gateway; no OpenClaw-hosted SaaS. — _Evidence:_ `oc/docs/gateway/cloud-workers.md`

</details>
<details><summary><b>WEB-13</b> Voice interface</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:voice-dictation
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** 🟡 — 1.18.35 (❌): No voice interface. 2.0.31 (🟡): Voice only via the iOS app's voice-call delegation; no voice in TUI/desktop — _Evidence:_ packages/app/src/session/timeline/voice-delegation.ts:1-30
- **OpenClaw 2026.9.8** ✅ — Talk mode realtime voice (browser, macOS, iOS, Android), voice wake, phone calls plugin, Discord voice — _Evidence:_ `oc/docs/nodes/talk.md`; `oc/docs/nodes/voicewake.md`; `oc/docs/channels/discord/voice-channels.md`

</details>
<details><summary><b>WEB-14</b> Approvals from web / remote</summary>

- **sugar-crush** ✅ — `always` remembered per session with argument-scoped patterns and cascades only to questions the pattern covers; `reject` + `cascade` stops the turn; `server.askTimeoutSeconds` optional. But `bg.spawn` work never asks at all (P1). — _Evidence:_ `sugar-crush/docs/SERVER.md:555-605`; `sugar-crush/src/Protocol/Methods/PermissionMethods.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/approvals.ts:113-141`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:remote-control#mobile-push-notifications
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/server
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/server
- **OpenClaw 2026.9.8** ✅ — Approvals from Control UI, apps, chat channels, web push — _Evidence:_ `oc/docs/web/notifications.md`; `oc/docs/cli/approvals.md`

</details>
<details><summary><b>WEB-N1</b> 🆕 New-session directory picker / browse server folders from the web UI</summary>

- **sugar-crush** ✅ — Verified live: `..`, `/etc`, `proj1/../..` and an in-root symlink pointing out all refused `-32003 outside_browse_root`; dot-dirs only with showHidden; `workspace.open {browse:true}` re-checks. — _Evidence:_ `sugar-crush/src/Support/Directories/DirectoryBrowser.php:77-161`; live probe
- **sugar-crush-web** ✅ — Starts a workspace host for a non-primary root; Esc cancels. Containment verified (.., absolute paths, out-of-root symlink refused). — _Evidence:_ `src-web/components/DirectoryPicker.vue`; `e2e/dir-picker.spec.ts`
- **Claude Code 2.1.301** 🟡 — Agent view (terminal) dispatches to child repos via `@<repo>`; Desktop app opens local folders. — _Evidence:_ cc:claude-code-on-the-web; cc:remote-control#start-a-remote-control-session; cc:agent-view#dispatch-to-a-specific-directory
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ APP/components/directory-picker.tsx; APP/components/dialog-select-directory-v2.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ packages/app/src/new-session/project/selector.tsx
- **OpenClaw 2026.9.8** ✅ — Paths outside configured agent workspaces need `operator.admin`; destination picker also chooses local / paired device / cloud. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md` (New session page)

</details>
<details><summary><b>WEB-N2</b> 🆕 Multi-root workspaces (sessions in other project roots served by one server)</summary>

- **sugar-crush** ✅ — Token-authenticated UNIX socket bound after spawn; secrets on stdin not argv; host death announced as `server.overflow {sessionId, resubscribe}` (which the web ignores — ISSUES). — _Evidence:_ `sugar-crush/src/Server/Workspace/Gateway.php`; `sugar-crush/src/Server/Workspace/WorkspaceHostProcess.php`
- **sugar-crush-web** 🟡 — The web ignores `server.overflow {sessionId, action: resubscribe}`. — _Evidence:_ `src-web/stores/sessions.ts:64-76`; `sugar-crush/src/Server/Workspace/Gateway.php:579-601`
- **Claude Code 2.1.301** ✅ — `claude agents --cwd` filters by project; Remote Control servers are per directory (up to 32 sessions, worktree spawn mode). — _Evidence:_ cc:agent-view#monitor-sessions-with-agent-view; cc:remote-control
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/server (Project, /project); OC/project/instance-store.ts
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ V2D/cli/index (Background service); specs/v2/session.md
- **OpenClaw 2026.9.8** ✅ — Execution folder's AGENTS.md and skills are added per session; multiple agents each own a workspace. — _Evidence:_ `oc/docs/web/control-ui/sessions-and-sidebar.md`; `oc/docs/gateway/config-agents/workspace-and-bootstrap.md` (cwd)

</details>
<details><summary><b>WEB-N3</b> 🆕 Lossless reconnect (resume the event stream from a cursor without gaps or duplicates)</summary>

- **sugar-crush** 🟡 — Server: durable events are logged with a gap-free per-session `seq` before broadcast (20,000 kept, in the session DB, so seqs continue across restarts). `server.hello {resume: {sessionId: cursor}}`, or subscribing again, replays exactly what was missed. The subscription is registered before the catch-up is read, so there are no gaps or duplicates. A cursor older than the log gets a reset snapshot. Idempotency keys make a resent request safe. The SC terminal client is weaker: RemoteSessionHost keeps `lastSeq` but nothing reads it. When the socket closes, pending calls are rejected and the running turn fails with "the connection to the server closed", and the user has to re-run `sugarcrush attach`. — _Evidence:_ `docs/SERVER.md:375`, `:607-628`; `src/Host/RemoteSessionHost.php:99`, `:418-421`, `:583-606`; `src/Backend/RemoteBackend.php:433`
- **sugar-crush-web** ✅ — Watchdog closes a socket silent for 2× tick (30 s). Verified by e2e "a dropped socket reconnects and resumes". — _Evidence:_ `src-web/protocol/client.ts:149-174,321-339`; `e2e/reconnect.spec.ts:5`
- **Claude Code 2.1.301** 🟡 — After compaction or `/resume` a connected device doesn't receive earlier history; `/remote-control` reconnects manually. — _Evidence:_ cc:remote-control#what-connected-devices-see; cc:remote-control#connection-and-security
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/server (Events)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ specs/v2/session.md (Durable Events)
- **OpenClaw 2026.9.8** ✅ — Live events are reconciled with history rather than replayed; uncertain sends show "Waiting for reconnect"; TUI reloads history and logs event gaps. — _Evidence:_ `oc/docs/web/webchat.md`; `oc/docs/web/control-ui/offline-and-reconnect.md`; `oc/docs/web/tui.md`

</details>
<details><summary><b>WEB-N4</b> 🆕 Background / detached server mode (serve --detach, status / stop / logs)</summary>

- **sugar-crush** ✅ — `serve url` mints a fresh code over the token-authenticated control socket; `--parent-pid` watchdog; drain on SIGTERM with `server.shutdown {graceSeconds}`. — _Evidence:_ `sugar-crush/docs/SERVER.md:123-212`
- **sugar-crush-web** 🟡 — `server.shutdown` and `server.health` are unused by the web, a server drain shows only "reconnecting…", and logs are CLI-only (`serve logs`). — _Evidence:_ `docs/SERVER.md:123-212`; `sugar-crush-web/src-web/components/ConnectionBanner.vue`
- **Claude Code 2.1.301** ✅ — Survives sleep and restarts crashed sessions; `claude remote-control` server mode runs in the foreground (resumable for ~4 h after stop). — _Evidence:_ cc:cli-reference (`claude daemon`); cc:agent-view#how-background-sessions-are-hosted; help
- **OpenCode 1.18.35** ❌ — — — _Evidence:_ CLI: `opencode serve --help`
- **OpenCode 2.0.31** ✅ — Server logs go to the log dir; `--print-logs` for server logs needs `--standalone`. — _Evidence:_ V2CLI: `opencode service --help`; V2D/cli/web
- **OpenClaw 2026.9.8** ✅ — launchd/systemd/schtasks supervision, restart recovery, `openclaw gateway status`, `openclaw health`. — _Evidence:_ `oc/docs/cli/daemon.md`; `oc/docs/cli/gateway/service.md`; CLI: `openclaw --help`

</details>
<details><summary><b>WEB-N5</b> 🆕 Terminal client that attaches to a running server session (remote TUI)</summary>

- **sugar-crush** 🟡 — Documented gaps: slash commands run locally on the attached window's copy; a turn started by another client shows only on re-attach. — _Evidence:_ `sugar-crush/docs/SERVER.md:857-910`
- **sugar-crush-web** ➖ — Approvals answered in either client close the card everywhere (first answer wins). — _Evidence:_ `docs/SERVER.md:857-910`
- **Claude Code 2.1.301** ✅ — Local machine only (per-session sockets); remote attach is via web/mobile Remote Control, not a TUI. — _Evidence:_ help (`attach`); cc:sessions; cc:agent-view#attach-to-a-session
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CLI: `opencode attach --help`, `opencode run --help`
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ V2CLI: `opencode --help`; V2D/cli/web (Standalone)
- **OpenClaw 2026.9.8** ✅ — TUI resumes the last session by default; `--session`, Ctrl+P picker; handoff payloads from the Control UI. — _Evidence:_ `oc/docs/web/tui.md`; `oc/docs/cli/resume.md`; `oc/docs/cli/attach.md`

</details>

### AUT — Automation / headless

<details><summary><b>AUT-01</b> Non-interactive mode (-p / run)</summary>

- **sugar-crush** 🟡 — `NonInteractive::run()` builds the engine backend, closes AskUser/PlanExit, prints the final message. Default permission mode for `-p` is bypass-permissions (owner ruling, documented); maxToolSteps default 1000; `SUGARCRUSH_MAX_COST` is neither validated nor enforced on this path (ISSUES P1). — _Evidence:_ `bin/sugarcrush:439-441`; `src/Cli/NonInteractive.php:196-310`; `src/Backend/EngineBackend.php:395-405`; repro `SUGARCRUSH_MAX_COST=5USD … -p hi` → exit 0
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — `--bare` for reproducible CI; `--permission-prompts none`, `--permission-prompt-tool`. — _Evidence:_ help (`-p`); cc:headless
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CLI: `opencode run --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode run [message]` with --agent/--model/--variant/--file/--command/--continue/--attach. 2.0.31 (✅): No --command, --share or --variant flags (variant via model ref). — _Evidence:_ V2CLI: `opencode run --help`
- **OpenClaw 2026.9.8** ✅ — `agent exec`: `--cwd`, `--model/--fallback`, `--code-mode`, `--config`, `--auth-env-only`, `--isolated`, timeout 600 s. — _Evidence:_ CLI: `openclaw agent exec --help`; `oc/docs/cli/agent.md`

</details>
<details><summary><b>AUT-02</b> Output formats (text / json / stream-json)</summary>

- **sugar-crush** 🟡 — Error types usage/provider_configuration/installation/backend/encoding/mcp-config/not-found. Subcommands share the envelope. `stream-json` → exit 2. — _Evidence:_ `src/Cli/ParsedArgs.php:33`; `src/Cli/NonInteractive.php:1175-1188,723-800`; README `1662-1690`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — `--forward-subagent-text`; `prompt_suggestion` messages. — _Evidence:_ help; cc:headless#stream-responses
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ CLI: `opencode run --help`; DOCS/sdk
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ CLI: `opencode run --help`; DOCS/sdk
- **OpenClaw 2026.9.8** 🟡 — Streaming consumers use the Gateway WS protocol or ACP. — _Evidence:_ `oc/docs/cli/agent.md`

</details>
<details><summary><b>AUT-03</b> Stdin piping</summary>

- **sugar-crush** 🟡 — `readStdinIfPiped()` does a blocking `stream_get_contents()` whenever stdin is not a TTY; `echo x ∣ sugarcrush -p` → "no prompt given" exit 2; `sleep 4 ∣ sugarcrush -p hi` takes 4 s. — _Evidence:_ `src/Cli/NonInteractive.php:171,840-856,1054-1077`; repro timings
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:headless#pipe-data-through-claude
- **OpenCode 1.18.35** ✅ — `Bun.stdin.text()` when stdin is not a TTY. — _Evidence:_ OC/cli/cmd/run.ts:416-418
- **OpenCode 2.0.31** ✅ — `Bun.stdin.text()` when stdin is not a TTY. — _Evidence:_ OC/cli/cmd/run.ts:416-418
- **OpenClaw 2026.9.8** ✅ — `--message-file -` reads the prompt from stdin — _Evidence:_ `oc/docs/cli/agent.md`

</details>
<details><summary><b>AUT-04</b> Max-turns / budget limits for headless runs</summary>

- **sugar-crush** 🟡 — `--max-turns` → unrecognized option (exit 2). `SUGARCRUSH_MAX_COST`/`maxCostUsd` reach only the TUI/host path via `TurnRunner::withSpendCap()`. — _Evidence:_ `src/Host/TurnRunner.php:286`; `src/Cli/NonInteractive.php:261`; repro `--max-turns 3` → exit 2
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:cli-reference; help (`--max-budget-usd`)
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/agents (Max steps)
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ DOCS/agents (Max steps)
- **OpenClaw 2026.9.8** 🟡 — Deadlines (`--timeout`, `runTimeoutSeconds`) and goal token budgets; no max-turns or spend cap — _Evidence:_ `oc/docs/cli/agent.md`; `oc/docs/tools/goal.md`

</details>
<details><summary><b>AUT-05</b> GitHub Action / CI recipe</summary>

- **sugar-crush** 🟡 — README "Running in CI and containers" + systemd unit for serve. — _Evidence:_ `README.md:1823-1842`; `docs/examples/sugarcrush.service`
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:github-actions; cc:gitlab-ci-cd
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/github; DOCS/gitlab
- **OpenCode 2.0.31** 🟡 — 1.18.35 (✅): Official GitHub Action (comments, PRs, issues, schedule, dispatch); GitLab CI component. 2.0.31 (🟡): GitHub Action exists only for the v1 CLI; no v2 CI recipe beyond `opencode run` — _Evidence:_ github/action.yml; V2D/cli/commands (run → CI)
- **OpenClaw 2026.9.8** ❌ — `agent exec` is designed to be scriptable (exit codes, JSON). — _Evidence:_ `oc/docs/cli/agent.md`

</details>
<details><summary><b>AUT-06</b> SDK (TS / Python / PHP)</summary>

- **sugar-crush** 🟡 — `docs/protocol/sugarcrush.v1.schema.json`; README shows ProviderFactory/ProviderInterface usage; `examples/*.php`. No TS/Python client (python_port is an incubating port of the app). — _Evidence:_ `README.md:1412-1419,2202-2220`; `docs/protocol/sugarcrush.v1.schema.json`; `src/Cli/Acp.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/protocol/`
- **Claude Code 2.1.301** ✅ — Same tools, hooks, subagents, sessions, permissions. — _Evidence:_ cc:agent-sdk/overview
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/sdk
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): JS/TS SDK (+ official Go and Python SDKs). 2.0.31 (✅): @opencode/sdk (in-process host, Effect + Promise, workerd) and @opencode/client — _Evidence:_ V2D/build/sdk/index
- **OpenClaw 2026.9.8** 🟡 — Plugin SDK (TS) + Gateway WS protocol + OpenAI-compatible HTTP; no standalone embeddable agent SDK — _Evidence:_ `oc/docs/plugins/sdk-overview.md`; `oc/docs/gateway/protocol.md`

</details>
<details><summary><b>AUT-07</b> Scheduled tasks / cron</summary>

- **sugar-crush** ❌ — No scheduler code exists (`crontab` appears only in a shell deny list). — _Evidence:_ `src/Permissions/SessionPermissionMemo.php:148`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `/schedule`; routines need claude.ai plans; session crons expire after 7 days. — _Evidence:_ cc:routines; cc:desktop-scheduled-tasks; cc:scheduled-tasks
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/github (Schedule); DOCS/ecosystem
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Scheduled runs via GitHub Actions `schedule`; local cron via 🔌 opencode-scheduler. 2.0.31 (🟡): Only via the V1-based GitHub Action schedule or external cron; scheduler plugin is V1-API — _Evidence:_ github/action.yml; npm view opencode-scheduler
- **OpenClaw 2026.9.8** ✅ — Heartbeat is a system-owned automation; Control UI Automations panel; standing grants for automation approvals. — _Evidence:_ `oc/docs/automation/cron-jobs.md`; `oc/docs/cli/cron.md`

</details>
<details><summary><b>AUT-08</b> Loop / recurring prompts (/loop)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `builtin-commands/`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:scheduled-tasks
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ awesome-opencode (hiai-opencode)
- **OpenCode 2.0.31** ❓ — 1.18.35 (🔌): No /loop; 🔌 oh-my-openagent / hiai-opencode ralph-loop style continuation. 2.0.31 (❓): No native loop; ralph-loop style plugins are V1-API — v2 compatibility not verified — _Evidence:_ npm metadata (see PLG-10)
- **OpenClaw 2026.9.8** ✅ — Self-paced loops back off toward 1 h while quiet. — _Evidence:_ `oc/docs/tools/slash-commands.md` (/loop)

</details>
<details><summary><b>AUT-09</b> Goal / long-running autonomous mode</summary>

- **sugar-crush** ✅ — Needs a title backend; respects the spend cap. `/goal clear` stops it, and the state is stored in history. — _Evidence:_ `src/Chat.php:3726-3800,2513,3877`; `src/Goal/GoalMode.php:25,28`; `src/Goal/GoalJudge.php:118`
- **sugar-crush-web** ❌ — — — _Evidence:_ live `command.list`
- **Claude Code 2.1.301** ✅ — Goal is a session-scoped prompt-based Stop hook; 🔌 `ralph-loop` for iterate-until-done loops. — _Evidence:_ cc:goal; cc:workflows#let-claude-decide-with-ultracode
- **OpenCode 1.18.35** 🔌 — It relies on experimental hooks. Tested to 1.18.29 and pinned to <2. — _Evidence:_ opencode-goal-plugin README (global npm 0.10.0)
- **OpenCode 2.0.31** ❌ — 1.18.35 (🔌): 🔌 opencode-goal-plugin (installed here): /goal with auto-continue, limits, evidence-gated completion. 2.0.31 (❌): opencode-goal-plugin explicitly does not support OpenCode 2; no native goal mode — _Evidence:_ opencode-goal-plugin README (OpenCode 2 not supported)
- **OpenClaw 2026.9.8** ✅ — `/goal` durable objective (goal tools, pause/resume/block/complete, token budget, TUI footer) + Promised Work rules — _Evidence:_ `oc/docs/tools/goal.md`; `oc/docs/concepts/system-prompt.md`

</details>
<details><summary><b>AUT-10</b> Workflows / multi-step orchestration scripts</summary>

- **sugar-crush** ✅ — Stage types: sequential, tasks:, parallel; pipeline and verification are PHP-only. Each stage agent runs the real engine loop in a forked child under hooks and the gate, with retries and timeouts. A model-authored plan cannot be resumed. The project tier is YAML-only. — _Evidence:_ `src/Workflows/WorkflowEngine.php:690`; `src/Workflows/WorkflowRegistry.php:300-385,1074`; `src/Tools/BuiltIn/WorkflowTool.php:59`; `docs/WORKFLOWS.md:406-454`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/components/panels/WorkflowPanel.vue`; `src-web/stores/agents/workflows.ts`
- **Claude Code 2.1.301** ✅ — Bundled `/deep-research`; `/workflows` progress view; Pro must enable. — _Evidence:_ cc:workflows
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ DOCS/ecosystem; awesome-opencode
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🔌): Subtask commands natively; orchestration via 🔌 subtask2, opencode-conductor, open-dynamic-workflows. 2.0.31 (🟡): Native background `subagent` commands; V1 orchestration plugins (subtask2, conductor) don't run — _Evidence:_ V2D/commands (Background); npm view subtask2
- **OpenClaw 2026.9.8** 🟡 — TaskFlow orchestration API was removed; standing orders for persistent programs. — _Evidence:_ `oc/docs/tools/swarm.md`; `oc/docs/tools/lobster.md`; `oc/docs/automation/index.md`

</details>
<details><summary><b>AUT-11</b> Webhooks / external triggers</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `docs/SERVER.md:260-267`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:routines; cc:channels
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/server
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): External triggers via HTTP API (prompt_async) and GitHub events; no generic webhook receiver. 2.0.31 (🟡): `opencode api` + HTTP API; still no webhook receiver — _Evidence:_ V2CLI: `opencode api --help`
- **OpenClaw 2026.9.8** ✅ — Authenticated Gateway HTTP hooks, Gmail Pub/Sub, IMAP trigger plugin (bundled), channel messages — _Evidence:_ `oc/docs/automation/cron-jobs/webhooks.md`; `oc/docs/automation/imap.md`; `oc/docs/automation/cron-jobs/gmail.md`

</details>
<details><summary><b>AUT-12</b> Exit codes / scripting ergonomics</summary>

- **sugar-crush** ✅ — Unknown flags, flag-as-prompt, stray operands, bad --root/--config, unusable explicitly-selected provider (never silently falls back to echo), unusable permission policy → 2. Shell completion for bash/zsh/fish. Warnings to stderr only (display_errors=stderr). — _Evidence:_ `bin/sugarcrush:13-208,291-362,585-591`; `src/Cli/NonInteractive.php:486-576`; `--help` exit-code section
- **sugar-crush-web** ➖ — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:headless; cc:cli-reference
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ OC/cli/cmd/run.ts:278-466,801-822
- **OpenCode 2.0.31** 🟡 — — — _Evidence:_ OC/cli/cmd/run.ts:278-466,801-822
- **OpenClaw 2026.9.8** ✅ — `agent exec` exits 0/1/2 (ok/error/timeout); `agent` preserves signal exit codes; JSON failure envelope — _Evidence:_ `oc/docs/cli/agent.md`

</details>

### CFG — Configuration

<details><summary><b>CFG-01</b> Settings-file hierarchy</summary>

- **sugar-crush** ✅ — Project files ignored until the root is listed in `trustedProjectSettings`; project tier limited to a whitelist (no provider, permission or instruction keys); `permissionMode/Rules` from user files only and a malformed one stops launch; `--config <file>` replaces config.json. — _Evidence:_ `README.md:994-1100`; `src/Config/LayeredSettings.php:819-943`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/settings/serverSettings.ts:60-70`
- **Claude Code 2.1.301** ✅ — Arrays merge; some keys only honored in user/managed scopes; `--setting-sources`. — _Evidence:_ cc:settings#settings-precedence; cc:settings-reference
- **OpenCode 1.18.35** ✅ — Configs are merged, not replaced. `instructions` arrays concatenate. `tui.json` is separate (OPENCODE_TUI_CONFIG). — _Evidence:_ DOCS/config (Precedence order); OC/config/config.ts:48-49
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): Remote .well-known → global → OPENCODE_CONFIG → project (walk to git root) → .opencode → inline → managed → MDM. 2.0.31 (✅): Same locations (V1 shapes normalized in memory) + global cli.json for the terminal client; managed > project — _Evidence:_ V2D/migrate-v1 (Configuration); V2CL v2.0.29
- **OpenClaw 2026.9.8** ✅ — Named profiles (`--profile`, `--dev`); session-level overrides; state DB for runtime preferences. — _Evidence:_ `oc/docs/gateway/configuration.md`

</details>
<details><summary><b>CFG-02</b> Settings UI (/config)</summary>

- **sugar-crush** ✅ — Writes go through `SettingsWriter` + `SettingValidator`; save preview names the file; toast, not transcript row. — _Evidence:_ `builtin-commands/0750-settings.php`; `docs/SETTINGS.md:1164-1270`
- **sugar-crush-web** ✅ — Targets: your config, a trusted project's local or committed shared file. e2e covers preview/save/read-back. — _Evidence:_ `src-web/components/settings/SettingsView.vue`; `e2e/settings.spec.ts:17`
- **Claude Code 2.1.301** ✅ — Settings that grant unattended execution can't be turned on via key=value. — _Evidence:_ cc:commands (/config); cc:settings
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/tui (Customization); DOCS/server
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): No /config screen; palette toggles persist (kv); desktop settings; PATCH /config API. 2.0.31 (✅): TUI /settings dialog and desktop Settings (projects, providers, pairing) — _Evidence:_ V2T slash list (settings); V2T/component/dialog-config.tsx
- **OpenClaw 2026.9.8** ✅ — `openclaw setup` chat assistant can apply approval-gated config writes. — _Evidence:_ `oc/docs/gateway/configuration.md` (Editing config); `oc/docs/web/tui.md` (OpenClaw setup)

</details>
<details><summary><b>CFG-03</b> JSON schema / validation</summary>

- **sugar-crush** 🟡 — Repro: `{"permisionMode":"plan","maxCostUsd":"lots",…}` → `-p` ran in bypass-permissions, no cap, and `doctor` printed "No problems detected". Only maxToolSteps/maxOutputTokens get "nonsense" notices. No JSON Schema file for editors (the shipped schema is the RPC protocol's). — _Evidence:_ `src/Config/LayeredSettings.php:1155-1185`; `src/Cli/Bootstrap.php:5615-5645`; `src/Config/Settings/SettingsSchema.php`
- **sugar-crush-web** ✅ — — — _Evidence:_ `src-web/stores/settings/fields.ts`; `e2e/settings.spec.ts:59`
- **Claude Code 2.1.301** ✅ — In `-p`, invalid settings files are silently ignored. — _Evidence:_ cc:settings; help (`-p` note)
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/config (Schema); OC/skill/index.ts:26-30
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): JSON Schemas opencode.ai/config.json + tui.json; invalid config hard-fails at startup. 2.0.31 (✅): Schema accepts both V1 and V2 shapes; warnings for ignored/conflicting fields — _Evidence:_ V2CL v2.0.30; V2D/migrate-v1
- **OpenClaw 2026.9.8** ✅ — Strict JSON Schema validation (unknown keys refuse start); `config schema/validate`; `config.schema.lookup` — _Evidence:_ `oc/docs/gateway/configuration.md` (Strict validation)

</details>
<details><summary><b>CFG-04</b> Environment variables</summary>

- **sugar-crush** ✅ — Precedence flag > env > config files documented per key. A few rows drift from code (CONNECT_TIMEOUT "connection only", SHARE_UPLOAD_URL "public default" in --help). — _Evidence:_ `docs/ENVIRONMENT.md`; `lang/en.php:300-520`; `tests/Config/EnvRosterDriftTest.php`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/settings/SettingsField.vue`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:env-vars
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CORE/flag/flag.ts; DOCS/cli (Environment variables)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ CORE/flag/flag.ts; DOCS/cli (Environment variables)
- **OpenClaw 2026.9.8** ✅ — OPENCLAW_STATE_DIR, OPENCLAW_CONFIG_PATH, OPENCLAW_WORKSPACE_DIR, OPENCLAW_PROFILE, OPENCLAW_THEME, provider keys... — _Evidence:_ `oc/docs/gateway/configuration/environment-variables.md`; `oc/docs/help/environment.md`

</details>
<details><summary><b>CFG-05</b> Hot reload of config</summary>

- **sugar-crush** 🟡 — `ToolLimits::current()` and EngineBackend re-read merged config per turn. `docs/SETTINGS.md` prose says "only three keys actually change behaviour mid-session", contradicting its own generated apply table (≈50 next-turn keys). — _Evidence:_ `docs/SETTINGS.md:1272-1345`; `src/Tools/ToolLimits.php:164-186`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/components/settings/SettingsField.vue`
- **Claude Code 2.1.301** ✅ — `ConfigChange` hook can block changes. — _Evidence:_ cc:settings; cc:hooks#configchange
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ OC/server/routes/instance/httpapi/handlers/config.ts:20; OC/server/routes/instance/httpapi/lifecycle.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Config changed through the API reloads the instance; on-disk edits need a restart. 2.0.31 (✅): Watched config/commands/skills/instructions reload automatically; `opencode reload` — _Evidence:_ V2D/plugins (Reload); V2D/commands (Loading); V2CLI: `opencode reload --help`
- **OpenClaw 2026.9.8** ✅ — `/debug` runtime-only overrides. — _Evidence:_ `oc/docs/gateway/configuration/hot-reload.md`

</details>
<details><summary><b>CFG-06</b> Doctor / diagnostics command</summary>

- **sugar-crush** 🟡 — Gaps: provider probe only checks the name is known — `SUGARCRUSH_PROVIDER=anthropic` with no key reports OK while `-p` exits 2; config keys not schema-checked; no network/auth probe. — _Evidence:_ `src/Cli/Subcommands.php:298-470`; repro doctor vs `-p`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `/status` connectivity and policy lines. — _Evidence:_ help (`doctor`); cc:skills#check-your-setup-with-/doctor
- **OpenCode 1.18.35** 🟡 — `debug` commands that touch the DB failed here with `PRAGMA wal_checkpoint` (DB busy or sandbox); `debug paths` worked. — _Evidence:_ CLI: `opencode debug --help`
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): `opencode debug config/paths/info/lsp/rg/skill/snapshot/startup/agent`, `mcp debug`; no single doctor. 2.0.31 (🟡): `opencode debug agents/config/paths [selector]`; service status; no doctor — _Evidence:_ V2CLI: `opencode debug --help`
- **OpenClaw 2026.9.8** ✅ — `openclaw triage` collects sanitized diagnostics and opens a local coding agent for repair. — _Evidence:_ `oc/docs/cli/doctor.md`; CLI: `openclaw --help`

</details>
<details><summary><b>CFG-07</b> Per-project config</summary>

- **sugar-crush** ✅ — `trustedProjectSettings`, `trustedProjectHooks`, `trustedProjectMcp`, `trustedProjectCommands` read from user config.json only, frozen for the process. — _Evidence:_ `README.md:1088-1110`; `docs/SETTINGS.md:124-150`
- **sugar-crush-web** ✅ — — — _Evidence:_ `sugar-crush/docs/SERVER.md:670-686`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:settings
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/config
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/config
- **OpenClaw 2026.9.8** 🟡 — No per-project config file; per-agent entries (workspace/cwd), per-session settings, `agent exec --config` — _Evidence:_ `oc/docs/gateway/config-agents/workspace-and-bootstrap.md`; `oc/docs/cli/agent.md`

</details>
<details><summary><b>CFG-08</b> Config migration / import from other tools</summary>

- **sugar-crush** 🟡 — Foreign agent/skill dirs are read by other subsystems (other agents' rows). — _Evidence:_ `--help` mcp import text; `src/Host/Commands/MemoryCommand.php:211`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `--dry-run`, `--yes=<digest>`. — _Evidence:_ help (`import`); cc:commands
- **OpenCode 1.18.35** 🟡 — 🔌 opencode-ccs-sync imports CCS providers. — _Evidence:_ DOCS/config (TUI); OC/config/tui-migrate.ts; CORE/v1/config/migrate.ts
- **OpenCode 2.0.31** ✅ — 1.18.35 (🟡): Auto-migrates legacy keys (tools→permission, maxSteps→steps, TUI keys); no import of other tools' settings. 2.0.31 (✅): Reads V1 config, migrates tui.json → cli.json, imports V1 sessions; `ask OpenCode to migrate` path — _Evidence:_ V2D/migrate-v1
- **OpenClaw 2026.9.8** ✅ — `openclaw migrate claude/codex/hermes` with preview, verified backup and conflict handling — _Evidence:_ `oc/docs/cli/migrate.md`; `oc/docs/install/migrating.md`

</details>

### OBS — Observability

<details><summary><b>OBS-01</b> Debug logs</summary>

- **sugar-crush** ✅ — Before Program starts, `error_log` is redirected (fd 2 is the screen); fatal → one line naming the log. `-p`/subcommands keep stderr. — _Evidence:_ `bin/sugarcrush:484-521`; `src/Diagnostics/TuiErrorLog.php`; `README.md:136`
- **sugar-crush-web** ❌ — — — _Evidence:_ `sugar-crush/src/Protocol/Dispatcher.php:365-370`
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ help; cc:debug-your-config
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ DOCS/troubleshooting (Logs)
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ DOCS/troubleshooting (Logs)
- **OpenClaw 2026.9.8** ✅ — Gateway file logs (`openclaw logs --follow`), log levels, diagnostics flags, verbose/trace — _Evidence:_ `oc/docs/logging.md`; `oc/docs/diagnostics/flags.md`

</details>
<details><summary><b>OBS-02</b> OpenTelemetry / metrics export</summary>

- **sugar-crush** ❌ — An internal typed event bus exists (`src/Events/*`: UsageUpdated, ToolStarted/Finished, StepStarted…) that could feed an exporter. — _Evidence:_ grep otel/otlp/prometheus → none
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — `tool_decision` events, raw API bodies opt-in, GenAI semantic conventions (v2.1.300). — _Evidence:_ cc:monitoring-usage; CL v2.1.300
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CORE/observability/otlp.ts:7-58; CORE/observability.ts; OC/session/llm.ts:208,345
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): OTLP traces + logs via OTEL_EXPORTER_OTLP_ENDPOINT; experimental.openTelemetry adds AI SDK spans. 2.0.31 (✅): OTLP export via util/observability (OTEL_* env); V1 experimental.openTelemetry ignored — _Evidence:_ packages/util/src/observability/otlp.ts; V2D/migrate-v1
- **OpenClaw 2026.9.8** 🔌 — Official installable plugins; spans for model calls/tools, privacy controls. — _Evidence:_ `oc/docs/gateway/opentelemetry.md`; `oc/docs/plugins/reference/diagnostics-otel.md`

</details>
<details><summary><b>OBS-03</b> Usage / cost reports (/cost, /usage, stats)</summary>

- **sugar-crush** 🟡 — Honest distinctions: `$?` when nothing priced vs `$0.0000`; unpriced models flagged as lower bound. No per-day/per-model stats command. — _Evidence:_ `builtin-commands/2200-budget.php`; `src/Host/SpendLedger.php:161-230`; `README.md:794-801`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — 🔌 `session-report`, `receipts`. — _Evidence:_ cc:costs; cc:analytics
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ CLI: `opencode stats --help`
- **OpenCode 2.0.31** ✅ — 1.18.35 (✅): `opencode stats` token/cost by days, tools, models, project. 2.0.31 (✅): `opencode stats` with --days/--year/--all, --models, --tools (reliability), --cost, --json — _Evidence:_ V2CLI: `opencode stats --help`
- **OpenClaw 2026.9.8** ✅ — `/usage cost`, `/status`, `openclaw status --usage`, Control UI Usage (session estimates + provider billing) — _Evidence:_ `oc/docs/concepts/usage-tracking.md`

</details>
<details><summary><b>OBS-04</b> Status command (/status)</summary>

- **sugar-crush** 🟡 — 55 built-in commands; none named status/cost/usage. — _Evidence:_ `ls builtin-commands` (55 specs); `docs/COMMANDS.md:305-362`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `src-web/App.vue:83`; `src-web/views/DashboardView.vue`
- **Claude Code 2.1.301** ✅ — Runs mid-turn. — _Evidence:_ cc:commands (/status)
- **OpenCode 1.18.35** ✅ — — — _Evidence:_ TUI/component/dialog-status.tsx
- **OpenCode 2.0.31** ✅ — — — _Evidence:_ TUI/component/dialog-status.tsx
- **OpenClaw 2026.9.8** ✅ — `/status` (runtime, uptimes, plugin health, quota, tokens/cache, compactions), `openclaw status`, TUI `/gateway-status` — _Evidence:_ `oc/docs/tools/slash-commands.md`; `oc/docs/cli/status.md`

</details>
<details><summary><b>OBS-05</b> Raw transcript files on disk</summary>

- **sugar-crush** 🟡 — No plain JSONL transcript files; durable per-session event log in `session_events` (capped). — _Evidence:_ `src/Session/EnhancedSessionStore.php:594-640,2065`; `src/Agents/Live/SubAgentTranscriptLog.php`
- **sugar-crush-web** 🟡 — —
- **Claude Code 2.1.301** ✅ — — — _Evidence:_ cc:sessions#where-transcripts-are-stored
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ CLI: `opencode db --help`
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Transcripts live in SQLite (not per-session files); `opencode db` shell, `opencode export` JSON. 2.0.31 (🟡): SQLite durable events + `session export`; `debug paths db` for sqlite3 — _Evidence:_ V2D/cli/index (Paths)
- **OpenClaw 2026.9.8** 🟡 — Transcripts live in per-agent SQLite, not plain files; `openclaw transcripts` materializes them; trajectory export — _Evidence:_ `oc/docs/cli/transcripts.md`; `oc/docs/concepts/session.md`

</details>
<details><summary><b>OBS-06</b> Insights / analytics over sessions</summary>

- **sugar-crush** ❌ — `/agents` dashboard shows per-agent tokens/cost for the live launch only. — _Evidence:_ `src/Agents/AgentManager.php:830-875`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ✅ — Stored in `~/.claude/usage-data/`. — _Evidence:_ cc:costs#analyze-your-usage-patterns; cc:commands
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/ecosystem
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Only `opencode stats`; 🔌 wakatime, helicone-session, sentry-monitor. 2.0.31 (🟡): Only `opencode stats` (now with tool reliability); wakatime etc. are V1-API plugins — _Evidence:_ V2CLI: `opencode stats --help`; npm view opencode-wakatime dependencies
- **OpenClaw 2026.9.8** ✅ — Team Reports plugin for GitHub/Discord activity reports. — _Evidence:_ `oc/docs/web/control-ui/settings.md` (Activity tab); `oc/docs/plugins/team-reports.md`

</details>

### PA — Personal-assistant / always-on features

<details><summary><b>PA-01</b> Always-on daemon / gateway</summary>

- **sugar-crush** 🟡 — serve status/stop/logs/url/token management; `--parent-pid` ties a private server to an editor. — _Evidence:_ `--help` serve section; `docs/SERVER.md:201-210`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/docs/SERVER.md:123-212`
- **Claude Code 2.1.301** 🟡 — Channels only deliver while a session is open. — _Evidence:_ cc:agent-view#the-supervisor-process; cc:channels
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/server
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): `opencode serve` can run headless/always-on (mDNS), but there is no assistant gateway or channels. 2.0.31 (🟡): Always-on per-user background service + remote pairing; still no assistant channels — _Evidence:_ V2D/cli/index (Background service)
- **OpenClaw 2026.9.8** ✅ — `openclaw onboard --install-daemon`, `openclaw daemon`, restart recovery. — _Evidence:_ `oc/docs/gateway/index.md`; `oc/docs/cli/daemon.md`

</details>
<details><summary><b>PA-02</b> Heartbeat / proactive check-ins</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `builtin-commands` (goal, grind)
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:scheduled-tasks; cc:routines
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — HEARTBEAT.md is retired (doctor imports it into monitor scratch); alerts go to the owner's DM; `HEARTBEAT_OK` suppressed; event wakes on exec completion. — _Evidence:_ `oc/docs/gateway/heartbeat.md`; `oc/docs/reference/templates/HEARTBEAT.md`

</details>
<details><summary><b>PA-03</b> Multi-channel inbox</summary>

- **sugar-crush** ❌ — Only TUI, web UI, ACP.
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🔌 — Slack is a separate cloud integration. — _Evidence:_ cc:channels; local: catalog
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — `session.dmScope`, `identityLinks` merge one person across channels. — _Evidence:_ `oc/docs/concepts/session.md`; `oc/docs/concepts/main-session.md`

</details>
<details><summary><b>PA-04</b> Persona / identity files (SOUL.md, IDENTITY.md, USER.md)</summary>

- **sugar-crush** ❌ — — — _Evidence:_ `src/Runtime.php:4882-4890`
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:output-styles; cc:sub-agents
- **OpenCode 1.18.35** 🔌 — — — _Evidence:_ LOCAL: ~/.config/opencode/memory/persona.md, human.md
- **OpenCode 2.0.31** 🔌 — — — _Evidence:_ LOCAL: ~/.config/opencode/memory/persona.md, human.md
- **OpenClaw 2026.9.8** ✅ — Sub-agents never get them; `openclaw agents set-identity`. — _Evidence:_ `oc/docs/concepts/agent-workspace.md`; `oc/docs/concepts/soul.md`

</details>
<details><summary><b>PA-05</b> Device nodes (camera, screen, location)</summary>

- **sugar-crush** ❌ — Clipboard image paste only.
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🟡 — — — _Evidence:_ cc:computer-use; cc:chrome
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — macOS/iOS/watchOS/Android/headless nodes; node-hosted MCP servers and skills. — _Evidence:_ `oc/docs/nodes/index.md`; `oc/docs/nodes/device-commands.md`

</details>
<details><summary><b>PA-06</b> Voice wake / talk mode</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** ❌ — — — _Evidence:_ cc:voice-dictation
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** 🟡 — 1.18.35 (❌): No voice wake/talk mode. 2.0.31 (🟡): iOS app voice calls delegate requests to OpenCode; no wake word — _Evidence:_ packages/app/src/session/timeline/voice-delegation.ts
- **OpenClaw 2026.9.8** ✅ — Global voice wake words (macOS 26+, iOS, Android), push-to-talk, realtime Talk sessions — _Evidence:_ `oc/docs/nodes/voicewake.md`; `oc/docs/nodes/talk.md`

</details>
<details><summary><b>PA-07</b> Email / calendar integrations</summary>

- **sugar-crush** ❌ — —
- **sugar-crush-web** ❌ — —
- **Claude Code 2.1.301** 🔌 — — — _Evidence:_ cc:mcp#use-mcp-servers-from-claude-ai
- **OpenCode 1.18.35** ❌ — —
- **OpenCode 2.0.31** ❌ — —
- **OpenClaw 2026.9.8** ✅ — Apple Reminders/Notes, Things, Trello, Notion skills; Google Meet/Teams/Zoom plugins. — _Evidence:_ `oc/docs/automation/imap.md`; `oc/skills/gog/SKILL.md`; `oc/skills/himalaya/`

</details>
<details><summary><b>PA-08</b> Multi-user access control (DM pairing, allowlists)</summary>

- **sugar-crush** 🟡 — `--allowed-ips`, `--allowed-host`, `--allowed-origin`, rate-limited sign-in, root refused. — _Evidence:_ `--help` serve section; `src/Server/Auth/RateLimiter.php`; `src/Server/Http/AuthMiddleware.php:55-62`
- **sugar-crush-web** 🟡 — — — _Evidence:_ `sugar-crush/src/Protocol/Dispatcher.php` (Scope::owner); `sugar-crush/docs/SERVER.md:1199-1236`
- **Claude Code 2.1.301** 🔌 — — — _Evidence:_ local: catalog (telegram/discord/imessage descriptions); cc:claude-tag
- **OpenCode 1.18.35** 🟡 — — — _Evidence:_ DOCS/server (Authentication)
- **OpenCode 2.0.31** 🟡 — 1.18.35 (🟡): Single shared basic-auth credential for the server; no per-user ACLs or pairing. 2.0.31 (🟡): Server password + one-time pairing links; still single-user — _Evidence:_ V2D/cli/web
- **OpenClaw 2026.9.8** ✅ — `openclaw pairing approve <channel> <code>`; `openclaw security audit` flags open DMs with tools. — _Evidence:_ `oc/docs/channels/pairing.md`; `oc/docs/channels/access-groups.md`; `oc/docs/concepts/multi-user.md`

</details>

---

## Appendix B — Method and sources

**Process.**
1. **Shared taxonomy.** Nine research agents filled the same 356-row taxonomy across 25 categories. Six audited sugar-crush and sugar-crush-web: context/memory, extensibility, engine/sessions/CLI, tools/safety, TUI, and web/server. Three researched Claude Code, OpenCode and OpenClaw.
2. **Extra rows.** Features found outside the taxonomy were merged into 42 🆕 rows; every product was then rated on each of them. A follow-up pass compared OpenCode 1.18.35 with 2.0.31.
3. **Verification.** Every issue an audit reported was re-checked by a separate adversarial verifier (6 passes). Verifiers confirmed, corrected, downgraded or rejected each claim; see `sugar_issues.md`.
4. **Merge.** Rows were merged mechanically by row ID, so every cell in §3 is the rating of the agent that studied that product. Narrative sections §1, §4 and §6 were written from the agents' deep-dive reports.

**sugar-crush and sugar-crush-web.**
- Source under `sugar-crush/src/`, `sugar-crush/docs/`, `sugar-crush/README.md`, `sugar-crush-web/src-web/`, `sugar-crush/src/Server/`, `sugar-crush/src/Protocol/`. Reachability was traced from `bin/sugarcrush`.
- Live runs:
  - the TUI in tmux (isolated HOME, scratch project, dev SGLang endpoint);
  - headless `-p`;
  - throwaway `sugarcrush serve` instances probed with curl, WebSocket and headless Chromium;
  - `npm run typecheck`, `lint` and `test` (211 tests, all passing);
  - a byte-for-byte rebuild of `dist/`.
- The repo was never modified.

**Claude Code 2.1.301.**
- Official docs at <https://code.claude.com/docs/en/>: 228 pages fetched and searched.
- Changelog: <https://github.com/anthropics/claude-code/blob/main/CHANGELOG.md>.
- `claude --help` and every subcommand's `--help`.
- A read-only look at `~/.claude` (plugin marketplace catalogs list 315 entries).
- Direct observation of what a Claude Code session/subagent is handed (system reminders, deferred tools, memory blocks).

**OpenCode 1.18.35 and 2.0.31.**
- Source at both tags of <https://github.com/anomalyco/opencode> (`packages/opencode/src/`, v2 core), the docs shipped with each tag, and <https://opencode.ai/docs/>.
- `opencode --help` and subcommand help; `npm view opencode-ai` and `npm view @opencode/cli`.
- Read-only local configs: `~/.config/opencode/` and the repo's `.opencode/`.
- READMEs of installed ecosystem plugins (opencode-goal-plugin, opencode-pty, ocx, DCP, opencode-agent-memory, …).

**OpenClaw 2026.9.8.**
- The installed package (`openclaw/docs/` ≈1,300 pages, `README.md`, `CHANGELOG.md`, `skills/`, `dist/`) and the `clawhub` CLI.
- `openclaw --help` and subcommand help.
- <https://docs.openclaw.ai> and GitHub release notes up to 2026.10.4 (later releases are reliability fixes only and change no row).

**Prior internal research.** Used as leads only and re-verified: `prompt_kit/findings/crush-report/*` (2026-10-01/02) and `docs/old/crush_report.md` / `crush_feat.md`.

**Raw material.** The per-agent reports, including every cell's full DETAIL and EVIDENCE text (also reproduced in Appendix A), live in this session's scratchpad under `research/`. They are not part of the repo.
