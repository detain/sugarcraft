<img src=".assets/icon.png" alt="candy-ssh-launcher" width="160" align="right">

# CandySshLauncher

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-ssh-launcher)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-ssh-launcher)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-ssh-launcher?label=packagist)](https://packagist.org/packages/sugarcraft/candy-ssh-launcher)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

> Formerly published as `sugarcraft/sugar-wishlist` (renamed 2026-10). <!-- @rename-keep -->


![demo](.vhs/picker.gif)

candy-ssh-launcher — a TUI directory of SSH endpoints for PHP 8.3+. Launch it, pick a host, hit Enter: the launcher replaces itself with an `ssh` session to that host, so prompts, keys and exit codes are all native ssh. Your machines live in one searchable list — type to filter, arrows to move, Enter to connect.

The config schema here is a **flat top-level list** of endpoint objects; charmbracelet/wishlist's top-level endpoint-name-keyed nested config is deliberately *not* accepted — feeding one in fails loudly (`wishlist yaml: unparseable line`, or a named refusal for inline-flow values and block mappings under list fields), never silently. <!-- @rename-keep -->

```
── wishlist ──
filter:
▸ production  ─  deploy@prod.example.com:2222
  staging     ─  stage.example.com:22
  dev         ─  dev.example.com:22

↑/↓ select · Enter connect · Esc quit · type to filter
⌫ delete · Ctrl+U/W clear · PgUp/PgDn page · Home/End jump
```

While you type, the frame swaps its second help row for the funnel: a `(shown of total)` count once a filter is live, plus a `(no matches — backspace or Esc)` line when the query has killed every row.

## Requirements

* PHP **8.3+** with `ext-pcntl` (the launcher hands off via `pcntl_exec`).
* **Unix only** — raw-mode terminals, `pcntl`, and `~` expansion have no Windows story.
* A **UTF-8 terminal**: the picker draws `▸`, `─`, and `…`.
* The picker honors the capability signals: `NO_COLOR` (and BSD `CLICOLOR=0`) drop every SGR — the frame keeps its `▸` marker and plain-text layout — and `TERM=dumb` additionally prints single-pass frames with no cursor addressing, no alternate buffer and no paste bracketing. `CLICOLOR_FORCE=1` / `FORCE_COLOR=1` overrule both opt-outs. `--list` is plain text by construction either way.
* Inside a container, attach a TTY: `docker run -it --rm <image> candy-ssh-launcher`.

## Install

The `candy-ssh-launcher` binary lands in whichever `vendor/bin` belongs to the install you chose:

```bash
# project install — run it from the project root
composer require sugarcraft/candy-ssh-launcher
./vendor/bin/candy-ssh-launcher

# global install — goes to Composer's global bin dir
composer global require sugarcraft/candy-ssh-launcher
~/.config/composer/vendor/bin/candy-ssh-launcher   # Composer >= 2.1 default
~/.composer/vendor/bin/candy-ssh-launcher           # legacy location; `composer config --global home` prints yours
```

With a global install, put your endpoints in `~/.config/wishlist.yml` and run bare from anywhere — that is the zero-flag flow the demo above assumes.

Running from a checkout? Add `candy-ssh-launcher/bin/` to your `$PATH`. The checkout also ships `examples/sample-wishlist.yml` — a ready-made endpoint list to try with `--config examples/sample-wishlist.yml`.

## Quick start

1. Create `~/.config/wishlist.yml` holding one endpoint:

   ```yaml
   - name: home
     host: myserver.example.com
   ```

2. Verify the launcher reads it — `--list` needs no terminal and touches no ssh:

   ```bash
   candy-ssh-launcher --list
   ```

   One row prints — the name, `host:port`, and the user (`-` when unset), tab-joined — and the exit code is `0`.

3. Connect:

   ```bash
   candy-ssh-launcher
   ```

   The picker lists `home`; hit Enter and you are in `ssh`.

If step 2 exits `1` with a "no config found" refusal, the launcher never saw the file at the path its message names — check there first.

## Usage

```
candy-ssh-launcher [--config <path>] [--ssh <path>] [--from-ssh-config] [--list] [-h|--help] [-v|--version] [--] [endpoint]
```

| Argument | Meaning |
|----------|---------|
| `endpoint` (positional) | Connect by name. Exactly one endpoint with that name dispatches it directly — no terminal needed, all dispatch guards honored. An ambiguous name (duplicates are loaded with a warning, not refused) opens the picker pre-filtered to the matches; an unknown name opens the full picker. A second bare token is refused as an unknown argument. |
| `--config <path>` | Read this config file and skip the search. Accepts `--config <path>` and `--config=<path>`; a missing value is refused. |
| `--from-ssh-config` | Import the endpoint list from an OpenSSH config instead of a wishlist file. With `--config <path>` it reads that file; without it the default `~/.ssh/config` is used — auto-discovered, so the same ownership/writability trust gate applies as on the wishlist ladder. The resolved source is named on stderr. A wishlist-format refusal on an ssh-config-style file names this flag as the route instead of printing a fake-YAML parse error. |
| `--ssh <path>` | ssh binary to exec. Must be an **existing executable path** — `pcntl_exec` performs no `$PATH` search, so a bare `ssh` is rejected; relative paths resolve against the current directory. Accepts `--ssh <path>` and `--ssh=<path>`. Without the flag the binary resolves through the environment variable `SSH_LAUNCHER_SSH`, then the candidate list (`/usr/bin/ssh`, `/run/current-system/sw/bin/ssh`, `/opt/homebrew/bin/ssh` — first existing executable wins), then the default `/usr/bin/ssh`. A config row may override this for itself with `command:` (see the field table). |
| `--list` | Print the resolved endpoints to stdout as TAB-joined `name`, `host:port`, `user` columns (an unset or empty user prints as a literal `-`; a name that occurs more than once is suffixed with its 1-based config index, `dup#1`/`dup#3`), and exit `0` — no terminal, no ssh, no picker, and no `--ssh` executability check. Control and line bytes in every column are folded to spaces, so the output is exactly one physical row per endpoint and safe to `awk -F'\t'`. |
| `-h`, `--help` | Print the usage block (synopsis, picker keys, exit codes, no-terminal and `--` notes) and exit `0`. |
| `-v`, `--version` | Print `candy-ssh-launcher <version>` and exit `0` — reads no config, touches no terminal. |
| `--` | End of options — remaining arguments are ignored (the terminator ends option parsing; it does not introduce the positional). |

Unrecognized flags, a second bare positional, and options given without their value exit `2` and name the offending argument. Flags that take no value ignore a glued `=value` portion: `--list=x` behaves as `--list`, and `-h=junk` prints help.

### Exit codes

| Code | When |
|------|------|
| `0` | `--help` / `-h` or `--version` / `-v` printed, or `--list` succeeded |
| `1` | • no config file found • config unreadable or invalid • config valid but with **no endpoints**, on the picker path (`--list` on such a config prints nothing and exits `0`) • the composer autoload is missing • `--ssh` names a path that is not an executable file — except that `--list` skips this last check |
| `2` | Unknown flag **or argument**, or `--config`/`--ssh` given without a value |
| `3` | The selected endpoint was refused at dispatch time (a guard-rejected field: a leading-dash `host`, `user`, `proxyJump`, `options` entry, `identityFiles` item, `flags` item, or `remoteCommand`), or a `command:` transport that is not an executable file, or the binary could not be exec'd; stderr names the sanitized reason |
| `130` | Quit from the picker (Esc / Ctrl+C — SIGINT restores the terminal before exiting; stdin EOF at the picker reads as quit too, the typical cause being a terminal that died, while a container started without `-i` never reaches the picker — the exit-`2` non-TTY gate answers it) |

### Finding your config

`candy-ssh-launcher` resolves its config in this order:

1. `--config <path>` (CLI flag) — wins outright
2. `wishlist.yml` / `wishlist.yaml` / `wishlist.json` in the **current directory** (first that exists) <!-- @rename-keep -->
3. `$XDG_CONFIG_HOME/wishlist.yml` / `.yaml` / `.json` — or `~/.config/…` when `XDG_CONFIG_HOME` is unset (this tier is skipped entirely when neither `$HOME` nor XDG is set; again first that exists)

So a config in the directory you launch from takes precedence over your home config. (The `wishlist.{yml,yaml,json}` file names predate the product rename and are kept for config-file compatibility.) Hosts are managed by **editing that file** — the launcher never rewrites your config. List ordering and navigation live under [Keybindings](#keybindings).

Auto-discovered configs (tiers 2 and 3) face a trust gate in the OpenSSH `StrictModes` spirit: a config **owned by another user** or **writable by the group or the world** is refused by name and the run exits `1` — a planted repo file is the easiest attack in, and it must not win silently. Whenever the current-directory tier wins, a one-line notice marks the run on stderr (`project-local wishlist in use: <path>`). A config named explicitly via `--config` skips the gate on purpose: pointing at a file is consent.

## Config format

Flat list of endpoint objects (YAML subset or JSON). The parser is chosen by the file extension (`.json`, `.yml`/`.yaml`). A config named otherwise is content-sniffed: JSON if the payload opens with `[` or `{`, else the YAML subset:

```yaml
- name: production
  host: prod.example.com
  port: 2222
  user: deploy
  description: prod deploy box
  identity_file: ~/.ssh/prod-deploy

- name: staging
  host: stage.example.com
  user: deploy

- name: jumpbox
  host: bastion.example.com
  proxyJump: gw.example.com
  options:
    - ServerAliveInterval=30
    - BatchMode=yes
```

```json
[
  { "name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy" },
  { "name": "staging",    "host": "stage.example.com" }
]
```

| Key | Type | Meaning |
|-----|------|---------|
| `name` | string, required (non-empty) | Label shown in the picker (the filter scores the whole display line `name ─ user@host:port`, so usernames and port digits match too). |
| `host` | string, required and non-empty | Destination hostname — what ssh connects to; an empty host is refused. |
| `port` | `1`–`65535`, default `22` | SSH port; junk values are refused with the endpoint's name. |
| `user` | string, optional | Remote user (omitted → ssh's own default). |
| `description` | string, optional | Dimmed text after the endpoint row. |
| `identityFiles` | list, optional | Identity files, in order, each passed as its own `-i`; a bare scalar (`identityFiles: ~/.ssh/id`) is accepted as a one-item list. |
| `identity_files` / `identity_file` / `identityFile` | list / string, optional | Accepted alternate spellings of the identity key — `identity_files` is the upstream-snake plural (takes a list), the other two are singular strings. They are not merged: the canonical `identityFiles` wins when present, else `identity_files`, else `identity_file`, else `identityFile` — a losing spelling is silently discarded, so use exactly one of these keys per endpoint. |
| `proxyJump` / `proxy_jump` | string, optional | Passed to ssh as `-J`; the canonical spelling wins when both appear. |
| `options` | list, optional | Passed verbatim as repeated `-o Key=Value`; a bare scalar is accepted as a one-item list; an empty item is refused at dispatch. |
| `flags` | list, optional | Extra ssh(1) flag letters (`-A`, `-t`, `-T`, `-N`, `-f`, `-X` are not `-o`-able): pass `t`, `N` — the dash is added at argv build, before the destination. An entry that itself starts with a dash is refused at dispatch (the option-injection guard), and an empty item is skipped. |
| `remoteCommand` | string, optional; alias `remote_command` | The ssh(1) command positional: appended after the destination per the `ssh [options] destination [command]` synopsis, so tunnel/kubectl/tmux-attach endpoints are expressible. Leading-dash values are refused at dispatch; an empty value behaves as no command. |
| `command` | string, optional; alias `transport` | Custom transport binary for this row (upstream's mosh lane, generalized): replaces `--ssh` when this endpoint dispatches. It must be an **existing executable path** — the same gate `--ssh` passes — and it is refused by name at dispatch (exit `3`) otherwise; it never rides argv, so the leading-dash guard family does not apply to it. The transport argv is the restricted ssh-shaped subset: binary, `-p <port>` when not 22, the raw `flags` lane, destination — `identityFiles`, `proxyJump`, `options` and `remoteCommand` are not mosh-class speak, so if the row sets any of them they are dropped **with one stderr line naming the endpoint and every dropped field** — never trimmed in silence. An empty value behaves as no command. |
| `forward_agent` / `request_tty` / `connect_timeout` | **not fields here** | Upstream's first-class keys are expressed as `options:` entries (`ForwardAgent=yes`, `RequestTTY=force`, `ConnectTimeout=30`) — see [Divergences from upstream wishlist](#divergences-from-upstream-wishlist). Spelling the upstream key here draws the unknown-field notice, never a silent ignore. |

Limits of the embedded YAML reader: flat entries only, and a nested list is written one `- item` line at a time — any number of items accumulates, but each must be a scalar. An optional field can be left out entirely or written `key: null` / `key: ~` — both read as omitted; a bare `key:` is never an omission: on a list field it opens the `- item` block, and on a scalar field it is refused by name as a non-scalar. **Inline flow collections** (`options: [a, b]`) are refused with a message that names the fix — write the nested list form; block mappings indented under a list field are refused by name, field and line. A `#` after whitespace starts a comment, and stripping is quote-aware: outside quotes only, so `description: "fix #1"` keeps its hash. Files saved **with a UTF-8 BOM load fine** (the mark is stripped at the read); **UTF-16 files are refused by name** with a re-save-as-UTF-8 hint — the reader never guesses an encoding. Booleans are refused at any string field rather than reaching ssh as `"1"` — and the boolean set is YAML 1.2 core only (`true/True/TRUE`, `false/False/FALSE`): `yes`, `no`, `on`, `off` are ordinary strings, so a host genuinely named `yes` loads as `yes`.

**Tilde expansion is identity-only.** A leading `~` on any identity key spelling is expanded by the launcher itself, on both ingest paths (wishlist and ssh_config import). `$HOME` is checked first and the passwd entry is the fallback; when neither exists the load refuses loudly, naming the path. `~user` resolves via passwd and stays literal for an unresolvable user. Every other field — `host`, `proxyJump`, `options`, and tildes anywhere but the start of a path (`a~b`, `/x/~/y`) — is passed to ssh exactly as written. Expansion of those is then client-dependent: OpenSSH expands `-i` argv against the *passwd* home (not `$HOME`), and dropbear/busybox never expand at all — which is why identity paths are expanded here and absolute paths remain the portable spelling.

## Keybindings

| Key       | Action                          |
|-----------|---------------------------------|
| ↑ / ↓     | Move up / down (arrows in both CSI and SS3 dialects) |
| Ctrl+U    | Clear the filter                 |
| Ctrl+H    | Delete one filter character (alias of Backspace) |
| Ctrl+W    | Delete the last filter word      |
| Home / End | Jump to the first / last match                |
| PgUp / PgDn | Scroll one page through the matches           |
| Enter     | Connect to highlighted endpoint |
| Esc / Ctrl+C | Quit without connecting (exit 130; on a real terminal Ctrl+C raises SIGINT, which the picker traps to restore the terminal first) |
| (typing)  | Type to filter — every letter, `j`/`k` included, joins the query; Backspace deletes one filter codepoint (a compound emoji or flag-emoji such as 🇺🇸 may need two presses) |
| (paste)   | Pasted text goes to the filter, newlines and all — a pasted Enter never connects (bracketed paste, with a same-batch fallback for older terminals) |

Unfiltered, the picker lists endpoints in exactly the order they appear in the file; typing narrows and re-ranks by match score. Navigation is always the arrows, Home/End and PgUp/PgDn; `j` and `k` are query letters like any other, so filtering for `jumpbox` never scrolls the list instead of typing it.

Arrow keys arrive as an `ESC [ A`-style byte burst; the decoder waits 150 ms for follow-on bytes so a burst fragmented by latency (tmux under load, a WAN hop) still reads as one keypress. Set `ESCDELAY` (milliseconds) to tune that window.

## Implementation

The picker is a tiny standalone widget — not a full SugarWidgets `List`. The lifecycle is

```
read config → render picker → read keys → choose → pcntl_exec(ssh, argv)
```

That last `pcntl_exec` is the critical line: it **replaces** the PHP process with `ssh`. File descriptors, environment, and the controlling tty all flow through unchanged, so the user sees a normal `ssh` session — host-key prompts, agent forwarding, MOTD, exit status, all native. We never proxy bytes; we get out of the way.

The interactive picker owns the **alternate screen buffer** — it enters on the first draw (`CSI ?1049h`) and leaves on every exit path (pick, cancel, or trapped signal, `CSI ?1049l`). The shell's screen is restored exactly: a cancelled session leaves nothing behind because the frame never touched the main screen. Pasted text is captured, never executed. The picker arms **bracketed paste mode** (`CSI ?2004h`) for the session and folds a pasted payload into the filter, newlines included. On a terminal too old to bracket, an Enter that rides in on the same input batch as the text before it is still treated as paste tail, not a connect intent. `--list` (no-terminal mode) is a plain line printer and performs no buffer switching.

## Import from ssh config

The launcher imports endpoints directly from your OpenSSH config file. From the command line:

```sh
candy-ssh-launcher --from-ssh-config --list   # show what the import yields
candy-ssh-launcher --from-ssh-config          # pick from the imported list
```

The default file is `~/.ssh/config`; pass `--config <path>` together with the flag to import another file instead. The resolved source is named on stderr before anything else runs. The default file is auto-discovered and faces the same ownership/writability trust gate as the wishlist ladder; naming it via `--config` is consent and skips the gate. Pointing `--config` at an ssh-config-style file *without* the flag refuses with a pointer to `--from-ssh-config` — never a fake-YAML `unparseable line` error. Programmatically:

```php
use SugarCraft\SshLauncher\Config;

$endpoints = Config::importFromSshConfig('/home/user/.ssh/config');
```

The parser handles:

| SSH Config Key      | Endpoint Field    |
|---------------------|-------------------|
| `Host <pattern>`     | `name`            |
| `HostName <value>`   | `host`            |
| `User <value>`       | `user`            |
| `Port <value>`       | `port`            |
| `IdentityFile <path>` | `identityFiles[]` |
| `ProxyJump <host>`   | `proxyJump`       |

Keywords accept both `Key Value` and `Key=Value` spacing (ssh_config(5) legal forms), and a repeated `Host` pattern reuses its block instead of duplicating endpoints. Precedence follows `ssh_config(5)`: *for each parameter, the first obtained value will be used.* A `Host *` block wins only where it appears before any matching specific block — conventionally it is written last and therefore acts as a fallback. `IdentityFile` is the documented exception and accumulates in file order. The block's pattern becomes the endpoint's `name`. And if the block carries no `HostName`, that same pattern also becomes the `host`.

Pattern matching is a deliberate **subset** of ssh_config(5): a block applies to a host only when the host matches a pattern *literally* or the pattern is exactly `*`. Wildcards like `Host web*` therefore contribute nothing to `web1` (they still mint a `web*` entry of their own). Unlike ssh, which matches such patterns against the hostname you connect to, the importer reads them literally — treat imports as a starting point and diff against your real config. A negated `!pattern` is skipped at mint time entirely — it is an exclusion token, never a hostname. It therefore never becomes a selectable endpoint, and a block left with no positive patterns mints nothing.

Import surprises worth knowing:

- Keywords outside the table above are dropped silently.
- A block whose only pattern is `Host *` mints no endpoint of its own.
- A multi-pattern block `Host a b c` mints one endpoint **per pattern**, sharing the block's values; `!negation` tokens only exclude, they never mint.
- Quoted patterns are no exception — patterns split on spaces, so `Host "web 1"` mints entries literally named `"web` and `1"` (quotes are not stripped).

## Divergences from upstream wishlist

This is a deliberately scoped port. The honest gap list — every upstream roadmap key the reviewers named is here, ported, re-expressed, or refused with a reason, because silence about scope is the bug we would not ship. Freshness caveat: this inventory was written against the 2026-05 research snapshot of charmbracelet/wishlist and a no-network lane — if upstream has moved since, this section can lag it.

**Not ported, by architecture: the directory server.** wishlist's flagship is an SSH directory service with its own certificate authority — a daemon that hands out host access over a network. This launcher is a local picker and an exec hand-off: it reads your files, draws a list, and `pcntl_exec`s `ssh`. No daemon, no CA, no per-user grant store. The fleet's server-side story is the separate `sugar-ssh-server` middleware library (composable auth/logging/rate-limit middleware run under a host OpenSSH daemon's `ForceCommand`) — and it, too, implements no directory CA.

**Opposite by design: `--wizard`.** Upstream can generate a config for you; this tool never writes one. It reads the config you already have — including the import route over your existing `~/.ssh/config` above. One config generator per fleet is enough.

**Refused with a schema reason: `hints`.** Upstream's top-level `hints:` block carries glob-defaulted connections (every `*.local` box is `admin:2222`) merged into matching endpoints. The embedded reader consumes a flat list of endpoint rows — it cannot represent a top-level map at all, so a file opening with `hints:` is refused as unparseable; that is the parser's shape, not a validator's choice. Porting hints means a schema plus parser plus merge semantics (the 2026-05 research estimated 6–8 h), so it stays an open product decision, recorded here and in `CALIBER_LEARNINGS.md`, not a forgotten feature. Mitigation for migrants: the ssh_config importer already carries positional first-obtained defaults — a `Host *.local` block with `User admin` and `Port 2222` is the same workflow expressed where patterns *are* understood.

**Dropped for want of a story: `link`.** Upstream attaches per-endpoint URLs. A raw-mode picker has no browser to hand them to, and no UI surface would show them; shipping the key with nothing consuming it is how silent dead config starts. A `link:` field is at least named — the unknown-field notice says so instead of swallowing it quietly. The plausible future is display-only OSC 8 hyperlinks in `--list` output (no argv involved); nothing is committed to that.

**Re-expressed, not renamed: `forward_agent`, `request_tty`, `connect_timeout`.** Upstream ships these as first-class keys. Here they are `options:` entries — the same `-o` argv, one less schema field:

| Upstream key | This schema's equivalent |
|--------------|--------------------------|
| `forward_agent: true` | `-o ForwardAgent=yes` via `options:` |
| `request_tty: force` | `-o RequestTTY=force` via `options:` |
| `connect_timeout: 30` | `-o ConnectTimeout=30` via `options:` |

Portability edge: `-o` support is client-dependent — OpenSSH honors all three, dropbear and busybox ssh implement only subsets and commonly none of `ForwardAgent`/`RequestTTY`; the option reaches the client verbatim and what it does there is that client's contract. Writing the upstream key names as fields gets the unknown-field notice, never a silent ignore.

**No longer divergences: `remote_command` and `flags`.** Both were missing when the audit ran; both ship now. `remoteCommand` (alias `remote_command`) rides the ssh(1) command positional; `flags` is the boolean-flag lane (`t`, `N`, `X`, … → `-t`, `-N`, `-X`) for the switches `options:` cannot express. They are named here so the list above is never misread as still claiming them absent.

## Security

**Your config file is trusted input** — exactly like `~/.ssh/config` itself. Whatever a config declares, the launcher passes to ssh: `options:` entries become real `-o` settings verbatim, and OpenSSH options such as `ProxyCommand` / `LocalCommand` **execute arbitrary commands at connect time**. Keep configs (and `~/.ssh/config`) owned by you with `0600`/`0644` permissions and do not accept endpoint files from strangers; see the fleet-wide [trust boundary](../docs/TRUST-BOUNDARY.md). Within that posture, the host-key-trust-destruction family is still named before launch: `-o` requests for `StrictHostKeyChecking` (no/never/accept-new), `UserKnownHostsFile`, `GlobalKnownHostsFile`, `LogLevel` (quiet/error/fatal), `IdentityAgent`, or `CertificateFile` print one stderr line per hit describing what it costs, so a MITM payload inside an otherwise-legit config never looks like a plain login (notice only — lab configs legitimately pin `StrictHostKeyChecking=no`).

Untrusted bytes are fenced where they only reach the *display* path: every picker row, the filter echo, and the `--list` columns pass through the canonical sanitizer (`Sanitize::untrusted()` plus its display policy `untrustedForDisplay()`) — escape sequences, C0 controls and encoded-C1 shims are removed, invisible formatting is marked as a visible sentinel, and CR/LF/TAB are folded to spaces so no field can forge an extra row. Fields that reach ssh as arguments are REFUSED, not stripped: a leading-dash `host`, `user`, `proxyJump`, `options` entry, `identityFiles` item, `flags` item, or `remoteCommand` aborts with a named error (`Endpoint::assertNotOption family`) rather than being altered into a safe shape — every argv value passes the same guard, identity slots included (the old `-i` exemption assumed an OpenSSH-only contract; dropbear/busybox re-parse a leading-dash value after `-i` as an option).

A row's `command:` transport is the one field that never joins argv as a value — it *is* the exec path, and it must pass the same exists-and-executable gate as `--ssh` before anything runs (refusal by name, exit `3`). The ssh-only legs it forces the launcher to drop are announced on stderr with the endpoint and field names, so a trimmed argv is never a silent surprise.

## Internationalization

The UI speaks **16 locales** (en plus ar, cs, de, es, fr, it, ja, ko, nl, pl, pt, pt-br, ru, tr, zh-cn). The CLI resolves the locale automatically from `LC_ALL` → `LC_MESSAGES` → `LANG` at startup. Embedders calling `Config`/`Picker` programmatically pick the language themselves:

```php
use SugarCraft\Core\I18n\T;
T::setLocale(T::detect()); // or T::setLocale('de');
```

The banner reads `── wishlist ──` in every locale — it is the product's heritage wordmark (the config filename, likewise, predates the rename). CLI and config error messages likewise carry the heritage `wishlist` word — it is this tool, not a dependency.

## Programmatic use

```php
use SugarCraft\Core\I18n\T;
use SugarCraft\SshLauncher\Config;
use SugarCraft\SshLauncher\Picker;
use SugarCraft\SshLauncher\Launcher;

T::setLocale(T::detect());           // optional: pick the UI language

$endpoints = Config::load('/etc/wishlist.yml');
$picked    = (new Picker())->pick($endpoints);
if ($picked !== null) {
    (new Launcher())->dispatch($picked);
}

// Or import from SSH config:
$sshEndpoints = Config::importFromSshConfig('/home/user/.ssh/config');
```

## Shared foundations

candy-ssh-launcher uses [sugar-fuzzy](https://github.com/detain/sugarcraft/tree/master/sugar-fuzzy/README.md) — `SmithWatermanMatcher::matchAll()` replaces ad-hoc `str_contains`-style filtering. The picker uses the scores to rank matches and paints matched characters bold+cyan.

## Test coverage

SSH config import (`Host`/`Host=` blocks, both spacing forms) is supported. Endpoint, Config (JSON + flat-YAML + SSH config), Picker, Launcher, SshConfigParser are all covered by the test suite.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
