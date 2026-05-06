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
├── bin/soft-serve          Entry point (serve command)
├── src/
│   ├── Server.php          Config (SSH/HTTP/Git daemon listen addrs)
│   ├── Config.php          YAML config loader
│   ├── Repo.php            Bare Git repo (init, access, metadata)
│   ├── User.php            SSH public key auth + user model
│   ├── AccessControl.php   Permissions (admin/read/write)
│   ├── SSH/
│   │   ├── SSHServer.php   libssh2-based SSH server
│   │   ├── Auth.php        Public key authentication
│   │   └── Commands.php    git-upload-pack / git-receive-pack
│   ├── Git/
│   │   ├── Protocol.php    Smart HTTP Git protocol handler
│   │   ├── UploadPack.php  git-upload-pack (clone/fetch)
│   │   └── ReceivePack.php git-receive-pack (push)
│   └── LFS/
│       └── LFSHandler.php  Git LFS batch API
├── cmd/
│   └── serve.php           Serve command implementation
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

## Repo Permissions

- **Public** — anyone can read, only collaborators can push
- **Private** — only collaborators can read or push
- **Collaborators** — added by admin via SSH public key

## License

[MIT](LICENSE)
