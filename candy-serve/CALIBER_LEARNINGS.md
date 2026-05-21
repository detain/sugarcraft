# Caliber Learnings

Accumulated patterns and anti-patterns for candy-serve development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:osc52]** OSC 52 payloads use base64 encoding for data. When writing clipboard data, encode it first; when reading, the TUI sends base64. The format is `52;{selection};{base64data}` (without the ESC prefix and ST terminator that the terminal emits).

- **[pattern:osc52-selections]** Valid OSC 52 selections are `c` (clipboard), `p` (primary), and `s` (secondary). Not all terminals support all selections. Treat unsupported selections as no-ops.

- **[pattern:http-smart-protocol]** Git smart protocol over HTTP uses specific path patterns: `/repo.git/info/refs?service=git-upload-pack` for ref advertisement and `/repo.git/git-upload-pack` (POST) for pack exchange. The `.git` suffix is required.

- **[pattern:http-smart-protocol-pktline]** Git pkt-line format encodes length as a 4-digit hexadecimal prefix (e.g., `0045` for 69 bytes). A flush packet is `0000`. Never assume null-termination — use explicit length prefixes.

- **[pattern:http-smart-protocol-auth]** Authentication happens at pack-exchange time (POST), not at ref-advertisement time (GET /info/refs). Anonymous GET to info/refs is always allowed for discovery; POST to git-upload-pack/git-receive-pack requires valid auth.

- **[pattern:clipboard-events]** Clipboard state changes are recorded as pending events. Call `pendingEvents()` to drain and reset. This pattern avoids lost events if listeners are slow to process.
