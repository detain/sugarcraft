#!/usr/bin/env bash
# Render the project README in a few themes for comparison.
#
#   ./examples/render-readme.sh

set -euo pipefail

README="${1:-../README.md}"

for theme in dark dracula tokyo-night pink; do
    echo "═══ $theme ═══"
    sugarglow --theme "$theme" --width 80 "$README" | head -40
    echo
done
