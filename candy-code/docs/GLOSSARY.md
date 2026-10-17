# Glossary

The vocabulary this documentation assumes, defined in two lines each. Terms are
listed where first-time readers hit them; each entry names where to read more.

| Term | Meaning |
|---|---|
| **ACP** | Agent Client Protocol — a JSON-RPC standard for editors and IDEs to drive an AI coding agent as a subprocess. CandyCode speaks it under `candy-code serve` ([SERVER.md](SERVER.md)). |
| **CandyCode** | This app; formerly SugarCrush / sugar-crush. <!-- @rename-keep --> The `candy-code` binary, the `SugarCraft\Code\` namespace, and the `~/.candy-code/` state directory. <!-- @rename-keep --> |
| **ANSI** | The family of escape-sequence conventions (ECMA-48 and its successors) terminals use for cursor movement, colors, and modes like the alternate screen. Everything a TUI "draws" is ANSI text. |
| **chafa** | A terminal image renderer that converts pictures to character art. As a render mode it is the last-resort rung: works in any terminal, lowest fidelity ([MEDIA.md](MEDIA.md)). |
| **kitty protocol** | The Kitty terminal's graphics protocol (and its keyboard-extension counterpart): images addressed as numbered, animatable objects at full color; the keyboard side lets Ctrl+Enter arrive as itself. Used when detected, ignored otherwise. |
| **LSP** | Language Server Protocol — the standard JSON-RPC bridge to a code editor's intelligence (diagnostics, symbols). CandyCode is an LSP *client*, feeding model answers with real IDE-grade context. |
| **MCP** | Model Context Protocol — a standard for plugging external tool servers into an AI agent over stdio or HTTP. CandyCode is an MCP *client*: [MCP.md](MCP.md). |
| **OSC 52** | An escape sequence (Operating System Command 52) that asks the terminal to copy text into the *system* clipboard, even over ssh. How selecting with the mouse in CandyCode reaches your paste buffer. |
| **PTY** | Pseudo-TTY — a kernel terminal pair: a master the parent program reads/writes and a slave that behaves like a real terminal for a child process. How `Bash interactive:true`, `PtyRun`, and tmux-style embedding work. |
| **sdapi** | The Stable Diffusion web UI API family — the HTTP interfaces of A1111/SD.Next-style servers (`/sdapi/v1/txt2img` and friends). The media tools speak it, plus the SGLang-Diffusion variant ([MEDIA.md](MEDIA.md)). |
| **sixel** | A bitmap terminal graphics protocol (DEC) that paints pixels directly as escaped color runs. Middle rung of the render ladder; broader terminal support than kitty. |
| **SGR** | Select Graphic Rendition — the `ESC[ … m` sequences that set text color, weight, and underline. "Colored output" is SGR output. |
| **SSRF** | Server-Side Request Forgery — tricking a program into making requests to an address the attacker cannot reach directly (e.g. cloud metadata, localhost services). Why outbound fetches are jail-checked. |
| **TUI** | Terminal User Interface — an interactive full-screen program drawn with text (panes, menus, keybindings), as opposed to a line-by-line CLI. CandyCode's main mode is a TUI; `-p` is the non-TUI one-shot path. |

## See also

- [`README.md`](README.md) — the index of every page in this directory.
- The [project README](../README.md) — quick start and the key/command tables.
