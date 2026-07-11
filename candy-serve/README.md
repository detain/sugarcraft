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
│   ├── Config.php                YAML config loader (symfony/yaml)
│   ├── Repo.php                  Bare Git repo (init, access, metadata)
│   ├── Visibility.php            Repo visibility enum (public/collaborator-only/private)
│   ├── User.php                  SSH public key auth + user model
│   ├── AccessControl.php         Permissions (admin/read/write)
│   ├── Stats.php                 Counters (connections, packs, LFS)
│   ├── StatsServer.php           JSON stats endpoint on stats.listen_addr
│   ├── Lang.php                 i18n strings
│   ├── Jobs/
│   │   ├── Schedule.php         "@every 10m"-style job schedules
│   │   └── MirrorPuller.php     Mirror-pull background job
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
  max_pack_bytes: 268435456
db:
  driver: "sqlite"
  data_source: "candy-serve.db"
lfs:
  enabled: true
jobs:
  mirror_pull: "@every 10m"
stats:
  listen_addr: ":23233"
```

Config files are parsed with [symfony/yaml](https://symfony.com/doc/current/components/yaml.html),
so full YAML 1.2 (block nesting, flow maps, anchors, …) is supported.
Quote values that start with a YAML reserved indicator — listen
addresses like `":23232"` and schedules like `"@every 10m"`.

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

### See also — SSH middleware (candy-wish)

`SSH\SSHServer` is a `ForceCommand`-style git-shell gate: it parses a single
`git-upload-pack`/`git-receive-pack <repo>` request, validates the repo name,
and dispatches to `Git\UploadPack`/`Git\ReceivePack`. It does not implement the
SSH wire protocol — it expects to run under the host's OpenSSH daemon via
`ForceCommand`.

For a general SSH middleware/session framework (middleware chain, `ForceCommand`
supervisor, `Session`, transports), see the sibling
[`candy-wish`](../candy-wish). Both run under host sshd; neither implements the
SSH wire protocol itself. Their auth models differ deliberately: candy-serve
does its own SSH public-key auth (`SSH\Auth` + `User`), while candy-wish trusts
sshd and allowlists by username + key fingerprint.

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

### Async daemon mode (ReactPHP, opt-in)

The daemon is dual-mode. `serve()` runs the classic blocking
`socket_select()` loop (the default shown above). `serveAsync()` is the
opt-in [ReactPHP](https://reactphp.org/) path: connections are accepted
and read through the event loop, so a host application can run the Git
daemon alongside timers, HTTP servers, and other sockets on one loop.

```php
use React\EventLoop\Loop;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Git\GitDaemon;

$daemon = new GitDaemon(Config::load('/var/lib/candy-serve/config.yaml'));
$daemon->registerRepos($repos);

// Returns a promise that resolves with exit code 0 on graceful stop.
$promise = $daemon->serveAsync();           // global loop, or pass your own
echo $daemon->listenAddress(), "\n";        // actual bound addr (port 0 = ephemeral)

Loop::addTimer(3600, fn () => $daemon->shutdown());  // graceful stop from loop code
Loop::run();
```

Both modes share the same protocol code; `shutdown()` (or a
`SIGTERM`/`SIGINT`) tears down every loop registration — server stream,
per-client streams, housekeeping timer — unsubscribes candy-async
`Subscriptions`, closes connections, and removes the PID file. The
per-request work (ref advertisement, `git pack-objects`, `git
update-ref`) is the same synchronous code the blocking mode runs; it
executes inside the readiness callback.

LFS batches have the same split: `LFSHandler::handleBatch()` is the
synchronous path, `handleBatchAsync()` resolves the identical response
via the loop with at most `concurrentTransfers` objects in flight
(bounded by `SugarCraft\Serve\Support\PromisePool`). Note that per-object
storage inspection is still synchronous file I/O inside its loop tick —
the async path bounds *scheduling*, it does not make `file_get_contents`
asynchronous.

## Git LFS over HTTP

The smart-HTTP server speaks the [Git LFS batch API](https://github.com/git-lfs/git-lfs/blob/main/docs/api/batch.md)
plus basic object transfer when `lfs.enabled` is true:

| Route | Method | Purpose |
|---|---|---|
| `/{repo}/info/lfs/objects/batch` | POST | Batch API — advertises download/upload/verify actions |
| `/{repo}/info/lfs/objects/{oid}` | GET | Download an object |
| `/{repo}/info/lfs/objects/{oid}` | PUT | Upload an object |
| `/{repo}/info/lfs/objects/{oid}/verify` | POST | Post-upload existence + size check |

Rules enforced at the HTTP boundary:

- OIDs must be full lowercase SHA-256 hex (64 chars) — anything else is a 400.
- Downloads (and `download` batches) require read access; uploads,
  verifies, and `upload` batches require write access — the same auth
  (Basic or `X-CandyServe-User`) as the pack endpoints.
- Uploads are capped by `http.max_pack_bytes` (413 when exceeded) and the
  body's SHA-256 must match the OID (422 on mismatch).

Objects are stored via `LFS\LocalStorageBackend` under `<data_path>/lfs/`
using the standard `aa/bb/oid` fan-out layout.

## Mirrors

A repo becomes a mirror by giving it an upstream pull URL:

```php
use SugarCraft\Serve\Jobs\MirrorPuller;
use SugarCraft\Serve\Repo;

$mirror = Repo::new('linux', '/var/lib/candy-serve/repositories/linux')
    ->withMirrorFrom('https://github.com/torvalds/linux.git');

$puller = new MirrorPuller($config);   // parses jobs.mirror_pull
$puller->registerRepo($mirror);

// Async daemon: pull due mirrors on the event loop every interval.
$puller->attach($loop);

// Blocking host / cron: pull due mirrors right now.
$puller->runOnce();
```

Each due mirror is refreshed with
`git -C <path> fetch --prune <url> '+refs/*:refs/*'` (the full-mirror
refspec, so refs deleted upstream prune locally). The `jobs.mirror_pull`
schedule accepts `@every <duration>` with Go-style durations (`30s`,
`10m`, `8h`, `1h30m`) and the aliases `@hourly`, `@daily`, `@midnight`,
`@weekly`, `@monthly`, `@yearly` — full cron expressions are not
supported and throw. Failed pulls are logged and re-attempted on the
next interval.

## Stats endpoint

When `stats.listen_addr` is configured, `StatsServer` serves the shared
`Stats` counters as JSON:

```php
use SugarCraft\Serve\StatsServer;

$stats = new StatsServer($config);   // reads stats.listen_addr
$stats->start($loop);                // ReactPHP loop only
```

```console
$ curl -s localhost:23233/stats
{"uptime_seconds":42.1,"connections":7,"pack_uploads":1,"pack_downloads":4,
 "lfs_batch_requests":2,"lfs_object_downloads":2,"lfs_object_uploads":1}
```

Counters are recorded by the Git daemon (both modes), the smart-HTTP
server, and the LFS handler into the shared `Stats::getInstance()`
(injectable per-object via `setStats()` for tests/hosts). The stats
server itself runs only on a ReactPHP loop — there is deliberately no
blocking mode, since a blocking stats listener would starve the servers
it reports on; hosts running only the blocking `GitDaemon::serve()`
loop simply don't get a stats endpoint.

## OSC 52 Clipboard

The TUI supports clipboard operations via OSC 52 (Operating System Command 52). This enables:
- Copying repo URLs, file content, or commit hashes from the TUI to the system clipboard
- Reading clipboard content into the TUI (e.g., for pasting)

Supported selections:
- `c` — system clipboard (default)
- `p` — primary selection (X11)
- `s` — secondary selection

## Repo Permissions

Visibility is a single `SugarCraft\Serve\Visibility` enum on `Repo`
(replacing the old paired `isPublic`/`private` booleans; the boolean
accessors remain as BC delegates):

- **`Visibility::Public`** — anyone can read; only collaborators can
  push (or anyone, when `allowPush` is set)
- **`Visibility::CollaboratorOnly`** — not readable anonymously; only
  collaborators and admins
- **`Visibility::Private`** — only collaborators and admins can read or
  push

```php
$repo = Repo::new('secret', $path)->withVisibility(Visibility::Private);
$repo->isPrivate();        // true
$repo->isPublic;           // false (BC readonly property)
$repo->isVisiblePublic();  // false
```

Collaborators are added by an admin via SSH public key.

## Shared foundations

candy-serve uses [candy-async](https://github.com/detain/sugarcraft/tree/master/candy-async) for graceful shutdown via subscriptions on Git daemon connections.

## License

[MIT](LICENSE)
