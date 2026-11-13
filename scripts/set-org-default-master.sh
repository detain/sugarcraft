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
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Roster DERIVED from the CI discovery rule (scripts/affected-libs.php
# --all) — the hand-maintained list this replaces drifted: it still
# prescribed a repo that died in the 2026-10 rename and missed
# every lib added afterwards. Sync pushes exactly all_libs, so this
# script must flip exactly all_libs.
mapfile -t LIBS < <(php -r '
    $j = json_decode((string) file_get_contents("php://stdin"), true);
	if (!is_array($j) || !isset($j["all_libs"])) { fwrite(STDERR, "set-org-default-master: affected-libs.php --all produced no all_libs list\n"); exit(1); }
    foreach ($j["all_libs"] as $s) { echo $s, "\n"; }
' < <(php "$SCRIPT_DIR/affected-libs.php" --all)) || {
    echo "set-org-default-master: affected-libs discovery failed" >&2
    exit 1
}
[ "${#LIBS[@]}" -gt 0 ] || {
	echo "set-org-default-master: empty derived roster — refusing" >&2
	exit 1
}

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
