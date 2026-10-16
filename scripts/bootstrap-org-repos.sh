#!/usr/bin/env bash
# Create the per-lib repositories under the sugarcraft org. Run once
# by an org admin; idempotent — repos that already exist are skipped.
#
# Auth: needs a `gh auth login` session (or `GH_TOKEN`) for a user
# with admin rights on the sugarcraft org.
#
#   ./scripts/bootstrap-org-repos.sh

set -euo pipefail
unset GITHUB_TOKEN
ORG="sugarcraft"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

declare -A DESCRIPTIONS=(
    [candy-core]="Elm-architecture TUI runtime — port of charmbracelet/bubbletea on the SugarCraft stack."
    [candy-sprinkles]="Declarative styling + layout — port of charmbracelet/lipgloss on the SugarCraft stack."
    [honey-bounce]="Damped spring physics + Newtonian projectile sim — port of charmbracelet/harmonica."
    [candy-mouse]="Mouse-zone tracker — port of lrstanley/bubblezone on the SugarCraft stack."
    [sugar-bits]="Seventeen pre-built TUI components — port of charmbracelet/bubbles on the SugarCraft stack."
    [sugar-charts]="Terminal charts — port of NimbleMarkets/ntcharts on the SugarCraft stack."
    [candy-forms]="Interactive form library — port of charmbracelet/huh on the SugarCraft stack."
    [candy-scriptui]="Composer-installable CLI of TUI primitives — port of charmbracelet/gum."
    [candy-shine]="Markdown → ANSI renderer — port of charmbracelet/glamour on the SugarCraft stack."
    [candy-kit]="CLI presentation helpers — port of charmbracelet/fang on the SugarCraft stack."
    [candy-freeze]="Code → SVG screenshot generator — port of charmbracelet/freeze."
    [candy-mdview]="Markdown CLI viewer + pager — port of charmbracelet/glow."
    [candy-ansi-inspect]="ANSI escape-sequence inspector — port of charmbracelet/sequin."
    [candy-wish]="SSH server middleware framework — port of charmbracelet/wish."
    [sugar-wishlist]="TUI directory of SSH endpoints — port of charmbracelet/wishlist."
    [candy-metrics]="Telemetry primitives — port of charmbracelet/promwish on the SugarCraft stack."
    [candy-starter]="Starter skeleton for SugarCraft apps — port of bubbletea-app-template."
    [candy-tetris]="Tetris on the SugarCraft stack — port of Broderick-Westrope/tetrigo."
    [super-candy]="Dual-pane terminal file manager — port of yorukot/superfile."
    [sugar-crush]="AI coding-assistant chat shell — port of charmbracelet/crush."
    [sugar-crush-web]="Browser UI for sugar-crush's WebSocket server mode — Vite + Vue 3 bundle with a one-class PHP shim."
    [candy-git]="Terminal Git client — port of jesseduffield/lazygit on the SugarCraft stack."
    [candy-sql]="Terminal SQLite browser — port of jorgerojas26/lazysql on the SugarCraft stack."
    [sugar-tick]="Privacy-first coding-time tracker — port of Rtarun3606k/TakaTime."
    [candy-minesweeper]="Minesweeper TUI — port of maxpaulus43/go-sweep on the SugarCraft stack."
    [candy-flip]="ASCII GIF viewer — port of namzug16/gifterm on the SugarCraft stack."
    [candy-gifview]="Terminal GIF + .ansi-art viewer — port of gifterm."
    [candy-top]="btop-style terminal system monitor — port of aristocratos/btop on the SugarCraft stack."
    [candy-flappy]="Flappy-Bird-style game — port of kbrgl/flapioca on the SugarCraft stack."
)

# plan_rename.md step 2.4: offline metadata mode — print one description and
# exit (no gh, no network). rename-remote.sh uses it during Procedure R2,
# BEFORE rename-lib.php (R3) has rewritten this map's keys, so an unknown key
# is resolved through the old->new pairs in tools/renames.json, then the
# monorepo <dir>/composer.json description as last resort.
if [ "${1:-}" = "--print-description" ]; then
    [ "$#" -eq 2 ] || { echo "usage: $(basename "$0") --print-description <slug>" >&2; exit 1; }
    slug="$2"
    if [ -n "${DESCRIPTIONS[$slug]:-}" ]; then
        printf '%s\n' "${DESCRIPTIONS[$slug]}"
        exit 0
    fi
    old="$(php -r '
        $file = $argv[1];
        $wanted = $argv[2];
        if (!is_file($file)) { exit(0); }
        $j = json_decode(file_get_contents($file), true);
        foreach ($j["entries"] ?? [] as $e) {
            if (($e["new"] ?? null) === $wanted && !empty($e["old"])) { echo $e["old"]; break; }
        }
    ' "$SCRIPT_DIR/../tools/renames.json" "$slug")" || old=""
    if [ -n "$old" ] && [ -n "${DESCRIPTIONS[$old]:-}" ]; then
        printf '%s\n' "${DESCRIPTIONS[$old]}"
        exit 0
    fi
    # The map predates several libs; fall back to the monorepo composer.json
    # description (the metadata SSOT per R4.2) for the new or old slug's dir.
    local_dir=""
    if [ -f "$SCRIPT_DIR/../$slug/composer.json" ]; then
        local_dir="$SCRIPT_DIR/../$slug"
    elif [ -n "$old" ] && [ -f "$SCRIPT_DIR/../$old/composer.json" ]; then
        local_dir="$SCRIPT_DIR/../$old"
    fi
    if [ -n "$local_dir" ]; then
        composer_desc="$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["description"] ?? "";' "$local_dir/composer.json")"
        if [ -n "$composer_desc" ]; then
            printf '%s\n' "$composer_desc"
            exit 0
        fi
    fi
    echo "bootstrap-org-repos: no description for '$slug' (DESCRIPTIONS, renames.json old->new, or composer.json all missed)" >&2
    exit 1
fi

# Default topic set for every repo — the sync-sugarcraft workflow
# expects these to exist so each push doesn't have to re-tag.
TOPICS=(php tui terminal sugarcraft sugarcraft composer)

if ! command -v gh >/dev/null 2>&1; then
    echo "error: gh CLI not found — install https://cli.github.com" >&2
    exit 1
fi

for slug in "${!DESCRIPTIONS[@]}"; do
    desc="${DESCRIPTIONS[$slug]}"
    if gh repo view "$ORG/$slug" >/dev/null 2>&1; then
        echo "skip  $ORG/$slug (already exists)"
        continue
    fi
    echo "create $ORG/$slug"
    gh repo create "$ORG/$slug" \
        --public \
        --description "$desc" \
        --homepage "https://sugarcraft.github.io/lib/$slug.html"
    # Topics + features.
    gh api -X PUT "/repos/$ORG/$slug/topics" \
        -F "names[]=php" -F "names[]=tui" -F "names[]=terminal" \
        -F "names[]=sugarcraft" -F "names[]=sugarcraft" -F "names[]=composer" \
        -H "Accept: application/vnd.github.mercy-preview+json" >/dev/null
    gh api -X PATCH "/repos/$ORG/$slug" \
        -F has_issues=true \
        -F has_discussions=true \
        -F allow_squash_merge=true \
        -F allow_merge_commit=true \
        -F allow_rebase_merge=false \
        -F delete_branch_on_merge=true >/dev/null
done

echo
echo "all done. now run the sync workflow once to push initial contents:"
echo "  gh workflow run sync-sugarcraft.yml -R detain/sugarcraft"
echo
echo "after the first sync push lands, normalise default branches"
echo "(main → master) so they match the monorepo:"
echo "  ./scripts/set-org-default-master.sh"
echo
echo "tip: set the org-wide default-branch name to 'master' in"
echo "  https://github.com/organizations/$ORG/settings/repository-defaults"
echo "to make this automatic for future repos."
