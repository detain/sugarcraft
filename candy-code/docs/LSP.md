# Language servers (LSP)

candy-code can talk to a language server, the same process your editor uses for
go-to-definition, find-references, hover and diagnostics. The servers give the agent
precise answers about code it is reading or changing. A server runs only when you
have configured it, or asked for one, and never because a project file asked.

There are three parts to it:

- **`candy-code lsp install <language>`** downloads a pinned server into
  `~/.candy-code/lsp/`. This is the only step that fetches anything.
- **The `lsp` setting** names the servers to start. You set it in your user settings,
  never in a project file, because starting a server is running code.
- **The `Lsp` tool** is how the model asks a running server a question.

Once a server is running, each edit the agent makes is re-checked, and new errors are
appended to the edit's result. The same servers answer `Lsp` requests and outlines for
`Read`.

If no server is configured for a language, `Lsp` answers with an error for that
language. That is expected, and [TROUBLESHOOTING.md](TROUBLESHOOTING.md) covers it.

## Install a server

```sh
candy-code lsp list                  # each installable language, its pinned version, and whether it is installed
candy-code lsp install php           # intelephense
candy-code lsp install typescript    # typescript-language-server
candy-code lsp install python        # pyright
candy-code lsp install rust          # rust-analyzer
```

The four languages and their pinned sources are fixed in the source, in
`src/LSP/LspReleases.php`:

| Language | Server | How it is fetched |
|---|---|---|
| `php` | intelephense 1.18.5 | npm package, with `--ignore-scripts` |
| `typescript` | typescript-language-server 6.0.2 | npm package, with `--ignore-scripts` |
| `python` | pyright 1.1.414 (`pyright-langserver`) | npm package, with `--ignore-scripts` |
| `rust` | rust-analyzer, release `2026-10-26` | a single `.gz` release binary, checked against a pinned sha256 before it is unpacked. Linux and macOS, x86_64 and aarch64 |

The npm servers install under `~/.candy-code/lsp/`, and npm's own integrity check
covers the download. Nothing else is fetched. Every download URL must be HTTPS, on
`github.com` or `objects.githubusercontent.com`, and redirects are checked the same way.

Go is not on the list. `gopls` is installed with `go install`, which candy-code does
not run for you. Install it yourself, then configure it as a custom server below. Go
is still in the auto-detect table, so it starts on its own when `gopls` is already on
your `PATH`.

`candy-code lsp install` requires that the host can reach the download sources. Behind a
proxy, install on a machine that can, then copy `~/.candy-code/lsp/` across.

## Configure servers

The `lsp` setting maps a language identifier to a server. Put it in your user
settings, `~/.candy-code/config.json`:

```json
{
  "lsp": {
    "php": {"command": "intelephense", "args": ["--stdio"]},
    "typescript": {
      "command": "typescript-language-server",
      "args": ["--stdio"],
      "extensions": ["ts", "tsx"]
    }
  }
}
```

The key is the language identifier that is sent to the server with each file. Each
entry takes these fields:

| Field | Default | Meaning |
|---|---|---|
| `command` | required | The program to run |
| `args` | `[]` | Its arguments |
| `extensions` | the key itself | File extensions routed to this server |
| `env` | none | Extra environment variables for the server |
| `initializationOptions` | none | Passed to the server's `initialize` request |
| `timeout` | `10` s | Seconds allowed per request |
| `disabled` | `false` | Set `true` to keep the entry but not start it |
| `sandbox` | jailed | Literal `false` exempts this one server from the `lsp` `sandboxScope` jail. Any other value keeps the jail |

At most 8 servers start in one launch. Entries beyond that are skipped, and the launch
names the skipped languages. An entry that is malformed is also named and skipped, and
the rest still start.

A server that fails its handshake is reported, and the other servers still run.

## Auto-detect

With `lspAutoDetect` set to `true` in your user settings, candy-code looks at the
project's files and starts a server for each language it recognizes, if that server
is installed:

| Language | Project file that triggers it | Server tried |
|---|---|---|
| `php` | `composer.json` | `intelephense` |
| `typescript` | `package.json` or `tsconfig.json` | `typescript-language-server` |
| `python` | `pyproject.toml` or `setup.py` | `pyright-langserver` |
| `go` | `go.mod` | `gopls` |
| `rust` | `Cargo.toml` | `rust-analyzer` |

Auto-detect runs only when the project is trusted. Trust is the same grant that lets a
project's hooks run, because a language server can run build scripts. A server that
auto-detect would start but cannot start because of trust is reported as skipped.

A language you configure explicitly always wins over auto-detect. An installed copy
under `~/.candy-code/lsp/` is preferred over the one on your `PATH`.

## The `Lsp` tool

The model calls `Lsp` with an `operation`, a `path`, and, for position-based
operations, a `line` and `column`. The operations map onto standard LSP requests:

| `operation` | LSP request |
|---|---|
| `definition` | `textDocument/definition` |
| `references` | `textDocument/references` |
| `hover` | `textDocument/hover` |
| `symbols` | `textDocument/documentSymbol` |
| `codeActions` | `textDocument/codeAction` |
| `diagnostics` | `textDocument/publishDiagnostics` |

`line` and `column` are **zero-indexed**, as the LSP spec requires. `grep -n` reports
one-based lines, so subtract one before asking. The optional `language` argument picks
the server. When omitted, candy-code picks the server by the file's extension, and
falls back to `php`.

## Sandboxing

The `lsp` sandbox scope is set by the `sandboxScope` setting. When it places servers
in a sandbox, a server runs inside that jail. Setting `sandbox: false` on one server
exempts only that server. The exemption belongs in your user settings, so a project
cannot grant it to itself. See [SETTINGS.md](SETTINGS.md) for the scopes.

## Troubleshooting

- **`Lsp` says there is no server for the language.** Configure one under `lsp`, or
  install one with `candy-code lsp install`. `lsp list` shows what is present.
- **A server started but answers slowly.** Raise its `timeout`. A cold start of a large
  project can take longer than the 10-second default.
- **The handshake fails.** Run the `command` yourself in a terminal to confirm it starts,
  and check that its arguments include `--stdio` (or the transport the server expects).
- **Diagnostics after an edit are missing.** The server must be running for that file's
  language. Check that the file's extension routes to a configured server.

## Related

- [SETTINGS.md](SETTINGS.md): the `lsp`, `lspAutoDetect` and `sandboxScope` settings.
- [PERMISSIONS.md](PERMISSIONS.md): how the `Lsp` tool is gated.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): the `Lsp` error answer, and install failures.
- [COMMANDS.md](COMMANDS.md): the `lsp` verbs.
