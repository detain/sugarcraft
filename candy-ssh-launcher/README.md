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

candy-ssh-launcher — a TUI directory of SSH endpoints for PHP 8.3+. Launch `candy-ssh-launcher`, pick a host, hit Enter, and the current process is replaced with `ssh` connecting to it.

The config schema here is a **flat top-level list** of endpoint objects; another tool's nested `host:`-keyed `wishlist.yml` is deliberately *not* accepted — feeding one in fails loudly (`wishlist yaml: unparseable line`, or a named refusal for inline-flow values), never silently. <!-- @rename-keep -->

```
── wishlist ──
filter:
▸ production  ─  deploy@prod.example.com:2222
  staging     ─  stage.example.com
  dev         ─  dev.example.com

  ↑/↓ select · Enter connect · Esc quit · type to filter
```

## Requirements

* PHP **8.3+** with `ext-pcntl` (the launcher hands off via `pcntl_exec`).
* **Unix only** — raw-mode terminals, `pcntl`, and `~` expansion have no Windows story.
* A **UTF-8 terminal**: the picker draws `▸`, `─`, and `…`.
* ANSI is always emitted: `NO_COLOR` / `TERM=dumb` are **not** currently honored.
* Inside a container, attach a TTY: `docker run -it --rm <image> candy-ssh-launcher`.

## Install

The `candy-ssh-launcher` binary lands in whichever `vendor/bin` belongs to the install you chose:

```bash
# project install — run it from the project root
composer require sugarcraft/candy-ssh-launcher
./vendor/bin/candy-ssh-launcher

# global install — goes to Composer's global bin dir
composer global require sugarcraft/candy-ssh-launcher
~/.composer/vendor/bin/candy-ssh-launcher
```

Or add this repo's `candy-ssh-launcher/bin/` to your `$PATH` for a checkout.

## Usage

```
candy-ssh-launcher [--config <path>] [--ssh <ssh-binary>] [--list] [-h|--help]
```

| Argument | Meaning |
|----------|---------|
| `--config <path>` | Read this config file and skip the search. Accepts `--config <path>` and `--config=<path>`; a missing value is refused. |
| `--ssh <path>` | ssh binary to exec (default `/usr/bin/ssh`). Must be an **existing executable path** — `pcntl_exec` performs no `$PATH` search, so a bare `ssh` is rejected; relative paths resolve against the current directory. |
| `--list` | Print the resolved endpoints (`name`, `host:port`, `user`) to stdout and exit `0` — no terminal, no ssh, no picker. |
| `-h`, `--help` | Print the usage line and exit `0`. |
| `--` | End of options — remaining arguments are ignored (there are no positional arguments). |

Unrecognized flags and options given without their value exit `2` and name the offending argument.

### Exit codes

| Code | When |
|------|------|
| `0` | `--help` / `-h` or `--list` succeeded |
| `1` | No config found, config not found/unreadable/invalid, a config with no endpoints, or the `--ssh` path is not an executable file |
| `2` | Unknown flag, or `--config`/`--ssh` given without a value |
| `130` | Quit from the picker (Esc / Ctrl-C) |

### Config discovery

`candy-ssh-launcher` resolves its config in this order:

1. `--config <path>` (CLI flag) — wins outright
2. `wishlist.yml` / `wishlist.yaml` / `wishlist.json` in the **current directory** (first that exists) <!-- @rename-keep -->
3. `~/.config/wishlist.yml` / `.yaml` / `.json` (only when `$HOME` is set; again first that exists)

So a config in the directory you launch from takes precedence over your home config. (The `wishlist.{yml,yaml,json}` file names predate the product rename and are kept for config-file compatibility.) Hosts are managed by **editing that file** — the launcher never rewrites your config. Unfiltered, the picker lists endpoints in exactly the order they appear in the file; typing narrows and re-ranks by match score.

## Config format

Flat list of endpoint objects (YAML subset or JSON):

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
| `name` | string, required | Label shown in the picker (filtering ranks against it and `host`). |
| `host` | string, required | Destination hostname — what ssh connects to. |
| `port` | `1`–`65535`, default `22` | SSH port; junk values are refused with the endpoint's name. |
| `user` | string, optional | Remote user (omitted → ssh's own default). |
| `description` | string, optional | Dimmed text after the endpoint row. |
| `identityFiles` | list, optional | Identity files, in order, each passed as its own `-i`; a bare scalar (`identityFiles: ~/.ssh/id`) is accepted as a one-item list. |
| `identity_file` / `identityFile` | string, optional | Singular aliases of the above. |
| `proxyJump` | string, optional | Passed to ssh as `-J`. |
| `options` | list, optional | Passed verbatim as repeated `-o Key=Value`; a bare scalar is accepted as a one-item list. |

Limits of the embedded YAML reader: flat entries, nested one-item lists only, `#` comments. **Inline flow collections** (`options: [a, b]`) are refused with a message that names the fix — write the nested list form. Booleans (`name: yes`) are refused at any string field rather than reaching ssh as `"1"`.

## Keybindings

| Key       | Action                          |
|-----------|---------------------------------|
| ↑ / k     | Move up                         |
| ↓ / j     | Move down                       |
| Enter     | Connect to highlighted endpoint |
| Esc / ^C  | Quit without connecting (exit 130) |
| (typing)  | Type to filter; Backspace deletes one filter character |

## Implementation

The picker is a tiny standalone widget — not a full SugarWidgets `List`. The lifecycle is

```
read config → render picker → read keys → choose → pcntl_exec(ssh, argv)
```

That last `pcntl_exec` is the critical line: it **replaces** the PHP process with `ssh`. File descriptors, environment, and the controlling tty all flow through unchanged, so the user sees a normal `ssh` session — host-key prompts, agent forwarding, MOTD, exit status, all native. We never proxy bytes; we get out of the way.

## Import from SSH Config

`candy-ssh-launcher` can import endpoints directly from your OpenSSH config file (`~/.ssh/config`):

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

Keywords accept both `Key Value` and `Key=Value` spacing (ssh_config(5) legal forms), and a repeated `Host` pattern reuses its block instead of duplicating endpoints. Precedence follows `ssh_config(5)`: *for each parameter, the first obtained value will be used.* A `Host *` block wins only where it appears before any matching specific block — conventionally it is written last and therefore acts as a fallback. `IdentityFile` is the documented exception and accumulates in file order. Host patterns are used as the endpoint name (when no `HostName` is specified, the pattern itself becomes the host).

Pattern matching is a deliberate **subset** of ssh_config(5): a block applies to a host only when the host matches a pattern *literally* or the pattern is exactly `*`. Wildcards like `Host web*` therefore contribute nothing to `web1` (they still mint a `web*` entry of their own). This mirrors what `ssh` does **not** do, so treat imports as a starting point and diff against your real config.

## Security

**Your config file is trusted input** — exactly like `~/.ssh/config` itself. Whatever a config declares, the launcher passes to ssh: `options:` entries become real `-o` settings verbatim, and OpenSSH options such as `ProxyCommand` / `LocalCommand` **execute arbitrary commands at connect time**. Keep configs (and `~/.ssh/config`) owned by you with `0600`/`0644` permissions and do not accept endpoint files from strangers; see the fleet-wide [trust boundary](../docs/TRUST-BOUNDARY.md).

Untrusted bytes are still fenced where they only reach the *display* path: endpoint fields are sanitized before printing (`--list`, stderr error wrapping), the filter echo strips control sequences before rendering, and no leading-dash value can smuggle an ssh option (`proxyJump`/`options`/destination carry a dash guard; identity paths ride `-i`'s mandatory value slot).

## Internationalization

The UI speaks **16 locales** (en plus ar, cs, de, es, fr, it, ja, ko, nl, pl, pt, pt-br, ru, tr, zh-cn). The CLI resolves the locale automatically from `LC_ALL` → `LC_MESSAGES` → `LANG` at startup. Embedders calling `Config`/`Picker` programmatically pick the language themselves:

```php
use SugarCraft\Core\I18n\T;
T::setLocale(T::detect()); // or T::setLocale('de');
```

The banner reads `── wishlist ──` in every locale — it is the product's heritage wordmark (the config filename, likewise, predates the rename).

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

candy-ssh-launcher uses [sugar-fuzzy](https://github.com/detain/sugarcraft#sugar-fuzzy) — `SmithWatermanMatcher::matchAll()` replaces ad-hoc `str_contains`-style filtering. The picker now surfaces scored ranking and match-highlight indices (ANSI bold+cyan on matched characters) for ranked, highlighted filter results.

## Status

SSH config import (`Host`/`Host=` blocks, both spacing forms) is supported. 295 tests / 1550 assertions. Endpoint, Config (JSON + flat-YAML + SSH config), Picker, Launcher, SshConfigParser are all covered.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
