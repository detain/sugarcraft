#!/usr/bin/env bash
# Flip the default branch of every sugarcraft/<lib> repo from `main`
# to `master` (and create the master branch if it doesn't exist).
# Idempotent — repos already on master are skipped.
#
# Auth: needs `gh auth login` for a user with admin on sugarcraft.
#
#   ./scripts/set-org-default-master.sh

set -euo pipefail
unset GITHUB_TOKEN
ORG="sugarcraft"

LIBS=(
    sugar-core sugar-style sugar-spring sugar-mouse
    sugar-widgets sugar-charts sugar-forms candy-scriptui
    sugar-markdown sugar-cli candy-codeshot candy-mdview candy-ansi-inspect
    sugar-ssh-server candy-ssh-launcher sugar-metrics
    candy-starter candy-tetris super-candy candy-code
    candy-git candy-sql candy-codetime
    candy-minesweeper sugar-animation candy-top candy-flappy
)

if ! command -v gh >/dev/null 2>&1; then
    echo "error: gh CLI not found — install https://cli.github.com" >&2
    exit 1
fi

for slug in "${LIBS[@]}"; do
    if ! gh repo view "$ORG/$slug" >/dev/null 2>&1; then
        echo "skip  $ORG/$slug (does not exist — run bootstrap-org-repos.sh first)"
        continue
    fi

    current=$(gh api "/repos/$ORG/$slug" --jq '.default_branch')
    if [ "$current" = "master" ]; then
        echo "skip  $ORG/$slug (already on master)"
        continue
    fi

    # The master branch may not exist yet on a brand-new repo. The
    # rename API copies the current default and atomically renames it
    # — no force-push, no separate create-then-PATCH dance.
    echo "rename $ORG/$slug: $current → master"
    gh api -X POST "/repos/$ORG/$slug/branches/$current/rename" \
        -F new_name=master >/dev/null
done

echo
echo "all done."
