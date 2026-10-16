#!/usr/bin/env bash
# Render the project README in a few themes for comparison.
#
#   ./examples/render-readme.sh

set -euo pipefail

# Resolve `candy-mdview` once up-front. `command -v` inside the function
# would match the function itself and recurse / fall through to a
# binary lookup that fails when running from a checkout without a
# global composer install. Stash an absolute path instead.
if [ -x "$(dirname "${BASH_SOURCE[0]}")/../bin/candy-mdview" ]; then
    CANDY_MDVIEW_BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/bin/candy-mdview"
else
    CANDY_MDVIEW_BIN="$(type -P candy-mdview || true)"
    if [ -z "$CANDY_MDVIEW_BIN" ]; then
        echo "candy-mdview: not found on PATH and ./bin/candy-mdview missing" >&2
        exit 1
    fi
fi
export CANDY_MDVIEW_BIN
run_mdview() { "$CANDY_MDVIEW_BIN" "$@"; }
export -f run_mdview

README="${1:-../README.md}"

for theme in dark dracula tokyo-night pink; do
    echo "═══ $theme ═══"
    run_mdview --theme "$theme" --width 80 "$README" | head -40
    echo
done
