#!/usr/bin/env bash
# Show what sugarspark labels for a handful of common terminal sequences.
#
#   ./examples/inspect.sh

set -euo pipefail

# Resolve which `sugarspark` to use ONCE up-front. Using `command -v`
# inside the function would match the shell function we're about to
# define and recurse / fall through to `command sugarspark`, which
# then tries to look up a binary by that name — fails when the user
# is running from a checkout without a global composer install
# (`sugarspark: command not found`). Picking a concrete path now and
# stashing it in a variable side-steps the whole mess.
if [ -x "$(dirname "${BASH_SOURCE[0]}")/../bin/sugarspark" ]; then
    SUGARSPARK_BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/bin/sugarspark"
else
    SUGARSPARK_BIN="$(type -P sugarspark || true)"
    if [ -z "$SUGARSPARK_BIN" ]; then
        echo "sugarspark: not found on PATH and ./bin/sugarspark missing" >&2
        exit 1
    fi
fi
export SUGARSPARK_BIN
sugarspark() { "$SUGARSPARK_BIN" "$@"; }
export -f sugarspark

echo "── SGR (foreground colours) ────────────────────"
printf '\e[31mred\e[32m green\e[34m blue\e[0m default\n' | sugarspark
echo

echo "── DEC private modes ────────────────────────────"
printf '\e[?2026h\e[?2027h\e[?1004h' | sugarspark
echo

echo "── OSC (clipboard write) ────────────────────────"
printf '\e]52;c;dGVzdA==\e\\' | sugarspark
echo

echo "── OSC 8 hyperlink ──────────────────────────────"
printf '\e]8;;https://charm.sh\e\\Charm\e]8;;\e\\' | sugarspark
echo

echo "── DCS (XTVERSION reply) ────────────────────────"
printf '\eP>|xterm(367)\e\\' | sugarspark
echo

echo "── APC (CandyZone marker) ───────────────────────" # @rename-keep legacy wire token / historical log
printf '\e_candyzone:S:btn:ok\e\\OK\e_candyzone:E:btn:ok\e\\' | sugarspark
