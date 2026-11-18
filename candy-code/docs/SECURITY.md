# Security

> Every page in `docs/` — see [the docs index](README.md).

candy-code runs commands and edits files on your machine, on behalf of a model
whose output you do not fully control. Its security model is therefore mostly
about what the model may do, and what your files may make it do. This page is
the map: the trust boundaries, where each secret lives, and where each surface's
detail is documented. The SugarCraft security policy itself, covering supported
versions, scope, supply-chain rules and the disclosure timeline, lives in one
place so it cannot drift: the repository-root
[`SECURITY.md`](https://github.com/detain/sugarcraft/blob/master/SECURITY.md)
(its *Supported versions*, *Scope*, *Supply chain* and *Disclosure* sections).
When this page and that policy disagree, the root policy wins.

## Reporting a vulnerability

Do not open a public issue. Report privately, through the monorepo (`detain/sugarcraft`):

- **GitHub private vulnerability reporting** — the "Report a vulnerability" form on the repository's Security
  tab. This is the primary channel.
- **Email** — the maintainer address named in the root policy, when the form is unavailable.

Name `candy-code` as the affected package, describe the impact, and include minimal reproduction steps.
The root policy commits to an acknowledgment within 48 hours and a first-pass triage within 7 days.

## Supported versions

The root policy is the authority. In short, `master` is the source of truth, and
every fix lands there first. Tagged, signed releases are built into a single
`candy-code.phar`, and `candy-code self-update` installs a newer one only after
the signature verifies against the public key pinned in
`src/Cli/SelfUpdate.php:75`. A build with an empty key constant is keyless, and
binds its bytes only through the SHA-256 sidecar (`src/Cli/SelfUpdate.php:70-75`).
`candy-code self-update --allow-unsigned` is the dev/test-only bypass of that
signature leg: it removes the signature protection for that run, while the
SHA-256 sidecar still applies. Releases are not backported to old tags before
1.0, so the fix for a version you run is the next release.

## Trust boundaries

Each row names an input, how far candy-code trusts it, and the mechanism that
enforces that trust. The surface's full model is on the page named in the last
column.

| Input | Trust | What enforces it | Read |
|---|---|---|---|
| Model output: tool calls, edits, shell commands | Untrusted | The permission gate runs the circuit breaker, then the channel tool scope (only when a channel sets one), then the rules, then the mode. The circuit breaker denies recursive deletes of `/` or `~` unconditionally. | [PERMISSIONS.md](PERMISSIONS.md) (`src/Permissions/PermissionGate.php:1002-1047`) |
| A project's settings file | Ignored until you trust the project | The project layer is read only when the project root is listed under `trustedProjectSettings` (`src/Config/LayeredSettings.php:234`). Once trusted, your own files outrank the project's, so it only fills in what you left unset. The `trustedProject*` keys cannot be written from the project layer (`src/Config/LayeredSettings.php:109-118`). | [SETTINGS.md](SETTINGS.md) |
| A project's `.mcp.json` | Declined until you trust it | A project's servers start only after `candy-code mcp trust`, which records each server's command, arguments and environment. A later change is refused until you approve it again (`src/MCP/McpTrustPins.php:14`). | [MCP.md](MCP.md) |
| A project's hooks file | Ignored until you trust the project | `<root>/.candy-code/hooks.yaml` loads only for a trusted project (`src/Hooks/HookManager.php:141`). | [HOOKS.md](HOOKS.md) |
| An installed plugin bundle | Partly trusted | Its hooks run only through a channel listed in `trustedBundleChannels`, and its `.mcp.json` stays declined. | [PLUGINS.md](PLUGINS.md) |
| Terminal output from tools, files and servers | Untrusted | Anything a program writes to the terminal is a control-sequence sink. The presentation code sanitizes these strings with `SugarCraft\Core\Util\Sanitize`, the fleet contract in section 1 of the root [`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md). | [`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md) |
| Web content fetched by `WebFetch` | Untrusted | Each host is resolved and refused if any answer is in a blocked range, on every redirect hop (`src/Tools/BuiltIn/WebFetch.php:28-42`, hop check at `:353`). When you have permission rules configured, each redirect target is also judged by them, and a rule that denies or would ask refuses the hop (`src/Tools/BuiltIn/WebFetch.php:337-350`). With no rules configured that rule check does not run. | [PERMISSIONS.md](PERMISSIONS.md) |
| A command you type with `!` | Trusted, by you | It runs without a prompt, because you typed it. A policy you configured still applies (`src/Commands/BangShell.php`). | [TUTORIAL.md](TUTORIAL.md#6-run-your-own-commands) |
| The provider you select | Trusted with your prompts | Prompts go to the endpoint you chose, and your key authenticates the request. The offline echo provider makes no network calls. | [ENVIRONMENT.md](ENVIRONMENT.md) |
| Network clients of `candy-code serve` | Untrusted until authenticated | The server binds `127.0.0.1` by default (`src/Server/ServerConfig.php:27`). Any other address needs `--allow-remote` (`src/Server/ServerConfig.php:753`). Every route except the health check, the login exchange and the static UI needs a credential: the owner token or a viewer token (as a bearer header or a WebSocket subprotocol), the HttpOnly login session cookie (API only, with its per-login secret header), or a single-use `/ws?ticket=` (`src/Server/Http/AuthMiddleware.php:24-45`). `--allowed-host` and `--allowed-ips` narrow who may connect. | [SERVER.md](SERVER.md) |

Two defaults are worth knowing before you rely on the model. The TUI starts in
`default` mode, which asks before it writes. The Bash sandbox is off, so a Bash
call runs with your privileges unless you turn `bashSandbox` on (`src/Config/Settings/Definitions/ToolSettings.php:98`).
When it is on, it runs Seatbelt on macOS (`Darwin`) and Bubblewrap on every other
OS family (`src/Tools/Sandbox/SandboxFactory.php:73-80`).

## Threat model

**Assets.** Your project files, the provider keys in `~/.candy-code/config.json`,
the transcripts in `~/.candy-code/session.db`, MCP OAuth tokens, and everything
your user account can reach, including the shell.

**Attackers the design defends against.**

- *Instructions planted in data the model reads.* A file, web page or tool result
  can carry text that tries to steer the model (prompt injection). The model's
  tool calls still pass the gate, which runs the circuit breaker, the channel
  scope, the rules and then the mode (`src/Permissions/PermissionGate.php:1002-1047`).
  Fetched web content is untrusted input to the model, and `WebFetch` re-runs its
  address guard on every redirect hop (`src/Tools/BuiltIn/WebFetch.php:23-58`).
- *A project you clone.* Its `.mcp.json`, hooks, settings and bundles stay inert
  until you trust them. The table above names the gate for each.
- *A network client of `serve`.* The server binds loopback by default, and any
  other address needs `--allow-remote` (`src/Server/ServerConfig.php:27`). Every
  route except the health check, the login exchange and the static UI needs a
  token (`src/Server/Http/AuthMiddleware.php:18-30`).
- *Untrusted pull requests in CI.* The fork-PR and prompt-injection rules in
  [CI.md](CI.md) apply to any run of the agent on a headless job.

**Assumed trusted.** Your own user account and the files it owns. The candy-code
binary you installed, verified against the pinned release key when it came from
`self-update` (see the root policy's *Supported versions*). The provider endpoint
you selected, which receives every prompt you send to it.

**Out of the model.** A process already running as your user can read every
file in the list above, and no setting on this page stops it. The Bash sandbox is
off by default, so an approved Bash call runs with your privileges
(`src/Config/Settings/Definitions/ToolSettings.php:98`). An `Always` reply allows
calls like the one you approved for the rest of the session, and a saved rule
keeps that allowance across sessions ([TUTORIAL.md](TUTORIAL.md#4-answer-a-permission-prompt)).

## Credentials on disk

candy-code stores the secrets it needs in your home directory. Each store has
a different owner and mode, and anything that can read files as you can read
them all.

| Secret | Where | Protection |
|---|---|---|
| Provider API keys, saved by `candy-code login` or the first-run wizard | `~/.candy-code/config.json` | Plain text, unless you pass `--keychain` to `candy-code login`, which keeps the secret in the OS keychain and writes only a `${keychain:<provider>}` reference (`src/Support/Keychain.php`). The file is written at mode `0600` (`src/Cli/Bootstrap.php:5159`). |
| MCP OAuth tokens | `~/.local/share/candy-code/mcp-auth.json` (`src/MCP/OAuthClientRegistration.php:772`) | Written at mode `0600` through an atomic rename, so no looser copy is ever on disk (`src/MCP/OAuthClientRegistration.php:821`). |
| MCP trust pins | The MCP trust file | Hashes and display summaries only, at mode `0600` (`src/MCP/McpTrustPins.php:37`, `:197`). |
| TUI error log | `~/.candy-code/logs/candy-code.log` | The directory is made `0700` and the file `0600` before the log is opened (`src/Diagnostics/TuiErrorLog.php:24-25`). |
| Session transcripts | `~/.candy-code/session.db` | A plain SQLite database that candy-code does not encrypt. Anything the model saw in a session can be read back from it (`src/Cli/Bootstrap.php:10345`). |

Do not store a key you cannot afford to rotate. If a key is exposed, revoke it at
the provider, then remove it from `config.json`, or run `candy-code login` again
with the new one.

## Running it safely

- Start a new repository in `default` mode, and read a clone's `.mcp.json`,
  hooks and bundles before you trust them. `candy-code mcp list` and
  `candy-code bundles list` show them without starting anything.
- Run `candy-code doctor`. Its `sandbox` row says whether the Bash jail is
  enabled and whether it would start on this host.
- Keep `serve` on loopback. If you must expose it, put TLS in front, and set
  `--allowed-host` and `--allowed-ips` to the clients you expect.
- In CI, follow the fork-PR and prompt-injection rules in [CI.md](CI.md) before
  you run the agent on pull requests from forks.

## Out of scope

The root policy lists what it does not treat as a vulnerability. It excludes bugs
in user-supplied themes, hooks and callbacks, vulnerabilities in transitive
dependencies (report those upstream and copy the SugarCraft maintainers), and
denial of service from deeply nested input.

## Where each surface's security model is documented

- [`PERMISSIONS.md`](PERMISSIONS.md) — the permission gate, the six modes, rules,
  the circuit breaker, the `auto` classifier and the Bash sandbox.
- [`MCP.md`](MCP.md) — the MCP trust gate and why `.mcp.json` is declined by default.
- [`HOOKS.md`](HOOKS.md) — hook events, exit codes, and which hooks a project can add.
- [`PLUGINS.md`](PLUGINS.md) — plugin bundles, `trustedBundleChannels`, and what a bundle may ship.
- [`SETTINGS.md`](SETTINGS.md) — the settings layers and the keys that only you can set.
- [`CI.md`](CI.md) — the fork-PR and prompt-injection doctrine for headless runs.
- [`SERVER.md`](SERVER.md) — the `serve` mode security model, allowed hosts and IPs.
- [`DOCKER.md`](DOCKER.md) — the container image.
- The root [`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md) — the fleet-wide contract that every SugarCraft library follows.
