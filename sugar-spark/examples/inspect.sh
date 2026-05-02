#!/usr/bin/env bash
# Show what sugarspark labels for a handful of common terminal sequences.
#
#   ./examples/inspect.sh

set -euo pipefail

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

echo "── APC (CandyZone marker) ───────────────────────"
printf '\e_candyzone:S:btn:ok\e\\OK\e_candyzone:E:btn:ok\e\\' | sugarspark
