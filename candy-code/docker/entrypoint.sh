#!/bin/sh
# DST-2 entrypoint for the candy-code serve image.
#
# The serve preflight refuses euid 0 without --allow-root and this image
# deliberately never passes --allow-root; the guard below keeps a
# `--user root` invocation from reaching the preflight at all, so the failure
# names the ENTRYPOINT as the refuser.
[ "$(id -u)" -ne 0 ] || {
	echo "docker-entrypoint: this image must run unprivileged (serve refuses root; do not override the USER)" >&2
	exit 1
}

# The state directory lives under HOME=/data. When /data is a volume, Docker
# copies the image directory's ownership into a fresh volume; if it did not
# (an old volume, or a bind mount with foreign ownership) the app's private
# directory checks would fail later with a security-flavoured message. Fail
# fast here with the operational fix instead.
[ -n "${HOME:-}" ] || {
	echo "docker-entrypoint: HOME is not set; the image sets HOME=/data" >&2
	exit 1
}
[ -w "$HOME" ] || {
	echo "docker-entrypoint: HOME ($HOME) is not writable by uid $(id -u) — chown the volume to the runtime user or remove the stale named volume" >&2
	exit 1
}

exec candy-code "$@"
