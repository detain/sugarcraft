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

declare -A DESCRIPTIONS=(
    [candy-core]="Elm-architecture TUI runtime — port of charmbracelet/bubbletea on the SugarCraft stack."
    [candy-sprinkles]="Declarative styling + layout — port of charmbracelet/lipgloss on the SugarCraft stack."
    [honey-bounce]="Damped spring physics + Newtonian projectile sim — port of charmbracelet/harmonica."
    [candy-zone]="Mouse-zone tracker — port of lrstanley/bubblezone on the SugarCraft stack."
    [sugar-bits]="Fourteen pre-built TUI components — port of charmbracelet/bubbles."
    [sugar-charts]="Terminal charts — port of NimbleMarkets/ntcharts on the SugarCraft stack."
    [sugar-prompt]="Interactive form library — port of charmbracelet/huh on the SugarCraft stack."
    [candy-shell]="Composer-installable CLI of TUI primitives — port of charmbracelet/gum."
    [candy-shine]="Markdown → ANSI renderer — port of charmbracelet/glamour on the SugarCraft stack."
    [candy-kit]="CLI presentation helpers — port of charmbracelet/fang on the SugarCraft stack."
    [candy-freeze]="Code → SVG screenshot generator — port of charmbracelet/freeze."
    [sugar-glow]="Markdown CLI viewer + pager — port of charmbracelet/glow."
    [sugar-spark]="ANSI escape-sequence inspector — port of charmbracelet/sequin."
    [candy-wish]="SSH server middleware framework — port of charmbracelet/wish."
    [sugar-wishlist]="TUI directory of SSH endpoints — port of charmbracelet/wishlist."
    [candy-metrics]="Telemetry primitives — port of charmbracelet/promwish on the SugarCraft stack."
    [candy-mold]="Starter skeleton for CandyCore apps — port of bubbletea-app-template."
    [candy-tetris]="Tetris on the SugarCraft stack — port of Broderick-Westrope/tetrigo."
    [super-candy]="Dual-pane terminal file manager — port of yorukot/superfile."
    [sugar-crush]="AI coding-assistant chat shell — port of charmbracelet/crush."
    [sugar-stash]="Terminal Git client — port of jesseduffield/lazygit on the SugarCraft stack."
    [candy-query]="Terminal SQLite browser — port of jorgerojas26/lazysql on the SugarCraft stack."
    [sugar-tick]="Privacy-first coding-time tracker — port of Rtarun3606k/TakaTime."
    [candy-mines]="Minesweeper TUI — port of maxpaulus43/go-sweep on the SugarCraft stack."
    [candy-flip]="ASCII GIF viewer — port of namzug16/gifterm on the SugarCraft stack."
    [honey-flap]="Flappy-Bird-style game — port of kbrgl/flapioca on the SugarCraft stack."
)

# Default topic set for every repo — the sync-sugarcraft workflow
# expects these to exist so each push doesn't have to re-tag.
TOPICS=(php tui terminal candycore sugarcraft composer)

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
        -F "names[]=candycore" -F "names[]=sugarcraft" -F "names[]=composer" \
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
