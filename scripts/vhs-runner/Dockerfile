# syntax=docker/dockerfile:1.7
#
# VHS demo runner — used by .github/workflows/vhs.yml.
#
# Bakes in: vhs, ttyd, ffmpeg, PHP 8.3 + required extensions, composer.
# The render job in vhs.yml uses this image so it never has to hit
# GitHub release APIs at render time. Image is rebuilt weekly (Monday
# 06:00 UTC) by .github/workflows/vhs-runner-image.yml, which resolves
# the latest vhs + ttyd tags and passes them as build args.
#
# Build locally for testing:
#   docker build \
#       --build-arg VHS_VERSION=v0.10.0 \
#       --build-arg TTYD_VERSION=1.7.7 \
#       -t vhs-runner:local scripts/vhs-runner

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        git \
        jq \
        unzip \
        php8.3-cli \
        php8.3-curl \
        php8.3-intl \
        php8.3-mbstring \
        php8.3-sqlite3 \
        php8.3-xml \
        php8.3-zip \
        php-ssh2 \
        # Headless Chromium runtime deps. VHS uses go-rod, which
        # downloads its own chromium binary at first launch — we just
        # need the shared libraries on the PATH. Canonical Puppeteer
        # /Playwright list for Ubuntu 24.04 (t64 suffix on the libs
        # that gained 64-bit time_t in noble). Without these every
        # render fails with `libnss3.so: cannot open shared object`.
        fonts-liberation \
        libasound2t64 \
        libatk-bridge2.0-0t64 \
        libatk1.0-0t64 \
        libcairo2 \
        libcups2t64 \
        libdbus-1-3 \
        libdrm2 \
        libgbm1 \
        libglib2.0-0t64 \
        libgtk-3-0t64 \
        libnspr4 \
        libnss3 \
        libpango-1.0-0 \
        libx11-6 \
        libxcb1 \
        libxcomposite1 \
        libxdamage1 \
        libxext6 \
        libxfixes3 \
        libxkbcommon0 \
        libxrandr2 \
        libvulkan1 \
        xdg-utils \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer

ARG VHS_VERSION
ARG TTYD_VERSION

RUN test -n "$VHS_VERSION" && test -n "$TTYD_VERSION" \
    && vhs_ver="${VHS_VERSION#v}" \
    && curl -fsSL -o /tmp/vhs.tar.gz \
        "https://github.com/charmbracelet/vhs/releases/download/${VHS_VERSION}/vhs_${vhs_ver}_Linux_x86_64.tar.gz" \
    && tar -xz -f /tmp/vhs.tar.gz -C /usr/local/bin --strip-components=1 --wildcards '*/vhs' \
    && rm /tmp/vhs.tar.gz \
    && chmod +x /usr/local/bin/vhs

RUN curl -fsSL -o /usr/local/bin/ttyd \
        "https://github.com/tsl0922/ttyd/releases/download/${TTYD_VERSION}/ttyd.x86_64" \
    && chmod +x /usr/local/bin/ttyd

RUN vhs --version \
    && ttyd --version \
    && ffmpeg -version | head -1 \
    && php --version \
    && composer --version

# Chromium refuses to launch as root (crbug.com/638180), so vhs renders
# fail in container jobs unless we drop privileges. Match the
# github-hosted runner's UID/GID (1001:1001) so the workspace
# bind-mount permissions stay correct for composer install and the
# rendered .gif output.
RUN groupadd -g 1001 vhs \
    && useradd -m -u 1001 -g vhs -s /bin/bash vhs

# Even as non-root, chromium's namespace sandbox can't initialize in a
# default-seccomp container — it aborts in ZygoteHostImpl::Init().
# Tell vhs to pass --no-sandbox to chromium, matching the upstream
# charmbracelet/vhs Dockerfile pattern. The vhs source reads this:
#   enableNoSandbox := os.Getenv("VHS_NO_SANDBOX") != ""
ENV VHS_NO_SANDBOX="true"

USER vhs

LABEL org.opencontainers.image.source="https://github.com/detain/sugarcraft"
LABEL org.opencontainers.image.description="VHS demo runner — vhs + ttyd + ffmpeg + PHP 8.3"
LABEL org.opencontainers.image.licenses="MIT"
