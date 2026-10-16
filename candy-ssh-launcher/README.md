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

The config schema here is a **flat top-level list** of endpoint objects; another tool's nested `host:`-keyed `wishlist.yml` is deliberately *not* accepted — feeding one in fails loudly with `wishlist yaml: unparseable line`, never silently. <!-- @rename-keep -->

```
── wishlist ──
filter:
▸ production  ─  deploy@prod.example.com:2222
  staging     ─  stage.example.com
  dev         ─  dev.example.com

  ↑/↓ select · Enter connect · Esc quit · type to filter
```

## Install

The wishlist binary lives at `bin/candy-ssh-launcher`. Composer adds it to your global `vendor/bin/` when installed as a project dependency, or you can add the repo's `bin/` to your `$PATH`.

```bash
composer require sugarcraft/candy-ssh-launcher
~/.composer/vendor/bin/candy-ssh-launcher
```

## Configure

`candy-ssh-launcher` resolves its config in this order:

1. `--config <path>` (CLI flag) — wins outright
2. `wishlist.yml` / `wishlist.yaml` / `wishlist.json` in the **current directory** (first that exists) <!-- @rename-keep -->
3. `~/.config/wishlist.yml` / `.yaml` / `.json` (only when `$HOME` is set)

So a config in the directory you launch from takes precedence over your home config. Other flags:

* `--ssh <binary>` — absolute path to the ssh executable (default `/usr/bin/ssh`). `pcntl_exec` does
  not search `$PATH`, so a bare `ssh` will be rejected; the path must exist and be executable.
* `--help` — print the usage line and exit 0. Unrecognised `--flags` exit 2 naming the offending arg.

### YAML

```yaml
- name: production
  host: prod.example.com
  port: 2222
  user: deploy
  identity_file: ~/.ssh/prod-deploy

- name: staging
  host: stage.example.com
  user: deploy

- name: jumpbox
  host: bastion.example.com
  options:
    - ServerAliveInterval=30
    - ProxyJump=gw.example.com
```

### JSON

```json
[
  { "name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy" },
  { "name": "staging",    "host": "stage.example.com" }
]
```

## Keybindings

| Key       | Action                          |
|-----------|---------------------------------|
| ↑ / k     | Move up                         |
| ↓ / j     | Move down                       |
| Enter     | Connect to highlighted endpoint |
| Esc / ^C  | Quit without connecting         |
| (typing)  | Type-to-filter; Backspace clears|

## Implementation

The picker is a tiny standalone widget — not a full SugarBits `List`. The lifecycle is

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

Precedence follows `ssh_config(5)`: *for each parameter, the first obtained value will be used.* A `Host *` block wins only where it appears before any matching specific block — conventionally it is written last and therefore acts as a fallback. `IdentityFile` is the documented exception and accumulates in file order. Host patterns are used as the endpoint name (when no `HostName` is specified, the pattern itself becomes the host).

## Programmatic use

```php
use SugarCraft\SshLauncher\Config;
use SugarCraft\SshLauncher\Picker;
use SugarCraft\SshLauncher\Launcher;

$endpoints = Config::load('/etc/wishlist.yml');
$picked    = (new Picker())->pick($endpoints);
if ($picked !== null) {
    (new Launcher())->dispatch($picked);
}

// Or import from SSH config:
$sshEndpoints = Config::importFromSshConfig('/home/user/.ssh/config');
```

## Shared foundations

candy-ssh-launcher uses [candy-fuzzy](https://github.com/detain/sugarcraft#candy-fuzzy) — `SmithWatermanMatcher::matchAll()` replaces ad-hoc `str_contains`-style filtering. The picker now surfaces scored ranking and match-highlight indices (ANSI bold+cyan on matched characters) for ranked, highlighted filter results.

## Status

SSH config import is supported. 257 tests / 1160 assertions. Endpoint, Config (JSON + flat-YAML + SSH config), Picker, Launcher, SshConfigParser are all covered.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
