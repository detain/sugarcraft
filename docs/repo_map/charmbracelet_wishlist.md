# charmbracelet/wishlist

## Metadata
- **URL:** https://github.com/charmbracelet/wishlist
- **Language:** Go
- **Stars:** ~700 (estimated from GitHub activity; not confirmed via API)
- **License:** MIT
- **Description:** The SSH Directory — a TUI-based SSH client that serves as a unified entry point for multiple SSH endpoints, whether they are Wish (bubbletea/SSH) apps or standard SSH servers. It can list and connect to servers from `~/.ssh/config` or a YAML configuration file, and can also serve a TUI over SSH itself.

---

## Feature List

### Core Features
- **TUI-based SSH Directory**: Interactive terminal UI listing all configured SSH endpoints with selection, filtering, and connection.
- **Dual-Mode Operation**:
  - **Local mode**: Browse and connect to endpoints from `~/.ssh/config` or a YAML config without serving anything.
  - **Server mode**: Serve the TUI over SSH, allowing users to SSH in and see the same interactive listing.
- **Multi-Format Configuration**: Supports both standard OpenSSH `~/.ssh/config` format and YAML configuration files.
- **SSH Agent Forwarding**: Supports SSH agent forwarding for authentication.
- **ProxyJump/Bastion Support**: Connects through intermediate hosts via SSH ProxyJump.
- **Endpoint Discovery**:
  - DNS SRV record discovery
  - Zeroconf/mDNS/Avahi/Bonjour discovery (`_ssh._tcp` services)
  - Tailscale tailnet device discovery (with API key or OAuth client credentials)
- **Dynamic Endpoint Updates**: Server can refresh endpoints on a configurable interval and push updates to connected clients via a broadcast relay.
- **Endpoint Hints**: Apply glob-based hints to discovered endpoints (override port, user, description, etc.).
- **SSH Options Support**: Supports `User`, `Hostname`, `Port`, `IdentityFiles`, `ForwardAgent`, `RequestTTY`, `RemoteCommand`, `SendEnv`, `SetEnv`, `ConnectTimeout`, `Include`, `PreferredAuthentications`, `ProxyJump`.
- **Public Key Access Control**: Optional user allowlist with authorized public keys for server mode.
- **Prometheus Metrics**: Optional `/metrics` endpoint via `promwish`.
- **Man Page Generation**: Built-in `man` subcommand via `mango-cobra`.
- **STDIN Multiplexing**: Copies STDIN for both interactive terminal use and handoff to connected remote sessions.
- **Blocking Reader**: Wraps an `io.Reader` to suppress `io.EOF` until real data arrives (used for STDIN handoff from a buffer).

### Authentication Methods
- SSH Agent (local and remote forwarding)
- Ed25519/RSA/EC keys from `~/.ssh/` or custom `IdentityFiles`
- Auto-generated ephemeral client keys (`.wishlist/client_ed25519`)
- Password authentication (keyboard-interactive fallback)
- Tailscale OAuth (preferred over static API keys which expire in 90 days)

---

## Key Classes and Methods

### `wishlist` package (root)

- **`ListModel`** (`wishlist.go`): Main bubbletea `tea.Model` for the listing UI. Implements `Init()`, `Update()`, `View()`.
  - `NewListing()` — factory creating a `ListModel` with endpoints, SSH client, and renderer.
  - `SetItems()` — updates displayed endpoints and adjusts delegate height.
  - `selected()` — returns the currently selected `ItemWrapper`.

- **`ItemWrapper`** (`listitem.go`): Wraps an `Endpoint` plus rendering descriptors to implement `list.Item` (bubbles list).
  - `FilterValue()` — filtering string (endpoint name).
  - `Title()` — display title (endpoint name).
  - `Description()` — multi-line descriptor output (SSH URL, link, description).
  - `descriptor` — function type: `func(e *Endpoint, styles styles) string`.

- **`Endpoint`** (`config.go`): Represents an SSH endpoint. Fields mirror SSH config options: `Name`, `Address`, `User`, `ForwardAgent`, `RequestTTY`, `RemoteCommand`, `Desc`, `Link`, `ProxyJump`, `SendEnv`, `SetEnv`, `PreferredAuthentications`, `IdentityFiles`, `Timeout`, `Middlewares`.
  - `Valid()` — checks if endpoint has a name and (middlewares or address).
  - `ShouldListen()` — true if middlewares are set (endpoint should run as SSH server).
  - `Authentications()` — returns preferred auth methods or defaults.
  - `Environment()` — merges `SendEnv`/`SetEnv` into env map.
  - `shouldSend()` — glob-matches env var names against `SendEnv` patterns.

- **`Config`** (`config.go`): Top-level wishlist configuration. Fields: `Listen`, `Port`, `Endpoints`, `Hints`, `Factory` (SSH server factory func), `Users`, `Metrics`, `EndpointChan`.
  - **`User`** (`config.go`): `Name` + `PublicKeys` slice for access control.
  - **`Metrics`** (`config.go`): `Enabled`, `Name`, `Address` for Prometheus.
  - **`Link`** (`config.go`): `Name` + `URL` for OSC8 hyperlink in TUI.

- **`Serve()`** (`server.go`): Starts all SSH servers for listed endpoints. Handles graceful shutdown via signal notification.
  - `listenAndServe()` — starts one `*ssh.Server` per endpoint.
  - `publicKeyAccessOption()` — returns `ssh.PublicKeyHandler` checking against `Users` allowlist.

- **`SSHClient`** interface (`client.go`): `For(e *Endpoint) tea.ExecCommand` — factory for creating an executable command to connect to an endpoint.

- **`createSession()`** (`client.go`): Dial + session setup, handling `ProxyJump` by calling `proxyJump()`.
- **`shellAndWait()`** / **`runAndWait()`** (`client.go`): Execute remote shell or specific command.
- **`closers`** type (`client.go`): `[]func() error` slice with a `close()` helper.
- **`resetPty()`** (`client.go`): Sends ANSI terminal reset sequences.

- **`remoteClient`** / **`remoteSession`** (`client_remote.go`): Implements `SSHClient` for server mode. Connects to endpoint from within an existing SSH session.
  - `notifyWindowChanges()` — goroutine forwarding `ssh.Window` resize events to the remote session.

- **`localClient`** / **`localSession`** (`client_local.go`): Implements `SSHClient` for local CLI mode. Full TTY/raw terminal handling, PTY sizing, window change notification.

- **`proxyJump()`** (`jump.go`): Creates SSH connection through a bastion host via `gossh.Dial` on the jump host, then `jumpClient.Dial` to target, then `gossh.NewClientConn`.
- **`splitJump()`** / **`ensureJumpPort()`** (`jump.go`): Parses `user@host:port` ProxyJump strings.

- **`cmdsMiddleware()`** (`middleware.go`): SSH middleware handling `-t appname` style connections to named apps.
- **`listingMiddleware()`** (`middleware.go`): Main TUI-listing middleware using `bubbletea.Program` with `blocking.Reader` for STDIN.
- **`listenAppEvents()`** (`middleware.go`): Event loop handling: done signal, session context cancel, window resize (`tea.WindowSizeMsg`), endpoint updates (`SetEndpointsMsg`), errors.

- **`remoteBestAuthMethod()`** (`client_auth.go`): Auth method selection in server mode — tries SSH agent, then keyboard-interactive, then public key.
- **`localBestAuthMethod()`** (`client_auth.go`): Auth method selection in local mode — `IdentityFiles` first, then SSH agent, then common key names.
- **`getLocalAgent()`** (`client_auth.go`): Connects to `$SSH_AUTH_SOCK`.
- **`getRemoteAgent()`** (`client_auth.go`): Sets up remote agent forwarding via `ssh.AgentRequested` + `ssh.ForwardAgentConnections`.
- **`tryNewKey()`** (`client_auth.go`): Auto-generates `.wishlist/client_ed25519` if not present.
- **`tryUserKeysInternal()`** (`client_auth.go`): Tries `id_rsa`, `id_ecdsa`, `id_ecdsa_sk`, `id_ed25519`, `id_ed25519_sk`.
- **`hostKeyCallback()`** (`client_auth.go`): `knownhosts`-style host key verification with auto-accept of unknown hosts.
- **`keyboardInteractiveAuth()`** / **`passwordAuth()`** (`client_auth.go`): Interactive password prompts.

- **`makeStyles()`** / **`styles`** (`styles.go`): Lipgloss styling for TUI elements (Logo, Err, Footer, NoContent, Doc).

- **`FirstNonEmpty()`** (`wishlist.go`): Returns first non-empty string from variadic args.

### Sub-packages

- **`multiplex.Reader()`** (`multiplex/reader.go`): Reads from an `io.Reader` and writes to two `ResetableReader` instances simultaneously. Used to fork STDIN for both the TUI and the remote session.
  - `syncWriter` type: thread-safe `bytes.Buffer` wrapping `io.Reader`/`io.Writer`/`ResetableReader`.

- **`blocking.Reader`** (`blocking/reader.go`): Wraps any `io.Reader`, retries on `io.EOF` every 10ms. Used to prevent premature EOF on the STDIN buffer during session handoff.

- **`srv.Endpoints()`** (`srv/srv.go`): DNS SRV record lookup + TXT record parsing for `wishlist.name` override. Returns `[]*wishlist.Endpoint`.
  - `fromRecords()` — maps `*net.SRV` + `[]string` TXT records to endpoints.

- **`zeroconf.Endpoints()`** (`zeroconf/zeroconf.go`): Uses `grandcat/zeroconf` to browse `_ssh._tcp` services. Returns `[]*wishlist.Endpoint`.

- **`tailscale.Endpoints()`** (`tailscale/tailscale.go`): Queries `https://api.tailscale.com/api/v2/tailnet/{tailnet}/devices`. Supports API key auth or OAuth client credentials via `golang.org/x/oauth2/clientcredentials`. Maps devices to `[]*wishlist.Endpoint`.
  - `apiKeyRoundTripper` type: `RoundTrip` injecting `Authorization: Bearer` header.

- **`sshconfig.ParseFile()`** / **`ParseReader()`** (`sshconfig/parse.go`): Parses OpenSSH config format into `[]*wishlist.Endpoint`. Handles `Host` patterns with glob matching, `HostName`, `User`, `Port`, `IdentityFile`, `ForwardAgent`, `RequestTTY`, `RemoteCommand`, `ProxyJump`, `ConnectTimeout`, `SendEnv`, `SetEnv`, `PreferredAuthentications`, and `Include` directives (with recursive file globbing and relative path resolution).
  - `hostinfo` type: struct mirroring SSH config options.
  - `hostinfoMap` type: thread-safe map with ordered keys.
  - `split()`: Separates wildcard vs concrete hosts, merges seed endpoints.
  - `mergeHostinfo()`: Left-biased merge of two `hostinfo` structs.

- **`home.ExpandPath()`** (`home/expand.go`): Expands `~` in paths to `$HOME`.

- **`cmd/wishlist/main.go`**: Cobra CLI with `root` (local mode), `serve` (server mode), and `man` commands. Handles config file discovery, seed endpoint gathering (tailscale/zeroconf/srv), YAML vs SSH config parsing, hint application, and local TUI or server startup.

---

## Notable Algorithms / Named Patterns

### ProxyJump (Bastion Tunneling)
`proxyJump()` in `jump.go` performs a multi-leg SSH connection: dial the bastion → open a TCP connection from the bastion to the target → wrap that connection in `gossh.NewClientConn`. This is the direct Go equivalent of OpenSSH's `ProxyJump` option.

### STDIN Multiplexing
`multiplex.Reader()` forks the SSH session's STDIN into two independent readers: one feeds the bubbletea TUI, the other is "handed off" to the remote session after a user selects an endpoint. The `ResetableReader` interface allows the TUI to drain/reset its copy after handoff without affecting the other.

### EOF-Suppressing Blocking Reader
`blocking.Reader` re-reads on `io.EOF` every 10ms. This prevents the `bytes.Buffer` STDIN stand-in from reporting EOF when the buffer might still receive data (since the SSH session continues writing while the TUI is running).

### Host Key Callback with Auto-Accept
`hostKeyCallback()` in `client_auth.go` uses `knownhosts.New()` to validate. Unknown hosts are not rejected — the key is appended to the known_hosts file. This mimics SSH's `StrictHostKeyChecking=no` behavior for a better UX in a directory tool.

### Tea Model Pattern (bubbletea)
The project heavily uses the `charmbracelet/bubbletea` architecture: `ListModel` implements `tea.Model` with `Init()`/`Update()`/`View()`. The `Update()` method handles `tea.KeyMsg`, `tea.WindowSizeMsg`, `SetEndpointsMsg`, and error messages. The middleware wires the bubbletea `Program` into the SSH session I/O streams.

### Broadcast Relay for Dynamic Endpoint Updates
`broadcast.Relay[[]*Endpoint]` from `teivah/broadcast` propagates endpoint list updates from the config reloader goroutine to all connected SSH session handlers without mutex complexity.

### Thread-Safe Hostinfo Map
`sshconfig.hostinfoMap` uses a `sync.Mutex` protecting `inner map[string]hostinfo` and ordered `keys []string`. All operations (`set`, `get`, `forEach`) lock the mutex.

---

## Strengths

- **Thoughtful Dual-Mode Design**: Separating local CLI browsing from SSH-server mode is architecturally clean and avoids complexity in either mode.
- **Real SSH Client, Not a Wrapper**: Uses `golang.org/x/crypto/ssh` for real SSH connections, supporting full SSH semantics (ProxyJump, agent forwarding, PTY allocation, environment variables).
- **No Password Auth in Server Mode**: Rightly refuses password auth in server mode, relying on public-key auth with authorized user allowlists.
- **Discovery Integration**: First-class support for Zeroconf, DNS SRV, and Tailscale discovery makes it practical for dynamic infrastructure.
- **Tailscale OAuth**: Using OAuth client credentials instead of expiring API keys is a best practice for long-running deployments.
- **Configuration Format Flexibility**: Parsing both YAML and raw SSH config formats dramatically lowers the barrier to entry (users can point it at their existing `~/.ssh/config`).
- **Clean Separation of Concerns**: `client_local.go` vs `client_remote.go` isolate the two connection models. Auth logic is in `client_auth.go`. Middleware in `middleware.go`. Discovery in sub-packages.
- **STDIN Handoff Pattern**: The multiplex/blocking reader pattern for handing off STDIN from the TUI to the remote session is well-designed and handles the tricky EOF-surpression case.
- **Lipgloss Styling**: Consistent use of `lipgloss.AdaptiveColor` for light/dark terminal profiles.
- **Graceful Shutdown**: `Serve()` coordinates signal handling and calls `Shutdown(ctx)` with timeout on all servers.

---

## Weaknesses

- **No Windows Support for Local Mode**: `client_unix.go` vs `client_windows.go` distinction exists, but the local TTY/raw mode handling is Unix-only. Windows users can only use server mode.
- **Password Auth Not Supported in Server Mode**: While correct for security, it means deployments relying on password auth (rare but existent) cannot use wishlist as a server.
- **Auto-Accept Host Keys is a Security Risk**: The `hostKeyCallback()` auto-accepts unknown host keys rather than prompting the user or failing. This is a man-in-the-middle risk unless `UserKnownHostsFile=/dev/null` is used.
- **No Host Key Verification Options**: No way to require strict host key checking; the auto-accept is hardcoded.
- **Refresh Interval Race**: The `EndpointChan` refresh loop in `cmd/wishlist/main.go` fetches new endpoints and broadcasts them, but there is no synchronization between config reload and in-flight session operations.
- **Goroutine Leak Potential in `listenAppEvents()`**: The goroutine in `listenAppEvents` spawned from `listingMiddleware` has no explicit cleanup mechanism beyond the session context — if `endpointL.Close()` races with context cancellation, the goroutine might exit at an unexpected time.
- **Seed Endpoint Merging**: The `split()` function in `sshconfig/parse.go` merges seed endpoints into the parsed hosts map but the merging is order-dependent and uses a left-biased `mergeHostinfo` that may not preserve all seed fields correctly.
- **No集成 Tests**: The test suite is primarily unit tests; no integration tests verifying end-to-end SSH session lifecycle.
- **Assumes Single User for Local Mode**: The `.wishlist/` directory is created with `0o700` permissions in the current working directory, which could be a shared working directory in multi-user scenarios.

---

## SugarCraft Mapping

Wishlist is an SSH directory/TUI app that combines several Charmbracelet ecosystem components. Here is the many-to-many mapping to SugarCraft libraries:

| Wishlist Component | SugarCraft Library | Rationale |
|---|---|---|
| TUI Listing (`ListModel` using `bubbles/list`) | `sugar-bits` | The list-based TUI navigation is directly analogous to `sugar-bits` which ports `bubbles`. The `list.Model` + `ItemWrapper` pattern mirrors how SugarCraft models wrap `bubbletea` components. |
| Server mode SSH serving (`charmbracelet/ssh` + `charmbracelet/wish`) | `candy-shell` | The SSH server side of wishlist heavily uses `charmbracelet/ssh` and `charmbracelet/wish`. `candy-shell` is the SugarCraft port of the `wish` library for SSH server middleware. |
| Config parsing (SSH config + YAML) | `candy-core` (future `candy-sshconfig`) | Wishlist's `sshconfig.ParseFile()` is a complex SSH config parser. SugarCraft does not yet have a dedicated SSH config parsing library. A `candy-sshconfig` leaf lib would be the natural home for this. |
| Endpoint discovery (Zeroconf, SRV, Tailscale) | `sugar-bits` (service discovery) | Discovery mechanisms are service-announcement patterns. Could be a `sugar-discovery` leaf lib if more such protocols are added. |
| PTY / TTY handling + raw terminal mode | `candy-pty` | The local client raw terminal setup (`makeRaw`, `term.IsTerminal`, `RequestPty`) maps directly to `candy-pty`. |
| Terminal reset序列 (`resetPty`) | `candy-pty` | ANSI escape sequences for terminal reset would be part of `candy-pty`. |
| Bubbletea TUI framework | `sugar-bits` | `charmbracelet/bubbletea` → `sugar-bits`. The `ListModel` is a `tea.Model` using `bubbles/list`. |
| Lipgloss styling | `candy-shine` | `charmbracelet/lipgloss` → `candy-shine`. The `styles` struct with `lipgloss.AdaptiveColor` maps to `candy-shine`'s theme system. |
| Key bindings (`key.NewBinding`) | `sugar-bits` | `charmbracelet/bubbles/key` → `sugar-bits`. The `copyIPAddr` and `enter` bindings are standard bubble key patterns. |
| Logging (`charmbracelet/log`) | `candy-core` | `charmbracelet/log` → `candy-core` (the `Log` facade). Wishlist uses structured logging extensively. |
| `ssh/crypto` (ProxyJump, session handling) | `candy-shell` | `golang.org/x/crypto/ssh` is used throughout wishlist for the actual SSH protocol. SugarCraft's `candy-shell` wraps the `charmbracelet/ssh` which itself wraps `crypto/ssh`. |
| `Termenv` (clipboard, ANSI sequences) | `candy-shine` | `muesli/termenv` (used for `termenv.Copy` and `termenv.CSI` sequences) → `candy-shine`. |
| Multiplex STDIN fork | (no direct mapping) | The `multiplex.Reader()` pattern is wishlist-specific; not a direct port candidate. |
| Blocking EOF-suppressing reader | (no direct mapping) | The `blocking.Reader` is wishlist-specific; no SugarCraft equivalent. |
| `broadcast` relay for endpoint updates | `candy-core` (event bus) | `teivah/broadcast` is a generic pub/sub relay; could map to an event bus in `candy-core`. |
| Tailscale API client | (no mapping yet) | Tailscale-specific HTTP client with OAuth. No SugarCraft lib for this yet. |
| DNS SRV/TXT discovery | `sugar-bits` (network utils) | Standard DNS lookup patterns could be in a `sugar-network` leaf lib. |
| `keygen` for ephemeral key generation | `candy-core` (crypto utils) | `charmbracelet/keygen` for ed25519 key generation; SugarCraft has no keygen equivalent yet. |

**Primary SugarCraft ports for wishlist dependencies**:
- `sugar-bits` — ports `charmbracelet/bubbletea`, `charmbracelet/bubbles` (including `list`, `key`, `spinner`)
- `candy-shell` — ports `charmbracelet/ssh`, `charmbracelet/wish`
- `candy-shine` — ports `charmbracelet/lipgloss`, `muesli/termenv`
- `candy-pty` — ports TTY/PTY raw mode and terminal control utilities
- `candy-core` — ports `charmbracelet/log`

---

## Analysis

**charmbracelet/wishlist** is a well-architected SSH directory application that serves as both a standalone TUI for browsing SSH config files and a self-hosted SSH-based application hub. Its design philosophy reflects the broader Charmbracelet ecosystem: composable middleware (`charmbracelet/wish`), interactive TUI (`charmbracelet/bubbletea`), and rich terminal rendering (`charmbracelet/lipgloss`). The project's dual-mode architecture — local CLI browsing vs. server-mode SSH serving — is cleanly separated through the `SSHClient` interface and platform-specific client implementations (`client_local.go` vs `client_remote.go`).

The most technically interesting aspect is the **STDIN multiplexing pattern** used when serving the TUI over SSH. When a user connects to the wishlist server and selects an endpoint, the TUI needs to release STDIN and hand it over to the new SSH connection. The `multiplex.Reader` forks STDIN into two independent buffers, and `blocking.Reader` prevents premature EOF on the handoff buffer. This is a robust solution to a subtle concurrency problem.

The **endpoint discovery layer** (Zeroconf, DNS SRV, Tailscale) is a strong differentiator — wishlist can auto-discover SSH services on a network without any configuration. The Tailscale integration is particularly well-engineered, preferring OAuth client credentials over short-lived API keys.

From a security standpoint, the **auto-accept host keys** behavior is the most significant concern. It bypasses SSH's host key verification, which is the primary defense against man-in-the-middle attacks. The README even notes the `UserKnownHostsFile=/dev/null` workaround, but this should be opt-in rather than the default behavior. The server mode correctly refuses password authentication and supports only public-key auth with an authorized-users allowlist.

For the **SugarCraft ecosystem**, wishlist would primarily be a *consumer* of existing ports (`sugar-bits`, `candy-shell`, `candy-shine`) rather than a direct port target, since it is a full application rather than a reusable library. However, its `sshconfig` parser and discovery sub-packages (`srv`, `zeroconf`, `tailscale`) are strong candidates for leaf libraries that could stand alone. The `sshconfig` parser in particular is a non-trivial piece of infrastructure — handling glob patterns, `Include` directives with relative path resolution, and SSH config precedence rules — that would be valuable as a standalone `candy-sshconfig` package.
