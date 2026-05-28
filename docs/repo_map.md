# SugarCraft Repository Map

> Comprehensive mapping of 3rd-party TUI/CLI/GitHub repos to SugarCraft libraries.
> Each repo has a detailed `repo_map/<org>_<repo>.md` report with full analysis.
> Last updated: 2026-05-27

---

## Quick Reference: Many-to-Many Mapping

| 3rd-Party Repo | Language | Stars | SugarCraft Libraries |
|---|---|---|---|
| [alecrabbit/php-console-spinner](#alecrabbitphp-console-spinner) | PHP | ~niche | candy-core, sugar-bits |
| [Bdeering1/console-menu](#bdeering1console-menu) | Rust | ~niche | sugar-bits, candy-core |
| [blacktop/go-termimg](#blacktopgo-termimg) | Go | ~57 | candy-mosaic |
| [c9s/CLIFramework](#c9scliframework) | PHP | 436 | candy-shell, candy-kit, sugar-prompt |
| [charmbracelet/bubbles](#charmbraceletbubbles) | Go | ~2000 | sugar-bits, sugar-prompt |
| [charmbracelet/bubbletea](#charmbraceletbubbletea) | Go | ~23000 | candy-core (primary), sugar-bits, candy-shell |
| [charmbracelet/bubbletea-app-template](#charmbraceletbubbletea-app-template) | Go | 248 | candy-core |
| [charmbracelet/catwalk](#charmbraceletcatwalk) | Go | 687 | candy-shell, sugar-prompt |
| [charmbracelet/charm](#charmbraceletcharm) | Go | ~2400 | candy-shell, candy-sprinkles, candy-core |
| [charmbracelet/colorprofile](#charmbraceletcolorprofile) | Go | 111 | candy-core, candy-palette |
| [charmbracelet/confettysh](#charmbraceletconfettysh) | Go | 81 | honey-bounce, sugar-charts |
| [charmbracelet/crush](#charmbraceletcrush) | Go | 24731 | candy-shell, sugar-prompt, candy-shine |
| [charmbracelet/fang](#charmbraceletfang) | Go | ~600 | candy-kit |
| [charmbracelet/fantasy](#charmbraceletfantasy) | Go | 774 | candy-shell |
| [charmbracelet/freeze](#charmbraceletfreeze) | Go | 4583 | candy-shine, candy-freeze |
| [charmbracelet/glamour](#charmbraceletglamour) | Go | ~3400 | candy-shine |
| [charmbracelet/glow](#charmbraceletglow) | Go | ~9000 | sugar-glow, sugar-bits, candy-shine |
| [charmbracelet/gum](#charmbraceletgum) | Go | ~8700 | candy-shell, candy-sprinkles, sugar-bits |
| [charmbracelet/harmonica](#charmbraceletharmonica) | Go | 1530 | honey-bounce |
| [charmbracelet/huh](#charmbracelethuh) | Go | ~2800 | sugar-prompt, sugar-bits, candy-shine |
| [charmbracelet/lipgloss](#charmbraceletlipgloss) | Go | ~3500 | candy-sprinkles |
| [charmbracelet/log](#charmbraceletlog) | Go | ~700 | candy-log |
| [charmbracelet/melt](#charmbraceletmelt) | Go | ~800 | candy-shell |
| [charmbracelet/mods](#charmbraceletmods) | Go | 5316 | candy-shell, sugar-prompt |
| [charmbracelet/pop](#charmbraceletpop) | Go | ~2800 | sugar-post, candy-shell, candy-shine |
| [charmbracelet/promwish](#charmbraceletpromwish) | Go | ~200 | candy-metrics |
| [charmbracelet/sequin](#charmbraceletsequin) | Go | 804 | candy-core, sugar-spark |
| [charmbracelet/sh](#charmbraceletsh) | Go | ~4000 | candy-shell |
| [charmbracelet/skate](#charmbraceletskate) | Go | ~2000 | sugar-skate |
| [charmbracelet/soft-serve](#charmbraceletsoft-serve) | Go | ~7000 | candy-serve |
| [charmbracelet/ultraviolet](#charmbraceletultraviolet) | Go | ~200 | candy-core, sugar-bits |
| [charmbracelet/vhs](#charmbraceletvhs) | Go | ~19800 | candy-core, candy-shine |
| [charmbracelet/vhs-action](#charmbraceletvhs-action) | TypeScript | ~100 | (CI infrastructure) |
| [charmbracelet/wish](#charmbraceletwish) | Go | 5233 | candy-wish |
| [charmbracelet/wishlist](#charmbraceletwishlist) | Go | ~700 | candy-shell, candy-pty, candy-core |
| [charmbracelet/x](#charmbraceletx) | Go | 293 | candy-core, sugar-bits, candy-shine |
| [Evertras/bubble-table](#evertrasbubble-table) | Go | ~800 | sugar-table |
| [KevM/bubbleo](#kevmbubbleo) | Go | 69 | sugar-prompt, sugar-bits, candy-shell |
| [kojiflowers/php-tui-chart](#kojiflowersphp-tui-chart) | PHP | ~niche | sugar-charts (data parsing only) |
| [lrstanley/bubblezone](#lrstanleybubblezone) | Go | 880 | candy-zone |
| [p-gen/smenu](#p-gensmenu) | C | ~900 | candy-shell |
| [pterm/pterm](#ptermpterm) | Go | ~6000 | sugar-bits, candy-shine, candy-core |
| [php-school/cli-menu](#php-schoolcli-menu) | PHP | ~1944 | sugar-bits, candy-shell |
| [php-tui/php-tui](#php-tuiphp-tui) | PHP | ~2000 | candy-core, candy-sprinkles, sugar-bits |
| [rasjonell/dashbrew](#rasjonell-dashbrew) | Go | ~100 | candy-core, candy-shine, sugar-charts |
| [ratatui/ratatui](#ratatuiratatui) | Rust | ~19600 | candy-core, sugar-bits, candy-sprinkles |
| [ratatui/ratatui-image](#ratatuiratatui-image) | Rust | ~400 | candy-mosaic |
| [textualize/textual](#textualizetextual) | Python | ~35000 | candy-core, sugar-bits, candy-sprinkles |
| [treilik/bubbleboxer](#treilikbubbleboxer) | Go | 84 | candy-core, candy-shine, sugar-bits |
| [treilik/bubblelister](#treilikbubblelister) | Go | 52 | sugar-bits, candy-core |
| [WhispPHP/whisp](#wisphpwhisp) | PHP | ~200 | candy-pty, candy-core |
| [76creates/stickers](#76createsstickers) | Go | ~500 | sugar-bits, sugar-table |
| [daltonsw/bubbleup](#daltonswbubbleup) | Go | ~50 | sugar-toast |
| [erikgeiser/promptkit](#erikgeiserpromptkit) | Go | ~200 | sugar-prompt |
| [EthanEFung/bubble-datepicker](#ethanefungbubble-datepicker) | Go | ~40 | sugar-calendar |
| [Genekkion/theHermit](#genekkionthehermit) | Go | 15 | sugar-bits |
| [rmhubbert/bubbletea-overlay](#rmhubbertbubbletea-overlay) | Go | ~50 | sugar-bits |
| [charmbracelet/bubbletea](#charmbraceletbubbletea) | Go | ~23000 | candy-core, sugar-bits, candy-shell |
| [charmbracelet/meta](#charmbraceletmeta) | Go | ~100 | (CI infrastructure only) |
| [charmbracelet/scoop-bucket](#charmbraceletscoop-bucket) | JSON/YAML | 29 | (distribution manifests) |
| [charmbracelet/soft-serve-action](#charmbraceletsoft-serve-action) | TypeScript | ~30 | (GitHub Action) |
| [charmbracelet/tree-sitter-vhs](#charmbracelettree-sitter-vhs) | Go/C | ~50 | (grammar, not TUI lib) |
| [charmbracelet/winget-pkgs](#charmbraceletwinget-pkgs) | YAML | ~1000 | (Windows distribution manifests) |
| [charmbracelet/git-lfs-transfer](#charmbraceletgit-lfs-transfer) | Go | ~50 | (Git infrastructure, not TUI) |
| [charmbracelet/promwish](#charmbraceletpromwish) | Go | ~200 | candy-metrics |
| [charmbracelet/sh](#charmbraceletsh) | Go | ~4000 | candy-shell |

---

## Detailed Entries

### alecrabbit/php-console-spinner

**File:** `repo_map/alecrabbit_php-console-spinner.md`

- **URL:** https://github.com/alecrabbit/php-console-spinner
- **Language:** PHP 8.2+
- **Stars:** Unknown (niche, actively maintained)
- **License:** MIT
- **Description:** Extremely flexible console spinner for PHP CLI with async (Revolt/ReactPHP) and sync modes, ANSI color support (16/256/true color), signal handling, and extensible architecture. 419 PHP files.

**Features:** Dual-mode (sync/async), ANSI color, auto cursor hide/show, SIGINT/SIGTERM, pipe/stream redirection, PSR container DI, custom character palettes, built-in spinners.

**SugarCraft Mapping:**
- `candy-core` → ANSI cursor management (`ConsoleCursor`, `SequenceStateWriter`) patterns
- `sugar-bits` → Spinner/progress component

**Strengths:** Zero external deps, high-res timing (hrtime), correct pipe handling, extensible.
**Weaknesses:** Steep learning curve, 419 files, pre-1.0 API, complex DI/factory hierarchy.

---

### Bdeering1/console-menu

**File:** `repo_map/Bdeering1_console-menu.md`

- **URL:** https://github.com/Bdeering1/console-menu
- **Language:** Rust
- **Stars:** Unknown (niche)
- **License:** MIT
- **Description:** Simple yet powerful library for creating beautiful console menus in Rust. Single `src/lib.rs` (~383 lines), buffered stdout + raw ANSI escape codes.

**Features:** Menu with label + callback, theme configuration (colors, borders, padding), vim keybindings, pagination, centered layout.

**SugarCraft Mapping:**
- `sugar-bits` → Menu component (single widget)
- `candy-core` → Rendering/event loop patterns

**Strengths:** Simple, no dependencies, ANSI 8-bit colors, vim keys.
**Weaknesses:** No Bubble Tea pattern, no composition, no immutable state.

---

### blacktop/go-termimg

**File:** `repo_map/blacktop_go-termimg.md`

- **URL:** https://github.com/blacktop/go-termimg
- **Language:** Go
- **Stars:** ~57
- **License:** MIT
- **Description:** Modern terminal image library for Go supporting Kitty, Sixel, iTerm2, and Unicode halfblock protocols.

**Features:** 4 protocol renderers (Kitty/iTerm2/Sixel/Halfblocks), terminal detection via CSI queries, LRU resize cache, parallel Base64 encoding, tmux passthrough, image widget for TUI frameworks.

**SugarCraft Mapping:**
- `candy-mosaic` → Image rendering (primary target)

**Strengths:** Universal terminal support, automatic detection, performance optimizations.
**Weaknesses:** Sixel slow (90ms vs 2.5ms Kitty), WebP not supported.

---

### c9s/CLIFramework

**File:** `repo_map/c9s_CLIFramework.md`

- **URL:** https://github.com/c9s/CLIFramework
- **Language:** PHP
- **Stars:** ~436
- **License:** BSD-2-Clause
- **Description:** Powerful command line application framework for PHP. Hierarchical commands with GetOptionKit option parsing, Pimple-based DI, event-driven hooks.

**Features:** Command hierarchy, GetOptionKit option parsing, event hooks (execute.before/after), Levenshtein typo correction, reflection-based argument extraction, zsh/bash completion.

**SugarCraft Mapping:**
- `candy-shell` → Command application framework
- `candy-kit` → CLI options
- `sugar-prompt` → Prompter, Chooser

**Strengths:** Mature (11yr), Levenshtein correction, completion generation, event system.
**Weaknesses:** No PHP 8.x features (no return types), singleton anti-pattern, 945-line CommandBase god object, no Windows TTY support.

---

### charmbracelet/bubbles

**File:** `repo_map/charmbracelet_bubbles.md`

- **URL:** https://github.com/charmbracelet/bubbles
- **Language:** Go
- **Stars:** ~2000+
- **License:** MIT
- **Description:** TUI components for Bubble Tea framework: Spinner, TextInput, TextArea, Table, Progress, List, Viewport, Timer, Stopwatch, Help, Key, Cursor, FilePicker, Paginator.

**Features:** 14 components, fuzzy filtering (List), pagination, row selection, progress gradients, textarea with line numbers.

**SugarCraft Mapping:**
- `sugar-bits` → Primary target (all components)
- `sugar-prompt` → TextInput, TextArea
- `sugar-table` → Table
- `sugar-calendar` → Timer/Stopwatch

**Strengths:** Bubble Tea integration, immutable builders, comprehensive test coverage.
**Weaknesses:** Go-only, some gaps vs upstream (ItemList fuzzy filter), PHP port still incomplete.

---

### charmbracelet/bubbletea

**File:** `repo_map/charmbracelet_bubbletea.md`

- **URL:** https://github.com/charmbracelet/bubbletea
- **Language:** Go
- **Stars:** ~23000+
- **License:** MIT
- **Description:** The Elm Architecture TUI framework for Go. Model/Update/View paradigm with unidirectional data flow, commands for async I/O, subscriptions for recurring events.

**Features:** Elm Architecture (Model/Update/View), keyboard (Kitty protocol) + mouse (SGR) input, alt screen, cursor control, clipboard, signal handling, suspend/resume.

**SugarCraft Mapping:**
- `candy-core` → Primary port (main framework)
- `sugar-bits` → Components built on core
- `candy-shell` → Application shell
- `candy-sprinkles` → Styling
- `honey-bounce` → Animations

**Strengths:** Battle-tested, excellent docs, huge ecosystem, clean architecture.
**Weaknesses:** Go-specific (goroutine concurrency vs PHP ReactPHP), large API surface.

---

### charmbracelet/bubbletea-app-template

**File:** `repo_map/charmbracelet_bubbletea-app-template.md`

- **URL:** https://github.com/charmbracelet/bubbletea-app-template
- **Language:** Go
- **Stars:** ~248
- **License:** MIT
- **Description:** Production-grade starter template for Bubble Tea apps with Go 1.24.2, complete CI/CD (build/lint/release/Dependabot), demonstrates TEA pattern with spinner command.

**Features:** TEA pattern boilerplate, Bubble Tea v1.3.10, bubbles v1.0.0, lipgloss v1.1.0, comprehensive GitHub Actions.

**SugarCraft Mapping:**
- `candy-core` → TEA runner pattern
- `sugar-bits` → Components
- `candy-shine` → Styling

---

### charmbracelet/catwalk

**File:** `repo_map/charmbracelet_catwalk.md`

- **URL:** https://github.com/charmbracelet/catwalk
- **Language:** Go
- **Stars:** ~687
- **License:** MIT
- **Description:** Provider/model registry database for Crush shell. JSON-embedded model metadata for 35+ LLM providers (OpenAI, Anthropic, Gemini, DeepSeek, Groq, etc.) with pricing, context windows, reasoning capability.

**Features:** 35+ providers, automated model fetching from APIs, cosign/SBOM signing, Prometheus metrics, HTTP server with ETag caching.

**SugarCraft Mapping:**
- No direct TUI component mapping. Not a TUI library.
- Indirect: model-picker TUI (sugar-bits), pricing chart (sugar-charts)

---

### charmbracelet/charm

**File:** `repo_map/charmbracelet_charm.md`

- **URL:** https://github.com/charmbracelet/charm
- **Language:** Go
- **Stars:** ~2400
- **License:** MIT
- **Description:** Cloud infrastructure layer: encrypted KV store (BadgerDB), encrypted filesystem (go-fs), E2E encryption (SIV), SSH key authentication, self-hostable server. **Sunset Nov 2024.**

**Features:** Charm KV (encrypted sync), Charm FS (fs.FS compatible), Charm Crypt (SIV encryption), SSH PKAM auth, JWT via SSH session.

**SugarCraft Mapping:**
- No direct mapping (cloud infrastructure, not TUI components)
- Indirect: `candy-shell` (CLI patterns), future Sugar-KV/Sugar-FS could draw inspiration

**Weaknesses:** Sunset project, SQLite doesn't scale horizontally, SSH exposure required.

---

### charmbracelet/colorprofile

**File:** `repo_map/charmbracelet_colorprofile.md`

- **URL:** https://github.com/charmbracelet/colorprofile
- **Language:** Go
- **Stars:** ~111
- **License:** MIT
- **Description:** Terminal color profile detection (TrueColor/256/16/no color) with automatic ANSI sequence downsampling.

**Features:** 5-profile system, Color.Convert() for downsampling, Writer wrapper for transparent conversion, environment/terminfo/tmux/Windows detection layers.

**SugarCraft Mapping:**
- `candy-core` → Terminal capability detection
- `candy-palette` → Color profile handling

**Strengths:** Standards-compliant (NO_COLOR, CLICOLOR), thread-safe memoization, recognizes modern terminals.
**Weaknesses:** No built-in color generation.

---

### charmbracelet/confettysh

**File:** `repo_map/charmbracelet_confettysh.md`

- **URL:** https://github.com/charmbracelet/confettysh
- **Language:** Go
- **Stars:** ~81
- **License:** MIT
- **Description:** SSH server rendering animated confetti and fireworks in terminals. Wrapper app, not a library.

**Features:** Confetti (falling particles) and fireworks (radial explosions), wishlist endpoint factory, Prometheus metrics.

**SugarCraft Mapping:**
- `honey-bounce` → Particle physics (velocity, gravity, acceleration)
- `sugar-charts` → ANSI color palette

**Note:** Not a library — application only.

---

### charmbracelet/crush

**File:** `repo_map/charmbracelet_crush.md`

- **URL:** https://github.com/charmbracelet/crush
- **Language:** Go
- **Stars:** ~24731
- **License:** MIT
- **Description:** Terminal-based AI coding assistant with multi-step tool calling, LSP integration, MCP client, bubble tea v2 TUI, and fantasy provider abstraction.

**Features:** Session-based agent, shell execution, LSP integration, MCP client, self-documenting tools, auto-summarization, workspace sharing.

**SugarCraft Mapping:**
- `candy-shell` → CLI patterns
- `sugar-prompt` → Bubble Tea components

**Note:** Sunset March 2026, archived in favor of this project.

---

### charmbracelet/fang

**File:** `repo_map/charmbracelet_fang.md`

- **URL:** https://github.com/charmbracelet/fang
- **Language:** Go
- **Stars:** ~600
- **License:** MIT
- **Description:** CLI starter kit for Cobra applications. Styled help/errors, automatic --version, manpage generation, shell completions.

**Features:** Single Execute() entry, functional options, help rendering with command grouping, theme system with light/dark adaptation, platform-specific Windows VT processing.

**SugarCraft Mapping:**
- `candy-kit` → Primary port (Output, Banner, Section, Stage, HelpText)
- `candy-shine` → Styling
- `candy-log` → Colored output

---

### charmbracelet/fantasy

**File:** `repo_map/charmbracelet_fantasy.md`

- **URL:** https://github.com/charmbracelet/fantasy
- **Language:** Go
- **Stars:** ~774
- **License:** MIT
- **Description:** Go AI agent framework with multi-provider abstraction (OpenAI, Anthropic, Google, Azure, Bedrock, OpenRouter), tool calling, streaming, and structured outputs.

**Features:** Multi-provider abstraction, agentic loop, tool creation via generics, JSON schema generation, structured outputs, retry with exponential backoff.

**SugarCraft Mapping:**
- No direct mapping (AI agent framework vs TUI libs)

---

### charmbracelet/freeze

**File:** `repo_map/charmbracelet_freeze.md`

- **URL:** https://github.com/charmbracelet/freeze
- **Language:** Go
- **Stars:** ~4583
- **License:** MIT
- **Description:** CLI tool generating beautiful images of code and terminal output (PNG/SVG/WebP) with syntax highlighting via Chroma, window decorations, and customizable styling.

**Features:** Chroma syntax highlighting (100+ themes), SVG-first rendering, PNG/WebP via rsvg-convert/resvg, ANSI SGR parsing, interactive TUI configuration.

**SugarCraft Mapping:**
- `candy-shine` → ANSI parsing, styling
- `candy-freeze` → Primary port
- `sugar-bits` → Theme registry, PTY execution

**Note:** Port exists as `candy-freeze` (🟢 in MATCHUPS.md).

---

### charmbracelet/glamour

**File:** `repo_map/charmbracelet_glamour.md`

- **URL:** https://github.com/charmbracelet/glamour
- **Language:** Go
- **Stars:** ~3400
- **License:** MIT
- **Description:** Stylesheet-based markdown rendering for CLI apps. Goldmark parser → ANSI via visitor pattern.

**Features:** Goldmark AST → ANSI rendering, block stack for indent/margin tracking, OSC 8 hyperlinks (FNV-hashed), cascade styles.

**SugarCraft Mapping:**
- `candy-shine` → Primary port
- `sugar-bits` → Word wrap

**Note:** Port exists as `candy-shine` (🟢 in MATCHUPS.md).

---

### charmbracelet/glow

**File:** `repo_map/charmbracelet_glow.md`

- **URL:** https://github.com/charmbracelet/glow
- **Language:** Go
- **Stars:** ~9000
- **License:** MIT
- **Description:** Render markdown on the CLI with pizzazz. TUI for browsing + CLI for single-file rendering, GitHub/GitLab integration, fuzzy filtering.

**Features:** Bubble Tea TUI, glamour markdown, GitHub/GitLab README fetching, gitcha file discovery, fsnotify live reload, clipboard (OSC 52), style auto-detection.

**SugarCraft Mapping:**
- `sugar-glow` → Primary port
- `sugar-bits` → TUI components
- `candy-shine` → Markdown rendering

**Note:** Port exists as `sugar-glow` (🟢 in MATCHUPS.md).

---

### charmbracelet/gum

**File:** `repo_map/charmbracelet_gum.md`

- **URL:** https://github.com/charmbracelet/gum
- **Language:** Go
- **Stars:** ~8700
- **License:** MIT
- **Description:** Tool for glamorous shell scripts. 15 interactive TUI commands (choose, filter, input, confirm, spin, etc.) wrapper over bubbles/lipgloss.

**Features:** 15 commands, fuzzy matching (sahilm/fuzzy), xpty for spin, lipgloss styling, Kong CLI framework.

**SugarCraft Mapping:**
- `candy-shell` → Primary umbrella (shell/framework)
- `sugar-bits` → Individual commands (choose, filter, input, spin, style, etc.)

**Note:** Port exists as `candy-shell` (🟢 in MATCHUPS.md).

---

### charmbracelet/harmonica

**File:** `repo_map/charmbracelet_harmonica.md`

- **URL:** https://github.com/charmbracelet/harmonica
- **Language:** Go
- **Stars:** ~1530
- **License:** MIT
- **Description:** Physics-based animation library. Damped spring oscillator (Ryan Juckett algorithm) + Newtonian projectile with Euler integration.

**Features:** Spring with under/critically/over-damped regimes, projectile with velocity/acceleration, O(1) per-frame updates.

**SugarCraft Mapping:**
- `honey-bounce` → Primary port (already extended with immutable patterns, SpringChain, Easing, etc.)

**Note:** Port exists as `honey-bounce` (🟢 in MATCHUPS.md).

---

### charmbracelet/huh

**File:** `repo_map/charmbracelet_huh.md`

- **URL:** https://github.com/charmbracelet/huh
- **Language:** Go
- **Stars:** ~2800
- **License:** MIT
- **Description:** Build terminal forms and prompts. 7 field types (Input, Text, Select, MultiSelect, Confirm, FilePicker, Note) with dynamic reactivity.

**Features:** Form → Groups → Fields hierarchy, *Func dynamic reactivity, Bubble Tea integration, vim-style keyboard nav, accessibility mode.

**SugarCraft Mapping:**
- `sugar-prompt` → Primary port
- `sugar-bits` → Individual fields
- `candy-shine` → Theming (Lipgloss equivalent)

**Note:** Port exists as `sugar-prompt` (🟢 in MATCHUPS.md).

---

### charmbracelet/lipgloss

**File:** `repo_map/charmbracelet_lipgloss.md`

- **URL:** https://github.com/charmbracelet/lipgloss
- **Language:** Go
- **Stars:** ~3500
- **License:** MIT
- **Description:** Style definitions for terminal layouts. Pure-value fluent Style type with bitmask properties, ANSI SGR, CSS-like margin/padding, borders, CIELAB color blending, layer compositing.

**Features:** Full ANSI SGR (bold/italic/underline/hyperlinks), 10 border styles, color profile downsampling, CIELAB blending, sub-packages for table/list/tree rendering.

**SugarCraft Mapping:**
- `candy-sprinkles` → Primary port (foundation styling layer)

**Note:** Port exists as `candy-sprinkles` (🟢 in MATCHUPS.md).

---

### charmbracelet/log

**File:** `repo_map/charmbracelet_log.md`

- **URL:** https://github.com/charmbracelet/log
- **Language:** Go
- **Stars:** ~700
- **License:** MIT
- **Description:** Minimal, colorful Go logging library with Text/JSON/Logfmt formatters, leveled logging, slog integration.

**Features:** 6 log levels, atomic level checking, string builder pooling, sub-logger pattern, slog.Handler implementation.

**SugarCraft Mapping:**
- `candy-log` → Primary port

**Note:** Port exists as `candy-log` (🟢 in MATCHUPS.md).

---

### charmbracelet/melt

**File:** `repo_map/charmbracelet_melt.md`

- **URL:** https://github.com/charmbracelet/melt
- **Language:** Go
- **Stars:** ~800
- **License:** MIT
- **Description:** Convert Ed25519 SSH private keys to BIP39 mnemonic seed phrases and back. CLI + library.

**Features:** BIP39 encoding, Ed25519 ↔ seed round-trip, Cobra CLI, 12-language i18n.

**SugarCraft Mapping:**
- No direct mapping (cryptographic CLI utility)

---

### charmbracelet/mods

**File:** `repo_map/charmbracelet_mods.md`

- **URL:** https://github.com/charmbracelet/mods
- **Language:** Go
- **Stars:** ~5316
- **License:** MIT
- **Description:** AI on the command line. Go CLI with Bubble Tea TUI, glamour rendering, SQLite + gob caching, MCP integration.

**Features:** Multi-provider LLM streaming, tool calling, SQLite conversation storage, MCP client (stdio/SSE/HTTP).

**SugarCraft Mapping:**
- `candy-shell` → TUI framework
- `sugar-prompt` → Interactive forms

**Note:** Sunset March 2026, archived in favor of Crush.

---

### charmbracelet/pop

**File:** `repo_map/charmbracelet_pop.md`

- **URL:** https://github.com/charmbracelet/pop
- **Language:** Go
- **Stars:** ~2800
- **License:** MIT
- **Description:** Send emails from your terminal. TUI (sequential field navigation) + CLI mode.

**Features:** TUI via Bubble Tea, goldmark markdown rendering, dual delivery (Resend API + SMTP), Gmail smart-defaults.

**SugarCraft Mapping:**
- `sugar-post` → Primary port
- `sugar-prompt` → TUI form navigation
- `candy-shine` → Markdown rendering

**Note:** Port exists as `sugar-post` (🟢 in MATCHUPS.md).

---

### charmbracelet/promwish

**File:** `repo_map/charmbracelet_promwish.md`

- **URL:** https://github.com/charmbracelet/promwish
- **Language:** Go
- **Stars:** ~200
- **License:** MIT
- **Description:** Prometheus middleware for Wish SSH servers. Exposes session metrics (created/finished/duration) to Prometheus via embedded HTTP server.

**Features:** wish middleware + metrics server, 3 metrics (sessions created/finished/duration).

**SugarCraft Mapping:**
- `candy-metrics` → Primary port

**Note:** Port exists as `candy-metrics` (🟢 in MATCHUPS.md).

---

### charmbracelet/sequin

**File:** `repo_map/charmbracelet_sequin.md`

- **URL:** https://github.com/charmbracelet/sequin
- **Language:** Go
- **Stars:** ~804
- **License:** MIT
- **Description:** Human-readable ANSI escape sequence debugger. Parses SGR, cursor, screen, mode sequences for TUI debugging.

**Features:** Full ANSI parsing (CSI/DCS/OSC/ESC/PM/SOS), SGR text styling, theme support, PTY execution, golden file testing.

**SugarCraft Mapping:**
- `candy-core` → ANSI parsing/rendering
- `sugar-spark` → Visual debugging

**Note:** Port exists as `sugar-spark` (🟢 in MATCHUPS.md).

---

### charmbracelet/sh

**File:** `repo_map/charmbracelet_sh.md`

- **URL:** https://github.com/charmbracelet/sh
- **Language:** Go
- **Stars:** ~4000
- **License:** BSD-3-Clause
- **Description:** Shell parser, formatter, and interpreter with bash support. Includes shfmt CLI tool.

**Features:** Full AST parser (POSIX/Bash/mksh), formatter (shfmt), interpreter with handler injection, expansion engine.

**SugarCraft Mapping:**
- `candy-shell` → Shell parsing patterns
- No direct port (shell scripting domain, different from TUI)

---

### charmbracelet/skate

**File:** `repo_map/charmbracelet_skate.md`

- **URL:** https://github.com/charmbracelet/skate
- **Language:** Go
- **Stars:** ~2000
- **License:** MIT
- **Description:** Personal key value store CLI with multi-database support (`KEY@DB` syntax), BadgerDB backend, Levenshtein fuzzy DB suggestions.

**Features:** Multi-database (subdirectories), Levenshtein distance for fuzzy DB names, read/write Badger transactions.

**SugarCraft Mapping:**
- `sugar-skate` → Primary port

**Note:** Port exists as `sugar-skate` (🟢 in MATCHUPS.md).

---

### charmbracelet/soft-serve

**File:** `repo_map/charmbracelet_soft-serve.md`

- **URL:** https://github.com/charmbracelet/soft-serve
- **Language:** Go
- **Stars:** ~7000
- **License:** MIT
- **Description:** Self-hostable Git server with TUI over SSH, Git LFS, access control, SQLite/PostgreSQL, Prometheus metrics.

**Features:** SSH + HTTP + Git daemon protocols, Bubble Tea TUI, Git LFS server, per-repo access control, TLS hot-reload.

**SugarCraft Mapping:**
- `candy-serve` → Primary port

**Note:** Port exists as `candy-serve` (🟢 in MATCHUPS.md).

---

### charmbracelet/ultraviolet

**File:** `repo_map/charmbracelet_ultraviolet.md`

- **URL:** https://github.com/charmbracelet/ultraviolet
- **Language:** Go
- **Stars:** ~200
- **License:** MIT
- **Description:** Terminal UI framework providing cell-based rendering with diffing, unified keyboard/mouse input (Kitty keyboard + SGR mouse), Cassowary constraint solver for split-screen layouts.

**Features:** Cassowary constraints, cell diffing (touched-line tracking), ECH/REP/ICH/DCH optimization, ANSI SGR, border primitives, progress bars.

**SugarCraft Mapping:**
- `candy-core` → Buffer/screen/terminal
- `sugar-bits` → Styling/input/width methods
- `candy-shell`/`candy-sprinkles` → Borders/windows
- `honey-bounce` → Cassowary layout

---

### charmbracelet/vhs

**File:** `repo_map/charmbracelet_vhs.md`

- **URL:** https://github.com/charmbracelet/vhs
- **Language:** Go
- **Stars:** ~19800
- **License:** MIT
- **Description:** CLI home video recorder. Parses .tape DSL → records terminal output → encodes to GIF/MP4 via FFmpeg.

**Features:** .tape DSL (Type/Sleep/Enter/Set/Click/Keys), frame capture via go-rod browser automation, FFmpeg encoding, 40+ themes, VHS格式 parser, SSH server.

**SugarCraft Mapping:**
- `candy-core` → Input simulation, TTY handling
- `candy-shine` → ANSI/Theming
- `sugar-bits` → Component concepts

**Note:** Port exists as `candy-vcr` (🟢 in MATCHUPS.md).

---

### charmbracelet/wish

**File:** `repo_map/charmbracelet_wish.md`

- **URL:** https://github.com/charmbracelet/wish
- **Language:** Go
- **Stars:** ~5233
- **License:** MIT
- **Description:** Make SSH apps, just like that! SSH server library with middleware pipeline pattern.

**Features:** Auto-generated ED25519 host keys, middleware pipeline (recovery/logging/auth/ratelimit/scp/activeterm/git), LRU rate limiting, PTY fallback.

**SugarCraft Mapping:**
- `candy-wish` → Primary port

**Note:** Port exists as `candy-wish` (🟢 in MATCHUPS.md).

---

### charmbracelet/wishlist

**File:** `repo_map/charmbracelet_wishlist.md`

- **URL:** https://github.com/charmbracelet/wishlist
- **Language:** Go
- **Stars:** ~700
- **License:** MIT
- **Description:** SSH directory. TUI-based endpoint listing/connection with Zeroconf/DNS SRV/Tailscale discovery.

**Features:** Dual-mode (CLI browsing + SSH-server TUI), ProxyJump tunneling, agent forwarding, OpenSSH config + YAML parsing.

**SugarCraft Mapping:**
- `sugar-wishlist` → Primary port
- `candy-shell` → TUI framework
- `candy-pty` → PTY wrappers

**Note:** Port exists as `sugar-wishlist` (🟢 in MATCHUPS.md).

---

### charmbracelet/x

**File:** `repo_map/charmbracelet_x.md`

- **URL:** https://github.com/charmbracelet/x
- **Language:** Go
- **Stars:** ~293
- **License:** MIT
- **Description:** Go monorepo of 30+ experimental packages: ansi parser, virtual terminal emulator, color utilities, input handling, testing tools.

**Features:** ANSI/ECMA-48 parser, vt (virtual terminal), cellbuf, term/termios/xpty, colors/charmtone/toner/mosaic, input/key/clipboard, teatest/golden/vcr testing tools.

**SugarCraft Mapping:**
- `candy-core` → ansi, term, termios, cellbuf
- `sugar-bits` → colors, charmtone
- `candy-vt` → virtual terminal emulator

**Note:** `candy-vt` exists in MATCHUPS (🟡 partial).

---

### Evertras/bubble-table

**File:** `repo_map/Evertras_bubble-table.md`

- **URL:** https://github.com/Evertras/bubble-table
- **Language:** Go
- **Stars:** ~800
- **License:** MIT
- **Description:** Interactive terminal table component for Bubble Tea. Sortable, filterable, paginated, row-selectable with x/y scrolling.

**Features:** Multi-column stable sort, contains + fuzzy filtering, pagination, row selection, horizontal scrolling with frozen columns, lipgloss styling.

**SugarCraft Mapping:**
- `sugar-table` → Primary port
- `sugar-bits` → Table component

**Note:** Port exists as `sugar-table` (🟢 in MATCHUPS.md).

---

### kojiflowers/php-tui-chart

**File:** `repo_map/kojiflowers_php-tui-chart.md`

- **URL:** https://github.com/kojiflowers/php-tui-chart
- **Language:** PHP
- **Stars:** Unknown (niche)
- **License:** MIT
- **Description:** PHP wrapper generating JavaScript for Toast UI Chart (browser-based). Defines chart data in PHP, emits `<script>` tags.

**Features:** 3 chart types (line/bar/column), CSV/TSV/JSON/array input, `keypair` data reorganization, `__toString()` for easy echo.

**SugarCraft Mapping:**
- No direct port. `sugar-charts` renders native PHP/ANSI charts; this wraps a browser JS library.

**Weaknesses:** No tests, no strict types, deprecated CDN, insecure string-concatenation JSON.

---

### lrstanley/bubblezone

**File:** `repo_map/lrstanley_bubblezone.md`

- **URL:** https://github.com/lrstanley/bubblezone
- **Language:** Go
- **Stars:** ~880
- **License:** MIT
- **Description:** Mouse zone tracking for Bubble Tea. Marks component regions with invisible ANSI sequences, scans at root View() to map coordinates to IDs.

**Features:** zone.Mark/Scan/Get pattern, AABB collision detection, atomic prefix ID generation, channel-based concurrent zone storage.

**SugarCraft Mapping:**
- `candy-zone` → Primary port

**Note:** Port exists as `candy-zone` (🟢 in MATCHUPS.md).

---

### p-gen/smenu

**File:** `repo_map/p-gen_smenu.md`

- **URL:** https://github.com/p-gen/smenu
- **Language:** C
- **Stars:** ~900
- **License:** MPL 2.0
- **Description:** Terminal selection filter. Reads words from stdin, presents scrollable window with cursor-based selection. Ternary Search Tree indexing for O(k) prefix search.

**Features:** TST-based word indexing, bitmap for match highlighting, 3 display modes (line/column/tabulate), UTF-8 grapheme support, scrollbars.

**SugarCraft Mapping:**
- `sugar-bits` → Selection component
- `candy-shell` → CLI tool patterns

---

### pterm/pterm

**File:** `repo_map/pterm_pterm.md`

- **URL:** https://github.com/pterm/pterm
- **Language:** Go
- **Stars:** ~6000
- **License:** MIT
- **Description:** Modern Go module to beautify console output. 25+ components (Printers): colors, progressbar, spinner, table, bar chart, heatmap, box, panel, tree, bigtext, header, etc.

**Features:** ANSI 3/4-bit/TrueColor, RGB gradient Fade(), MapRangeToRange scaling, fuzzy search in InteractiveSelect, slog bridge.

**SugarCraft Mapping:**
- `sugar-bits` → Primary target (all components)
- `candy-shine` → Color/style system
- `candy-core` → Terminal utilities
- `honey-bounce` → Math utilities (MapRangeToRange, Fade)

---

### php-school/cli-menu

**File:** `repo_map/php-school_cli-menu.md`

- **URL:** https://github.com/php-school/cli-menu
- **Language:** PHP
- **Stars:** ~1944
- **License:** MIT
- **Description:** Build beautiful PHP CLI menus. Builder API, 8 item types, CSS-box-model styling, non-canonical TTY input.

**Features:** CliMenu builder, MenuStyle (margin/border/padding/content), 8 item types (Selectable/Checkbox/Radio/Static/LineBreak/AsciiArt/MenuMenu/Split), Input for Text/Number/Password, Dialogue (Flash/Confirm).

**SugarCraft Mapping:**
- `sugar-bits` → Individual menu items
- `candy-shell` → Full menu runtime + builder

---

### php-tui/php-tui

**File:** `repo_map/php-tui_php-tui.md`

- **URL:** https://github.com/php-tui/php-tui
- **Language:** PHP (port of Rust's Ratatui)
- **Stars:** ~2000
- **License:** MIT
- **Description:** Make awesome console applications in PHP. Ratatui port with backend abstraction, widget/renderer visitor pattern, Cassowary constraint solver.

**Features:** 15+ widgets (Paragraph/Block/List/Table/Chart/BarChart/Sparkline/Gauge/Scrollbar/Tabs/Canvas/Grid), BDF fonts, ImageMagick images, double-buffering with cell diffing.

**SugarCraft Mapping:**
- `candy-core` → Display/Buffer/Backend/Widget interfaces
- `candy-sprinkles` → Style/Color/Modifier/Text/Span
- `honey-bounce` → Cassowary constraint solver
- `sugar-bits` → Individual widgets

---

### rasjonell/dashbrew

**File:** `repo_map/rasjonell_dashbrew.md`

- **URL:** https://github.com/rasjonell/dashbrew
- **Language:** Go
- **Stars:** ~100
- **License:** MIT
- **Description:** TUI dashboard builder. Compiles JSON config → Bubble Tea model with 6 component types (text/list/todo/chart/histogram/table).

**Features:** Recursive flexbox layout, bounding-box navigation (4-directional neighbor lookup), LAB color blending, asciigraph charts, HTTP fetch + scheduling.

**SugarCraft Mapping:**
- `candy-core` → Layout, TEA model, actions
- `candy-shine` → Styling/themes
- `candy-sprinkles` → Key bindings
- `sugar-charts` → chart/histogram

---

### ratatui/ratatui

**File:** `repo_map/ratatui_ratatui.md`

- **URL:** https://github.com/ratatui/ratatui
- **Language:** Rust
- **Stars:** ~19600
- **License:** MIT
- **Description:** Cook up terminal UIs in Rust. Modular workspace (ratatui-core + ratatui-widgets), Widget/StatefulWidget traits, Cassowary constraints, multiple backends (crossterm/termion/termwiz).

**Features:** 18+ widgets, immediate-mode rendering with buffer diffing, Widget/StatefulWidget trait system, Stylize fluent styling, layout constraints.

**SugarCraft Mapping:**
- `candy-core` → Framework foundation (Display/Buffer/Terminal)
- `sugar-bits` → Widget library
- `candy-sprinkles` → Styling/layout
- `candy-shell` → TTY I/O
- `honey-bounce` → Cassowary constraint solver

---

### ratatui/ratatui-image

**File:** `repo_map/ratatui_ratatui-image.md`

- **URL:** https://github.com/ratatui/ratatui-image
- **Language:** Rust
- **Stars:** ~400
- **License:** MIT
- **Description:** Ratatui widget rendering images via Kitty/Sixel/iTerm2/Unicode halfblocks protocols.

**Features:** Unified Image/StatefulImage, ThreadProtocol (background resize/encode), Picker (terminal capability detection), SlicedImage (partial rendering).

**SugarCraft Mapping:**
- `candy-mosaic` → Image rendering protocols

---

### textualize/textual

**File:** `repo_map/textualize_textual.md`

- **URL:** https://github.com/textualize/textual
- **Language:** Python
- **Stars:** ~35000
- **License:** MIT
- **Description:** The lean application framework for Python. Build TUI apps with a simple Python API, runs in terminal and web browser.

**Features:** 40+ widgets, Reactive state (watchers), CSS-based styling (TCSS), MessagePump for async, compositor for efficient redraws, Tree-sitter integration, command palette.

**SugarCraft Mapping:**
- `candy-core` → App/Widget/Screen/MessagePump
- `sugar-bits` → Widget library
- `candy-sprinkles` → CSS/layout engine

**Sister project:** Textual is the Python equivalent of the Bubble Tea ecosystem that SugarCraft ports to PHP.

---

### treilik/bubbleboxer

**File:** `repo_map/treilik_bubbleboxer.md`

- **URL:** https://github.com/treilik/bubbleboxer
- **Language:** Go
- **Stars:** ~84
- **License:** MIT
- **Description:** Layout composer for BubbleTea bubbles. Composite tree pattern composing tea.Model instances with borders and proper sizing.

**Features:** Boxer tea.Model, LayoutTree (Node tree), ModelMap (address → model), recursive rendering with ANSI-aware width.

**SugarCraft Mapping:**
- `candy-core` → Viewport
- `candy-shine` → Table layout
- `sugar-bits` → View rendering

---

### treilik/bubblelister

**File:** `repo_map/treilik_bubblelister.md`

- **URL:** https://github.com/treilik/bubblelister
- **Language:** Go
- **Stars:** ~52
- **License:** MIT
- **Description:** Cursor-navigable list bubble for BubbleTea. Customizable prefixes/suffixes, word-wrapping, viewport-relative cursor.

**Features:** sort.Interface for Go sorting, Elm architecture, concurrent GetIndex via goroutine.

**SugarCraft Mapping:**
- `sugar-bits` → List widget with cursor navigation
- `candy-core` → Core model infrastructure

---

### WhispPHP/whisp

**File:** `repo_map/WhispPHP_whisp.md`

- **URL:** https://github.com/WhispPHP/whisp
- **Language:** PHP 8.2+ (FFI)
- **Stars:** ~200
- **License:** MIT
- **Description:** Pure PHP SSH server. Implements complete SSH protocol stack: Kex (Curve25519), auth (Ed25519/RSA), channels, PTY, commands.

**Features:** Server (socket/threading), Connection (SSH state machine), Ffi.php (PTY via FFI), Kex.php, PacketHandler (AES-256-GCM), PublicKeyValidator.

**SugarCraft Mapping:**
- `candy-pty` → PTY management
- `candy-core` → Event loop / I/O multiplexing

---

### 76creates/stickers

**File:** `repo_map/76creates_stickers.md`

- **URL:** https://github.com/76creates/stickers
- **Language:** Go
- **Stars:** ~500
- **License:** MIT
- **Description:** FlexBox (CSS flexbox-inspired responsive grid) and Table (sortable/filterable/scrollable) for BubbleTea.

**Features:** Ratio-based layout with minimums, fixed dimension locking, style inheritance, ContentGenerator, bubble sort table.

**SugarCraft Mapping:**
- `sugar-bits` → FlexBox/Table primitives
- `sugar-table` → Table component

---

### daltonsw/bubbleup

**File:** `repo_map/daltonsw_bubbleup.md`

- **URL:** https://github.com/daltonsw/bubbleup
- **Language:** Go
- **Stars:** ~50
- **License:** MIT
- **Description:** Floating alert notification library for BubbleTea. Alerts float to top like bubbles in soda.

**Features:** AlertModel (tea.Model), 6 positions, 4 alert types (Info/Warn/Error/Debug), dynamic width, ESC dismiss.

**SugarCraft Mapping:**
- `sugar-toast` → Primary port (already extended with queue, progress bars, action buttons, history log)

---

### erikgeiser/promptkit

**File:** `repo_map/erikgeiser_promptkit.md`

- **URL:** https://github.com/erikgeiser/promptkit
- **Language:** Go
- **Stars:** ~200
- **License:** MIT
- **Description:** Collection of interactive CLI prompts (selection, text input, confirmation) built on BubbleTea.

**Features:** Generic Selection/MultiSelection, TextInput/TextArea, Confirmation, template rendering, full customization.

**SugarCraft Mapping:**
- `sugar-prompt` → Primary target
- `sugar-bits` → Individual prompt types

---

### EthanEFung/bubble-datepicker

**File:** `repo_map/EthanEFung_bubble-datepicker.md`

- **URL:** https://github.com/EthanEFung/bubble-datepicker
- **Language:** Go
- **Stars:** ~40
- **License:** MIT
- **Description:** Calendar datepicker bubble component for BubbleTea with 3-tier focus (Month/Year/Calendar).

**Features:** Focus state machine, 7-column calendar grid algorithm, vim-style keybindings (hjkl).

**SugarCraft Mapping:**
- `sugar-calendar` → Primary port

---

### Genekkion/theHermit

**File:** `repo_map/Genekkion_theHermit.md`

- **URL:** https://github.com/Genekkion/theHermit
- **Language:** Go
- **Stars:** ~15
- **License:** MIT
- **Description:** Quick-fix list overlay for BubbleTea. Wraps views, overlays list content, background continues updating.

**Features:** Model with tea.Model interface, Item interface (Title()), views.go overlay rendering, ANSI escape handling.

**SugarCraft Mapping:**
- `sugar-bits` → Overlay/component patterns

---

### KevM/bubbleo

**File:** `repo_map/KevM_bubbleo.md`

- **URL:** https://github.com/KevM/bubbleo
- **Language:** Go
- **Stars:** ~69
- **License:** MIT
- **Description:** TUI navigation components for BubbleTea: navstack (push/pop), menu, breadcrumb, shell, window dimension management.

**Features:** navstack, menu, breadcrumb, shell, window; tea.Model interface; Closable interface; tea.Sequence for ordered commands.

**SugarCraft Mapping:**
- `sugar-prompt` → Navigation
- `sugar-bits` → Menu/list widgets
- `candy-shell` → Shell composition

---

### rmhubbert/bubbletea-overlay

**File:** `repo_map/rmhubbert_bubbletea-overlay.md`

- **URL:** https://github.com/rmhubbert/bubbletea-overlay
- **Language:** Go
- **Stars:** ~50
- **License:** MIT
- **Description:** Modal overlay compositor for BubbleTea. Places foreground/background views into 5 positions (Top/Right/Bottom/Left/Center) with offsets.

**Features:** Model with tea.Model, Viewable interface, Composite() algorithm, Position enum, ANSI-aware string handling.

**SugarCraft Mapping:**
- `sugar-bits` → Overlay/modal patterns

---

## Non-TUI Infrastructure Repos (Informational Only)

These repos exist in the ecosystem but are not TUI component libraries suitable for porting:

| Repo | Type | Notes |
|---|---|---|
| charmbracelet/bubbletea-app-template | Go template | Production-ready starter, not a library |
| charmbracelet/meta | CI infra | GitHub Actions workflows + GoReleaser configs |
| charmbracelet/scoop-bucket | Package manifests | Scoop Windows installer manifests |
| charmbracelet/soft-serve-action | GitHub Action | Syncs repos to Soft Serve |
| charmbracelet/vhs-action | GitHub Action | Runs VHS in CI for GIF generation |
| charmbracelet/winget-pkgs | Package manifests | Windows Package Manager manifests |
| charmbracelet/tree-sitter-vhs | Tree-sitter grammar | Syntax highlighting for .tape files |
| charmbracelet/git-lfs-transfer | Git infra | Server-side Git LFS pure-SSH transfer |
| charmbracelet/promwish | Metrics middleware | Prometheus for wish SSH servers |
| charmbracelet/sh | Shell parser | Shell parsing/formatter (not TUI) |
| charmbracelet/catwalk | LLM registry | Provider/model metadata database |

---

## Popularity Summary

| Tier | Stars | Repos |
|---|---|---|
| ⭐⭐⭐⭐⭐ (10k+) | textualize/textual (~35k), charmbracelet/bubbletea (~23k), charmbracelet/crush (~25k), charmbracelet/vhs (~20k), ratatui/ratatui (~20k) | 5 |
| ⭐⭐⭐⭐ (5k-10k) | charmbracelet/gum (~9k), charmbracelet/glow (~9k), charmbracelet/bubbles (~2k), charmbracelet/freeze (~4.6k), charmbracelet/glamour (~3.4k), charmbracelet/lipgloss (~3.5k), pterm/pterm (~6k), charmbracelet/mods (~5.3k), charmbracelet/wish (~5.2k) | 9 |
| ⭐⭐⭐ (1k-5k) | charmbracelet/huh (~2.8k), charmbracelet/pop (~2.8k), charmbracelet/charm (~2.4k), charmbracelet/sh (~4k), charmbracelet/harmonica (~1.5k), charmbracelet/skate (~2k), Evertras/bubble-table (~800), charmbracelet/soft-serve (~7k), c9s/CLIFramework (~436), charmbracelet/gum (~8.7k), charmbracelet/colorprofile (~111) | 11 |
| ⭐⭐ (100-1k) | charmbracelet/sequin (~804), charmbracelet/melt (~800), charmbracelet/log (~700), charmbracelet/wishlist (~700), charmbracelet/promwish (~200), p-gen/smenu (~900), charmbracelet/fang (~600), lrstanley/bubblezone (~880), 76creates/stickers (~500), charmbracelet/fantasy (~774), charmbracelet/catwalk (~687), charmbracelet/x (~293) | 12 |
| ⭐ (niche/<100) | All remaining repos including php-school/cli-menu (~1.9k - note: this is actually popular), erikgeiser/promptkit (~200), WhispPHP/whisp (~200), kojiflowers/php-tui-chart, Bdeering1/console-menu, alecrabbit/php-console-spinner, etc. | ~22 |

---

## Per-File Details Index

Each 3rd-party repo has a detailed analysis at `repo_map/<org>_<repo>.md`:

| Org_Repo | File |
|---|---|
| alecrabbit_php-console-spinner | repo_map/alecrabbit_php-console-spinner.md |
| Bdeering1_console-menu | repo_map/Bdeering1_console-menu.md |
| blacktop_go-termimg | repo_map/blacktop_go-termimg.md |
| c9s_CLIFramework | repo_map/c9s_CLIFramework.md |
| charmbracelet_bubbles | repo_map/charmbracelet_bubbles.md |
| charmbracelet_bubbletea | repo_map/charmbracelet_bubbletea.md |
| charmbracelet_bubbletea-app-template | repo_map/charmbracelet_bubbletea-app-template.md |
| charmbracelet_catwalk | repo_map/charmbracelet_catwalk.md |
| charmbracelet_charm | repo_map/charmbracelet_charm.md |
| charmbracelet_colorprofile | repo_map/charmbracelet_colorprofile.md |
| charmbracelet_confettysh | repo_map/charmbracelet_confettysh.md |
| charmbracelet_crush | repo_map/charmbracelet_crush.md |
| charmbracelet_fang | repo_map/charmbracelet_fang.md |
| charmbracelet_fantasy | repo_map/charmbracelet_fantasy.md |
| charmbracelet_freeze | repo_map/charmbracelet_freeze.md |
| charmbracelet_glamour | repo_map/charmbracelet_glamour.md |
| charmbracelet_glow | repo_map/charmbracelet_glow.md |
| charmbracelet_gum | repo_map/charmbracelet_gum.md |
| charmbracelet_harmonica | repo_map/charmbracelet_harmonica.md |
| charmbracelet_huh | repo_map/charmbracelet_huh.md |
| charmbracelet_lipgloss | repo_map/charmbracelet_lipgloss.md |
| charmbracelet_log | repo_map/charmbracelet_log.md |
| charmbracelet_melt | repo_map/charmbracelet_melt.md |
| charmbracelet_mods | repo_map/charmbracelet_mods.md |
| charmbracelet_pop | repo_map/charmbracelet_pop.md |
| charmbracelet_promwish | repo_map/charmbracelet_promwish.md |
| charmbracelet_sequin | repo_map/charmbracelet_sequin.md |
| charmbracelet_sh | repo_map/charmbracelet_sh.md |
| charmbracelet_skate | repo_map/charmbracelet_skate.md |
| charmbracelet_soft-serve | repo_map/charmbracelet_soft-serve.md |
| charmbracelet_ultraviolet | repo_map/charmbracelet_ultraviolet.md |
| charmbracelet_vhs | repo_map/charmbracelet_vhs.md |
| charmbracelet_vhs-action | repo_map/charmbracelet_vhs-action.md |
| charmbracelet_wish | repo_map/charmbracelet_wish.md |
| charmbracelet_wishlist | repo_map/charmbracelet_wishlist.md |
| charmbracelet_x | repo_map/charmbracelet_x.md |
| Evertras_bubble-table | repo_map/Evertras_bubble-table.md |
| KevM_bubbleo | repo_map/KevM_bubbleo.md |
| kojiflowers_php-tui-chart | repo_map/kojiflowers_php-tui-chart.md |
| lrstanley_bubblezone | repo_map/lrstanley_bubblezone.md |
| p-gen_smenu | repo_map/p-gen_smenu.md |
| pterm_pterm | repo_map/pterm_pterm.md |
| php-school_cli-menu | repo_map/php-school_cli-menu.md |
| php-tui_php-tui | repo_map/php-tui_php-tui.md |
| rasjonell_dashbrew | repo_map/rasjonell_dashbrew.md |
| ratatui_ratatui | repo_map/ratatui_ratatui.md |
| ratatui_ratatui-image | repo_map/ratatui_ratatui-image.md |
| textualize_textual | repo_map/textualize_textual.md |
| treilik_bubbleboxer | repo_map/treilik_bubbleboxer.md |
| treilik_bubblelister | repo_map/treilik_bubblelister.md |
| WhispPHP_whisp | repo_map/WhispPHP_whisp.md |
| 76creates_stickers | repo_map/76creates_stickers.md |
| daltonsw_bubbleup | repo_map/daltonsw_bubbleup.md |
| erikgeiser_promptkit | repo_map/erikgeiser_promptkit.md |
| EthanEFung_bubble-datepicker | repo_map/EthanEFung_bubble-datepicker.md |
| Genekkion_theHermit | repo_map/Genekkion_theHermit.md |
| rmhubbert_bubbletea-overlay | repo_map/rmhubbert_bubbletea-overlay.md |
| charmbracelet_meta | repo_map/charmbracelet_meta.md |
| charmbracelet_scoop-bucket | repo_map/charmbracelet_scoop-bucket.md |
| charmbracelet_soft-serve-action | repo_map/charmbracelet_soft-serve-action.md |
| charmbracelet_tree-sitter-vhs | repo_map/charmbracelet_tree-sitter-vhs.md |
| charmbracelet_winget-pkgs | repo_map/charmbracelet_winget-pkgs.md |
| charmbracelet_git-lfs-transfer | repo_map/charmbracelet_git-lfs-transfer.md |
