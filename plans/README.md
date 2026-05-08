# `charmbracelet/x` port wave — plan index

Each file in this directory is a self-contained execution plan for one
slice of the [charmbracelet/x](https://github.com/charmbracelet/x)
experimental tree. Drafted 2026-05-07 as a follow-up to the breakdown
in [`UPSTREAM_OPPORTUNITIES.md`](../UPSTREAM_OPPORTUNITIES.md).

> **Running a plan with another chat app?**
> See [`EXECUTE_PROMPT.md`](./EXECUTE_PROMPT.md) (to execute a plan)
> or one of the `AUDIT_*_PROMPT.md` files (to run an audit). Each is a
> copy-paste-ready prompt template that briefs the target agent on the
> repo, the conventions, and how to ship one slice at a time. Every
> prompt also enforces the **commit-author rule**:
> all commits in this repo MUST be authored as
> `Joe Huss <detain@interserver.net>` — pass `--author` on every
> `git commit` regardless of the agent's default identity.

## Audit prompt templates

| Prompt | Scope | Output |
|---|---|---|
| [`EXECUTE_PROMPT.md`](./EXECUTE_PROMPT.md) | Run any of the plans below, one slice at a time | code PRs |
| [`AUDIT_DOCS_PROMPT.md`](./AUDIT_DOCS_PROMPT.md) | PHPDoc + READMEs + cross-cutting docs | doc PRs per lib |
| [`AUDIT_TESTS_PROMPT.md`](./AUDIT_TESTS_PROMPT.md) | green PHPUnit suites + coverage + CI workflows | test PRs per lib + workflow PRs |
| [`AUDIT_WEBSITE_PROMPT.md`](./AUDIT_WEBSITE_PROMPT.md) | `docs/` site completeness + accessibility + SEO + perf | website PRs |
| [`AUDIT_QUALITY_PROMPT.md`](./AUDIT_QUALITY_PROMPT.md) | anti-patterns, dead code, security, perf | bug/cleanup PRs per lib + category |
| [`AUDIT_UPSTREAM_PROMPT.md`](./AUDIT_UPSTREAM_PROMPT.md) | scan upstream Charmbracelet repos for missing features | `UPSTREAM_OPPORTUNITIES.md` updates |

## Plans

| Plan | Upstream | Lib / location | Effort | Status |
|---|---|---|---|---|
| [x-windows](./x-windows.md) | `x/windows` | `candy-core` (Windows backend) | 4-5 d | drafted |
| [x-exp-open](./x-exp-open.md) | `x/exp/open` | `candy-core/Util/Open.php` | 2-3 h | drafted |
| [x-editor](./x-editor.md) | `x/editor` | `candy-core/Util/Editor.php` + `sugar-bits/TextArea` | half day | drafted |
| [x-ansi](./x-ansi.md) | `x/ansi` | `candy-core/Util/Ansi.php` (rolling) | rolling | drafted |
| [x-mosaic](./x-mosaic.md) | `x/mosaic` + `blacktop/go-termimg` + `ratatui/ratatui-image` | new lib `candy-mosaic` | 5 d (v1) + 5 d (v1.5) | drafted |
| [x-vt](./x-vt.md) | `x/vt` | new lib `candy-vt` | 1-2 w | drafted |
| [x-vcr](./x-vcr.md) | `x/vcr` | new lib `candy-vcr` | 3-5 d | drafted |
| [x-xpty](./x-xpty.md) | `x/xpty` | new lib `candy-pty` (Linux/macOS) | 4-6 d | drafted |
| [ratatui-widget-mining](./ratatui-widget-mining.md) | `ratatui/ratatui` (mined, not ported) | individual feature additions across candy-sprinkles / sugar-bits / candy-kit / candy-log | ~9 d total | drafted |

## Skipped (no plan)

| Upstream | Reason |
|---|---|
| `x/conpty` | Linux/macOS-only port; ConPTY is for hosting child PTYs, not running TUIs. Reconsider only if a Windows-host SSH/multiplexer use case lands. |
| `x/wcwidth` | Already absorbed in `candy-core/Util/Width.php`. |
| `x/input` | Already absorbed in `candy-core/InputReader.php`. |
| `x/term` | Already absorbed in `candy-core/Util/Tty.php` (POSIX side). Windows side covered by [x-windows](./x-windows.md). |
| `ratatui/ratatui` | Architectural mismatch (immediate-mode vs SugarCraft's Elm architecture). Individual widget + layout ideas mined into [ratatui-widget-mining](./ratatui-widget-mining.md) instead. |
| `blacktop/go-termimg` | Same domain as `x/mosaic` (already planned). Best ideas (dithering, font-size CSI, tmux passthrough, scaling modes, Kitty z-index) folded into `x-mosaic` v1.5. |
| `ratatui/ratatui-image` | Same domain as `x/mosaic`. Best ideas (Picker pattern, two-tier API, async resize) folded into `x-mosaic` v1 + v1.5. |

## Dependency graph

```
x-ansi ────────────────┐
                       │
x-windows (parallel)   │
                       │
x-exp-open ────────────┤
x-editor ──────────────┤
                       │
x-mosaic ──────────────┤   (consumes x-ansi sixel/kitty helpers)
                       │
x-vt   ─────┬─────►  x-vcr   (vcr asserts on vt grid)
            │
x-xpty ─────┴─────►  candy-wish upgrade (separate PR, not in this wave)
```

## Recommended sequencing

For a continuous ship-as-you-go cadence per `AGENTS.md`:

1. **Wave 1 — small wins (1 day total)**
   - PR: `x-exp-open` — `Util\Open` helper
   - PR: `x-editor` — `Util\Editor` + sugar-bits TextArea Ctrl-O binding
2. **Wave 2 — Windows console (4-5 days)**
   - Run as 6-7 small PRs per the slice list in `x-windows.md`
3. **Wave 3 — image rendering (5 days for v1; 5 more for v1.5)**
   - PR set: `x-mosaic` v1 lib + candy-flip refactor onto it
   - Pulls in `x-ansi` sixel/kitty/iTerm2 helpers as it needs them
   - Optional v1.5 follow-up wave absorbs go-termimg + ratatui-image features (dithering, tmux passthrough, font-size CSI, two-tier API, async resize)
4. **Wave 4 — virtual terminal (1-2 weeks)**
   - PR set: `x-vt` lib, sliced per `x-vt.md`
5. **Wave 5 — recording (3-5 days)**
   - PR set: `x-vcr` lib, blocked on `x-vt` PR3 (basic SGR support)
6. **Wave 6 — PTY (4-6 days, conditional)**
   - PR set: `x-xpty` lib. Only if we want to upgrade candy-wish away from host-sshd.

## Cross-cutting touch-ups

When each plan ships, the following must update:

- `MATCHUPS.md` — new row in the libraries table for `candy-mosaic`, `candy-vt`, `candy-vcr`, `candy-pty`
- `PROJECT_NAMES.md` — naming-decision entry per new lib
- `CONVERSION.md` — phase row per new lib
- `UPSTREAM_OPPORTUNITIES.md` — flip the `x/*` row from 🔴 to 🟡/🟢/🚀 as it lands
- `README.md` (root) — table row + library count
- `docs/index.html` — homepage tile per new lib
- `media/` — 256-square candy-themed PNG icon per new lib
- `.github/workflows/{ci,vhs}.yml` — matrix entry per new lib (hand-maintained)
- per-lib `CALIBER_LEARNINGS.md` — gotchas captured during the build
