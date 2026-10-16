#!/usr/bin/env bash
# Show what candy-ansi-inspect labels for a handful of common terminal sequences.
#
#   ./examples/inspect.sh

set -euo pipefail

# Resolve which `candy-ansi-inspect` to use ONCE up-front. Using `command -v`
# inside the function would match the shell function we're about to
# define and recurse / fall through to `command candy-ansi-inspect`, which
# then tries to look up a binary by that name — fails when the user
# is running from a checkout without a global composer install
# (`candy-ansi-inspect: command not found`). Picking a concrete path now and
# stashing it in a variable side-steps the whole mess.
if [ -x "$(dirname "${BASH_SOURCE[0]}")/../bin/candy-ansi-inspect" ]; then
    CANDY_ANSI_INSPECT_BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/bin/candy-ansi-inspect"
else
    CANDY_ANSI_INSPECT_BIN="$(type -P candy-ansi-inspect || true)"
    if [ -z "$CANDY_ANSI_INSPECT_BIN" ]; then
        echo "candy-ansi-inspect: not found on PATH and ./bin/candy-ansi-inspect missing" >&2
        exit 1
    fi
fi
export CANDY_ANSI_INSPECT_BIN
run_ansi_inspect() { "$CANDY_ANSI_INSPECT_BIN" "$@"; }
export -f run_ansi_inspect

echo "── SGR (foreground colours) ────────────────────"
printf '\e[31mred\e[32m green\e[34m blue\e[0m default\n' | run_ansi_inspect
echo

echo "── DEC private modes ────────────────────────────"
printf '\e[?2026h\e[?2027h\e[?1004h' | run_ansi_inspect
echo

echo "── OSC (clipboard write) ────────────────────────"
printf '\e]52;c;dGVzdA==\e\\' | run_ansi_inspect
echo

echo "── OSC 8 hyperlink ──────────────────────────────"
printf '\e]8;;https://charm.sh\e\\Charm\e]8;;\e\\' | run_ansi_inspect
echo

echo "── DCS (XTVERSION reply) ────────────────────────"
printf '\eP>|xterm(367)\e\\' | run_ansi_inspect
echo

echo "── APC (CandyZone marker) ───────────────────────" # @rename-keep legacy wire token / historical log
printf '\e_candyzone:S:btn:ok\e\\OK\e_candyzone:E:btn:ok\e\\' | run_ansi_inspect
