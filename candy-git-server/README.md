<img src=".assets/icon.png" alt="candy-git-server" width="160" align="right">

> Formerly published as `sugarcraft/candy-serve` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Serve\` → `SugarCraft\GitServer\`. <!-- @rename-keep -->
> Env compatibility: `CANDY_SERVE_*` exports are still honored via `LegacyEnv` (deprecated, stderr-noticed). <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-git-server)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-git-server)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-git-server?label=packagist)](https://packagist.org/packages/sugarcraft/candy-git-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyGitServer

candy-git-server — a self-hostable Git server, for PHP 8.3+. The
smart-HTTP transport (clone, fetch, push, LFS) runs as a real service via
`serve --http`; the SSH and git-daemon transports are libraries awaiting
host wiring.

## Overview

This package is a PHP port of pieces of the charmbracelet/soft-serve Go
project. The domain classes are real and individually tested, and the
**smart-HTTP transport is a running service**: `serve --http` starts a
listener that real `git clone`, `git fetch`, and `git push` succeed
against, with LFS batch and object routes alongside — and the e2e suite
drives the launched `bin/candy-git-server` process itself over a live
socket (`tests/HttpSmartProtocol/BinServeLiveE2ETest.php`), covering
anonymous clone, authenticated push, fail-closed refusal, and graceful
SIGTERM shutdown. Every other
transport stays assembly-less: the default `serve` still refuses, and so
do `--daemon`/`--pid-file`:

```console
$ candy-git-server serve
candy-git-server: 'serve' refuses to start — server assembly is not
implemented; see docs (status: scaffold).
$ echo $?
1
```

The bare command refuses loudly (non-zero exit, honest stderr) rather than
printing a startup banner while serving nothing — the ssh and git-daemon
transports remain unwired libraries. On the live listener
push is served: authorization runs before a single ref byte or pack body
is touched, and hook declines ride back in-band in the report-status
stream. Nothing forks per connection — but every served request spawns
bounded, reaped `git` children.

**What is implemented:**

- `Config` — YAML config loader (symfony/yaml) with typed accessors
- `Repo` — bare-repository model: init (Git at `<path>/.git`), refs,
  branches/tags, file reads, mirror metadata, visibility
- `User`, `AccessControl`, `Visibility` — user model (passwords stored
  only as `password_hash` digests), per-repo permission checks,
  visibility enum
- `UserStore` — accounts persist atomically in `<data_path>/users.json`
  (temp-file + rename, mode `0600`, corrupt store refuses to load)
- `Git\PktLine` — the single pkt-line codec (spec `%04x` ASCII-hex length
  prefixes, `0000` flush, tolerant decode); it replaced four ad-hoc
  binary-framed emitters
- `Git\StatelessRpc` — spawns the real `git upload-pack/receive-pack
  --stateless-rpc` children the HTTP read path delegates to (bounded
  stdin write + bounded drain on one deadline, cap refusal, TERM→KILL
  reap per spawn)
- `Git\UploadPack`, `Git\ReceivePack` — authorize, then delegate to the
  real `git upload-pack` / `git receive-pack` over a bounded stdio pump
  (`Git\StdioPipe`); the SSH `ForceCommand` dispatch path uses these
- `Git\GitDaemon` — git-daemon protocol class: read-only by design,
  answers the `git-upload-pack` advertisement via a real
  `git upload-pack --advertise-refs` child and refuses `git-receive-pack`
  outright. No shipped entrypoint constructs it (library-level), and
  completing a fetch over `git://` is open work (Known gaps)
- `HttpSmartProtocol\Server` — request router for the Git smart-HTTP
  clone/fetch/push flow and the LFS batch + object APIs, all behind the
  same access-control gates
- `HttpSmartProtocol\HttpListener` — the HTTP/1.1 socket server that
  hosts that router, started only by `serve --http`
- `SSH\SSHServer` — a `ForceCommand`-style git-shell gate for host sshd
  (parses one `git-upload-pack`/`git-receive-pack` request and dispatches;
  does **not** implement the SSH wire protocol)
- `LFS\LFSHandler` + `LocalStorageBackend` — LFS batch API + object store
- `Jobs\Schedule`, `Jobs\MirrorPuller` — `@every`/alias schedules and
  mirror-pull job logic
- `Stats`, `StatsServer` — counters + JSON stats endpoint class
- `Support\PromisePool`, `Support\Version` — bounded-concurrency helper,
  phar build-stamp resolver
- `bin/candy-git-server` — CLI: `serve` (refuses), `serve --http
  --repo-root DIR` (starts the smart-HTTP listener), `init` (writes a
  config skeleton), `repo create|list|info` (`--public` marks a repo
  public via an on-disk visibility marker — anonymous serving additionally
  requires the `git.allow_anonymous_read` lever, default off), `user
  add|key|list|token` (persisted accounts, hashed passwords, expiring
  proxy-token minting), `help`, `version`

**What does not exist:** any TUI (there is no terminal UI of any kind); a
listener on `:23231`/`:23232`/`:9418` by default (`serve --http` binds only
the address you pass it); a git-daemon transport that completes fetches
(advertisement only) or takes pushes (refused by design); a shipped SSH
host wiring (the `ForceCommand` dispatcher works end-to-end once your
sshd routes git commands into it — the deployment recipe is open work).
`Clipboard/Osc52.php` is a standalone OSC 52 encoder with zero consumers —
it was written for a TUI that was never built.

## Architecture (actual files)

```
candy-git-server/
├── bin/candy-git-server            CLI (serve refuses; serve --http listens; repo/init work)
├── src/
│   ├── Config.php                  YAML config loader (symfony/yaml)
│   ├── Repo.php                    Bare Git repo (init at <path>/.git, refs, metadata)
│   ├── Visibility.php              Repo visibility enum (public/collaborator-only/private)
│   ├── User.php                    User model + SSH public-key matching (no persistence)
│   │   ├── UserStore.php             Accounts persisted to <data_path>/users.json (atomic writes)
│   ├── AccessControl.php           Permissions (admin/read/write)
│   ├── Stats.php                   Counters (connections, packs, LFS)
│   ├── StatsServer.php             JSON stats endpoint class (needs a host to start it)
│   ├── Lang.php                    i18n strings
│   ├── Jobs/
│   │   ├── Schedule.php            "@every 10m"-style job schedules
│   │   └── MirrorPuller.php        Mirror-pull background job
│   ├── SSH/
│   │   └── SSHServer.php           ForceCommand git-shell gate (no SSH wire protocol)
│   ├── Git/
│   │   ├── GitDaemon.php           Socket daemon class (un-assembled; see Known gaps)
│   │   ├── PktLine.php             The pkt-line codec (spec framing, flush, tolerant decode)
│   │   ├── StatelessRpc.php        git --stateless-rpc children for the HTTP read path
│   │   ├── StdioPipe.php           Bounded client<->git-child stdio pump (daemon path)
│   │   ├── UploadPack.php          git-upload-pack handler (daemon/CGI shape)
│   │   └── ReceivePack.php         git-receive-pack handler (daemon/CGI shape)
│   ├── HttpSmartProtocol/
│   │   ├── HttpListener.php        HTTP/1.1 socket server (serve --http hosts the router)
│   │   └── Server.php              Smart-HTTP + LFS router (clone, fetch, push and LFS live)
│   ├── Clipboard/
│   │   └── Osc52.php               OSC 52 encoder (orphan: no TUI consumes it)
│   ├── LFS/
│   │   ├── LFSHandler.php          Git LFS batch API
│   │   ├── LocalStorageBackend.php aa/bb/oid object store
│   │   └── LFSStorageBackendInterface.php
│   └── Support/
│       ├── BoundedCommand.php      argv-only subprocess runner: caps, deadline, reaping
│       │   ├── PromisePool.php         Bounded-concurrency helper
│       └── Version.php             Phar build-stamp resolver
├── examples/                       config.php, repo.php (library usage)
├── tools/build-phar.php            phar build script
└── tests/
```

## Install

```bash
git clone https://github.com/sugarcraft/candy-git-server
cd candy-git-server
composer install
bin/candy-git-server help
```

As a library consumer: `composer require sugarcraft/candy-git-server`.
The shipped single-file build (rebuilt and published on every push to
master) needs only PHP:

```bash
curl -LO https://github.com/detain/sugarcraft/releases/download/candy-git-server/dev/candy-git-server.phar
chmod +x candy-git-server.phar
php candy-git-server.phar serve --http --repo-root /srv/git/repositories
```

Both the read and the push paths delegate to the **system `git` binary**
(tested against git 2.43): advertisement, negotiation, pack building, the
receive-pack relay child and its post-receive hooks all run through it.
Without a `git` on `PATH`, `serve --http` starts but every request answers
500 and the protocol pins skip loudly.

The `ext-sockets` entry in `require` is a hard floor, not a formality:
`Git\GitDaemon`'s blocking listen path calls `socket_create()` directly,
and a missing socket extension is an undefined-function fatal at the call,
not a refusal the class can answer with — there is no load-time guard that
would let it live in `suggest`. (The opt-in async daemon mode and the
smart-HTTP listener bind through core `stream_socket_server` and never
touch ext-sockets; it is the blocking daemon seat that pins the require.)

## Configuration

Create `config.yaml` in your data directory (`candy-git-server init`
emits a skeleton). The census below is authoritative: which keys
`Config` actually parses, and which shipped code path consults each
one. Keys marked dead are accepted and held on the `Config` object for
the host wiring that would use them — reading them today changes
nothing.

| Key | Parsed by `Config` | Consumed by |
|---|---|---|
| `name` | yes | the example script only — identity string |
| `log_format` | yes | **dead** |
| `ssh.listen_addr`, `ssh.public_url`, `ssh.key_path`, `ssh.client_key_path`, `ssh.max_timeout`, `ssh.idle_timeout` | yes | **dead** (the SSH host wiring that would use them is unwired scaffold) |
| `git.listen_addr` | yes | `GitDaemon::serve()`/`serveAsync()` bind |
| `git.max_timeout` | yes | smart-HTTP stateless git-child deadline |
| `git.idle_timeout` | yes | `GitDaemon` idle connection timer |
| `git.max_connections` | yes | `GitDaemon` capacity (eviction past the cap) |
| `git.allow_anonymous_read` | yes | the anonymous-read lever (default off) |
| `http.listen_addr` | yes | **flags only** — `serve --http` binds `--http-host`/`--http-port`, never this key |
| `http.public_url` | yes | LFS batch hrefs |
| `http.tls_key_path`, `http.tls_cert_path` | yes | validation only — either one set refuses at load (`config.tls_not_supported`); the lib never terminates TLS |
| `http.max_pack_bytes` | yes | the single request-body cap (pack POST + LFS object PUT) |
| `http.user_trust_mode`, `http.trusted_proxies`, `http.auth_secret` | yes | header identity trust modes (see Authentication) |
| `lfs.enabled` | yes | the LFS route gate |
| `lfs.ssh_enabled` | yes | **dead** |
| `jobs.mirror_pull` | yes | `Jobs\MirrorPuller` schedule |
| `stats.listen_addr` | yes | `StatsServer` bind (hostless `:PORT` resolves to loopback) |
| `db` | **no** | `Config` has no database concept; a `db:` block in the YAML is silently ignored |

```yaml
name: "My Git Server"
git:
  listen_addr: ":9418"           # GitDaemon bind (class-level daemon)
  allow_anonymous_read: false    # anonymous-read lever, default off
http:
  listen_addr: ":23232"          # parsed; serve --http uses its CLI flags
  public_url: "http://localhost:23232"
  max_pack_bytes: 268435456
lfs:
  enabled: true
jobs:
  mirror_pull: "@every 10m"
stats:
  listen_addr: ":23233"          # hostless bind stays on loopback
```

Config files are parsed with [symfony/yaml](https://symfony.com/doc/current/components/yaml.html),
so the practical YAML subset its parser implements is supported (nested
block mappings and sequences, flow collections, scalars, comments).
Quote values that start with a YAML reserved indicator — listen
addresses like `":23232"` and schedules like `"@every 10m"`.

## Run

The default `serve` still refuses with exit 1 (see Overview). Two things
run for real: the local-data CLI pieces, and the opt-in smart-HTTP read
listener:

```bash
# clone, fetch, push (auth-gated) and LFS all ride this one listener
candy-git-server serve --http --repo-root /var/lib/candy-git-server/repositories \
    --http-port 23232
git clone http://localhost:23232/myrepo.git
```

`serve --http` flags: `--repo-root DIR` (required; every bare dir and every
`<dir>/.git` under it is registered under its basename), `--http-port N`
(default `23232`), `--http-host H` (default `127.0.0.1`), `--config F`
(the YAML config, defaults applied when absent). `--port` is accepted as a deprecated alias of `--http-port` (a notice lands on stderr); `--daemon`, `--pid-file` and `--verbose` are refused on this path — the listener always stays in the foreground. Exit codes: `1` for a
flag or bind error, `0` after SIGINT/SIGTERM shutdown (the graceful exit
requires ext-pcntl — without it the terminal's default signal action kills
the process and the shell sees 130/143). Anonymous reads
are served only when `git.allow_anonymous_read: true` is configured
(default off — fail-closed); unauthenticated requests for gated content
answer 401 with a `WWW-Authenticate: Basic` challenge so git prompts for
credentials. Push requires an account with write access — every account
the CLI creates is an admin (`user add` sets `User::withAdmin(true)`; admin
passes every check, so collaborator-only and `allowPush` scoping is not
reachable through the CLI yet). Accounts and the anonymous-read lever are
taken from the config file: `--config F` makes the data path `dirname(F)`
and `users.json` is read from there; without `--config` the default data
path applies (`CANDY_GIT_SERVER_DATA_PATH`, else
`sys_get_temp_dir()/candy-git-server`), so serving an initialized tree
without `--config` starts a listener with zero accounts and the lever
off. The CLI pieces that work without any listener operate on the local
data directory:

```bash
candy-git-server init /var/lib/candy-git-server   # writes config skeleton + dirs
candy-git-server repo create myrepo /var/lib/candy-git-server   # creates myrepo/.git bare
candy-git-server repo list /var/lib/candy-git-server
candy-git-server repo info myrepo /var/lib/candy-git-server
```

`repo create` makes repos PRIVATE by default; pass `--public` to mark a
repo public (this writes a visibility marker inside the git dir; every
path that resolves a repo — the `serve --http --repo-root` scan and both
embedded transports — consults the marker and defaults to PRIVATE when it
is absent, so directory presence alone never publishes). Anonymous access
additionally requires `git.allow_anonymous_read: true` in config (default
off) — both are required. The
data path resolves as: explicit argument → `CANDY_GIT_SERVER_DATA_PATH`
→ `sys_get_temp_dir()/candy-git-server`; the `/tmp` default is a
convenience for trials, not a deploy location — set a real data path in
production. CLI message locale comes from `CANDY_GIT_SERVER_LANG`
(unknown values fall through to English).

## SSH Access (not shipped)

No SSH server is wired. `SSH\SSHServer` is a `ForceCommand`-style
git-shell gate: it parses a single
`git-upload-pack`/`git-receive-pack <repo>` request, validates the repo name
and the provided SSH public key against a `User`, and dispatches to
`Git\UploadPack`/`Git\ReceivePack`, which delegate to the real git
services over a bounded stdio pump — wired through host sshd today, clone
and push both work end-to-end. It does not implement the SSH wire
protocol — it expects to run under the host's OpenSSH daemon via
`ForceCommand`, and that deployment recipe is not shipped here (Known
gaps).

### See also — SSH middleware (sugar-ssh-server)

For a general SSH middleware/session framework (middleware chain, `ForceCommand`
supervisor, `Session`, transports), see the sibling
[`sugar-ssh-server`](../sugar-ssh-server). Both run under host sshd; neither implements the
SSH wire protocol itself. Their auth models differ deliberately: candy-git-server
does its own SSH public-key matching (`SSH\SSHServer` + `User`), while sugar-ssh-server trusts
sshd and allowlists by username + key fingerprint.

## HTTP Smart Protocol (clone, fetch, push and LFS live behind `serve --http`)

`HttpSmartProtocol\Server` routes the Git smart-HTTP flow and the LFS
batch API; `HttpSmartProtocol\HttpListener` feeds it real sockets when you
run `serve --http`:

1. Client GETs `/repo.git/info/refs?service=git-upload-pack` — a codec-
   framed `# service=` header + flush, then the advertisement bytes of
   `git upload-pack --stateless-rpc --advertise-refs` themselves
2. Client POSTs `/repo.git/git-upload-pack` — the body is fed to
   `git upload-pack --stateless-rpc`; the pack comes back side-banded
3. For push: Client POSTs `/repo.git/git-receive-pack` — after the write
   gate, the body is relayed to a `git receive-pack --stateless-rpc`
   child; oversized bodies answer 413, stalls answer 504, and hook
   declines ride back in-band in the report-status stream

Routes are matched by their final segment: `/alice/repo.git/...` and
`/repo.git/...` reach the same registered repo (`Server::findRepo` falls
back to the basename) — an owner prefix is namespacing sugar, not an
identity boundary.

Both advertisement routes are gated **before** a single ref byte is
built: `git-upload-pack` requires `canRead` (anonymous passes only for
public repos AND only while `git.allow_anonymous_read` is on — default
off), `git-receive-pack` requires `canWrite` (fail-closed false
for anonymous callers, so the old unauthenticated receive-pack
advertisement hole is gone). Authentication uses HTTP Basic auth or the
paired `X-CandyGitServer-User` + `X-CandyGitServer-User-Sig` headers; the
header path is governed by `http.user_trust_mode` — `proxy` trusts
`X-CandyGitServer-User` only from peers listed in `http.trusted_proxies`,
`token` trusts it only with an unexpired `X-CandyGitServer-User-Sig` HMAC
verified against `http.auth_secret`, and `off` (the default) ignores the
headers entirely; each mode needs its own lever, not all of them at once
(Server.php). Deactivated
(`isActive === false`) users authenticate to nothing on either path. Passwords are stored only as `password_hash` digests (cleartext never
touches disk), unauthenticated requests for gated content receive a real
401 + `WWW-Authenticate` challenge, and proxy tokens carry an expiry
(`user token` mints the `<expiry-unix>.<hex-hmac>` value the
`X-CandyGitServer-User-Sig` header carries). Each request is logged
one-per-line to stderr for operators.

## Git Protocol (daemon class only — not assembled)

`Git\GitDaemon` is a class, not a service: nothing in the shipped CLI or
composer scripts constructs it, and it serves the **advertisement only**
— completing a fetch past the advertisement (want/have negotiation and
pack transfer over `git://`) is open work, and pushes are refused at
dispatch by design. The descriptions below document the class surface for
whoever wires it, not a promise of working `git://` clones.

**Class behavior:**
- Repository resolution is marker-honest: registered instances first, then
  a disk fallback under `<data_path>/repositories` that carries the on-disk
  visibility marker — an unregistered directory is fail-closed PRIVATE,
  never public-by-existence
- Runs synchronously in the calling process — no fork-per-connection
  accept loop, but each served request spawns bounded, reaped `git`
  children; daemonize with systemd/supervisord if you host it
- Listens on `git.listen_addr` from `config.yaml` (default `:9418`)
- Writes a PID file only when `serve()`/`serveAsync()` is passed one
  (the argument defaults to the empty string = no PID file is written)
- Handles `SIGTERM`, `SIGINT` (graceful shutdown) and `SIGHUP`
- Cleans up PID file and closes all connections on exit

**Signal handling:**
- `SIGTERM` / `SIGINT` — graceful shutdown (closes connections, removes PID file)
- `SIGHUP` — **also shutdown** (`registerSignalHandlers()` routes all
  three signals to one `handleSignal()`; config reload is not implemented)

### Async daemon mode (ReactPHP, opt-in, library-level)

`serve()` runs a classic blocking `socket_select()` loop. `serveAsync()` is
the opt-in [ReactPHP](https://reactphp.org/) path: connections are accepted
and read through the event loop, so a host application could run the Git
daemon alongside timers, HTTP servers, and other sockets on one loop.

```php
use React\EventLoop\Loop;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;

$daemon = new GitDaemon(Config::load('/var/lib/candy-git-server/config.yaml'));
$repos = [Repo::new('demo', '/var/lib/candy-git-server/repositories/demo')];
$daemon->registerRepos($repos);

// Returns a promise that resolves with exit code 0 on graceful stop.
$promise = $daemon->serveAsync();           // global loop, or pass your own
echo $daemon->listenAddress(), "\n";        // actual bound addr (port 0 = ephemeral)

Loop::addTimer(3600, fn () => $daemon->shutdown());  // graceful stop from loop code
Loop::run();
```

Both modes share the same protocol code; `shutdown()` (or a
`SIGTERM`/`SIGINT`) tears down every loop registration — server stream,
per-client streams, housekeeping timer — unsubscribes sugar-async
`Subscriptions`, closes connections, and removes the PID file. The
per-request work is spawning a real `git upload-pack --advertise-refs`
child through `Git\StdioPipe` (bounded pump, TERM→KILL reap); requests
past the pre-auth byte cap or deadline are closed, and capacity refusal
kicks the NEWCOMER, never an established transfer.

LFS batches have the same split: `LFSHandler::handleBatch()` is the
synchronous path, `handleBatchAsync()` resolves the identical response
via the loop with at most `concurrentTransfers` objects in flight
(bounded by `SugarCraft\GitServer\Support\PromisePool`). Note that per-object
storage inspection is still synchronous file I/O inside its loop tick —
the async path bounds *scheduling*, it does not make `file_get_contents`
asynchronous.

## Git LFS over HTTP (class-level)

The smart-HTTP router implements the [Git LFS batch API](https://github.com/git-lfs/git-lfs/blob/main/docs/api/batch.md)
plus basic object transfer when `lfs.enabled` is true (reachable through
`serve --http`; the routes are pinned end-to-end at router level against
recording backends). Real clients send them under the repository route
with the `.git` suffix (`/{repo}.git/info/lfs/objects/batch`); repo
lookup strips the suffix, so the table's bare spelling and the `.git`
form address the same routes:

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
  (Basic or `X-CandyGitServer-User`) as the pack endpoints.
- Uploads are capped by `http.max_pack_bytes` (413 when exceeded) and the
  body's SHA-256 must match the OID (422 on mismatch); downloads refuse an
  oversize object before it is read, and storage writes publish atomically
  (temp + `0600` + rename, no residue on failure).
- Batch requests validate every entry before touching storage: 64-hex
  oids, sizes within the cap, operations from `{download, upload}`, and a
  transfer list that must include `basic` — malformed entries answer with
  per-object 400 errors, never storage probes.
- TLS keys in config (`http.tls_key_path`/`http.tls_cert_path`) are
  refused at load (`config.tls_not_supported`) — HTTPS termination is
  out of scope for the class.

Objects are stored via `LFS\LocalStorageBackend` under `<data_path>/lfs/`
using the standard `aa/bb/oid` fan-out layout.

## Mirrors

A repo becomes a mirror by giving it an upstream pull URL:

```php
use SugarCraft\GitServer\Jobs\MirrorPuller;
use SugarCraft\GitServer\Repo;

$mirror = Repo::new('linux', '/var/lib/candy-git-server/repositories/linux')
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
`@weekly`, `@monthly`, `@yearly`/`@annually` — full cron expressions are
not supported and throw. Aliases are interval shorthand, not wall-clock
anchors: `@daily` means every 24 h measured from when the schedule was
attached (`@midnight` is the same interval), and every job is due on its
first pass. Failed pulls are logged and re-attempted on the next
interval. As with everything server-shaped, no scheduler host ships:
you call `runOnce()` (cron) or `attach()` (your loop).

## Stats endpoint (class-level)

`StatsServer` serves the shared `Stats` counters as JSON when a host
starts it on `stats.listen_addr`:

```php
use SugarCraft\GitServer\StatsServer;

$stats = new StatsServer($config);   // reads stats.listen_addr
$stats->start($loop);                // ReactPHP loop only
```

```console
$ curl -s localhost:23233/stats
{"uptime_seconds":42.1,"connections":7,"pack_uploads":1,"pack_downloads":4,
  "lfs_batch_requests":2,"lfs_object_downloads":2,"lfs_object_uploads":1}
```

`/`, `/stats` and `/stats.json` all answer the same JSON document.

The endpoint is **unauthenticated**: whatever host starts it publishes
every counter to any client that can reach the address. The safety floor
is the bind: a hostless `stats.listen_addr` like the default `":23233"`
resolves to loopback (`127.0.0.1`), never the wildcard — setting an
explicit non-loopback host is what makes the counters network-visible.

Counters are recorded by the Git daemon class (both modes), the smart-HTTP
class, and the LFS handler into the shared `Stats::instance()`
(injectable per-object via `setStats()` for tests/hosts). The stats
server itself runs only on a ReactPHP loop — there is deliberately no
blocking mode, since a blocking stats listener would starve the servers
it reports on; hosts running only the blocking `GitDaemon::serve()`
loop simply don't get a stats endpoint.

## OSC 52 Clipboard

`Clipboard/Osc52.php` is a standalone OSC 52 escape-sequence encoder
(system/primary/secondary selections). **No TUI consumes it** — there is
no terminal interface in this package. The class is kept because a future
front end would want it; it does nothing today.

## Repo Permissions

Visibility is a single `SugarCraft\GitServer\Visibility` enum on `Repo`
(replacing the old paired `isPublic`/`private` booleans; the boolean
accessors remain as BC delegates):

- **`Visibility::Public`** — readable without credentials only while
  `git.allow_anonymous_read` is on (default off; with the lever down,
  anonymous callers are refused as for any other repo); authenticated
  collaborators can push, and `allowPush` widens push to ANY
  authenticated user — never to anonymous ones (`canWrite` refuses a null
  identity unconditionally)
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

Collaborator lists live on the `Repo` object in memory. Accounts do not:
`user add`/`user key` persist through `UserStore` into
`<data_path>/users.json`, and `serve --http` loads every stored account
at launch.

## Known gaps — open work

Re-derived 2026-11-03 against the live tree, after the review-driven
repair pass. Fixed and covered by pins since the earlier audit: pkt-line
framing through one codec, the unauthenticated receive-pack advertisement,
the `isActive` skip, push wired over smart-HTTP with authorization ahead
of the body, plaintext passwords replaced by `password_hash` digests,
replayable proxy tokens replaced by expiring HMACs (with a mint command),
401 + `WWW-Authenticate` challenges, LFS batch validation parity and
atomic size-capped object storage, persisted accounts and repo
visibility, the daemon's blocking loop / capacity eviction / pre-auth
caps, SSH services delegating to real git, argv-only bounded subprocesses
everywhere, and the extension manifest. What remains open:

1. **git:// completes nothing past the advertisement.** `GitDaemon`
   answers `git-upload-pack` advertisement via a real git child and
   refuses `git-receive-pack` outright; want/have negotiation and pack
   transfer over `git://` (stateless-rpc relay per connection) is open
   work.
2. **No shipped SSH deployment.** The `ForceCommand` dispatcher and its
   delegated git services work end-to-end over inherited stdio, but the
   sshd `command=` wiring recipe (and account key provisioning into
   `authorized_keys` semantics) is operator work not documented here yet.
3. **No process manager.** `serve --http` is one foreground process:
   `Jobs\MirrorPuller` has no built-in host (call `runOnce()` from cron
   or `attach()` to your loop), and `StatsServer` does not start with the
   listener.
4. **Dead config keys.** `log_format`, every `ssh.*` key and
   `lfs.ssh_enabled` are parsed but read by nothing; `name` reaches only
   the example script; `http.listen_addr` is parsed but `serve --http`
   binds from its CLI flags alone; a `db:` block is not even parsed —
   `Config` has no database concept. The Configuration table above is
   the authoritative census. Live keys worth knowing:
   `git.allow_anonymous_read` (default false), `git.max_timeout`
   (stateless child deadline), `git.listen_addr`/`git.idle_timeout`/
   `git.max_connections` (daemon bind, idle timer, capacity),
   `http.max_pack_bytes` (the single request-body cap shared by socket
   and router), `http.public_url` (LFS hrefs), `http.user_trust_mode` +
   `http.trusted_proxies` + `http.auth_secret` (header identity),
   `lfs.enabled`, `jobs.mirror_pull`, `stats.listen_addr`.
5. **No TLS termination.** `http.tls_key_path`/`http.tls_cert_path`
   refuse at config load (`config.tls_not_supported`) by design —
   terminate HTTPS in a reverse proxy.
6. **`SIGHUP` shuts down instead of reloading** — all three signals share
   one handler.
7. **Locale coverage of CLI help copy** — the en strings are the reference
   and every key exists in all 16 locales, but a few translated help
   lines lag en wording changes.
8. **Every CLI account is an admin.** `user add` mints `withAdmin(true)`
   accounts only; non-admin (collaborator-scoped) account creation is
   open work, so the per-repo collaborator/allowPush model is currently
   reachable only by library callers persisting their own users.json.

## Shared foundations

The sugar-async `Subscriptions` machinery is wired into `GitDaemon` for
graceful shutdown registration (present and tested at class level; it
becomes load-bearing only once a host runs the daemon).

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem (soft-serve); SugarCraft is developed as a native PHP project.
