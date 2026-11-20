#!/usr/bin/env bash
#
# sync-sugarcraft-local.sh — manual local runner for the sugarcraft-org sync
#
# What this is:
#   A faithful local port of the sync-libs + sync-site jobs of
#   .github/workflows/sync-sugarcraft.yml WITHOUT the ci-gate job: the owner
#   runs this to publish from a chosen commit with CI-green NOT required
#   (the OD-7 fail-closed gate stays CI-only and is deliberately not ported).
#   The YAML is the authority on WHY each piece exists — the comments here
#   cite it; keep the two in sync when either changes.
#
# Usage:
#   scripts/sync-sugarcraft-local.sh [options] [lib ...]
#
#     (no options)     split-sync ALL libs (affected-libs.php --all) + site
#     --affected       sync only libs affected over a range, like a push event
#     --range <r>      before..head range for the affected computation
#                      (default HEAD~1..HEAD; implies --affected)
#     --libs "a b"     explicit lib dirs — same as the workflow_dispatch
#                      `libs` input, validated identically (regex + composer.json)
#     lib [lib ...]    positional shorthand for --libs
#     --rev <sha>      monorepo commit everything derives from (default HEAD);
#                      splitsh derives from commits, not the working tree
#     --dry-run        compute the split SHAs, compare them to the live remote
#                      tips, print the plan table + the site WOULD-change
#                      status — commit nothing, push nothing. Works tokenless.
#     --no-site        skip the sugarcraft.github.io docs sync
#     --site-only      skip the lib split wave; sync only the site
#     --no-packagist   skip the post-push Packagist pings
#     -h, --help       this text
#
# Auth:
#   TOKEN resolves from SUGARCRAFT_SYNC_TOKEN, else `gh auth token` when gh is
#   installed and authenticated (probed silently; never echoed). A real run
#   refuses without one; a dry run needs nothing — every remote read here is
#   a public ls-remote or a gh repo view.
#   Pushes ride the YAML's GIT_ASKPASS pattern (RT-33): the token never enters
#   a remote URL or any on-disk .git/config, and the helper itself refuses
#   when TOKEN is unset rather than answering auth with garbage.
#
# Environment knobs (same names the YAML honors):
#   SUGARCRAFT_SYNC_TOKEN   GitHub token, repo scope on the sugarcraft org
#   SPLIT_BASE_URL          push base — default https://github.com/sugarcraft;
#                           set git@github.com:sugarcraft to push over SSH
#   PACKAGIST_USERNAME      default 'detain'
#   PACKAGIST_TOKEN         Packagist API token — enables the pings
#
# Exit codes:
#   0 clean · 1 any failure (a failing lib is named) · 2 usage error
# ---------------------------------------------------------------------------
set -euo pipefail

usage() { awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"; }

# Never let a read park forever on a credential prompt. The CI runner dies
# on such a hang; locally it would just eat the terminal. Auth answers come
# from GIT_ASKPASS below, so anything reaching the prompt path should fail
# fast instead.
export GIT_TERMINAL_PROMPT=0

ORG="sugarcraft"
base="${SPLIT_BASE_URL:-https://github.com/sugarcraft}"

REPO="$(git rev-parse --show-toplevel)"
cd "$REPO"

# --- CLI ---------------------------------------------------------------------

dry_run=0
site_only=0
no_site=0
no_packagist=0
mode=all # all | affected | explicit
RANGE=""
REV=HEAD
named=()

while [ $# -gt 0 ]; do
	case "$1" in
	-h | --help)
		usage
		exit 0
		;;
	--dry-run) dry_run=1 ;;
	--site-only) site_only=1 ;;
	--no-site) no_site=1 ;;
	--no-packagist) no_packagist=1 ;;
	--affected) mode=affected ;;
	--range)
		RANGE="${2:?--range needs a value (before..head)}"
		shift
		mode=affected
		;;
	--libs)
		read -r -a named <<<"${2:?--libs needs a value}"
		shift
		mode=explicit
		;;
	--rev)
		REV="${2:?--rev needs a value}"
		shift
		;;
	--) : ;;
	-*)
		echo "sync-sugarcraft-local: unknown option: $1" >&2
		usage >&2
		exit 2
		;;
	*)
		named+=("$1")
		mode=explicit
		;;
	esac
	shift
done

if [ "$site_only" = 1 ] && [ "$no_site" = 1 ]; then
	echo "sync-sugarcraft-local: --site-only and --no-site cancel out; nothing to do" >&2
	exit 2
fi

# Splitsh derives from commits, not the working tree, so every read below
# (dir checks, composer validation, release-tag lookup) is pinned to a
# resolved commit object. An unresolvable --rev must abort before any
# remote is contacted.
if ! REV="$(git rev-parse -q --verify "${REV}^{commit}" 2>/dev/null)"; then
	echo "sync-sugarcraft-local: --rev '$REV' does not resolve to a commit in this repo" >&2
	exit 2
fi
HEAD_SHA_CURRENT="$(git rev-parse HEAD)"

workdir="$(mktemp -d)"
plan="$workdir/plan"
pushdir="$workdir/ok"
tipdir="$workdir/tips"
mkdir -p "$pushdir" "$tipdir"
: >"$plan"
trap 'rm -rf "$workdir"' EXIT

# --- auth (RT-33 askpass, same shape as the YAML's two push jobs) ------------

TOKEN="${SUGARCRAFT_SYNC_TOKEN:-}"
if [ -z "$TOKEN" ] && command -v gh >/dev/null 2>&1; then
	# Silent probe: gh on a logged-in box is the local equivalent of the
	# secret the workflow carries. Never print the value.
	TOKEN="$(gh auth token 2>/dev/null || true)"
fi

if [ -n "$TOKEN" ]; then
	git_askpass="$workdir/askpass.sh"
	{
		echo '#!/bin/sh'
		echo '[ -n "${TOKEN:-}" ] || { echo "GIT_ASKPASS: TOKEN env is unset - refusing to answer auth" >&2; exit 1; }'
		echo 'case "$1" in'
		echo '  *sername*) printf "x-access-token\n" ;;'
		echo '  *)         printf "%s\n" "$TOKEN" ;;'
		echo 'esac'
	} >"$git_askpass"
	chmod 700 "$git_askpass"
	export GIT_ASKPASS="$git_askpass"
	export TOKEN
	# ensure_repo must act as the SAME identity the pushes ride — in the
	# workflow both come from one secret (sync-sugarcraft.yml:210-213).
	export GH_TOKEN="$TOKEN"
elif [ "$dry_run" -eq 0 ]; then
	cat >&2 <<'MSG'
sync-sugarcraft-local: refusing to run a real sync without a token.
  export SUGARCRAFT_SYNC_TOKEN=<PAT with repo scope on the sugarcraft org>
  (or install `gh` and `gh auth login` — the token is then picked up silently)
  Read-only rehearsal needs nothing:  scripts/sync-sugarcraft-local.sh --dry-run
MSG
	exit 1
fi

# --- retry helper (verbatim from the YAML, both push jobs share it) ----------

retry_git_push() {
	local max_attempts=3
	local delay=5
	local attempt=1
	while ((attempt <= max_attempts)); do
		if "$@"; then
			return 0
		fi
		echo "Push failed (attempt $attempt/$max_attempts), waiting ${delay}s..." >&2
		sleep $delay
		((attempt++))
		delay=$((delay * 2))
	done
	echo "Push failed after $max_attempts attempts" >&2
	return 1
}

# --- splitsh-lite resolution --------------------------------------------------
#
# Determinism law (sync-sugarcraft.yml:175-181): splitsh-lite v1.0.1 output is
# a pure function of (prefix, commit) — same monorepo HEAD + same single
# --prefix always yields the same split SHA (verified against the live
# sugarcraft/* masters in prompt_kit/briefs/rename-2.1/REPORT.md §3). It is
# NOT equal to `git subtree split` output, so nothing may substitute subtree
# here: the SHA this runner pushes must be the splitsh-lite SHA.
#
# Every candidate is sha256-verified, INCLUDING one found on PATH: this box
# once carried a fake /tmp/splitsh-lite shell stub whose output looked like a
# commit SHA and silently poisoned a whole comparison run. The pinned
# checksum below is the YAML's own (sync-sugarcraft.yml:199-203).
#
# Unlike the runner, we DO let splitsh use its in-repo .git/splitsh.db cache:
# the "permission denied" abort noted in the YAML is a cache-restored-BoltDB
# runner pathology; locally the db sits in the developer's own .git and the
# first lib pays the full history walk while the rest are near-instant.

SPLITSH_EXPECTED="ec46c5a409422bf55b26f7de1faab67c2320362934947f984872b3aedc4a9d99"
SPLITSH=""

verify_splitsh() {
	# $1 = candidate path → echo nothing on success, non-zero on mismatch.
	[ -x "$1" ] || return 1
	echo "$SPLITSH_EXPECTED  $1" | sha256sum -c - >/dev/null 2>&1
}

for candidate in \
	"${SPLITSH_LITE_BIN:-}" \
	"$(command -v splitsh-lite 2>/dev/null || true)" \
	"$HOME/.cache/sync-sugarcraft/splitsh-lite" \
	"/tmp/splitsh-lite"; do
	[ -n "$candidate" ] || continue
	if verify_splitsh "$candidate"; then
		SPLITSH="$candidate"
		break
	fi
	echo "note: ignoring splitsh-lite candidate '$candidate' — sha256 does not match the pinned v1.0.1 digest" >&2
done

if [ -z "$SPLITSH" ]; then
	SPLITSH_CACHE="$HOME/.cache/sync-sugarcraft"
	mkdir -p "$SPLITSH_CACHE"
	url="https://github.com/splitsh/lite/releases/download/v1.0.1/lite_linux_amd64.tar.gz"
	curl -fsSL "$url" -o "$SPLITSH_CACHE/lite_linux_amd64.tar.gz"
	tar -xzf "$SPLITSH_CACHE/lite_linux_amd64.tar.gz" -C "$workdir" ./splitsh-lite
	# Verify-then-promote: a corrupt or replaced artifact never lands in cache.
	echo "$SPLITSH_EXPECTED  $workdir/splitsh-lite" | sha256sum -c -
	chmod +x "$workdir/splitsh-lite"
	mv "$workdir/splitsh-lite" "$SPLITSH_CACHE/splitsh-lite"
	chmod 755 "$SPLITSH_CACHE/splitsh-lite"
	SPLITSH="$SPLITSH_CACHE/splitsh-lite"
fi
"$SPLITSH" --version >/dev/null # fail fast before the plan loop if it cannot run

split_sha() {
	# $1 = lib prefix. CI-shape invocation when REV is the checked-out HEAD
	# (the workflow always is: actions/checkout pins HEAD to the event SHA);
	# -commit repoints the split at an older commit for --rev runs. Go's flag
	# package accepts -commit and --commit identically.
	local lib="$1"
	if [ "$REV" = "$HEAD_SHA_CURRENT" ]; then
		"$SPLITSH" --prefix="$lib"
	else
		"$SPLITSH" --prefix="$lib" -commit="$REV"
	fi
}

# --- rename continuity (verbatim port of the YAML's old_slug_for) ------------
#
# tools/renames.json is the authoritative old->new map (plan_rename.md
# Part C). Print the pre-rename slug when the lib dir $1 is a rename target;
# empty otherwise. FAILS CLOSED (exit 1) on a missing/corrupt map or a
# duplicate `new` claim: an empty answer here silently skips legacy
# preservation, so an unreadable map must abort the whole sync rather than
# let renamed libs force-push uninspected (sync-sugarcraft.yml:271-281).
# The splitsh-lite invocation above must stay SINGLE-prefix — two plain
# --prefix flags are a hard parse error in v1.0.1 and never preserved
# continuity anyway (prompt_kit/briefs/rename-2.1/REPORT.md §4, §8).
old_slug_for() {
	php -r '
      if (!is_file("tools/renames.json")) {
        fwrite(STDERR, "old_slug_for: tools/renames.json is missing — refusing to sync uninspected\n");
        exit(1);
      }
      $map = json_decode((string) file_get_contents("tools/renames.json"), true);
      if (!is_array($map) || !isset($map["entries"]) || !is_array($map["entries"])) {
        fwrite(STDERR, "old_slug_for: tools/renames.json is not a valid renames map — refusing to sync uninspected\n");
        exit(1);
      }
      $matches = [];
      foreach ($map["entries"] as $e) {
        if (($e["kind"] ?? "") !== "rename" || ($e["old"] ?? null) === null) continue;
        if (($e["new"] ?? null) === $argv[1]) $matches[] = $e["old"];
      }
      if (count($matches) > 1) {
        fwrite(STDERR, "old_slug_for: " . count($matches) . " rename entries claim new slug {$argv[1]} (" . implode(", ", $matches) . ") — refusing to guess\n");
        exit(1);
      }
      echo $matches[0] ?? "";
    ' "$1"
}

# --- lib roster (three shapes, same as the YAML) ------------------------------

all_libs() {
	mapfile -t libs < <(php scripts/affected-libs.php --all 2>/dev/null | python3 -c "import json,sys; print('\n'.join(json.load(sys.stdin)['all_libs']))")
	# An empty list means affected-libs.php (or php) failed — a process-sub
	# failure can't trip set -e, so fail loudly rather than silently "sync"
	# zero libs and report success (sync-sugarcraft.yml:344-350).
	if [ "${#libs[@]}" -eq 0 ]; then
		echo "lib list is empty — affected-libs.php --all produced nothing" >&2
		exit 1
	fi
}

if [ "$mode" = explicit ]; then
	# Same validation as the workflow_dispatch arm
	# (sync-sugarcraft.yml:129-140): nothing unvalidated reaches the push
	# loop. The composer.json check is pinned to REV, not the working tree,
	# so --rev runs validate what the split will actually read.
	for lib in "${named[@]}"; do
		if ! [[ "$lib" =~ ^(sugar|candy)-[a-z0-9-]+$ ]] ||
			[ -z "$(git ls-tree --name-only "$REV" -- "$lib/composer.json")" ]; then
			echo "libs: '$lib' is not a monorepo lib dir (sugar-*/candy-* with composer.json at $REV)" >&2
			exit 2
		fi
	done
	libs=("${named[@]}")
	echo "explicit libs: ${libs[*]}"
elif [ "$mode" = affected ]; then
	# Same affected-libs logic as ci.yml: changed libs + transitive dependents
	# over the whole range. Locally there is no github.event.before, so the
	# default range is the HEAD~1..HEAD fallback the YAML uses for dispatches
	# and broken before-shas (sync-sugarcraft.yml:147-152).
	RANGE="${RANGE:-HEAD~1..HEAD}"
	echo "affected-libs range: $RANGE"
	# stdout ONLY: the script prints its one-line summary on stderr, and
	# folding that into the JSON (2>&1) made every CI parse fail — which fell
	# back to force_all and pushed all libs on every sync
	# (sync-sugarcraft.yml:154-157). stderr still reaches the console here.
	result=$(php scripts/affected-libs.php --range "$RANGE")
	# A parse failure still fails SAFE (push everything), never silent.
	affected=$(echo "$result" | python3 -c "import json,sys; d=json.load(sys.stdin); print(' '.join(d['affected']))" || true)
	force_all=$(echo "$result" | python3 -c "import json,sys; d=json.load(sys.stdin); print('yes' if d['force_all'] else 'no')" || echo "yes")
	if [ "$force_all" = "yes" ]; then
		echo "force_all=yes — pushing all libs"
		all_libs
	else
		echo "Pushing affected libs:"
		echo "$affected"
		read -r -a libs <<<"$affected"
	fi
else
	echo "default mode: all libs"
	all_libs
fi

# --- preflight: warn (never abort) when local master trails origin -----------
# Deliberately no auto-fetch: the runner must not mutate the user's remote
# refs as a side effect of a sync probe. The remote-tracking ref may be
# stale; that possibility is part of the warning.
if git rev-parse -q --verify refs/remotes/origin/master >/dev/null; then
	if ! git merge-base --is-ancestor refs/remotes/origin/master "$REV" 2>/dev/null; then
		behind=$(git rev-list --count "$REV"..refs/remotes/origin/master 2>/dev/null || echo "?")
		echo "! note: origin/master (as last fetched) is $behind commit(s) ahead of $REV — this run will not publish them (no fetch performed)" >&2
	fi
fi

echo "monorepo rev: $(git log -1 --format='%h %s' "$REV")"
[ "$dry_run" -eq 1 ] && echo "DRY RUN — nothing will be committed or pushed"

# --- phase 1: plan + split (serial — splitsh's warm cache makes the walk once)
#
# RT-27: the publish wave is phased exactly as the YAML does it — phase 1
# computes every split SHA serially (ensure_repo's fail-loud preflight stays
# AHEAD of the wave: the parallel push must never race a missing repo),
# phase 2 fans the pushes out bounded with xargs -P 8.

ensure_repo() {
	# First-sync preflight (RT-06, sync-sugarcraft.yml:306-334), verbatim in
	# intent: view -> create public with the composer.json description (the
	# metadata SSOT, same source bootstrap-org-repos.sh resolves) + homepage
	# + the same topics PUT. If provisioning cannot be resolved, refuse LOUD
	# naming the lib — never let the push retry loop stand in for a missing
	# repo. Locally, when gh is absent the remote's existence is probed with
	# a read-only ls-remote instead (creating still needs an admin path).
	local lib="$1"
	if ! command -v gh >/dev/null 2>&1; then
		if ! git ls-remote --heads "$base/$lib.git" >/dev/null 2>&1; then
			echo "ensure_repo: sugarcraft/$lib is unreachable and gh is unavailable — create the repo (or install gh) before syncing it" >&2
			exit 1
		fi
		return 0
	fi
	if gh repo view "$ORG/$lib" >/dev/null 2>&1; then
		return 0
	fi
	echo "ensure_repo: sugarcraft/$lib missing — auto-creating (first sync of this lib)"
	desc="$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["description"] ?? "";' "$lib/composer.json" 2>/dev/null || true)"
	create_args=(repo create "$ORG/$lib" --public --homepage "https://sugarcraft.github.io/lib/$lib.html")
	if [ -n "$desc" ]; then
		create_args+=(--description "$desc")
	fi
	if ! gh "${create_args[@]}"; then
		echo "ensure_repo: cannot create sugarcraft/$lib — grant SUGARCRAFT_SYNC_TOKEN admin on the org, or run scripts/bootstrap-org-repos.sh as an org admin" >&2
		exit 1
	fi
	gh api -X PUT "/repos/$ORG/$lib/topics" \
		-F "names[]=php" -F "names[]=tui" -F "names[]=terminal" \
		-F "names[]=sugarcraft" -F "names[]=composer" \
		-H "Accept: application/vnd.github.mercy-preview+json" >/dev/null || true
}

for lib in "${libs[@]}"; do
	# Dir check pinned to REV via ls-tree (the YAML's [ -d "$lib" ] reads a
	# fresh checkout whose HEAD is exactly this commit — equivalent intent).
	[ -n "$(git ls-tree --name-only "$REV" -- "$lib/")" ] || {
		echo "skip $lib (no dir at $REV)"
		continue
	}
	if [ "$dry_run" -eq 0 ]; then
		ensure_repo "$lib"
	fi
	# splitsh-lite prints the split-commit SHA for the prefix on stdout.
	sha=$(split_sha "$lib")
	# Rename continuity (step 2.1 verdict NOT-CONTINUOUS fallback): when this
	# dir came from a `git mv <old> <new>` the split has a fresh unrelated
	# root, so the remote master still holds the only copy of the pre-rename
	# split history. Immediate-predecessor lookup only (chained renames A->B->C
	# preserve the A-era chain as legacy/B). Non-renamed libs answer empty, so
	# the push worker never touches the network for the guard.
	prev=$(old_slug_for "$lib")
	printf '%s|%s|%s|%s|%s\n' "$lib" "$sha" "$prev" "$base" "$pushdir" >>"$plan"
done

if [ ! -s "$plan" ]; then
	echo "plan is empty — nothing to sync (lib roster resolved to no splittable dirs)" >&2
	exit 1
fi

# --- phase 2: push wave / dry comparison ---------------------------------------

if [ "$dry_run" -eq 1 ]; then
	# Read-only rehearsal: compare each computed split SHA to the live remote
	# master tip. ls-remote rc=0/empty means the repo has no master yet
	# (new-repo); non-zero means auth/network trouble — recorded per lib, and
	# a real run would fail closed on exactly this read (see push_one).
	fetch_tip() {
		# xargs children never see the parent's locals (same law as push_one
		# and ping_one in the YAML), so the output dir rides in the spec.
		set -euo pipefail
		local spec="$1" lib bs tipdir tip
		IFS='|' read -r lib bs tipdir <<<"$spec"
		if tip=$(git ls-remote "$bs/$lib.git" refs/heads/master 2>/dev/null | cut -f1); then
			if [ -n "$tip" ]; then printf '%s\n' "$tip"; else printf 'MISSING\n'; fi
		else
			printf 'ERROR\n'
		fi >"$tipdir/$lib.txt"
	}
	export -f fetch_tip
	cut -d'|' -f1,4 "$plan" | while IFS='|' read -r l b; do printf '%s|%s|%s\n' "$l" "$b" "$tipdir"; done |
		xargs -d '\n' -P 8 -n 1 bash -c 'fetch_tip "$1"' _ || true

	noop=0 change=0 newrepo=0 errored=0
	change_names=() new_names=() err_names=()
	printf '%-26s %-41s %-41s %-13s %s\n' 'lib' 'split-sha' 'remote-tip' 'status' 'prev-rename'
	while IFS='|' read -r lib sha prev bs _pushdir; do
		tip="$(cat "$tipdir/$lib.txt" 2>/dev/null || echo ERROR)"
		case "$tip" in
		ERROR)
			status="remote-error"
			errored=$((errored + 1))
			err_names+=("$lib")
			;;
		MISSING)
			status="new-repo"
			newrepo=$((newrepo + 1))
			new_names+=("$lib")
			;;
		"$sha")
			status="no-op"
			noop=$((noop + 1))
			;;
		*)
			status="would-change"
			change=$((change + 1))
			change_names+=("$lib")
			;;
		esac
		printf '%-26s %-41s %-41s %-13s %s\n' "$lib" "$sha" "$tip" "$status" "$prev"
	done <"$plan"

	total=$((noop + change + newrepo + errored))
	echo "---"
	echo "dry-run totals: $total libs — $noop no-op, $change would-change, $newrepo new-repo, $errored remote-error"
	report_names() {
		local label="$1"
		shift
		[ "$#" -eq 0 ] || echo "  $label: $*"
	}
	report_names "would-change" "${change_names[@]+"${change_names[@]}"}"
	report_names "new-repo" "${new_names[@]+"${new_names[@]}"}"
	report_names "remote-error" "${err_names[@]+"${err_names[@]}"}"
	[ "$errored" -eq 0 ] || { echo "dry-run: some remotes were unreadable — a real run fails closed on those reads" >&2; }
else
	push_one() {
		# spec: lib|sha|prev|base|pushdir — runs as its own bash via xargs,
		# so everything it needs is in the spec or the env (verbatim shape
		# from sync-sugarcraft.yml:391-434).
		set -euo pipefail
		local spec="$1" lib sha prev base pushdir push_url remote_tip
		IFS='|' read -r lib sha prev base pushdir <<<"$spec"
		push_url="$base/$lib.git"
		if [ -n "$prev" ]; then
			# Deliberately no `|| true` on ls-remote: a truly empty repo
			# answers rc=0/empty, so a non-zero exit is an auth/network
			# error that must NOT masquerade as "nothing to preserve" —
			# fail closed, never force-push over an uninspected master.
			remote_tip=$(git ls-remote "$push_url" refs/heads/master | cut -f1)
			if [ -n "$remote_tip" ]; then
				git fetch --quiet "$push_url" "$remote_tip"
				if ! git merge-base --is-ancestor "$remote_tip" "$sha"; then
					echo "! $lib: pre-rename master $remote_tip is not an ancestor of $sha — preserving legacy/$prev"
					retry_git_push git push "$push_url" "$remote_tip:refs/heads/legacy/$prev"
					retry_git_push git push "$push_url" "$remote_tip:refs/tags/$prev-final"
				fi
			fi
		fi
		retry_git_push git push --force "$push_url" "$sha:refs/heads/master"
		# CS-02 (candy-starter-002): a <lib>/v* release tag riding the synced
		# commit propagates to the split repo so Packagist can see a stable
		# version channel — re-anchored onto the split SHA, because the
		# monorepo tag object names a monorepo commit the split repo has
		# never seen. (sync-sugarcraft.yml:414-431)
		if [ -n "${HEAD_SHA:-}" ]; then
			while IFS= read -r rel_tag; do
				[ -n "$rel_tag" ] || continue
				echo "! $lib: propagating release tag $rel_tag onto split SHA $sha"
				git tag -f "$rel_tag" "$sha" >/dev/null 2>&1
				retry_git_push git push --force "$push_url" "refs/tags/$rel_tag"
			done < <(git tag --points-at "$HEAD_SHA" --list "$lib/v*")
		fi
		: >"$pushdir/$lib.ok"
		echo "✓ synced $lib ($sha)"
	}
	# Release-tag consult uses the selected rev — the YAML's HEAD_SHA is the
	# event SHA; here the runner's whole contract is REV.
	export HEAD_SHA="$REV"
	export -f push_one retry_git_push

	push_rc=0
	xargs -a "$plan" -d '\n' -P 8 -n 1 bash -c 'push_one "$1"' _ || push_rc=$?
	# Collection is roster-driven, not xargs-rc-driven: a worker killed
	# sideways (no .ok file) fails closed even if xargs shrugged.
	failed=()
	while IFS='|' read -r lib _rest; do
		[ -f "$pushdir/$lib.ok" ] || failed+=("$lib")
	done <"$plan"
	if [ "$push_rc" -ne 0 ] || [ "${#failed[@]}" -gt 0 ]; then
		printf 'split push FAILED for: %s\n' "${failed[*]}" >&2
		exit 1
	fi
	echo "Done — $(wc -l <"$plan") libs pushed"

	# --- phase 3: Packagist pings (best-effort, after the push wave) ---------
	#
	# RT-09 note (sync-sugarcraft.yml:450-462): the update-package contract
	# takes username/apiToken as QUERY PARAMETERS, so the CI form rides the
	# token in the URL behind Actions log masking. Locally there is no
	# masking, so scripts/packagist.sh (the named local twin of this call) is
	# preferred — it keeps the credential out of argv via `curl -K -`. The
	# inline CI-form curl remains the fallback for boxes without the twin.
	if [ "$no_packagist" -eq 0 ] && [ -n "${PACKAGIST_TOKEN:-}" ]; then
		export PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-detain}"
		cut -d'|' -f1 "$plan" | while IFS= read -r l; do printf '%s\n' "$l"; done >"$workdir/pings"
		if [ -x "$REPO/scripts/packagist.sh" ]; then
			ping_one() {
				# spec: lib — packagist.sh update derives the repo URL from
				# the split's composer.json (same SSOT bootstrap uses).
				local spec="$1" lib
				lib="$spec"
				"$REPO/scripts/packagist.sh" update "$lib" || true
			}
		else
			ping_one() {
				local spec="$1" lib code
				IFS='|' read -r lib <<<"$spec"
				code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -XPOST \
					-H 'content-type: application/json' \
					"https://packagist.org/api/update-package?username=${PACKAGIST_USERNAME}&apiToken=${PACKAGIST_TOKEN}" \
					-d "{\"repository\":{\"url\":\"$base/$lib\"}}" || echo "000")
				echo "  ↳ packagist update $lib → HTTP $code"
			}
		fi
		export -f ping_one
		xargs -a "$workdir/pings" -d '\n' -P 8 -n 1 bash -c 'ping_one "$1"' _ || true
	fi
fi

# --- site sync (sync-site job ported; OD-7 gate intentionally absent) --------

if [ "$site_only" -eq 0 ] && [ "$no_site" -eq 1 ]; then
	echo "--no-site: skipping sugarcraft.github.io sync"
elif [ -d "$REPO/docs" ]; then
	site_tmp="$workdir/site"
	git clone --quiet "https://github.com/sugarcraft/sugarcraft.github.io.git" "$site_tmp"
	# The YAML pushes master unconditionally; verify what the default branch
	# actually is and flag a mismatch loudly instead of silently diverging.
	site_default="$(git -C "$site_tmp" ls-remote --symref origin HEAD 2>/dev/null |
		awk '/^ref:/ {sub("refs/heads/", "", $2); print $2; exit}')"
	if [ -n "$site_default" ] && [ "$site_default" != "master" ]; then
		echo "! site repo default branch is '$site_default' — syncing master anyway, exactly as sync-sugarcraft.yml does" >&2
	fi
	# docs/old and docs/research are repo working documents, not site content:
	# drop any copy already published so --delete removes them from the site.
	rm -rf "$site_tmp/old" "$site_tmp/research"
	rsync -a --delete --exclude=.git --exclude=/old/ --exclude=/research/ "$REPO/docs/" "$site_tmp/"
	git -C "$site_tmp" config user.name 'Joe Huss'
	git -C "$site_tmp" config user.email 'detain@interserver.net'
	git -C "$site_tmp" add -A
	if git -C "$site_tmp" diff --cached --quiet; then
		echo "site already in sync"
	elif [ "$dry_run" -eq 1 ]; then
		echo "site WOULD change:"
		git -C "$site_tmp" status --porcelain | sed 's/^/  /'
		echo "DRY RUN — site: commit nothing, push nothing"
	else
		git -C "$site_tmp" commit --quiet -m "Sync from monorepo @ ${REV:0:7}"
		retry_git_push git -C "$site_tmp" push --quiet origin master
		echo "✓ pushed site update"
	fi
fi

exit 0
