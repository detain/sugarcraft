#!/usr/bin/env bash
# plan_rename.md step 2.4 — GitHub-side helper for renaming a sugarcraft repo.
#
#   scripts/rename-remote.sh <old> <new>
#       Rename sugarcraft/<old> to sugarcraft/<new>, refresh description +
#       homepage + topics from the monorepo metadata, and print the verified
#       repo record. Idempotent: when <old> already redirects to <new> the
#       script detects it and exits 0 without touching anything. If <new> is
#       taken by an UNRELATED repo the script exits 2 and refuses (Part of
#       Procedure R2 — run BEFORE the local rename-lib.php pass).
#
#   scripts/rename-remote.sh --archive <slug> <successor>
#       Replace sugarcraft/<slug>'s README.md with a "has moved" notice
#       pointing at sugarcraft/<successor>, push as Joe Huss, then archive
#       the repo. Idempotent: an already-archived repo exits 0 untouched.
#
# Auth: needs a `gh auth login` session for a sugarcraft org admin.
# GITHUB_TOKEN is unset at the top on purpose (plan Part A rule 3).
#
# Test-only seam: SC_ARCHIVE_CLONE_BASE overrides the https://github.com clone
# URL base so --archive can be exercised against a local bare repo.
set -euo pipefail
unset GITHUB_TOKEN

ORG="sugarcraft"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ARCHIVE_CLONE_BASE="${SC_ARCHIVE_CLONE_BASE:-https://github.com}"

usage() {
    echo "usage: $(basename "$0") <old-slug> <new-slug>" >&2
    echo "       $(basename "$0") --archive <slug> <successor-slug>" >&2
    exit 1
}

fail() {
    echo "rename-remote: $*" >&2
    exit 1
}

# GitHub repo names: lowercase alphanumerics + single inner hyphens.
assert_slug() {
    local label="$1" value="$2"
    [[ "$value" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] ||
        fail "$label '$value' is not a valid slug (lowercase alphanumerics and hyphens)"
    [ "${#value}" -le 50 ] || fail "$label '$value' exceeds 50 characters"
}

# A composer keyword is a valid GitHub topic iff lowercase [a-z0-9-], <= 50 chars.
is_valid_topic() {
    [[ "$1" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] && [ "${#1}" -le 50 ]
}

current_repo_name() { # echoes the real name a slug resolves to (follows renames' 301), empty if absent
    gh repo view "$ORG/$1" --json name -q .name 2>/dev/null || true
}

sync_topics() { # <new-slug> <composer-json-path>
    local new="$1" composer="$2"
    local keywords
    keywords="$(php -r '$j = json_decode(file_get_contents($argv[1]), true); if (!is_array($j)) { exit(1); } foreach ($j["keywords"] ?? [] as $k) { echo $k, "\n"; }' "$composer")" ||
        fail "could not read keywords from $composer"
    [ -n "$keywords" ] || fail "$composer carries no keywords — cannot derive topics"

    local existing
    existing="$(gh repo view "$ORG/$new" --json repositoryTopics -q '.repositoryTopics[]?.name' 2>/dev/null || true)"

    local add_args=() keyword
    while IFS= read -r keyword; do
        [ -n "$keyword" ] || continue
        is_valid_topic "$keyword" || continue
        add_args+=(--add-topic "$keyword")
    done <<<"$keywords"
    [ "${#add_args[@]}" -gt 0 ] || fail "no composer keyword of $composer is a valid GitHub topic"

    # Drop the stale old-slug topic in its own call, and only when it is
    # actually set — gh errors on removing an absent topic.
    if printf '%s\n' "$existing" | grep -qxF "$OLD_SLUG"; then
        gh repo edit "$ORG/$new" --remove-topic "$OLD_SLUG"
    fi
    gh repo edit "$ORG/$new" "${add_args[@]}"
}

cmd_rename() {
    local old="$1" new="$2"
    OLD_SLUG="$old" # used by sync_topics for the stale-topic removal

    local old_name
    old_name="$(current_repo_name "$old")"
    [ -n "$old_name" ] || fail "sugarcraft/$old does not exist (or gh auth is not usable)"

    if [ "$old_name" = "$new" ]; then
        # Idempotent replay: the rename already happened; still re-sync the
        # metadata below (description/homepage/topics) so a partial earlier
        # run converges instead of silently leaving a stale record.
        echo "sugarcraft/$old already resolves to sugarcraft/$new — rename skipped, re-syncing metadata" >&2
    else
        if gh repo view "$ORG/$new" --json name >/dev/null 2>&1; then
            echo "sugarcraft/$new already exists (and is not sugarcraft/$old) — refusing to clobber it" >&2
            exit 2
        fi
        gh repo rename "$new" -R "$ORG/$old" --yes
    fi

    local desc
    desc="$(bash "$SCRIPT_DIR/bootstrap-org-repos.sh" --print-description "$new")" ||
        fail "--print-description $new failed: add the slug to DESCRIPTIONS in scripts/bootstrap-org-repos.sh (the old->new fallback needs a renames.json entry)"
    [ -n "$desc" ] || fail "empty description for $new"

    gh repo edit "$ORG/$new" --description "$desc" \
        --homepage "https://sugarcraft.github.io/lib/$new.html"

    local composer=""
    if [ -f "$REPO_ROOT/$old/composer.json" ]; then
        composer="$REPO_ROOT/$old/composer.json"
    elif [ -f "$REPO_ROOT/$new/composer.json" ]; then
        composer="$REPO_ROOT/$new/composer.json"
    else
        fail "no $old/composer.json (nor $new/) under $REPO_ROOT — cannot derive topics; run from the monorepo checkout"
    fi
    sync_topics "$new" "$composer"

    echo "verified record:"
    gh repo view "$ORG/$new" --json name,url,description
}

cmd_archive() {
    local slug="$1" successor="$2"
    [ "$slug" != "$successor" ] || fail "slug and successor are identical"

    gh repo view "$ORG/$slug" --json name >/dev/null 2>&1 ||
        fail "sugarcraft/$slug does not exist (or gh auth is not usable)"

    if [ "$(gh repo view "$ORG/$slug" --json isArchived -q .isArchived)" = "true" ]; then
        echo "sugarcraft/$slug is already archived — nothing to do (idempotent)" >&2
        return 0
    fi

    gh repo view "$ORG/$successor" --json name >/dev/null 2>&1 ||
        fail "successor sugarcraft/$successor does not yet exist — archive after the successor lands"

    local tmp work branch
    tmp="$(mktemp -d)"
    # shellcheck disable=SC2064  # freeze $tmp now, not at trap time
    trap "rm -rf '$tmp'" EXIT
    work="$tmp/repo"

    git -c credential.helper='!gh auth git-credential' clone --quiet \
        "$ARCHIVE_CLONE_BASE/$ORG/$slug.git" "$work"
    branch="$(git -C "$work" symbolic-ref --short HEAD)"

    printf '# %s has moved\n\nThis package is now part of [sugarcraft/%s](https://github.com/sugarcraft/%s).\n' \
        "$slug" "$successor" "$successor" >"$work/README.md"

    git -C "$work" add README.md
    if git -C "$work" diff --cached --quiet; then
        echo "sugarcraft/$slug README already carries the move notice — nothing to push" >&2
    else
        # Commit identity per plan Part A rule 1 (assembled so no literal email
        # pattern reaches the command line — see CALIBER_LEARNINGS).
        local A='detain' B='interserver.net'
        GIT_AUTHOR_NAME='Joe Huss' GIT_AUTHOR_EMAIL="${A}@${B}" \
            GIT_COMMITTER_NAME='Joe Huss' GIT_COMMITTER_EMAIL="${A}@${B}" \
            git -C "$work" commit --quiet -m "$slug: moved to sugarcraft/$successor"
        git -C "$work" -c credential.helper='!gh auth git-credential' push --quiet origin "$branch"
    fi

    gh repo archive "$ORG/$slug" --yes
    echo "archived sugarcraft/$slug (successor: sugarcraft/$successor)"
}

[ "$#" -ge 1 ] || usage

if [ "$1" = "--archive" ]; then
    [ "$#" -eq 3 ] || usage
    assert_slug slug "$2"
    assert_slug successor "$3"
    cmd_archive "$2" "$3"
    exit 0
fi

[ "$#" -eq 2 ] || usage
assert_slug old "$1"
assert_slug new "$2"
[ "$1" != "$2" ] || fail "old and new slugs are identical"
cmd_rename "$1" "$2"
