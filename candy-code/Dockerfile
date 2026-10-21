# syntax=docker/dockerfile:1
#
# Application image for `candy-code serve`.
#
# This image builds from the PUBLISHED package stream (composer's dev-master
# resolution on Packagist), not from the monorepo working tree: the build never
# COPYs `src/`, so a linked-vendor checkout can never freeze working-tree code
# into a published layer. Merging `candy-code/**` re-runs the build as a proof
# that the published stream still composes a working server image.
#
# The startup contract this image honors (see src/Server/Preflight.php and the
# Serve docblock): a non-loopback `--host` requires `--allow-remote`, and
# running as root is REFUSED unless `--allow-root` is passed. The image takes
# the refusal seriously — it runs unprivileged and never passes `--allow-root`.
# HOME is /data, so the default state directory (~/.candy-code/server) lands
# on the volume and the app itself creates it 0700.

FROM php:8.3-cli

# Dev headers for the PHP extensions built below. Note the package names:
# intl needs libicu-dev and ffi needs libffi-dev — the names "libintl-dev"
# and "libmbstring-dev" do not exist (scripts/Dockerfile.vhs-runner learned
# that the hard way). curl and mbstring are already bundled+enabled in
# php:8.3-cli, so neither is re-installed here. git keeps composer's source
# fallback alive if a dist is ever missing. unzip is load-bearing, not
# garnish: php:8.3-cli ships neither ext-zip nor an archive binary, and
# `composer create-project --prefer-dist` below (and its `composer require`)
# dies with "The zip extension and unzip/7z commands are both missing"
# without one. unzip is the minimal fix — building ext-zip would add
# libzip-dev plus a compile layer for the same capability. The gd dev libs
# are for ext-gd below: sugar-image and candy-mosaic HARD-require it in
# their composer.json, so resolution fails without it (libavif-dev is not
# optional garnish — the bundled gd of PHP 8.1+ configures AVIF support by
# default and errors when the library is absent).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    libicu-dev \
    libffi-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libavif-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# pcntl, posix and ffi are hard requirements of `serve` (its preflight exits
# 2 without them); intl is required by the app's composer.json; gd is
# required by the sugar-image/candy-mosaic deps the app resolves at install.
# sqlite3 and
# pdo_sqlite are builtins of this base image — the exercise probe below pins
# the whole set so a silent base-image change fails the BUILD, not the pod.
# The probe is one line on purpose: a Dockerfile RUN continues only through
# trailing backslashes, and a heredoc would tie this file to a buildkit
# feature the other repo Dockerfiles do not require. It re-exercises ffi the
# way the preflight does — loading is not enough, the library must work here.
RUN docker-php-ext-install -j"$(nproc)" pcntl posix ffi intl gd \
    && php -r 'foreach (["pcntl","posix","ffi","intl","gd","mbstring","sqlite3","pdo_sqlite"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Dockerfile build probe: extension {$ext} is not loaded\n"); exit(1); } } FFI::cdef("int getpid(void);"); fwrite(STDOUT, "php-extensions-ok\n");'

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Published resolution only: no path repositories exist in a lib manifest
# (that is the repo-wide path-repo law), so this works in a bare container.
RUN composer create-project sugarcraft/candy-code:dev-master /opt/candy-code \
        --no-interaction --prefer-dist --no-progress \
    && cd /opt/candy-code \
    && composer require sugarcraft/candy-code-web \
        --no-interaction --prefer-dist --no-progress \
    && ln -s /opt/candy-code/bin/candy-code /usr/local/bin/candy-code \
    && rm -f /usr/local/bin/composer

# Unprivileged user; /data is both HOME and the state volume. HomeDirectory
# refuses '/', a world-writable HOME and a relative HOME — 0700 owned by the
# runtime user satisfies all three. The served workspace lives below it so a
# bind-mounted project never has to double as the state directory.
RUN useradd --uid 1000 --user-group --home-dir /data --shell /usr/sbin/nologin candy \
    && mkdir -p /data/workspace \
    && chown -R candy:candy /data \
    && chmod 700 /data

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod 755 /usr/local/bin/docker-entrypoint.sh

USER candy
ENV HOME=/data
WORKDIR /data/workspace

# ServerConfig::DEFAULT_PORT — `serve` binds here unless `--port` says otherwise.
EXPOSE 7420

# /api/health is the ONE public probe (GET/HEAD, answers {ok, protocol} with
# no token) — the same endpoint `serve url` itself answers against. It probes
# the default port; if you re-port the server, redefine the HEALTHCHECK in a
# derived image rather than trusting this one. There is no PORT environment
# knob — the port moves only via the `--port` argument you pass after the
# image name, so re-porting means redefining both the CMD arguments and this
# probe together.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:7420/api/health || exit 1

# WHAT-IT-PRINTS contract: the address, the root, the permission mode and the
# one-time sign-in URL (120 s, single use, code in the URL fragment) go to
# stderr at startup — `docker logs <container>` is how the operator reads the
# URL. Defaults are the serve action's own flags; anything after the image
# name replaces them (`docker run … candy-code serve status` works).
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["serve", "--host", "0.0.0.0", "--allow-remote"]
