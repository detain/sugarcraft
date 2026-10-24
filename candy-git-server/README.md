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

candy-git-server — **status: scaffold.** Library building blocks for a
self-hostable Git server, for PHP 8.3+. No assembled server ships yet.

## Overview

This package is a PHP port of pieces of the charmbracelet/soft-serve Go
project. The domain classes are real and individually tested, and the
**smart-HTTP read path is now a running service**: `serve --http` starts a
listener that real `git clone`/`git fetch` succeed against (owner ruling
1b, phase 1 — r1024/githttp). Every other transport stays assembly-less:
the default `serve` still refuses, and so do `--daemon`/`--pid-file`:

```console
$ candy-git-server serve
candy-git-server: 'serve' refuses to start — server assembly is not
implemented; see docs (status: scaffold).
$ echo $?
1
```

The bare command refuses loudly (non-zero exit, honest stderr) rather than
printing a startup banner while serving nothing — the ssh and git-daemon
transports remain scaffold until their phases land, and push
(`git-receive-pack` over HTTP) is a pinned phase-2 refusal (501) even on
the live listener. Nothing forks.

**What is implemented:**

- `Config` — YAML config loader (symfony/yaml) with typed accessors
- `Repo` — bare-repository model: init (Git at `<path>/.git`), refs,
  branches/tags, file reads, mirror metadata, visibility
- `User`, `AccessControl`, `Visibility` — user model, per-repo permission
  checks, visibility enum (no persistence layer — see Known gaps)
- `Git\PktLine` — the single pkt-line codec (spec `%04x` ASCII-hex length
  prefixes, `0000` flush, tolerant decode); it replaced four ad-hoc
  binary-framed emitters
- `Git\StatelessRpc` — spawns the real `git upload-pack/receive-pack
  --stateless-rpc` children the HTTP read path delegates to (bounded
  stdin write + bounded drain on one deadline, cap refusal, TERM→KILL
  reap per spawn)
- `Git\UploadPack`, `Git\ReceivePack` — in-process clone/push command
  handlers for the daemon/CGI shapes (framing now goes through the codec;
  the daemon's own dialect still deviates — see Known gaps)
- `Git\GitDaemon` — socket daemon class with PID file + signal handling
  (class-complete, but its protocol dialect deviates from spec and no
  shipped entrypoint constructs it)
- `HttpSmartProtocol\Server` — request router for the Git smart-HTTP and
  LFS batch APIs; the smart-HTTP **read** path is live (advertisement +
  negotiation behind trust gates; push POST answers 501)
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
  --repo-root DIR` (starts the smart-HTTP read listener), `init` (writes
  a config skeleton), `repo create|list|info` (works against the local
  data dir), `user` subcommands (in-memory only — nothing persists),
  `help`, `version`

**What does not exist:** any TUI (there is no terminal UI of any kind); a
listener on `:23231`/`:23232`/`:9418` by default (`serve --http` binds only
the address you pass it); user storage; `git push` end-to-end (phase 2 —
the wire refuses you with 403/501); LFS end-to-end proof (the batch routes
ride the same listener but are pinned only at router level); an SSH or
git-daemon transport that answers traffic.
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
│   │   ├── UploadPack.php          git-upload-pack handler (daemon/CGI shape)
│   │   └── ReceivePack.php         git-receive-pack handler (daemon/CGI shape)
│   ├── HttpSmartProtocol/
│   │   ├── HttpListener.php        HTTP/1.1 socket server (serve --http hosts the router)
│   │   └── Server.php              Smart-HTTP + LFS router (read path live; push 501)
│   ├── Clipboard/
│   │   └── Osc52.php               OSC 52 encoder (orphan: no TUI consumes it)
│   ├── LFS/
│   │   ├── LFSHandler.php          Git LFS batch API
│   │   ├── LocalStorageBackend.php aa/bb/oid object store
│   │   └── LFSStorageBackendInterface.php
│   └── Support/
│       ├── PromisePool.php         Bounded-concurrency helper
│       └── Version.php             Phar build-stamp resolver
├── examples/                       config.php, repo.php (library usage)
├── tools/build-phar.php            phar build script
└── tests/
```

## Install

```bash
composer install
```

The smart-HTTP read path delegates advertisement, negotiation and pack
building to the **system `git` binary** (tested against git 2.43); without
a `git` on `PATH`, `serve --http` starts but every read request answers
500 and the read-path pins skip loudly.

## Configuration

Create `config.yaml` in your data directory (`candy-git-server init`
emits a skeleton). Note: several keys below are parsed but not yet
consumed by any shipped code path (`db`, `ssh.idle_timeout` — see Known
gaps); the listening addresses describe what a future assembly would use.

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

The default `serve` still refuses with exit 1 (see Overview). Two things
run for real: the local-data CLI pieces, and the opt-in smart-HTTP read
listener:

```bash
# phase 1 read path: clone/fetch only — push stays a 501 refusal
candy-git-server serve --http --repo-root /var/lib/candy-git-server/repositories \
    --http-port 23232
git clone http://localhost:23232/myrepo.git
```

`serve --http` flags: `--repo-root DIR` (required; every bare dir and every
`<dir>/.git` under it is registered under its basename), `--http-port N`
(default `23232`), `--http-host H` (default `127.0.0.1`), `--config F`
(the YAML config, defaults applied when absent). Exit codes: `1` for a
flag or bind error, `0` after SIGINT/SIGTERM shutdown; anonymous reads of
public repos are served, private repos answer 403, and `git push` fails on
the wire. The CLI pieces that work without any listener operate on the
local data directory:

```bash
candy-git-server init /var/lib/candy-git-server   # writes config skeleton + dirs
candy-git-server repo create myrepo /var/lib/candy-git-server   # creates myrepo/.git bare
candy-git-server repo list /var/lib/candy-git-server
candy-git-server repo info myrepo /var/lib/candy-git-server
```

`repo create` prints no clone URL on purpose: nothing listens for git
traffic yet. `CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS` is **not** read by any
code (it appears only in a stale `init` next-steps string) — see Known gaps.

## SSH Access (not shipped)

No SSH server is wired. `SSH\SSHServer` is a `ForceCommand`-style
git-shell gate: it parses a single
`git-upload-pack`/`git-receive-pack <repo>` request, validates the repo name
and the provided SSH public key against a `User`, and dispatches to
`Git\UploadPack`/`Git\ReceivePack`. It does not implement the SSH wire
protocol — it expects to run under the host's OpenSSH daemon via
`ForceCommand`, which is a deployment step nobody has shipped here.

### See also — SSH middleware (sugar-ssh-server)

For a general SSH middleware/session framework (middleware chain, `ForceCommand`
supervisor, `Session`, transports), see the sibling
[`sugar-ssh-server`](../sugar-ssh-server). Both run under host sshd; neither implements the
SSH wire protocol itself. Their auth models differ deliberately: candy-git-server
does its own SSH public-key matching (`SSH\SSHServer` + `User`), while sugar-ssh-server trusts
sshd and allowlists by username + key fingerprint.

## HTTP Smart Protocol (read path live behind `serve --http`)

`HttpSmartProtocol\Server` routes the Git smart-HTTP flow and the LFS
batch API; `HttpSmartProtocol\HttpListener` feeds it real sockets when you
run `serve --http`:

1. Client GETs `/repo.git/info/refs?service=git-upload-pack` — a codec-
   framed `# service=` header + flush, then the advertisement bytes of
   `git upload-pack --stateless-rpc --advertise-refs` themselves
2. Client POSTs `/repo.git/git-upload-pack` — the body is fed to
   `git upload-pack --stateless-rpc`; the pack comes back side-banded
3. For push: Client POSTs `/repo.git/git-receive-pack` — answers **501**:
   receive-pack is phase 2; the route refuses before touching the access
   layer

Both advertisement routes are gated **before** a single ref byte is
built: `git-upload-pack` requires `canRead` (anonymous passes only for
public repos), `git-receive-pack` requires `canWrite` (fail-closed false
for anonymous callers, so the old unauthenticated receive-pack
advertisement hole is gone). Authentication uses HTTP Basic auth or the
`X-CandyGitServer-User` header — the header path additionally requires
`user_trust_mode`, `trusted_proxies` and `auth_secret` to be configured
(Server.php), otherwise the header is ignored; deactivated
(`isActive === false`) users authenticate to nothing on either path. The
Known gaps list below still records the plaintext-password storage and
403-without-challenge shapes as open work — the read path deliberately
ships no new credential surface.

## Git Protocol (daemon class only — not assembled)

`Git\GitDaemon` is a class, not a service: nothing in the shipped CLI or
composer scripts constructs it, and its wire behavior still deviates from
the git-daemon spec (Known gaps #2 — its packet *framing* went through the
shared `Git\PktLine` codec in r1024/githttp, but the request-line dialect,
capabilities and negotiation gaps are open). The descriptions below
document the class surface for whoever wires it, not a promise of working
`git://` clones.

**Class behavior:**
- Uses `pcntl_fork()` to detach and become a session leader (`serve()`'s
  `--daemon`-style argument path)
- Listens on `git.listen_addr` from `config.yaml` (default `:9418`)
- Writes PID to the given file (or a caller-chosen default)
- Handles `SIGTERM`, `SIGINT` (graceful shutdown) and `SIGHUP`
- Cleans up PID file and closes all connections on exit

**Signal handling:**
- `SIGTERM` / `SIGINT` — graceful shutdown (closes connections, removes PID file)
- `SIGHUP` — **also shutdown** (GitDaemon.php:1156-1158: all three signals
  route to the same `handleSignal()`; config reload is not implemented)

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
per-request work (ref advertisement, `git pack-objects`, `git
update-ref`) is the same synchronous code the blocking mode runs; it
executes inside the readiness callback.

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
`serve --http`; the routes are pinned at router level only — no end-to-end
LFS client proof ships in phase 1; repo keys use the `.git`-suffixed
form):

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
  body's SHA-256 must match the OID (422 on mismatch).
- TLS keys in config are refused at load (`config.tls_not_supported`) —
  HTTPS termination is out of scope for the class.

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
`@weekly`, `@monthly`, `@yearly` — full cron expressions are not
supported and throw. Failed pulls are logged and re-attempted on the
next interval. As with everything server-shaped, no scheduler host ships:
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

Counters are recorded by the Git daemon class (both modes), the smart-HTTP
class, and the LFS handler into the shared `Stats::getInstance()`
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

Collaborator lists live on the `Repo` object in memory. There is **no
storage layer**: the CLI's `user add`/`user key` commands construct
throwaway objects and persist nothing (`user list` always reports empty),
and a host must re-supply users on every run until a store exists
(Known gaps).

## Known gaps — open work on the remaining transports

Non-exhaustive inventory from the docs-review-1023 audit, renumbered
2026-10-24 (r1024/githttp, owner ruling 1b phase 1). Fixed since the
audit: the binary pkt-line framing (one `Git\PktLine` codec replaced all
four emitters — the byte-exact pins that deliberately recorded the broken
shape were flipped with dated comments), the unauthenticated receive-pack
advertisement (both advertisement routes are now gated before a single
ref byte is built), and the `isActive` skip on the HTTP auth path. Line
numbers below are as of this commit. The daemon and SSH transports remain
unreachable from the shipped CLI — plain `serve` refuses them — which is
why those phases still carry the scaffold label.

1. **Server assembly partial.** `serve --http` constructs
   `HttpSmartProtocol\Server` + `HttpSmartProtocol\HttpListener` for the
   read path (phase 1); nothing yet assembles `GitDaemon` or `SSHServer`,
   and the old `serve` command's simulated success (banner + exit 0)
   remains replaced by the refusal (docs-fix-1023 N4). Push over HTTP is
   a pinned 501 refusal — receive-pack wiring is phase 2.
2. **git-daemon dialect deviates from spec**: bare request line without
   the pkt-framed host/user delimiter (`Git/GitDaemon.php:575`), no
   side-band multiplexing or want/have depth negotiation, capabilities
   joined with `|` where the spec uses NUL/space separators
   (`Git/GitDaemon.php:813`). Packet *framing* went through the shared
   codec in r1024/githttp; these dialect gaps remain.
3. **Plaintext password comparison**: `User::$password` stores the raw
   string and is compared with `hash_equals` — no hashing
   (`HttpSmartProtocol/Server.php:716`, `User.php`). The phase-1 read
   path ships no new credential surface; fixing this is phase-2 push
   groundwork.
4. **Proxy HMAC replayable**: the signature covers only the username — no
   timestamp, nonce, or request binding (`HttpSmartProtocol/Server.php:782-792`).
5. **403 without `WWW-Authenticate`**: private repos answer 403 with no
   challenge header, so git clients never prompt for credentials
   (`HttpSmartProtocol/Server.php:246`, `:281`) — live behavior of the
   phase-1 read path under the anonymous-public doctrine.
6. **Async receive-pack race**: the loop can dispatch a push before the
   pack body has arrived, yielding `unpack no packfile received`
   (`Git/GitDaemon.php:424`, `:848-853`).
7. **`SIGHUP` shuts down instead of reloading** — all three signals share
    one handler (`Git/GitDaemon.php:1151`).
8. **No user/repo persistence at all**: `user add`/`user key` discard
    their objects (bin CLI, `cmdUserAdd`/`cmdUserKey`), `db.driver`/
    `db.data_source` config is parsed but consumed nowhere, and
    `CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS` is read by no code (it survives
    only in a stale `init` hint, `lang/en.php` `cli.next_step_3`).
9. **Extension reality mismatch**: the daemon uses ext-sockets
    (`Git/GitDaemon.php:1078-1093`) without declaring `ext-sockets`
    (composer.json requires only json/openssl/ssh2), and `ext-ssh2` is
    declared but has zero call sites (vestigial).
10. **`AccessControl::allowAnonymousRead()` is hardcoded `true`** with no
    config knob (`AccessControl.php:78-81`) — `serve --http` therefore
    serves every public repo to anyone by design (phase-1 doctrine); a
    knob belongs with the auth hardening.
11. **`User::keysMatch` requires the comment field to match verbatim**
    (whitespace-normalized) — a key offered without its stored comment
    fails; fail-closed but surprising (`User.php:208-213`).
12. **`max_connections` kicks the oldest client silently** — no warning,
    no error packet (`Git/GitDaemon.php:351-352`; the HTTP listener is
    one-request-per-connection and carries no concurrency throttle).
13. **`composer serve --daemon …` flags are intercepted by composer**;
    use `php bin/candy-git-server …` directly.
14. **Stale CLI strings**: `help` and the `init` next-step lines still
    describe a starting server; per the i18n law they cannot be fixed in
    one locale, so all 16 files must be touched together (deferred).

## Shared foundations

The sugar-async `Subscriptions` machinery is wired into `GitDaemon` for
graceful shutdown registration (present and tested at class level; it
becomes load-bearing only once a host runs the daemon).

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem (soft-serve); SugarCraft is developed as a native PHP project.
