<img src=".assets/icon.png" alt="sugar-wishlist" width="160" align="right">

# SugarWishlist

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
composer require candycore/sugar-wishlist
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
use CandyCore\Wishlist\Config;
use CandyCore\Wishlist\Picker;
use CandyCore\Wishlist\Launcher;

$endpoints = Config::load('/etc/wishlist.yml');
$picked    = (new Picker())->pick($endpoints);
if ($picked !== null) {
    (new Launcher())->dispatch($picked);
}
```

## Status

Phase 9+ — first cut. 26 tests / 67 assertions. Endpoint, Config (JSON + flat-YAML), Picker, Launcher are all covered.
