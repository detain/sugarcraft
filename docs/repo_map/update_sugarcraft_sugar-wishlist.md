# Overview

**sugar-wishlist** is a PHP port of `charmbracelet/wishlist`, providing a TUI-based SSH endpoint picker that enables users to interactively select SSH destinations from YAML/JSON/OpenSSH config and connect via `pcntl_exec` replacement with the system `ssh` binary. The package targets developers and system administrators who manage multiple SSH endpoints and want a fast, keyboard-driven interface for connection selection.

## Ecosystem Positioning

sugar-wishlist sits at the intersection of TUI interaction and SSH connectivity — a focused, single-purpose tool that doesn't try to be a general-purpose SSH library. It consumes several SugarCraft foundation libraries (`candy-core` for RawMode/i18n, `candy-sprinkles` for styling, `candy-pty` for terminal control) but implements its own lightweight picker rather than pulling in the full `sugar-bits` component library.

## Biggest Opportunity Areas

1. **SSH-server mode** — The most significant missing feature vs upstream. Serving the TUI over SSH would enable true remote access to the endpoint picker.
2. **Dynamic service discovery** — Zeroconf/mDNS, DNS SRV, and Tailscale discovery would make sugar-wishlist usable in dynamic infrastructure environments.
3. **Multiple identity file support** — SSH supports multiple `-i` flags; sugar-wishlist only emits the first.
4. **SSH config `Include` directive support** — Real-world configs often use `Include` to modularize host definitions.

## Biggest Missing Capabilities

1. **No SSH-server mode** — The upstream fully supports serving the TUI over SSH connections.
2. **No dynamic discovery** — Static config files only, no Zeroconf/SRV/Tailscale.
3. **No config watching** — Config loaded once at startup; no live reload.
4. **No theme adaptation** — Hardcoded ANSI colors; no light/dark theme detection.
5. **No programmatic frame stream** — The picker renders directly to STDOUT rather than returning a stream of render frames.

---

# Internal Capability Summary

## Current Architecture

**6-class design** with clean separation:

| Class | File | Responsibility |
|-------|------|----------------|
| `Endpoint` | `Endpoint.php` | Immutable value object for SSH destination |
| `Config` | `Config.php` | YAML/JSON loader + OpenSSH config importer |
| `Picker` | `Picker.php` | ANSI render + key input + filtering |
| `Launcher` | `Launcher.php` | `pcntl_exec`-based process replacement |
| `SshConfigParser` | `SshConfigParser.php` | OpenSSH config file parser |
| `Lang` | `Lang.php` | i18n facade |

## Execution Flow

```
bin/wishlist
  └── Config::load($cfgPath)          ← parse YAML/JSON OR
  └── Config::importFromSshConfig()    ← parse ~/.ssh/config
  └── new Picker()->pick($endpoints)  ← render TUI, read keys, filter
  └── new Launcher()->dispatch($ep)    ← pcntl_exec /usr/bin/ssh [args]
```

## Current Features

- **TUI-based SSH endpoint picker** — Interactive terminal UI with selection, filtering, connection
- **Dual-mode operation** — Local CLI mode (v1); SSH-server TUI mode (architecture in place, deferred)
- **Multi-format configuration** — YAML (`wishlist.yml`), JSON (`wishlist.json`), OpenSSH `~/.ssh/config`
- **ProxyJump support** — `-J` flag for bastion host connections
- **Agent forwarding** — Compatible with SSH agent forwarding via OpenSSH mechanisms
- **Identity file selection** — `-i` flag support (single file only in `toSshArgv()`)
- **OpenSSH config import** — Parses `Host`, `HostName`, `User`, `Port`, `IdentityFile`, `ProxyJump`
- **SSH options passthrough** — `-o KEY=VALUE` from YAML/JSON config
- **Type-to-filter** — Real-time fuzzy filtering as user types
- **Keyboard navigation** — j/k, arrow keys, Enter, Esc, Ctrl-C
- **i18n** — 16 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tx, ja, ru, it, ko, pl, nl, tr, cs, ar)
- **69 tests / 176 assertions** — Comprehensive coverage of all public APIs

## APIs

### Config::load(string $path): array<Endpoint>
Loads endpoints from YAML or JSON config file.

### Config::importFromSshConfig(string $path): array<Endpoint>
Imports endpoints from OpenSSH config file.

### Picker::pick(array<Endpoint> $endpoints): ?Endpoint
Renders the interactive picker, returns selected endpoint or null on quit.

### Launcher::dispatch(Endpoint $ep, ?string $sshBinary = null): void
Executes `ssh` via `pcntl_exec` with endpoint's SSH arguments.

### Endpoint::toSshArgv(): array<string>
Builds the argument array for `pcntl_exec`.

### SshConfigParser::parse($resource): array<hostinfo>
Parses OpenSSH config into hostinfo map with first-match-wins semantics.

## Rendering Systems

- **Hand-rolled ANSI rendering** — Uses `SugarCraft\Core\Util\Ansi` directly (not full Lipgloss port)
- **Simple `while(true)` loop** — Blocking `fread()` on STDIN, direct ANSI escape sequences to STDOUT
- **No cell-based buffer** — Unlike `php-tui` or `textualize/textual`, renders line-by-line
- **No delta rendering** — Full redraw on every frame

## Extension Systems

- **No plugin/extension system** — Single-purpose tool, intentionally minimal
- **Dependency injection** — Constructor accepts nullable `$in`/`$out` streams for testability
- **Callable executor** — `Launcher::$executor` allows test interception

## Strengths

1. **Zero-dependency SSH fidelity** — `pcntl_exec` + system `ssh` means all SSH features work natively
2. **Minimal, focused design** — Picker is ~40 lines of ANSI rendering, exactly what the job needs
3. **Excellent test coverage** — 69 tests covering all public methods
4. **Hand-rolled YAML parser** — Avoids `ext-yaml` dependency for flat-list config format
5. **i18n by default** — 16 locales, upstream has none
6. **Real SSH config import** — Properly handles `Host *` global defaults, per-host overrides, `~` expansion
7. **Portable raw mode** — `RawMode::enable()` safe no-op on non-tty streams
8. **Clean immutability** — `Endpoint` is a true immutable value object with `readonly` properties

## Weaknesses

1. **No SSH-server mode** — Most significant gap vs upstream; v1 is local-CLI-only
2. **No dynamic discovery** — Static config only; no Zeroconf/SRV/Tailscale
3. **Single identity file in argv** — `toSshArgv()` only uses `$identityFiles[0]`
4. **No config watching** — Config loaded once; no live reload
5. **TTY assumption** — No programmatic API returning stream of rendered frames
6. **No color scheme adaptation** — Hardcoded ANSI colors; no light/dark theme detection
7. **No `Include` directive support** — `SshConfigParser` ignores `Include` directives
8. **Single-user only** — No mechanism for sharing configs across a team
9. **No `Match` block support** — SSH config `Match` blocks completely ignored

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/wishlist` | Primary upstream, 700 stars | SSH directory, dual-mode (local+server), discovery (Zeroconf/SRV/Tailscale), STDIN multiplexing | Critical |
| `charmbracelet/wish` | SSH middleware framework, 5,233 stars | SSH server middleware, Bubble Tea TUI over SSH, middleware composition | High |
| `charmbracelet/charm` | Cloud infrastructure, 2.4k stars | SSH-based auth, encrypted KV, Tailscale OAuth integration | Medium |
| `charmbracelet/bubbletea` | TUI framework, 23k+ stars | Elm architecture, command pattern, subscriptions, cell-based rendering | High |
| `charmbracelet/lipgloss` | Styling library, 3,500+ stars | Adaptive color, CSS-like styling, layer compositing, true copy semantics | Medium |
| `textualize/textual` | Python TUI framework, 30k+ stars | Reactive state, CSS layout, spatial hit testing, command palette | Medium |
| `php-tui/php-tui` | PHP TUI library | Buffer diffing, constraint-based layout, extension architecture | Medium |

---

# Feature Gap Analysis

## Critical

### 1. SSH-Server Mode
**Title:** Serve TUI over SSH connections  
**Description:** Upstream `charmbracelet/wishlist` can serve its TUI over SSH, allowing users to SSH into the wishlist server and see the interactive picker, then select an endpoint to jump to.  
**Why it matters:** This is the primary differentiator for remote/multi-machine workflows. Without it, sugar-wishlist is only useful on the machine where it's run.  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Server mode: Serve the TUI over SSH, allowing users to SSH in and see the same interactive listing."  
**Source PR/Issue:** `charmbracelet/wishlist` serves the TUI via `charmbracelet/wish` middleware (`listingMiddleware` in `middleware.go`)  
**Implementation ideas:**
- Integrate `candy-shell` (SSH server port) to accept incoming SSH connections
- Serve the Picker TUI over SSH using the same stream-based I/O pattern from `candy-shell`
- Use the `wish/bubbletea` middleware pattern as reference: spawn a `tea.Program` per SSH session with PTY wiring
**Estimated complexity:** High — requires full SSH server integration, PTY handling per session, window resize forwarding
**Expected impact:** Enables remote access paradigm; major feature parity with upstream

### 2. Multiple Identity Files
**Title:** Emit all identity files in SSH argv  
**Description:** SSH supports multiple `-i` flags to specify multiple identity keys. sugar-wishlist's `Endpoint` model stores a `list<string>` of identity files, but `toSshArgv()` only emits `$identityFiles[0]`.  
**Why it matters:** Users with multiple SSH keys (e.g., one per environment) cannot specify all their keys  
**Source:** `docs/repo_map/sugarcraft_sugar-wishlist.md` — "Single identity file — `toSshArgv()` only uses `$identityFiles[0]` when building the argv"  
**Implementation ideas:**
- Change `toSshArgv()` to iterate and emit all identity files: `foreach ($this->identityFiles as $identityFile) { $argv[] = '-i'; $argv[] = $identityFile; }`
- Add tests for multiple identity files
**Estimated complexity:** Low — single method change
**Expected impact:** Full SSH feature parity, fixes real workflow issue

## High Value

### 3. SSH Config `Include` Directive
**Title:** Support `Include` directive in OpenSSH config parsing  
**Description:** Real-world OpenSSH configs often use `Include` directives to pull in host-specific config snippets (e.g., `Include ~/.ssh/config.d/*`). The `SshConfigParser` currently ignores `Include` directives.  
**Why it matters:** Users with modular SSH configs cannot fully import their configuration  
**Source:** `docs/repo_map/sugarcraft_sugar-wishlist.md` — "No `Include` directive support in SSH config parser"  
**Implementation ideas:**
- Add `Include` keyword support to `SshConfigParser`
- Recursively parse included files with relative path resolution
- Handle glob patterns in includes
**Estimated complexity:** Medium — requires file globbing and recursive parsing
**Expected impact:** Enables adoption for users with modular SSH configs

### 4. Dynamic Service Discovery
**Title:** Implement Zeroconf/mDNS, DNS SRV, and Tailscale discovery  
**Description:** Upstream `charmbracelet/wishlist` supports discovering SSH services via `_ssh._tcp` Zeroconf browsing, DNS SRV records, and Tailscale tailnet devices. These are deferred in sugar-wishlist v1.  
**Why it matters:** For users with dynamic infrastructure (Docker, Kubernetes, Tailscale), static config files are a step backward from upstream  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "DNS SRV record discovery", "Zeroconf/mDNS/Avahi/Bonjour discovery", "Tailscale tailnet device discovery"  
**Source discussion:** `charmbracelet/wishlist/srv/`, `zeroconf/`, `tailscale/` sub-packages  
**Implementation ideas:**
- Create a future `sugar-discovery` leaf library
- Implement `srv.Endpoints()` for DNS SRV + TXT record lookup
- Implement Zeroconf browsing via `grandcat/zeroconf` PHP equivalent or raw UDP/mDNS
- Implement Tailscale API client with OAuth support (as upstream prefers)
**Estimated complexity:** High — requires network protocol implementation and API integration
**Expected impact:** Enables dynamic infrastructure usage, major feature parity

### 5. Theme/Color Adaptation
**Title:** Adaptive light/dark terminal color scheme  
**Description:** The picker's ANSI colors (SGR 36 for cyan, SGR 1 for bold) are hardcoded. Upstream `charmbracelet/wishlist` uses `lipgloss.AdaptiveColor` for light/dark theme adaptation.  
**Why it matters:** Users with light terminal backgrounds may find the current cyan difficult to read  
**Source:** `docs/repo_map/sugarcraft_sugar-wishlist.md` — "No color scheme adaptation — The ANSI color codes are hardcoded"  
**Source reference:** `charmbracelet/lipgloss.md` — "Adaptive colors: `LightDark(hasDarkBackground)` helper returns light/dark variant"  
**Implementation ideas:**
- Use `candy-palette` color detection (`HasDarkBackground`, `BackgroundColor`) to detect terminal theme
- Define light/dark color variants in the style constants
- Select appropriate variant at render time
**Estimated complexity:** Low — only constant changes and conditional rendering
**Expected impact:** Improved accessibility and user experience

### 6. Config File Watching
**Title:** Watch config files for changes and reload  
**Description:** Upstream can refresh and broadcast endpoint updates to connected clients. sugar-wishlist loads config once at startup with no mechanism for live reload.  
**Why it matters:** Users editing config files while wishlist is running want their changes reflected without restarting  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Dynamic Endpoint Updates: Server can refresh endpoints on a configurable interval and push updates"  
**Implementation ideas:**
- Use `inotify` (Linux) or `FSEvents` (macOS) via FFI to watch config file
- Re-parse config on change and re-render picker
- For SSH-server mode: broadcast updated endpoints to connected clients via `broadcast.Relay`
**Estimated complexity:** Medium — file system event handling
**Expected impact:** Better UX for power users

## Medium

### 7. SSH Config `Match` Block Support
**Title:** Parse `Match` blocks in OpenSSH config  
**Description:** The `SshConfigParser` only handles `Host` blocks. OpenSSH `Match` blocks conditionally apply config based on user, host, or other criteria. These are completely ignored.  
**Why it matters:** Advanced SSH configs use `Match` for conditional configuration  
**Source:** `docs/repo_map/sugarcraft_sugar-wishlist.md` — "No SSH config `Match` block support"  
**Implementation ideas:**
- Parse `Match` blocks with their conditional criteria
- Evaluate match conditions at connection time
- Apply matched settings when connecting
**Estimated complexity:** High — requires condition evaluation logic
**Expected impact:** Niche but enables advanced SSH config usage

### 8. Host Key Management
**Title:** Configurable host key verification options  
**Description:** sugar-wishlist uses native `ssh` binary behavior (prompts user for host key verification). Upstream has `hostKeyCallback()` with auto-accept behavior.  
**Why it matters:** Users may want strict host key checking or, conversely, auto-accept for ephemeral environments  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Host Key Callback with Auto-Accept"  
**Implementation ideas:**
- Expose host key verification options in config (strict, accept-new, off)
- Pass appropriate `-o` options to ssh binary
**Estimated complexity:** Low — config and flag passing
**Expected impact:** Security flexibility

### 9. Endpoint Hints/Overrides
**Title:** Apply glob-based hints to discovered endpoints  
**Description:** Upstream supports applying hints (override port, user, description) to discovered endpoints via glob patterns.  
**Why it matters:** Discovered endpoints may need user-specific metadata applied  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Endpoint Hints: Apply glob-based hints to discovered endpoints"  
**Implementation ideas:**
- Add `hints` section to config file
- Apply hint overrides when building endpoint list
**Estimated complexity:** Low — config processing
**Expected impact:** Enables customization of discovered endpoints

### 10. Team/Shared Config Support
**Title:** Support team-level shared configuration  
**Description:** `~/.config/wishlist.yml` is per-user. No mechanism exists for sharing configs across a team.  
**Why it matters:** Teams managing shared infrastructure may want centralized endpoint definitions  
**Source:** `docs/repo_map/sugarcraft_sugar-wishlist.md` — "Single-user only — No mechanism for sharing configs across a team"  
**Implementation ideas:**
- Support loading from environment variable or CLI flag pointing to shared config
- Merge shared config with user config (user overrides shared)
**Estimated complexity:** Low — config loading logic
**Expected impact:** Team collaboration enablement

## Low Priority

### 11. Prometheus Metrics
**Title:** Optional metrics endpoint for SSH server mode  
**Description:** Upstream provides optional `/metrics` endpoint via `promwish`.  
**Why it matters:** Production deployments benefit from metrics observability  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Prometheus Metrics: Optional `/metrics` endpoint"  
**Implementation ideas:** Deferred until SSH-server mode is implemented  
**Expected impact:** Operational visibility

### 12. Man Page Generation
**Title:** Built-in man page via `man` subcommand  
**Description:** Upstream has a `man` subcommand via `mango-cobra`.  
**Why it matters:** CLI discoverability and documentation  
**Implementation ideas:** Use a PHP man page generator or embed pre-generated man page  
**Expected impact:** CLI discoverability

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### 1. Picker Rendering: Line-by-Line vs Cell-Based Buffer

**Current (sugar-wishlist):**
- Direct ANSI escape sequences written to STDOUT
- Full redraw on every keypress
- No cell-based buffer or diffing

**External approach (charmbracelet/bubbletea + ultraviolet):**
- Cell-based `ScreenBuffer` with per-cell styling
- Delta rendering: only changed lines are re-sent to terminal
- Synchronized output mode (ANSI 2026) for flicker-free updates

**Why external is better:**
- Reduces terminal I/O for large endpoint lists
- Enables partial updates (cursor movement without full redraw)
- ANSI 2026 synchronized mode eliminates flicker

**Tradeoffs:**
- Significant complexity increase for a single-purpose widget
- The picker is intentionally minimal (~40 lines) by design

**Applicability:** Low — the picker is fire-and-forget; complexity not justified

### 2. SSH Config Parsing: Hand-Rolled vs Lexer-Based

**Current (sugar-wishlist):**
- Stateful line-by-line parser
- Tracks `$globalOptions`, `$hostBlocks`, `$inGlobalBlock`
- First-match-wins semantics

**External approach (charmbracelet/wishlist sshconfig):**
- Thread-safe `hostinfoMap` with ordered keys
- Handles `Include` directives with recursive globbing
- Left-biased `mergeHostinfo` for host merging

**Why external is better:**
- Handles `Include` directives (critical for real-world configs)
- Thread-safe for concurrent access
- Proper path resolution for included files

**Applicability:** High — the `Include` directive support is a real gap

### 3. TUI Framework: Hand-Rolled Picker vs Full Elm Architecture

**Current (sugar-wishlist):**
- Single `while(true) { draw(); readKey(); switch() }` loop
- No message passing, no command pattern, no subscriptions
- Renders directly to STDOUT

**External approach (charmbracelet/bubbletea):**
- Elm architecture: `Model` → `Update(Msg)` → `View()`
- `Cmd` pattern for async operations returning messages
- Subscription system for time-based events

**Why external is better:**
- Enables composable, testable, predictable state management
- Commands can be cancelled, batched, sequenced
- Program can be tested in isolation from terminal I/O

**Tradeoffs:**
- Full Elm architecture is overkill for a fire-and-forget picker
- sugar-wishlist correctly chose minimalism for v1

**Applicability:** Low for v1, but reference for future SSH-server mode where full TUI framework would be needed

### 4. Layout: Manual ANSI Positioning vs Constraint Solver

**Current (sugar-wishlist):**
- Hardcoded cursor positions via ANSI escape sequences
- No layout algorithm

**External approach (php-tui/php-tui):**
- Cassowary constraint solver for flex-style layouts
- Responsive to terminal resize

**Why external is better:**
- Automatic layout adaptation to terminal size
- Removes hardcoded dimensions

**Applicability:** Low — picker has simple, fixed layout

---

# Architecture Improvements

## 1. Extract `SshConfigParser` to Standalone Library

**Current state:** `SshConfigParser` is a private class within sugar-wishlist  
**Improvement:** Extract to `candy-sshconfig` leaf library

**Rationale:** SSH config parsing is complex infrastructure (first-match-wins semantics, `Host *` defaults, `Include` directives, `~` expansion) that other tools could benefit from. The `charmbracelet/wishlist` sshconfig parser is a separate sub-package precisely because it's independently useful.

**Implementation:**
```php
// sugar-wishlist composer.json would change from:
// "require": { "sugarcraft/candy-core": "..." }
// to:
// "require": { "sugarcraft/candy-sshconfig": "..." }
```

**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "SugarCraft does not yet have a dedicated SSH config parsing library. A `candy-sshconfig` leaf lib would be the natural home for this."

## 2. Add Programmatic API for Frame Streaming

**Current state:** `Picker::pick()` renders directly to STDOUT and blocks until completion  
**Improvement:** Add an alternative method returning rendered frames as strings

**Rationale:** Enables testing without stream interception, programmatic use in non-TTY contexts, and future SSH-server mode integration where the TUI needs to be served over SSH.

**Implementation ideas:**
```php
interface PickerOutput {
    public function frame(): string;  // Render current state
    public function handleInput(string $key): ?Endpoint;  // Process key, return null to continue
}

// Usage:
$output = new StringPickerOutput();
$picker = new Picker($endpoints, $output);
$result = $picker->run();  // Returns ?Endpoint
$frames = $output->getFrames();  // For testing or streaming
```

**Source reference:** `charmbracelet/bubbletea.md` — "tea.Program.Send()" for injecting messages from outside

## 3. Introduce Optional Dependency on sugar-bits for List Widget

**Current state:** Hand-rolled picker not using sugar-bits `List` component  
**Improvement:** Consider using `sugar-bits` `List` widget for the endpoint picker

**Rationale:** The sugar-bits `List` provides full keyboard navigation, filtering, and rendering already. Using it would reduce maintenance burden and improve consistency with the rest of the ecosystem.

**Tradeoff:** The current minimal picker is ~40 lines; using sugar-bits would pull in more dependencies and add complexity for a use case that doesn't need full list functionality.

**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "uses a hand-rolled single-purpose picker (40 lines of ANSI rendering) to avoid pulling in the full SugarBits component library"

---

# API / Developer Experience Improvements

## 1. Fluent Endpoint Builder

**Current:** `Endpoint` has `withIdentityFiles()` and `withProxyJump()` fluent helpers  
**Improvement:** Add more fluent setters for all optional fields

**Current limitation:** No way to create an `Endpoint` with `description` or `options` via fluent API

**Implementation:**
```php
$endpoint = Endpoint::new()
    ->withName('production')
    ->withHost('prod.example.com')
    ->withPort(2222)
    ->withUser('deploy')
    ->withIdentityFiles(['~/.ssh/prod'])
    ->withDescription('Production server')
    ->withProxyJump('bastion.example.com')
    ->withOptions(['ServerAliveInterval=30']);
```

## 2. Better Error Messages for Config Parsing

**Current:** Config parsing errors may be opaque  
**Improvement:** Add context to parsing errors (file path, line number)

**Implementation:**
```php
throw new ConfigParseException(
    "Failed to parse config: {$path}",
    0,
    new \Exception("YAML parse error at line 15: unexpected mapping")
);
```

## 3. SSH Config Import with Dry Run

**Current:** `Config::importFromSshConfig()` directly returns endpoints  
**Improvement:** Add a method to preview what would be imported

```php
public static function previewSshConfigImport(string $path): array<SshConfigPreview>;
// where SshConfigPreview has: host, name, source, warnings
```

---

# Documentation / Cookbook Opportunities

## 1. Recipe: Multi-Environment SSH Management

**Content:** Guide showing how to use sugar-wishlist with development/staging/production environments using separate configs and shell aliases.

## 2. Recipe: Tailscale Integration

**Content:** Guide for using sugar-wishlist with Tailscale to access endpoints via tailnet addresses rather than IP addresses.

## 3. Recipe: Team-Wide Wishlist

**Content:** Guide for setting up a shared wishlist config on a network file system or git repo, with per-user overrides.

## 4. API Documentation for Programmatic Use

**Current:** README shows basic programmatic use  
**Improvement:** Add detailed API documentation for `Config`, `Picker`, `Launcher`, `Endpoint` with parameter descriptions and return types.

---

# UX / TUI Improvements

## 1. Visual Indicator for Selected Identity File

**Current:** Only one identity file shown in picker line  
**Improvement:** Show indicator when multiple identity files are available

## 2. Connection Status Indicator

**Current:** No visual feedback during connection establishment  
**Improvement:** Show brief "Connecting..." state before `pcntl_exec`

**Source reference:** `charmbracelet/bubbletea.md` — "Spinner" component for async state indication

## 3. Fuzzy Match Highlighting

**Current:** Filter matches case-insensitively but doesn't highlight the match  
**Improvement:** Highlight matched characters in filter results

**Source:** `charmbracelet/lipgloss.md` — "StyleRunes" for applying different styles to specific rune indices

## 4. Mouse Support for Endpoint Selection

**Current:** Keyboard-only navigation  
**Improvement:** Add mouse click to select, scroll to navigate

**Source:** `charmbracelet/bubbletea.md` — "Mouse Input: Click, release, wheel, and motion events with SGR extended mode"

---

# Testing / Reliability Improvements

## 1. Property-Based Testing for Config Parsing

**Current:** Snapshot/coercion tests for config parsing  
**Improvement:** Add property-based tests using `phpunit-quickcheck` or similar to test random valid configs

**Source reference:** `charmbracelet/bubbletea.md` — "Comprehensive golden/snapshot tests"

## 2. Integration Test for Full SSH Lifecycle

**Current:** 69 tests but no end-to-end SSH session test  
**Improvement:** Add integration test that starts an SSH server, connects via wishlist, and verifies session

**Challenge:** Requires `sshd` running, which may not be available in all CI environments

**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "No integration tests verifying end-to-end SSH session lifecycle"

## 3. Fuzzy Match Algorithm Testing

**Current:** Basic substring match testing  
**Improvement:** Test edge cases: Unicode hostnames, special characters in names, very long filter strings

## 4. Stream-Based Picker Testing

**Current:** Uses `php://memory` streams with `setRawMode()` override  
**Improvement:** After implementing programmatic frame API, test by capturing frame sequence

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-discovery (Future)

**Description:** When `sugar-discovery` (Zeroconf/SRV/Tailscale) is implemented, integrate it into sugar-wishlist for dynamic endpoint discovery.

**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Discovery mechanisms are service-announcement patterns. Could be a `sugar-discovery` leaf lib"

## 2. Integration with candy-shell (SSH Server)

**Description:** For SSH-server mode, integrate `candy-shell` (port of `charmbracelet/wish`) to serve the TUI over SSH.

**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "SugarCraft ports `charmbracelet/ssh` and `charmbracelet/wish` in `candy-shell`"

## 3. VSCode Remote SSH Integration Pattern

**Description:** Document how sugar-wishlist complements VSCode Remote SSH workflow — users can use wishlist for quick connections and VSCode for long-lived sessions.

## 4. Shell Completion Generation

**Description:** Add shell completion scripts for bash/zsh/fish for the `wishlist` binary.

**Source:** `charmbracelet/gum.md` — "Shell Completion" — Charmbracelet projects often include completion generation

---

# Notable PRs / Issues / Discussions

## charmbracelet/wishlist

### PR: STDIN Multiplexing Implementation
**Summary:** The `multiplex.Reader()` pattern for forking STDIN during TUI handoff to SSH session.  
**Relevance:** This is a critical pattern for SSH-server mode in sugar-wishlist. When serving the TUI over SSH and the user selects an endpoint, STDIN needs to be released from the TUI and handed to the SSH session.  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "STDIN Multiplexing: `multiplex.Reader()` forks the SSH session's STDIN into two independent readers"

### Issue: Auto-Accept Host Keys Security Concern
**Summary:** Upstream's `hostKeyCallback()` auto-accepts unknown host keys rather than prompting or failing.  
**Relevance:** sugar-wishlist uses the system `ssh` binary, which handles host keys natively. This is actually a strength — sugar-wishlist inherits SSH's security model rather than implementing its own (potentially flawed) one.  
**Lesson:** For SSH-server mode, follow the native SSH approach rather than auto-accepting.

### Discussion: Tailscale OAuth vs API Keys
**Summary:** Upstream prefers OAuth client credentials over static API keys for Tailscale, noting API keys expire in 90 days.  
**Relevance:** If Tailscale discovery is implemented, follow this best practice.  
**Source:** `docs/repo_map/charmbracelet_wishlist.md` — "Tailscale OAuth: Using OAuth client credentials instead of expiring API keys is a best practice"

## charmbracelet/wish

### Pattern: Middleware Composition
**Summary:** `wish.Middleware` type `func(next ssh.Handler) ssh.Handler` with first-to-last execution ordering.  
**Relevance:** Reference for how to structure middleware when implementing SSH-server mode.  
**Source:** `docs/repo_map/charmbracelet_wish.md` — "Middleware Pipeline Composition"

### Pattern: Bubble Tea over SSH Per-Session
**Summary:** Each SSH session spawns its own `tea.Program` in a goroutine with window-resize forwarding.  
**Relevance:** Reference for how to wire Picker into SSH session I/O for server mode.

---

# Recommended Roadmap

## Immediate Wins (Low Complexity, High Impact)

1. **Multiple identity files** — Fix `toSshArgv()` to emit all identity files
2. **Theme detection** — Use `candy-palette` to detect light/dark background and adapt colors
3. **Config file watching** — Use `inotify`/`FSEvents` to watch config and reload on change
4. **SSH config `Include` directive** — Add `Include` parsing to `SshConfigParser`

## Medium-Term Improvements

5. **SSH-server mode** — Integrate `candy-shell` to serve Picker over SSH
6. **Extract `candy-sshconfig`** — Refactor `SshConfigParser` into standalone leaf library
7. **Fuzzy match highlighting** — Highlight matched characters in filter results
8. **Mouse support** — Add click to select, scroll to navigate
9. **Programmatic frame API** — Add `PickerOutput` interface for frame streaming

## Major Architectural Upgrades

10. **Dynamic discovery** — Implement `sugar-discovery` with Zeroconf/SRV/Tailscale support
11. **Full sugar-bits integration** — Replace hand-rolled picker with `List` widget from sugar-bits
12. **Broadcast relay** — Add endpoint refresh/broadcast for SSH-server mode

## Experimental Ideas

13. **Web-based TUI** — Serve wishlist picker in a browser (inspired by `textualize/textual` web driver)
14. **Prometheus metrics** — Add metrics endpoint for SSH-server mode observability
15. **SSH config `Match` block support** — Parse and evaluate `Match` conditions

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|------------|--------|------------|------|---------|
| Multiple identity files | High | Low | Low | **Immediate** |
| Theme detection | Medium | Low | Low | **Immediate** |
| SSH config `Include` | High | Medium | Low | **Immediate** |
| Config file watching | Medium | Medium | Low | **Immediate** |
| Fuzzy match highlighting | Medium | Low | Low | **Medium** |
| Mouse support | Medium | Low | Low | **Medium** |
| Programmatic frame API | Medium | Medium | Low | **Medium** |
| Extract candy-sshconfig | Medium | Medium | Medium | **Medium** |
| SSH-server mode | Critical | High | High | **Long-term** |
| Dynamic discovery | High | High | Medium | **Long-term** |
| Full sugar-bits integration | Medium | Medium | Low | **Long-term** |
| Broadcast relay | Medium | High | High | **Long-term** |
| SSH config `Match` blocks | Low | High | Medium | **Deferred** |
| Web-based TUI | Low | Very High | High | **Experimental** |
| Prometheus metrics | Low | Medium | Low | **Deferred** |

---

# Final Strategic Assessment

sugar-wishlist is a well-scoped, well-implemented v1 port of `charmbracelet/wishlist`'s local-CLI mode. Its most important architectural decision — using `pcntl_exec` + system `ssh` rather than a PHP SSH library — is the right call for a tool in this category. It means PHP never touches the SSH protocol, so all SSH semantics (host-key verification, agent forwarding, PTY allocation, escape sequences) work exactly as they would for a manual `ssh` invocation.

The **single most important gap** relative to upstream is the lack of **SSH-server mode** — the ability to serve the TUI over SSH so users can connect remotely. This is the primary use case that distinguishes wishlist from a simple shell alias. The architecture is cleanly structured so this could be added later using `candy-shell` (for SSH serving), but the dependency chain is non-trivial.

The **most actionable immediate improvements** are:
1. **Multiple identity files** — trivial fix, real workflow impact
2. **SSH config `Include` directive** — enables adoption for users with modular SSH configs
3. **Theme detection** — quick win for accessibility

The **`SshConfigParser`** is the most substantial piece of infrastructure and could be extracted into a standalone `candy-sshconfig` leaf library. The parsing logic (first-match-wins semantics, `Host *` defaults, `~` expansion) is complex enough that other projects would benefit from a shared implementation.

The **discovery features** (Zeroconf, DNS SRV, Tailscale) represent significant capability expansion but require substantial implementation effort and are correctly deferred to a future `sugar-discovery` library.

Overall, sugar-wishlist v1 achieves its goal: a fast, focused, interactive SSH endpoint picker backed by YAML/JSON/OpenSSH config. The deferred features are clearly marked, the architecture supports future extension, and the 69-test suite provides confidence in the current implementation. For v1.1, focusing on the "immediate wins" above would deliver meaningful improvement without architectural risk.
