# Running candy-code in Docker

The `serve` verb ships with an application image definition at
[`Dockerfile`](../Dockerfile) and a publishing workflow at
`.github/workflows/candy-code-image.yml` in the monorepo. The workflow
publishes as a `workflow_run` after the split sync completes a successful
push to `master` — it tags `:latest` plus the bare commit sha (and, for a
`candy-code/v*` release, the versioned tag) and pushes to GitHub Container
Registry:

```
docker pull ghcr.io/detain/sugarcraft-candy-code:latest
```

> **Registry status.** The image is **live**: the first green
> `candy-code serve image` run (39525070215, master `63272f9ad`,
> 2026-10-20) published `:latest` plus the bare-sha tag, and anonymous
> manifest probes answer 200 for both. The road here: every workflow run
> before the build fix failed at `composer create-project` — `php:8.3-cli`
> ships neither ext-zip nor an archive binary (the first error, masking the
> rest), and once extraction works, `sugar-image`/`candy-mosaic`
> hard-require `ext-gd`, which the base image lacked too. The Dockerfile
> fix installs `unzip` and builds `gd`. PRs touching the image now
> proof-build before merge, so a broken Dockerfile cannot silently ride
> `master` again. To build without the registry, see
> [Building locally](#building-locally) below.

The image resolves the app from the **published** package stream (Composer
`dev-master`), never from a monorepo working tree, so its contents track the
packagist feed rather than an arbitrary commit. Publishing therefore waits on
the sync: the build starts only after `Sync to sugarcraft org` finishes green
for that push, because a push-triggered build raced the sync and could resolve
yesterday's feed state of the very commit it was tagged with.

Even so, the feed can lag; the image no longer hides it. The build passes a
per-run `<sha>-<run id>` source-ref build argument that the
create-project layer consumes (its name is rostered, with the truth that the
binary never reads it, in
[`ENVIRONMENT.md`](ENVIRONMENT.md#build-time-image-arguments)), which defeats the otherwise-constant instruction
text under the workflow's `type=gha` cache (a cached layer shipped a frozen
snapshot of the feed, silently, on every build). After the install the same
layer records the reference the stream actually resolved into
`/opt/candy-code/BUILD-REF` inside the image, and the workflow's job summary
prints it. To see what a running image carries:

```
docker run --rm --entrypoint cat \
  ghcr.io/detain/sugarcraft-candy-code:latest /opt/candy-code/BUILD-REF
```

The row reads `sugarcraft/candy-code-web <package-version> <resolved
commit>`. The recorded reference is the `sugarcraft/candy-code-web`
package's: the app package cannot be `composer show`n inside its own
create-project root, so the row is read from that sibling package of the
same split-synced stream. A web row whose commit stops moving while
`master` does is therefore a stream-level lag the next after-sync build
will show; a package-side-only lag (app feed behind while the web feed is
current) would stay invisible in the row.

## What the image guarantees

- **PHP 8.3 CLI with the extensions `serve` requires.** `pcntl`, `posix`,
  `ffi` and `intl` are compiled in; the build runs an exercise probe (same
  shape the preflight uses — FFI must actually work, not merely load) over
  `pcntl posix ffi intl mbstring sqlite3 pdo_sqlite`, so a base-image change
  that drops one fails the build instead of the container.
- **Unprivileged.** The image installs a `candy` user and switches to it. The
  serve preflight refuses to run as root unless `--allow-root` is passed, and
  this image deliberately never passes it: the container honors the refusal
  rather than arguing with it. Do not override the `USER`.
- **State lives in `$HOME=/data`.** The default state directory is
  `~/.candy-code/server`, so everything the server writes — sessions, the
  discovery record, the token, the control socket — lands on the `/data`
  volume. The app creates the state directory itself, `0700`, owned by the
  runtime user; the entrypoint fails fast with the operational fix if the
  mount is not writable (chown the volume, or drop the stale named volume).
  Point `CANDY_CODE_SERVER_DIR` at a path inside `/data` to keep a
  bind-mounted workspace out of the state directory.
- **Health.** A `HEALTHCHECK` probes `GET /api/health` on the default port
  `7420` — the one public endpoint that answers without any credential (the
  other public route, `/api/login`, only exists to redeem the one-time code).
  If you re-port the server, redefine the check in a derived image.
- **Web UI included.** The image additionally requires
  `sugarcraft/candy-code-web`, so `/` serves the browser UI rather than the
  install notice.

## Starting a server

```
docker run -d --name candy-code \
  -v candy-data:/data \
  -p 7420:7420 \
  ghcr.io/detain/sugarcraft-candy-code:latest
```

The default command is `candy-code serve --host 0.0.0.0 --allow-remote`.
The `--allow-remote` half is not a shortcut: the preflight refuses any
non-loopback bind without it because the container binds outward by design.

Startup prints its facts to **stderr** per the serve WHAT-IT-PRINTS contract:
the address, the served root, the session permission mode, and a sign-in URL
whose one-time code is valid for 120 seconds and rides in the URL fragment.
Read it with:

```
docker logs candy-code
```

Treat the sign-in URL as the credential it is — it passes through `docker
logs`, so ship stdout/stderr capture accordingly. To reach a workspace, mount
it below `/data` (the process runs as uid 1000) and pass it as the serve root
operand, with `-w` matching:

```
docker run -d --name candy-code \
  -v "$PWD:/data/workspace/my-project:ro" \
  -v candy-data:/data \
  -p 7420:7420 \
  ghcr.io/detain/sugarcraft-candy-code:latest \
  serve /data/workspace/my-project --host 0.0.0.0 --allow-remote --no-web
```

Everything else the CLI can do works in the container too — arguments after
the image name replace the default command:

```
docker exec candy-code candy-code serve status
docker exec candy-code candy-code serve token --rotate
```

## Compose sketch

```yaml
services:
  candy-code:
    image: ghcr.io/detain/sugarcraft-candy-code:latest
    volumes:
      - candy-data:/data
    ports:
      - "7420:7420"
volumes:
  candy-data:
```

## Building locally

```
docker build -t candy-code:local candy-code/
```

The build context is the `candy-code/` directory; the Dockerfile COPYs only
the entrypoint script and pulls the rest from Packagist, so a dirty working
tree cannot leak into an image built from this definition. The
source-ref build argument (named in
[`ENVIRONMENT.md`](ENVIRONMENT.md#build-time-image-arguments)) defaults to
`local`, so a plain build needs no `--build-arg`; passing a per-build value is
what makes CI builds uncachable against each other.

## Launch notices in the log

At startup the CLI prints advisory rows to stderr before anything else, so
`docker logs` is where they surface. Each names its own remedy; in a container
the cause is usually an environment detail worth fixing at the image or unit
level:

- **The `HOME` stand-in.** A row saying untrusted reads run from the
  world-writable temp-directory stand-in means the process has no usable
  home: the owned-home surfaces (the trusted config and hook files) refuse
  while it stands. The image ships `HOME=/data` on the volume; re-exporting
  `HOME` at a directory another uid owns — or one that is world-writable —
  re-triggers the stand-in, because both shapes fail the same check.
- **The dumb-family terminal.** A row saying the terminal reports a
  dumb-family `TERM` appears when an interactive run attaches to a tty that
  advertises no capability (empty, `dumb…`, `unknown…`) — typical of
  `docker exec` without allocation. Containers want the one-shot path,
  `candy-code -p "…"`; if the TUI must drive over such a tty, set
  `CANDY_CODE_DISABLE_MOUSE=1` as the row itself says.
- **The foreign Claude settings notice.** A row saying no candy-code hook
  event matches some event names a hook groups were skipped means a Claude
  Code `settings.json` rode along into the mounted home and asked for hook
  events this program has no case for; every other part of those files
  loaded. Nothing is broken — remove the foreign file to silence the row.
- **The bypass banner.** The `⚠ Permission mode is bypass-permissions` row
  prints on every launch that runs without an approver, TUI and `-p` alike,
  naming the three ways to change the mode. `-p` and `/bg` runs start in
  bypass precisely because nothing can answer a prompt; a server-spawned
  run instead inherits the server's admitted mode. Pick the mode
  explicitly with `--permission-mode` (see
  [PERMISSIONS.md](PERMISSIONS.md)).

## Long-lived containers and `/tmp`

Agent results, hook sidecars, and shell-out scratch live under the system
temporary directory — among them the `sc_pool_<pid>_<hex>` result directories
each agent run claims. The process reclaims them when their reader finishes,
and a startup sweep bounds what a crash can strand: entries older than an hour
whose owning process is gone are released, while anything never stamped is
left alone — a sweep that guesses ownership deletes live state. What the app
cannot control is the host's own policy for `/tmp`: under systemd,
`tmpfiles.d` ages temporary files out on a distro-configured deadline, and a
container kept up for weeks will eventually have live scratch removed under
it. A long-lived container should therefore point `TMPDIR` at a persistent
volume instead of the built-in `/tmp`; the startup environment reads it (a
mid-process `putenv` does not move `sys_get_temp_dir()` — see
[ENVIRONMENT.md](ENVIRONMENT.md) for the `TMPDIR` row).

A systemd unit running the container image with `PrivateTmp=yes` is the same
hazard in another shape: the service gets a private `/tmp` wiped when it
stops, so anything a run still owns at shutdown is gone — and so is anything
the next start expected to find. The persistent-`TMPDIR` answer covers this
too.

A home bind-mounted from a filesystem that cannot store POSIX ownership (NTFS,
several NFS and SMB setups) is the sharper hazard. The owned-home check asks
two things of the directory: it must not be world-writable, and it must belong
to the process's effective uid — the image's user is uid 1000. Mount options
that squash ownership to some other uid, or that give the tree world-writable
bits, fail that check, and the launch degrades exactly as the stand-in notice
describes. Named volumes carry their own ownership and are the safe default.

The image sets no `TZ`, so timestamps in logs, session rows and exports read
UTC. That is the stable choice for a shared server; pin `TZ` only when the
people reading the transcripts prefer their own wall clock, and pin it at the
image or unit level rather than changing it under a live container — a moved
clock lands between rows already written.

## See also

- [SERVER.md](SERVER.md) — the serve surface, tokens and the wire protocol.
- [ENVIRONMENT.md](ENVIRONMENT.md) — the full environment-variable roster.
- [PERMISSIONS.md](PERMISSIONS.md) — what `--permission-mode` may be asked
  for; the container ships the default mode and passing a dangerous one
  through `--allow-bypass` is a decision for the image consumer, not this one.
