<img src=".assets/icon.png" alt="sugar-wishlist" width="160" align="right">

# SugarWishlist

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-wishlist)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-wishlist)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-wishlist?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-wishlist)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/picker.gif)

PHP port of [`charmbracelet/wishlist`](https://github.com/charmbracelet/wishlist) — a TUI directory of SSH endpoints. Launch `wishlist`, pick a host, hit Enter, and the current process is replaced with `ssh` connecting to it.

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

`wishlist` looks for, in order:

1. `--config <path>` (CLI flag)
2. `~/.config/wishlist.yml`
3. `~/.config/wishlist.yaml`
4. `~/.config/wishlist.json`
5. `wishlist.{yml,yaml,json}` in the current directory

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
```

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

`Host *` global defaults are inherited by all subsequent host blocks. Host patterns are used as the endpoint name (when no `HostName` is specified, the pattern itself becomes the host).

## Programmatic Use

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

sugar-wishlist uses [candy-fuzzy](https://github.com/detain/sugarcraft#candy-fuzzy) — `SmithWatermanMatcher::matchAll()` replaces ad-hoc `str_contains`-style filtering. The picker now surfaces scored ranking and match-highlight indices (ANSI bold+cyan on matched grapheme clusters) for ranked, highlighted filter results.

## Status

Phase 10.28 — SSH config import. 69 tests / 176 assertions. Endpoint, Config (JSON + flat-YAML + SSH config), Picker, Launcher, SshConfigParser are all covered.
