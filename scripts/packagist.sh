#!/usr/bin/env bash
# plan_rename.md step 2.4 — Packagist API helper for sugarcraft packages.
#
#   scripts/packagist.sh create <slug>   register https://github.com/sugarcraft/<slug>
#   scripts/packagist.sh update <slug>   force re-index of the package
#   scripts/packagist.sh check  <slug>   read-only: does dev-master exist on
#                                        repo.packagist.org and at which SHA?
#
# create/update are "unsafe" endpoints and need the MAIN Packagist API token
# plus the Packagist account name that owns it (plan Part A rule 7). Both are
# supplied from the environment at run time only:
#   PACKAGIST_TOKEN=<token — the line after 'packagist.org:' in the mystage
#                   credentials file> \
#   PACKAGIST_USERNAME=<your Packagist account — same value CI reads from the
#                   PACKAGIST_USERNAME secret> \
#   bash scripts/packagist.sh create <slug>
# Auth rides the QUERY STRING (?username=..&apiToken=..), exactly like the
# proven call in .github/workflows/sync-sugarcraft.yml (runs every push) —
# Packagist does not accept a Bearer header. Never echo the token (this script
# must never be run under `set -x`); everything printed is additionally piped
# through mask_tokens() as defense in depth. check needs no credentials.
#
# NOTE: Packagist has NO abandon API — abandoning old names is a manual
# web-UI action (plan Phase 10).
set -euo pipefail

ORG="sugarcraft"
API_BASE="https://packagist.org/api"
REPO_BASE="https://repo.packagist.org"

usage() {
    echo "usage: $(basename "$0") create <slug> | update <slug> | check <slug>" >&2
    exit 1
}

fail() {
    echo "packagist: $*" >&2
    exit 1
}

assert_slug() {
    local value="$1"
    [[ "$value" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] ||
        fail "'$value' is not a valid package slug (lowercase alphanumerics and hyphens)"
    [ "${#value}" -le 50 ] || fail "'$value' exceeds 50 characters"
}

# Defense in depth (review MINOR-5): scrub token-shaped content out of any
# echoed request/response before it reaches a terminal or a captured log.
mask_tokens() {
    sed -E 's/(apiToken=)[^&"]+/\1REDACTED/g; s/("apiToken":")[^"]+/\1REDACTED/g'
}

require_creds() {
    local missing=0
    if [ -z "${PACKAGIST_TOKEN:-}" ]; then
        echo "packagist: PACKAGIST_TOKEN is not set — see plan Part A rule 7 (token = the line after 'packagist.org:' in the mystage credentials file, supplied as env at run time)" >&2
        missing=1
    fi
    if [ -z "${PACKAGIST_USERNAME:-}" ]; then
        echo "packagist: PACKAGIST_USERNAME is not set — the Packagist account owning the token (CI reads it from the PACKAGIST_USERNAME secret)" >&2
        missing=1
    fi
    [ "$missing" -eq 0 ] || exit 2
}

# Print HTTP code + masked response body; 2xx → return 0, anything else → exit 1.
report() { # <label> <http_code> <body_file>
    local label="$1" http_code="$2" body_file="$3"
    local body
    body="$(cat "$body_file")"
    echo "HTTP $http_code"
    printf '%s\n' "$body" | mask_tokens
    case "$http_code" in
    2??) return 0 ;;
    *)
        echo "packagist: $label failed with HTTP $http_code" >&2
        exit 1
        ;;
    esac
}

# create <slug> — register the repo with Packagist via the canonical
# maintainer form endpoint (packages/_update.json), authenticated through the
# query string like the proven CI call.
cmd_create() { # <slug>
    local slug="$1"
    require_creds

    local body_file http_code
    body_file="$(mktemp)"
    # shellcheck disable=SC2064  # freeze $body_file now, not at trap time
    trap "rm -f '$body_file'" EXIT

    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' --max-time 20 -XPOST \
        "$API_BASE/packages/_update.json?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
        --data-urlencode "packages[$ORG/$slug]=https://github.com/$ORG/$slug")" ||
        fail "curl to $API_BASE/packages/_update.json failed (network?)"

    report "create (register) for $ORG/$slug" "$http_code" "$body_file"
}

# update <slug> — request assembly byte-matches the CI ping in
# .github/workflows/sync-sugarcraft.yml (query-param auth + nested
# {"repository":{"url":...}} JSON body to /api/update-package).
cmd_update() { # <slug>
    local slug="$1"
    require_creds

    local body_file http_code
    body_file="$(mktemp)"
    # shellcheck disable=SC2064  # freeze $body_file now, not at trap time
    trap "rm -f '$body_file'" EXIT

    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' --max-time 20 -XPOST \
        -H 'content-type: application/json' \
        "$API_BASE/update-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
        -d "{\"repository\":{\"url\":\"https://github.com/$ORG/$slug\"}}")" ||
        fail "curl to $API_BASE/update-package failed (network?)"

    report "update-package for $ORG/$slug" "$http_code" "$body_file"
}

cmd_check() { # read-only, no token
    local slug="$1"
    local body_file http_code
    body_file="$(mktemp)"
    # shellcheck disable=SC2064  # freeze $body_file now, not at trap time
    trap "rm -f '$body_file'" EXIT

    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' \
        "$REPO_BASE/p2/$ORG/$slug~dev.json")" ||
        fail "curl to $REPO_BASE/p2/$ORG/$slug~dev.json failed (network?)"

    if [ "$http_code" != "200" ]; then
        echo "dev-master: ABSENT (HTTP $http_code — not registered or not indexed yet)"
        exit 1
    fi

    php -r '
        $j = json_decode(file_get_contents($argv[1]), true);
        $versions = $j["packages"]["sugarcraft/" . $argv[2]] ?? [];
        $dev = null;
        foreach ($versions as $v) {
            if (($v["version"] ?? null) === "dev-master") { $dev = $v; break; }
        }
        if ($dev === null) {
            echo "dev-master: ABSENT (metadata served, but no dev-master version yet)\n";
            exit(1);
        }
        $ref = $dev["source"]["reference"] ?? null;
        echo "dev-master: present\n";
        echo "source.reference: " . ($ref ?? "(no source.reference)") . "\n";
        exit(0);
    ' "$body_file" "$slug"
}

[ "$#" -eq 2 ] || usage
assert_slug "$2"

case "$1" in
create) cmd_create "$2" ;;
update) cmd_update "$2" ;;
check) cmd_check "$2" ;;
*) usage ;;
esac
