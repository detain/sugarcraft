# Running candy-code in Docker

The `serve` verb ships with an application image definition at
[`Dockerfile`](../Dockerfile) and a publishing workflow at
`.github/workflows/candy-code-image.yml` in the monorepo. The workflow builds
on every `candy-code/**` change and pushes to GitHub Container Registry:

```
docker pull ghcr.io/detain/sugarcraft-candy-code:latest
```

The image resolves the app from the **published** package stream (Composer
`dev-master`), never from a monorepo working tree, so its contents track the
packagist feed rather than an arbitrary commit.

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
tree cannot leak into an image built from this definition.

## See also

- [SERVER.md](SERVER.md) — the serve surface, tokens and the wire protocol.
- [ENVIRONMENT.md](ENVIRONMENT.md) — the full environment-variable roster.
- [PERMISSIONS.md](PERMISSIONS.md) — what `--permission-mode` may be asked
  for; the container ships the default mode and passing a dangerous one
  through `--allow-bypass` is a decision for the image consumer, not this one.
