#!/usr/bin/env bash
# Show a logo slug at every colour depth: tools/show-logo.sh <slug>
cd "$(dirname "$0")/.." || exit 1
for d in tc 256 16; do
  for f in logo-"$1"-"$d"-*.ansi; do
    [ -e "$f" ] || continue
    printf '\n\e[1m── %s ──\e[0m\n' "$f"; cat "$f"
  done
done
