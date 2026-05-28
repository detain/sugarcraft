# SugarCraft/sugar-wishlist

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-wishlist
- **Language:** PHP 8.3+
- **Status:** 🟢 v1 ready
- **License:** MIT
- **Composer:** `sugarcraft/sugar-wishlist`
- **Namespace:** `SugarCraft\Wishlist`
- **Upstream:** `charmbracelet/wishlist` (Go, ~700 stars)
- **Depends on:** `sugarcraft/candy-core`, `sugarcraft/sugar-bits`, `sugarcraft/candy-pty`, `sugarcraft/candy-sprinkles`, `sugarcraft/candy-forms`, `sugarcraft/candy-zone`, `sugarcraft/honey-bounce`, `sugarcraft/candy-palette`

---

## Feature List

### Core Features
- **TUI-based SSH endpoint picker** — Interactive terminal UI listing configured SSH endpoints with selection, filtering, and connection
- **Dual-mode operation**:
  - **Local mode**: Browse and connect to endpoints from YAML/JSON config or imported OpenSSH config
  - **SSH-server TUI mode**: (architecture in place; full server mode deferred to future phase)
- **Multi-format configuration**: Supports YAML (`wishlist.yml`), JSON (`wishlist.json`), and raw OpenSSH `~/.ssh/config`
- **ProxyJump support**: Connects through bastion hosts via `-J` flag
- **Agent forwarding**: Compatible with SSH agent forwarding via standard OpenSSH mechanisms
- **Identity file selection**: Supports multiple identity files via `-i` flag
- **OpenSSH config import**: Parses `~/.ssh/config` and maps `Host`, `HostName`, `User`, `Port`, `IdentityFile`, `ProxyJump` directives
- **SSH options passthrough**: Arbitrary `-o KEY=VALUE` options from YAML/JSON config
- **Type-to-filter**: Real-time fuzzy filtering as user types
- **Keyboard navigation**: j/k, arrow keys, Enter, Esc, Ctrl-C
- **i18n**: Full translation support with 16 locales (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)

---

## Architecture

### Source Files (6 classes)

| File | Class | Responsibility |
|------|-------|---------------|
| `src/Endpoint.php` | `Endpoint` | Immutable value object representing one SSH destination |
| `src/Config.php` | `Config` | YAML/JSON loader + OpenSSH config importer |
| `src/Picker.php` | `Picker` | Terminal UI: ANSI render + key input + filtering |
| `src/Launcher.php` | `Launcher` | `pcntl_exec`-based process replacement with ssh |
| `src/SshConfigParser.php` | `SshConfigParser` | OpenSSH config file parser |
| `src/Lang.php` | `Lang` | i18n facade extending `SugarCraft\Core\I18n\Lang` |

### Execution Flow

```
bin/wishlist
  └── Config::load($cfgPath)          ← parse YAML/JSON OR
  └── Config::importFromSshConfig()    ← parse ~/.ssh/config
  └── new Picker()->pick($endpoints)  ← render TUI, read keys, filter
  └── new Launcher()->dispatch($ep)    ← pcntl_exec /usr/bin/ssh [args]
```

The critical line is `pcntl_exec($argv[0], array_slice($argv, 1))` in `Launcher::dispatch()`. This **replaces** the PHP process with `ssh`. File descriptors, environment, and the controlling TTY all flow through unchanged — the user sees a normal `ssh` session (host-key prompts, agent forwarding, MOTD, exit status). sugar-wishlist never proxies bytes.

### Config Parsing Architecture

**File: `src/Config.php`**

Two distinct parsing paths, auto-detected by file extension or content heuristic:

1. **JSON path** — `json_decode()` on the raw string; top-level MUST be an array of objects; each object maps to one `Endpoint` via `buildEndpoint()`.

2. **YAML-ish flat-list path** — A hand-rolled parser (`parseYaml()`) that handles the documented subset:
   - `- key: value` entry headers
   - 2-space-indented continuation keys (`host:`, `port:`, `user:`, etc.)
   - 4-space-indented `- value` list items under a value-less key (used for `options:` lists)
   - `#` line comments
   - Scalar type coercion: integers, booleans (`true`/`false`/`yes`/`no`), null (`~`/`null`), quoted strings

   The YAML parser is intentionally minimal — `ext-yaml` is NOT a dependency. The library only needs a flat list of entry blocks; no anchors, multi-doc, or nested mappings.

3. **OpenSSH config path** — Delegates to `SshConfigParser::parse()`, then wraps results via `Config::importFromSshConfig()`.

### SSH Config Parser Architecture

**File: `src/SshConfigParser.php`**

A stateful line-by-line parser that tracks:
- `$globalOptions` — options from `Host *` blocks (inherited by all subsequent hosts)
- `$hostBlocks` — ordered list of per-host option maps
- `$inGlobalBlock` — flag indicating whether current block is `Host *`

Per SSH config semantics (first-match-wins), blocks are stored in declaration order. When building endpoints, `$globalOptions` is merged with per-host options (host-specific wins). `~` in `IdentityFile` paths is expanded via `getenv('HOME')` with `posix_getpwuid` fallback.

Supported keywords: `Host`, `HostName`, `User`, `Port`, `IdentityFile`, `ProxyJump`.

Ignored keywords (intentionally): `Match`, `Include`, `Set`, `SendEnv`, `ForwardAgent`, `ServerAliveCountMax`, `ServerAliveInterval`, `StrictHostKeyChecking`, `UserKnownHostsFile`, etc.

### Picker Architecture

**File: `src/Picker.php`**

A lightweight, single-purpose TUI widget (not a full SugarBits `List`). Key design decisions:

- **No full event loop** — Uses a simple `while(true)` loop with blocking `fread()` on STDIN. Directly draws to STDOUT via ANSI escape sequences.
- **Raw mode via `candy-core` `RawMode`** — Delegates terminal raw-mode setup to `SugarCraft\Core\Util\RawMode::enable/disable()`, which is a portable no-op on non-tty streams (enabling testability).
- **ANSI rendering** — Uses `SugarCraft\Core\Util\Ansi` for `cursorTo()`, `eraseToEnd()`, `sgr()`, `reset()`.
- **CSI sequence parsing** — `readKey()` handles ESC-prefixed CSI sequences for arrow keys (`ESC [ A/B/C/D`) with non-blocking retry to avoid hanging on a lone ESC byte.
- **Filter matching** — Case-insensitive `str_contains` across `name + host + user` fields.
- **Testable via stream override** — Constructor accepts nullable `$in`/`$out` resources; tests use `php://memory` streams with a `setRawMode()` override for in-process testing.

### Endpoint Value Object

**File: `src/Endpoint.php`**

Immutable (`final` class, `readonly` properties). Fields:

| Field | Type | Purpose |
|------|------|---------|
| `name` | `string` | Display name in picker |
| `host` | `string` | Hostname/IP |
| `port` | `int` | SSH port (default 22) |
| `user` | `?string` | SSH user |
| `identityFiles` | `list<string>` | Identity file paths |
| `description` | `?string` | Optional descriptive text |
| `proxyJump` | `?string` | Bastion host for `-J` flag |
| `options` | `list<string>` | Extra `-o KEY=VALUE` strings |

`toSshArgv()` builds the argument array suitable for `pcntl_exec()`. `displayLine()` formats the picker line: `name  ─  user@host:port`.

`withIdentityFiles()` and `withProxyJump()` are fluent `with*()` helpers returning new instances (immutable pattern).

### Launcher

**File: `src/Launcher.php`**

Uses `pcntl_exec()` to replace the PHP process. The `$executor` callable allows tests to intercept the call without actually exec'ing. On success, this method never returns. On failure (binary not found), throws `RuntimeException`.

### i18n

**File: `src/Lang.php`** — Extends `SugarCraft\Core\I18n\Lang` with `NAMESPACE = 'wishlist'` and `DIR = __DIR__ . '/../lang'`.

**File: `lang/en.php`** — Source-of-truth English strings (27 keys covering config errors, launcher errors, CLI messages).

**File: `lang/{locale}.php`** — 16 additional locales.

---

## Discovery System

sugar-wishlist does **not** implement dynamic service discovery (Zeroconf/DNS SRV/Tailscale) in v1. The upstream `charmbracelet/wishlist` includes these features but they have been deferred in the SugarCraft port.

**Upstream discovery features** (not yet ported):
- `_ssh._tcp` Zeroconf/mDNS/Bonjour browsing via `grandcat/zeroconf`
- DNS SRV record discovery via `srv.Endpoints()`
- Tailscale tailnet device discovery via API (OAuth client credentials preferred over static API keys)

If these are added, they would live in a future `sugar-discovery` leaf library.

---

## SSH Integration

### How it uses candy-wish

sugar-wishlist is a **consumer** of `candy-wish` (the SugarCraft port of `charmbracelet/wish`). However, v1 of sugar-wishlist does NOT run an SSH server — it runs as a local CLI tool that launches `ssh` as a subprocess via `pcntl_exec`.

The `candy-wish` dependency exists for potential future SSH-server TUI mode (`ssh user@host` to connect and see the wishlist picker over SSH). In v1, the `candy-pty` dependency is used only for `RawMode` support in the picker.

### Process replacement vs proxying

The key architectural decision: **do not proxy SSH bytes**. Instead, `pcntl_exec` replaces PHP entirely with the real `ssh` binary. This means:
- Full host-key prompting works natively
- Agent forwarding works natively
- TTY / PTY allocation works natively
- Exit status propagates correctly

This is architecturally cleaner than a PHP-based SSH proxy, but means sugar-wishlist cannot introspect or log the SSH session itself.

---

## Tests

**69 tests / 176 assertions** (per README), covering:

| Test File | What it covers |
|----------|---------------|
| `EndpointTest.php` | `toSshArgv()` for all flag combinations; `displayLine()` formatting |
| `EndpointIdentityFilesTest.php` | Identity file list handling; `withIdentityFiles()` immutability |
| `EndpointProxyJumpTest.php` | `-J` flag for all proxy jump configurations; `withProxyJump()` immutability |
| `ConfigTest.php` | JSON parse; YAML parse (flat list, comments, scalar coercion, nested lists, identityFile snake/camel); sample-wishlist.yml round-trip; error cases |
| `SshConfigParserTest.php` | Empty config; single/multiple hosts; global defaults inheritance; per-host override; pattern-as-hostname; multiple IdentityFiles; comments; `Host *` no-emit |
| `ConfigImportSshConfigTest.php` | Missing file; empty file; single/multiple endpoints; ProxyJump; global then per-host |
| `PickerTest.php` | Enter picks first; j/k navigation; Esc/Ctrl-C returns null; type-to-filter; backspace; arrow keys; empty list |
| `PickerDescriptionTest.php` | Description field rendering in picker |
| `LauncherTest.php` | Correct argv passed to executor; custom ssh binary; default port 22 omission; no-user defaults |

---

## Comparison with Upstream

### What sugar-wishlist preserves from charmbracelet/wishlist

| Upstream Feature | sugar-wishlist Status |
|-----------------|----------------------|
| YAML config loading | ✅ Implemented (hand-rolled flat-list parser) |
| JSON config loading | ✅ Implemented (`json_decode`) |
| SSH config import (`~/.ssh/config`) | ✅ Implemented (`SshConfigParser`) |
| Type-to-filter picker UI | ✅ Implemented |
| j/k and arrow key navigation | ✅ Implemented |
| ProxyJump (`-J`) | ✅ Implemented |
| Identity files (`-i`) | ✅ Implemented |
| SSH options passthrough (`-o`) | ✅ Implemented |
| pcntl_exec process replacement | ✅ Implemented |
| i18n (16 locales) | ✅ Implemented (upstream has no i18n) |
| ANSI rendering via candy-core | ✅ Implemented (upstream uses lipgloss) |

### What is deferred/not implemented

| Upstream Feature | Reason for deferral |
|----------------|---------------------|
| **SSH-server mode** (serve TUI over SSH) | Requires full `candy-wish` SSH server integration; v1 scope is local CLI |
| **Zeroconf discovery** | Would need a `sugar-discovery` leaf lib; not in v1 scope |
| **DNS SRV discovery** | Same as above |
| **Tailscale discovery** | Same as above; also requires OAuth2 client credentials handling |
| **Dynamic endpoint refresh** (broadcast relay) | No SSH server mode means no need for in-flight endpoint updates |
| **STDIN multiplexing** | Not needed for local CLI mode |
| **Host key auto-accept** | Uses native ssh binary behavior; sugar-wishlist never touches host keys |
| **Lipgloss styling** | Uses `SugarCraft\Core\Util\Ansi` directly (PHP terminal rendering, not lipgloss port) |
| **Prometheus metrics** | Not applicable without SSH server mode |
| **Authorized users allowlist** | Not applicable without SSH server mode |

### Key architectural differences from upstream

1. **Process model**: Upstream `charmbracelet/wishlist` uses `golang.org/x/crypto/ssh` for in-process SSH connections. sugar-wishlist uses `pcntl_exec` to hand off to the system `ssh` binary. This is a philosophical difference — upstream gets full programmatic SSH control; sugar-wishlist gets zero-knowledge simplicity and 100% fidelity with manual `ssh` usage.

2. **TUI framework**: Upstream uses `charmbracelet/bubbletea` with `bubbles/list`. sugar-wishlist uses a hand-rolled single-purpose picker (40 lines of ANSI rendering) to avoid pulling in the full SugarBits component library for a one-shot use case.

3. **No intermediate network layer**: Upstream maintains live SSH connections. sugar-wishlist simply selects an endpoint and execs — no persistent state, no connection pooling.

4. **i18n**: sugar-wishlist has 16 locales; upstream has no i18n at all (intentionally — it's a developer tool with English-only output).

---

## Strengths

1. **Zero-dependency SSH fidelity** — Using `pcntl_exec` + system `ssh` means every SSH feature (hkey prompts, agent forwarding, GSSAPI, keyboard-interactive, escape sequences, etc.) works without any PHP SSH library. The PHP code just selects which arguments to pass.

2. **Minimal, focused design** — The picker is not a general-purpose List widget — it's exactly what the job needs and nothing more. The `while(true) { draw(); readKey(); switch() }` loop is ~40 lines of code.

3. **Excellent test coverage** — 69 tests covering all public methods; snapshot-style output assertions for picker; coercion tests for config parsing; SSH config parsing covers all keyword types.

4. **Hand-rolled YAML parser** — Avoiding `ext-yaml` as a dependency for what is effectively a flat-list format is a good trade-off. The parser handles exactly what the config schema needs.

5. **i18n by default** — 16 locales shipped; translation infrastructure via `SugarCraft\Core\I18n\T` is consistent with the rest of SugarCraft.

6. **Real SSH config import** — The `SshConfigParser` properly handles `Host *` global defaults, per-host overrides, `~` path expansion, and comment stripping — matching actual OpenSSH behavior.

7. **Portable raw mode** — `RawMode::enable()` from candy-core handles non-tty streams gracefully (no-op), making the Picker testable without overriding.

8. **Clean immutability** — `Endpoint` is a true immutable value object with `readonly` properties and `with*()` factory methods returning new instances.

---

## Weaknesses

1. **No SSH-server mode** — The most significant missing feature vs upstream. The architecture is cleanly separated so adding it later would be feasible, but v1 is local-CLI-only.

2. **No dynamic discovery** — Zeroconf, DNS SRV, and Tailscale are all deferred. For users with dynamic infrastructure (Docker, Kubernetes, Tailscale), a static config file is a step backward from upstream.

3. **Single identity file** — `toSshArgv()` only uses `$identityFiles[0]` when building the argv. SSH supports multiple `-i` flags; sugar-wishlist only passes the first. (The `Endpoint` model stores the full list, but `toSshArgv()` only emits one.)

4. **No config file watching** — Config is loaded once at startup. Upstream can refresh and broadcast endpoint updates to connected clients.

5. **TTY assumption** — While `RawMode` is a safe no-op on non-tty streams, the overall design assumes a terminal context. There is no programmatic API that returns a stream of rendered frames (unlike bubbletea's `tea.Program`).

6. **No color scheme adaptation** — The ANSI color codes (SGR 36 for cyan, SGR 1 for bold) are hardcoded. Upstream uses `lipgloss.AdaptiveColor` for light/dark theme adaptation.

7. **No `Include` directive support in SSH config parser** — The `SshConfigParser` ignores `Include` directives. Real OpenSSH configs often use `Include` to pull in host-specific config snippets.

8. **Single-user only** — `~/.config/wishlist.yml` is per-user. No mechanism for sharing configs across a team.

9. **No SSH config `Match` block support** — The parser only handles `Host` blocks. `Match` blocks (which can conditionally apply config based on user, host, etc.) are completely ignored.

---

## Related Third-Party Repos

### `charmbracelet/wishlist` (primary upstream — 700 stars)
Full-featured Go SSH directory with local CLI mode + SSH server mode. sugar-wishlist v1 ports the local CLI mode only. The upstream also provides Zeroconf/DNS SRV/Tailscale discovery, which are deferred. See full analysis at `repo_map/charmbracelet_wishlist.md`.

### `charmbracelet/wish` (SSH middleware framework — 5,233 stars)
The SSH server framework that upstream wishlist builds on. sugar-wishlist does not use `candy-wish` in v1 (local CLI mode doesn't need an SSH server). The `candy-wish` port exists independently. See full analysis at `repo_map/charmbracelet_wish.md`.

### `charmbracelet/charm` (cloud infrastructure — 2.4k stars)
Provides SSH-based authentication, encrypted KV store, and cloud filesystem. Not directly related to wishlist's endpoint management use case. See full analysis at `repo_map/charmbracelet_charm.md`.

### `candy-wish` (SugarCraft port of charmbracelet/wish)
SSH server middleware framework. sugar-wishlist declares it as a dependency (via `candy-core`, `candy-pty`) but does not invoke its SSH server capabilities in v1. The dependency exists for future SSH-server TUI mode.

---

## Analysis

**sugar-wishlist** is a clean, well-scoped v1 port of the local-CLI portion of `charmbracelet/wishlist`. Its most important architectural decision — using `pcntl_exec` + system `ssh` rather than a PHP SSH library — is the right call for a tool in this category. It means PHP never touches the SSH protocol, so all SSH semantics (host-key verification, agent forwarding,PTY allocation, escape sequences) work exactly as they would for a manual `ssh` invocation.

The SSH config parser (`SshConfigParser`) is the most substantial piece of infrastructure — it properly handles OpenSSH's first-match-wins semantics, global `Host *` defaults, and `~` path expansion. This could be extracted into a standalone `candy-sshconfig` leaf library if other projects need SSH config parsing.

The picker is deliberately minimal — a single `while(true) { draw(); readKey(); switch() }` loop with hand-rolled ANSI rendering rather than a full SugarBits `List` component. This is the right trade-off: the picker is a fire-and-forget widget that immediately exits via `pcntl_exec`; wiring in the full SugarBits event loop would be unnecessary complexity.

The main gap vs upstream is the lack of SSH-server mode and dynamic service discovery. The architecture is cleanly structured so these could be added later using `candy-wish` (for SSH serving) and a future `sugar-discovery` leaf library (for Zeroconf/SRV/Tailscale). The deferred features are clearly marked in this analysis.

The 69-test / 176-assertion suite provides strong coverage of all public APIs, with particular attention to edge cases in config parsing (empty files, missing fields, type coercion, comment stripping) and SSH argv construction (port 22 default, identity file precedence, ProxyJump ordering).

For v1, sugar-wishlist is a solid, focused implementation that does exactly what it says: provides an interactive picker for SSH endpoints backed by YAML/JSON/OpenSSH config, then hands off cleanly to the system SSH binary.
