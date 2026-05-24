# Caliber Learnings

Accumulated patterns and anti-patterns for candy-serve development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:osc52]** OSC 52 payloads use base64 encoding for data. When writing clipboard data, encode it first; when reading, the TUI sends base64. The format is `52;{selection};{base64data}` (without the ESC prefix and ST terminator that the terminal emits).

- **[pattern:osc52-selections]** Valid OSC 52 selections are `c` (clipboard), `p` (primary), and `s` (secondary). Not all terminals support all selections. Treat unsupported selections as no-ops.

- **[pattern:http-smart-protocol]** Git smart protocol over HTTP uses specific path patterns: `/repo.git/info/refs?service=git-upload-pack` for ref advertisement and `/repo.git/git-upload-pack` (POST) for pack exchange. The `.git` suffix is required.

- **[pattern:http-smart-protocol-pktline]** Git pkt-line format encodes length as a 4-digit hexadecimal prefix (e.g., `0045` for 69 bytes). A flush packet is `0000`. Never assume null-termination — use explicit length prefixes.

- **[pattern:http-smart-protocol-auth]** Authentication happens at pack-exchange time (POST), not at ref-advertisement time (GET /info/refs). Anonymous GET to info/refs is always allowed for discovery; POST to git-upload-pack/git-receive-pack requires valid auth.

- **[pattern:clipboard-events]** Clipboard state changes are recorded as pending events. Call `pendingEvents()` to drain and reset. This pattern avoids lost events if listeners are slow to process.

- **[pattern:git-daemon-socket]** Git daemon uses PHP `socket_create/bind/listen` for TCP server. Always set `SO_REUSEADDR` before binding to allow quick restart. The socket operates in a main event loop with `socket_select()` for I/O multiplexing.

- **[pattern:git-daemon-pktline]** Git daemon protocol uses length-prefixed pkt-line format: 4-digit hex length prefix (e.g., `0045` = 69 bytes total including length field). Flush packet is `0000`. Data lines use `hash refname` format with `\n` terminator.

- **[pattern:git-daemon-signal-handling]** Daemon mode registers `pcntl_signal()` handlers for SIGTERM/SIGINT/SIGHUP with `pcntl_async_signals(true)`. On signal, sets shutdown flag; main loop checks flag and calls `cleanup()` which closes sockets and removes PID file.

- **[pattern:git-daemon-pid-file]** PID file is written as plain text (just the PID) to the specified path. Created with `mkdir` on the directory first if needed. Removed in `cleanup()` via `unlink`. Default location is `<data_path>/git-daemon.pid`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
