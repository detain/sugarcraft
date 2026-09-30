<img src=".assets/icon.png" alt="sugar-wishlist" width="160" align="right">

# SugarWishlist

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-wishlist)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-wishlist)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-wishlist?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-wishlist)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/picker.gif)

A PHP take on the concept of [`charmbracelet/wishlist`](https://github.com/charmbracelet/wishlist) (Charmed's SSH host directory, itself inspired by Charlie Gleason's original `wishlist`) — a TUI directory of SSH endpoints. Launch `wishlist`, pick a host, hit Enter, and the current process is replaced with `ssh` connecting to it.

This is a re-implementation, not a format-compatible port: the config schema here is a **flat top-level list** of endpoint objects. Upstream's nested `host:`-keyed `wishlist.yml` is deliberately *not* accepted — feeding one in fails loudly with `wishlist yaml: unparseable line`, never silently.

```
── wishlist ──
filter:
▸ production  ─  deploy@prod.example.com:2222
  staging     ─  stage.example.com
  dev         ─  dev.example.com

  ↑/↓ select · Enter connect · Esc quit · type to filter
```

## Install

The wishlist binary lives at `bin/wishlist`. Composer adds it to your global `vendor/bin/` when installed as a project dependency, or you can add the repo's `bin/` to your `$PATH`.

```bash
composer require sugarcraft/sugar-wishlist
~/.composer/vendor/bin/wishlist
```

## Configure

`wishlist` resolves its config in this order:

1. `--config <path>` (CLI flag) — wins outright
2. `wishlist.yml` / `wishlist.yaml` / `wishlist.json` in the **current directory** (first that exists)
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

`wishlist` can import endpoints directly from your OpenSSH config file (`~/.ssh/config`):

```php
use SugarCraft\Wishlist\Config;

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
use SugarCraft\Wishlist\Config;
use SugarCraft\Wishlist\Picker;
use SugarCraft\Wishlist\Launcher;

$endpoints = Config::load('/etc/wishlist.yml');
$picked    = (new Picker())->pick($endpoints);
if ($picked !== null) {
    (new Launcher())->dispatch($picked);
}

// Or import from SSH config:
$sshEndpoints = Config::importFromSshConfig('/home/user/.ssh/config');
```

## Shared foundations

sugar-wishlist uses [candy-fuzzy](https://github.com/detain/sugarcraft#candy-fuzzy) — `SmithWatermanMatcher::matchAll()` replaces ad-hoc `str_contains`-style filtering. The picker now surfaces scored ranking and match-highlight indices (ANSI bold+cyan on matched characters) for ranked, highlighted filter results.

## Status

Phase 10.28 — SSH config import. 257 tests / 1160 assertions. Endpoint, Config (JSON + flat-YAML + SSH config), Picker, Launcher, SshConfigParser are all covered.
