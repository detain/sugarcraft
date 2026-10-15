#!/usr/bin/env bash
# plan_rename.md step 2.4 — Packagist API helper for sugarcraft packages.
#
#   scripts/packagist.sh create <slug>   register https://github.com/sugarcraft/<slug>
#   scripts/packagist.sh update <slug>   force re-index of the package
#   scripts/packagist.sh check  <slug>   read-only: does dev-master exist on
#                                        repo.packagist.org and at which SHA?
#
# create/update are "unsafe" endpoints and need the MAIN Packagist API token
# (plan Part A rule 7):  PACKAGIST_TOKEN=<token from mystage credentials> \
#                        bash scripts/packagist.sh create <slug>
# The token is read from the environment ONLY, exits 2 when unset, and is
# never echoed (this script must never be run under `set -x`).
# check needs no token.
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

# POST to an unsafe endpoint; prints HTTP code + response body, never the token.
api_post() { # <endpoint-suffix> <slug>
    local endpoint="$1" slug="$2"
    if [ -z "${PACKAGIST_TOKEN:-}" ]; then
        echo "packagist: PACKAGIST_TOKEN is not set — see plan Part A rule 7 (grep the mystage credentials at run time)" >&2
        exit 2
    fi

    local body_file http_code body
    body_file="$(mktemp)"
    # shellcheck disable=SC2064  # freeze $body_file now, not at trap time
    trap "rm -f '$body_file'" EXIT

    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' -XPOST \
        -H "Authorization: Bearer detain:${PACKAGIST_TOKEN}" \
        -H 'Content-Type: application/json' \
        "$API_BASE/$endpoint" \
        -d "{\"repository\":\"https://github.com/$ORG/$slug\"}")" ||
        fail "curl to $API_BASE/$endpoint failed (network?)"

    body="$(cat "$body_file")"
    echo "HTTP $http_code"
    echo "$body"

    case "$http_code" in
    2??) return 0 ;;
    *)
        echo "packagist: $endpoint for $ORG/$slug failed with HTTP $http_code" >&2
        exit 1
        ;;
    esac
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
create) api_post "create-package" "$2" ;;
update) api_post "update-package" "$2" ;;
check) cmd_check "$2" ;;
*) usage ;;
esac
