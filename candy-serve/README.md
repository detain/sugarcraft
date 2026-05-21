<img src=".assets/icon.png" alt="candy-serve" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-serve)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-serve)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-serve?label=packagist)](https://packagist.org/packages/sugarcraft/candy-serve)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyServe

PHP port of [charmbracelet/soft-serve](https://github.com/charmbracelet/soft-serve) — the mighty, self-hostable Git server for the command line.

## Overview

CandyServe is a self-hostable Git server you run on a VPS or machine. Users authenticate via SSH public keys and can:

- **Browse** repos, files, and commits via a terminal TUI over SSH
- **Clone** repos over SSH (`git clone ssh://user@host/repo`), HTTP, or Git protocol
- **Push** to create repos on demand
- **Collaborate** via per-repo access control with SSH public keys
- **Use Git LFS** for large file storage

## Architecture

```
candy-serve/
├── bin/soft-serve                Entry point (serve command)
├── src/
│   ├── Config.php                YAML config loader
│   ├── Repo.php                  Bare Git repo (init, access, metadata)
│   ├── User.php                  SSH public key auth + user model
│   ├── AccessControl.php         Permissions (admin/read/write)
│   ├── Lang.php                 i18n strings
│   ├── SSH/
│   │   ├── SSHServer.php        libssh2-based SSH server
│   │   ├── Auth.php             Public key authentication
│   │   └── Commands.php         git-upload-pack / git-receive-pack
│   ├── Git/
│   │   ├── GitDaemon.php        Real daemon with socket connections, PID file, signal handling
│   │   ├── UploadPack.php       git-upload-pack (clone/fetch)
│   │   └── ReceivePack.php      git-receive-pack (push)
│   ├── HttpSmartProtocol/
│   │   └── Server.php           HTTP smart protocol server (git-over-HTTP)
│   ├── Clipboard/
│   │   └── Osc52.php            OSC 52 clipboard handler
│   └── LFS/
│       ├── LFSHandler.php       Git LFS batch API
│       ├── LocalStorageBackend.php
│       └── LFSStorageBackendInterface.php
├── cmd/
│   └── serve.php                 Serve command implementation
└── tests/
```

## Install

```bash
composer install
```

## Configuration

Create `config.yaml` in your data directory:

```yaml
name: "My Git Server"
ssh:
  listen_addr: ":23231"
  public_url: "ssh://localhost:23231"
  key_path: "ssh/soft_serve_host"
  idle_timeout: 120
git:
  listen_addr: ":9418"
http:
  listen_addr: ":23232"
  public_url: "http://localhost:23232"
db:
  driver: "sqlite"
  data_source: "candy-serve.db"
lfs:
  enabled: true
```

## Run

```bash
# Set admin SSH key (your public key)
export CANDY_SERVE_INITIAL_ADMIN_KEYS="ssh-ed25519 AAAA... user@host"

# Start the server
CANDY_SERVE_DATA_PATH=/var/lib/candy-serve composer serve
```

## SSH Access

```bash
# Connect to TUI
ssh -p 23231 user@your-server

# Clone a repo
git clone ssh://user@your-server:23231/repo-name

# Browse repo tree
ssh -p 23231 user@your-server repo tree repo-name

# View a file with syntax highlighting
ssh -p 23231 user@your-server repo blob repo-name path/to/file.php -c -l
```

## HTTP Smart Protocol

CandyServe supports Git clone/fetch/push over HTTP using the smart protocol (not the dumb HTTP transport).

```bash
# Clone over HTTP
git clone http://user@your-server:23232/repo-name.git

# Authenticate with Basic auth (when required)
git clone http://username:token@your-server:23232/repo-name.git
```

The smart protocol flow:
1. Client GETs `/repo.git/info/refs?service=git-upload-pack` — receives ref advertisement
2. Client POSTs `/repo.git/git-upload-pack` — exchanges pack data for fetch/clone
3. For push: Client POSTs `/repo.git/git-receive-pack` — sends pack and receives status

Authentication uses HTTP Basic auth or the `X-CandyServe-User` header.

## Git Protocol (Daemon Mode)

CandyServe can run as a real background daemon serving Git clone/fetch/push over the native Git protocol on port 9418.

```bash
# Start as a daemon (forks to background, writes PID file)
CANDY_SERVE_DATA_PATH=/var/lib/candy-serve composer serve --daemon --pid-file /var/run/candy-serve-git.pid

# Run in foreground (shows banner and repo list, stays attached)
CANDY_SERVE_DATA_PATH=/var/lib/candy-serve composer serve
```

**Daemon mode behavior:**
- Uses `pcntl_fork()` to detach and become a session leader
- Listens on `git.listen_addr` from `config.yaml` (default `:9418`)
- Writes PID to `--pid-file` (or `<data_path>/git-daemon.pid` by default)
- Handles `SIGTERM`, `SIGINT`, and `SIGHUP` for graceful shutdown
- Cleans up PID file and closes all connections on exit

**Signal handling:**
- `SIGTERM` / `SIGINT` — graceful shutdown (closes connections, removes PID file)
- `SIGHUP` — reload configuration (restarts the daemon)

**Clone over Git protocol:**
```bash
# Anonymous clone (for public repos)
git clone git://your-server:9418/repo-name

# The git protocol is stateless; access is controlled per-repo (public/private)
```

The Git protocol supports:
- `git-upload-pack` — clone and fetch (read access)
- `git-receive-pack` — push (write access, requires collaborator permission)

## OSC 52 Clipboard

The TUI supports clipboard operations via OSC 52 (Operating System Command 52). This enables:
- Copying repo URLs, file content, or commit hashes from the TUI to the system clipboard
- Reading clipboard content into the TUI (e.g., for pasting)

Supported selections:
- `c` — system clipboard (default)
- `p` — primary selection (X11)
- `s` — secondary selection

## Repo Permissions

- **Public** — anyone can read, only collaborators can push
- **Private** — only collaborators can read or push
- **Collaborators** — added by admin via SSH public key

## License

[MIT](LICENSE)
