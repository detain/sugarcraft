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
project. The domain classes are real and individually tested, but **the
server assembly that would bind sockets and accept git traffic has not
been written**:

```console
$ candy-git-server serve
candy-git-server: 'serve' refuses to start — server assembly is not
implemented; see docs (status: scaffold).
$ echo $?
1
```

The command refuses loudly (non-zero exit, honest stderr) rather than
printing a startup banner while serving nothing — so no deploy script can
mistake the scaffold for a running server. `--daemon` and `--pid-file` are
refused the same way; nothing forks.

**What is implemented (as classes, not as a running service):**

- `Config` — YAML config loader (symfony/yaml) with typed accessors
- `Repo` — bare-repository model: init (Git at `<path>/.git`), refs,
  branches/tags, file reads, mirror metadata, visibility
- `User`, `AccessControl`, `Visibility` — user model, per-repo permission
  checks, visibility enum (no persistence layer — see Known gaps)
- `Git\UploadPack`, `Git\ReceivePack` — clone/fetch and push command
  handlers (packets currently mis-framed — see Known gaps)
- `Git\GitDaemon` — socket daemon class with PID file + signal handling
  (class-complete, but its protocol dialect deviates from spec and no
  shipped entrypoint constructs it)
- `HttpSmartProtocol\Server` — request router for the Git smart-HTTP and
  LFS batch APIs (class-level; several security gaps before wiring)
- `SSH\SSHServer` — a `ForceCommand`-style git-shell gate for host sshd
  (parses one `git-upload-pack`/`git-receive-pack` request and dispatches;
  does **not** implement the SSH wire protocol)
- `LFS\LFSHandler` + `LocalStorageBackend` — LFS batch API + object store
- `Jobs\Schedule`, `Jobs\MirrorPuller` — `@every`/alias schedules and
  mirror-pull job logic
- `Stats`, `StatsServer` — counters + JSON stats endpoint class
- `Support\PromisePool`, `Support\Version` — bounded-concurrency helper,
  phar build-stamp resolver
- `bin/candy-git-server` — CLI: `serve` (refuses), `init` (writes a
  config skeleton), `repo create|list|info` (works against the local
  data dir), `user` subcommands (in-memory only — nothing persists),
  `help`, `version`

**What does not exist:** any TUI (there is no terminal UI of any kind), any
listener on `:23231`/`:23232`/`:9418`, user storage, `git clone/push`
end-to-end flows, LFS serving (no host wires the HTTP server).
`Clipboard/Osc52.php` is a standalone OSC 52 encoder with zero consumers —
it was written for a TUI that was never built.

## Architecture (actual files)

```
candy-git-server/
├── bin/candy-git-server            CLI entry (serve refuses; repo/init work)
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
│   │   ├── UploadPack.php          git-upload-pack (clone/fetch)
│   │   └── ReceivePack.php         git-receive-pack (push)
│   ├── HttpSmartProtocol/
│   │   └── Server.php              Smart-HTTP + LFS request router (class-level)
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

Nothing to run yet — `serve` refuses with exit 1 (see Overview). The CLI
pieces that do work operate on the local data directory:

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

## HTTP Smart Protocol (class-level, not reachable)

`HttpSmartProtocol\Server` routes the Git smart-HTTP flow and the LFS batch
API when a host constructs and feeds it requests:

1. Client GETs `/repo.git/info/refs?service=git-upload-pack` — ref advertisement
2. Client POSTs `/repo.git/git-upload-pack` — pack exchange for fetch/clone
3. For push: Client POSTs `/repo.git/git-receive-pack` — pack + status

No shipped entrypoint starts an HTTP listener, so `git clone http://…`
does not work today. When a host wires it: authentication uses HTTP Basic
auth or the `X-CandyGitServer-User` header — the header path additionally
requires `user_trust_mode`, `trusted_proxies` and `auth_secret` to be
configured (Server.php), otherwise the header is ignored. Note also the
Known gaps items on password storage, `isActive`, anonymous
receive-pack advertisement, and the 403-without-challenge shape — these
must be fixed **before** any wiring.

## Git Protocol (daemon class only — not assembled)

`Git\GitDaemon` is a class, not a service: nothing in the shipped CLI or
composer scripts constructs it, and its wire behavior deviates from the
git-daemon spec (Known gaps). The descriptions below document the class
surface for whoever wires it, not a promise of working `git://` clones.

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
plus basic object transfer when `lfs.enabled` is true (reachable only once
a host wires `HttpSmartProtocol\Server`; repo keys use the `.git`-suffixed
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

## Known gaps — must fix before any server wiring

Non-exhaustive inventory from the docs-review-1023 audit (line numbers as
of this commit). None of these paths is reachable from the shipped CLI
today — `serve` refuses — which is precisely why the package is labeled
scaffold.

1. **Server assembly absent.** Nothing constructs `GitDaemon`/`SSHServer`/
   `HttpSmartProtocol\Server` into a running host; the old `serve` command
   simulated success (banner + exit 0). Now refuses (docs-fix-1023 N4).
2. **pkt-line framing is binary, not spec ASCII-hex** — every emitted
   advertisement is malformed to real git clients, and the flush packet is
   ASCII while payloads are binary (self-inconsistent):
   `Git/GitDaemon.php:1030` + `:1035`, `HttpSmartProtocol/Server.php:773-774`,
   `Git/ReceivePack.php:187`, `Git/UploadPack.php:204`. Byte-exact pins in
   `tests/Git/GitProtocolTest.php` deliberately record the broken framing;
   the fix must rewrite them.
3. **git-daemon dialect deviates from spec**: bare request line without
   the pkt-framed host/user delimiter (`Git/GitDaemon.php:574`), no
   side-band multiplexing or want/have depth negotiation, capabilities
   joined with `|` in places where the spec uses NUL/space separators.
4. **Plaintext password comparison**: `User::$password` stores the raw
   string and is compared with `hash_equals` — no hashing
   (`HttpSmartProtocol/Server.php:821`, `User.php`).
5. **HTTP auth path skips `isActive`**: `getUserFromHeaders` returns the
   user found by Basic/header auth without the deactivated check the SSH
   path performs (`HttpSmartProtocol/Server.php:806-833` vs
   `SSH/SSHServer.php:86`).
6. **Anonymous receive-pack advertisement**: `info/refs?service=git-receive-pack`
   is answered without the `canRead` gate applied to upload-pack
   (`HttpSmartProtocol/Server.php:216-247`).
7. **Proxy HMAC replayable**: the signature covers only the username — no
   timestamp, nonce, or request binding (`HttpSmartProtocol/Server.php:886`).
8. **403 without `WWW-Authenticate`**: private repos answer 403 with no
   challenge header, so git clients never prompt for credentials
   (`HttpSmartProtocol/Server.php:242`, `:279`, `:353`).
9. **Async receive-pack race**: the loop can dispatch a push before the
   pack body has arrived, yielding `unpack no packfile received`
   (`Git/GitDaemon.php:423-425`, `:846-850`).
10. **`SIGHUP` shuts down instead of reloading** — all three signals share
    one handler (`Git/GitDaemon.php:1156-1158`).
11. **No user/repo persistence at all**: `user add`/`user key` discard
    their objects (bin CLI, `cmdUserAdd`/`cmdUserKey`), `db.driver`/
    `db.data_source` config is parsed but consumed nowhere, and
    `CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS` is read by no code (it survives
    only in a stale `init` hint, `lang/en.php` `cli.next_step_3`).
12. **Extension reality mismatch**: the daemon uses ext-sockets
    (`Git/GitDaemon.php:1085-1100`) without declaring `ext-sockets`
    (composer.json requires only json/openssl/ssh2), and `ext-ssh2` is
    declared but has zero call sites (vestigial).
13. **`AccessControl::allowAnonymousRead()` is hardcoded `true`** with no
    config knob (`AccessControl.php:78-81`).
14. **`User::keysMatch` requires the comment field to match verbatim**
    (whitespace-normalized) — a key offered without its stored comment
    fails; fail-closed but surprising (`User.php:208-213`).
15. **`max_connections` kicks the oldest client silently** — no warning,
    no error packet (`Git/GitDaemon.php:350-354`).
16. **`composer serve --daemon …` flags are intercepted by composer**;
    use `php bin/candy-git-server …` directly once a server exists.
17. **Stale CLI strings**: `help` and the `init` next-step lines still
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
