#!/bin/sh
#
# install.sh - fetch, verify and install the candy-code phar from the project's
# GitHub releases (plan_comparison C-SES-6.c).
#
# Six jobs, in order, each one fail-closed:
#
#   1. Require curl and PHP 8.3+ with sodium (the verifier is the same
#      sodium_crypto_sign_verify_detached leg SelfUpdate.php runs).
#   2. Resolve the release: the highest non-draft, non-prerelease tag of the
#      form candy-code/vX.Y.Z (--channel stable, the default), or the exact
#      candy-code/dev prerelease (--channel dev). --base-url swaps the
#      GitHub API origin for a location holding one releases.json of the
#      same shape, which is what the test fixtures and any future mirror
#      use; every later leg runs identically because the asset URLs come
#      from the list either way.
#   3. Download the legs the release carries: candy-code.phar, its sidecar
#      candy-code.phar.sha256, and a signature over one of them - the
#      preferred candy-code.phar.sha256.sig (Ed25519 over the EXACT sidecar
#      bytes) or the legacy candy-code.phar.minisig (over the whole phar).
#   4. Compare the phar's sha256 against the sidecar.
#   5. Verify the signature against the pinned key below. A release with no
#      signature asset is refused - the same pinned rule as SelfUpdate.
#   6. Install into --dir (default $HOME/.local/bin, else /usr/local/bin
#      when HOME is unset) as `candy-code` with mode 0755, staged and moved
#      so a running binary is never truncated in place.
#
# Options: --channel stable|dev, --dir DIR, --base-url URL, -h|--help.
# No environment variables are read: flags only.
#
# Verify order follows SelfUpdate: a .sha256.sig verifies the sidecar before
# the sidecar is trusted as the source of the digest; a legacy .minisig
# covers the phar itself.

set -u

# The trust anchor: Ed25519 public key, raw 32 bytes, base64. InstallScriptTest
# asserts this line byte-equals candy-code/src/Cli/SelfUpdate.php's
# PUBLIC_KEY_BASE64 constant. Change one, change both, in the same commit.
PINNED_PUBLIC_KEY="izi+7OlG5PTqGBpv03s2jnc5vRdxnChdBZIonN67T6o="

API_URL="https://api.github.com/repos/detain/sugarcraft/releases?per_page=100"
RELEASE_PAGE_LIMIT=5

PHAR_ASSET="candy-code.phar"
SIDECAR_ASSET="candy-code.phar.sha256"
SIDECAR_SIG_ASSET="candy-code.phar.sha256.sig"
MINISIG_ASSET="candy-code.phar.minisig"
TAG_PREFIX="candy-code/"
DEV_TAG="candy-code/dev"
BIN_NAME="candy-code"

CHANNEL="stable"
BIN_DIR=""
BASE_URL=""
TMP=""

usage() {
	cat <<'USAGE'
Usage: install.sh [--channel stable|dev] [--dir DIR] [--base-url URL]

Installs the signed candy-code phar as DIR/candy-code (mode 0755).

  --channel stable   Install the newest candy-code/vX.Y.Z release (default).
  --channel dev      Install the rolling candy-code/dev prerelease.
  --dir DIR          Install destination directory (default $HOME/.local/bin,
                     /usr/local/bin when HOME is unset).
  --base-url URL     Read releases.json from URL instead of the GitHub API;
                     asset URLs inside the list are used as given.
  -h, --help         Show this help.
USAGE
}

die() {
	printf 'install.sh: %s\n' "$1" >&2
	exit 1
}

need_arg() {
	# $@ as seen at a "--flag" arm: the value must be present as $2.
	[ "$#" -ge 2 ] || die "$1 requires a value"
}

set_channel() {
	case "$1" in
	stable | dev) CHANNEL="$1" ;;
	*) die "unknown --channel '$1' (expected stable or dev)" ;;
	esac
}

while [ "$#" -gt 0 ]; do
	case "$1" in
	-h | --help)
		usage
		exit 0
		;;
	--channel)
		need_arg "$@"
		set_channel "$2"
		shift
		;;
	--channel=*)
		set_channel "${1#--channel=}"
		;;
	--dir)
		need_arg "$@"
		[ -n "$2" ] || die "--dir requires a non-empty path"
		BIN_DIR="$2"
		shift
		;;
	--dir=*)
		BIN_DIR="${1#--dir=}"
		[ -n "$BIN_DIR" ] || die "--dir requires a non-empty path"
		;;
	--base-url)
		need_arg "$@"
		[ -n "$2" ] || die "--base-url requires a value"
		BASE_URL="$2"
		shift
		;;
	--base-url=*)
		BASE_URL="${1#--base-url=}"
		[ -n "$BASE_URL" ] || die "--base-url requires a value"
		;;
	--) ;;
	-*) die "unknown option '$1' (try --help)" ;;
	*) die "unexpected argument '$1' (try --help)" ;;
	esac
	shift
done

case "$BASE_URL" in
-*) die "--base-url must not start with '-'" ;;
esac

# ---------------------------------------------------------------- 1. tools ---

command -v curl >/dev/null 2>&1 || die "curl is required but was not found on PATH"

command -v php >/dev/null 2>&1 ||
	die "PHP 8.3 or newer (with sodium) is required but no php was found on PATH"

php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' ||
	die "PHP 8.3 or newer is required (found: $(php -r 'echo PHP_VERSION;'))"

php -r 'exit(function_exists("sodium_crypto_sign_verify_detached") ? 0 : 1);' ||
	die "the PHP sodium extension is required to verify the release signature"

if [ -z "$BIN_DIR" ]; then
	if [ -n "${HOME:-}" ]; then
		BIN_DIR="$HOME/.local/bin"
	else
		BIN_DIR="/usr/local/bin"
		printf 'install.sh: HOME is unset, installing to /usr/local/bin (needs write access)\n' >&2
	fi
fi

TMP=$(mktemp -d 2>/dev/null) || die "could not create a temporary directory"
cleanup() {
	[ -n "$TMP" ] && rm -rf "$TMP"
}
trap 'cleanup; exit 1' HUP INT TERM
trap 'cleanup' EXIT

# ------------------------------------------------- 2. resolve the release ---

cat >"$TMP/select.php" <<'PHPSELECTION'
<?php
// Mirrors SelfUpdate::selectRelease / selectDevRelease (plan_comparison
// C-SES-5.b): drafts are always skipped, prereleases are skipped on the
// stable channel and matched by EXACT tag on the dev channel, both required
// assets must carry non-empty download URLs, and the highest comparable
// X.Y.Z wins on the stable channel.
//
// argv: selection-out-file  channel  tag-prefix  dev-tag  pages-list-file
// The pages-list file names one fetched JSON page per line. Messages go to
// stderr WITHOUT a shell prefix; the caller stamps them.

$out = $argv[1];
$channel = $argv[2];
$tagPrefix = $argv[3];
$devTag = $argv[4];
$pageList = @file($argv[5], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($pageList === false) {
	fwrite(STDERR, "could not read the page list\n");
	exit(1);
}

$releases = [];
foreach ($pageList as $page) {
	$body = @file_get_contents($page);
	if ($body === false) {
		fwrite(STDERR, "could not read the fetched release page $page\n");
		exit(1);
	}
	$decoded = json_decode($body, true);
	// A bare list is the GitHub API shape; a JSON object keyed "releases" is
	// the shape a curated mirror index (or a hand-written fixture) may carry.
	$rows = null;
	if (is_array($decoded)) {
		if (array_is_list($decoded)) {
			$rows = $decoded;
		} elseif (isset($decoded['releases']) && is_array($decoded['releases'])) {
			$rows = $decoded['releases'];
		}
	}
	if ($rows === null) {
		fwrite(STDERR, "the fetched release list is not a JSON array of releases\n");
		exit(1);
	}
	foreach ($rows as $release) {
		if (is_array($release)) {
			$releases[] = $release;
		}
	}
}

$pickTag = static function (array $release) use ($channel, $tagPrefix, $devTag): ?string {
	$tag = isset($release['tag_name']) ? (string) $release['tag_name'] : '';
	if ($tag === '' || ($release['draft'] ?? false) === true) {
		return null;
	}
	if ($channel === 'dev') {
		return $tag === $devTag ? 'dev' : null;
	}
	if (($release['prerelease'] ?? false) === true) {
		return null;
	}
	if (strncmp($tag, $tagPrefix, strlen($tagPrefix)) !== 0) {
		return null;
	}
	$version = substr($tag, strlen($tagPrefix));
	return preg_match('/^v?\d+\.\d+\.\d+$/', $version) === 1 ? $version : null;
};

// The same tuple order SelfUpdate applies via Version::compare (parse strips
// an optional leading "v"; only comparable versions were admitted above).
$cmp = static function (string $a, string $b): int {
	preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $a, $ma);
	preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $b, $mb);
	foreach ([1, 2, 3] as $i) {
		if ((int) $ma[$i] !== (int) $mb[$i]) {
			return (int) $ma[$i] <=> (int) $mb[$i];
		}
	}
	return 0;
};

$pharKey = 'candy-code.phar';
$sidecarKey = 'candy-code.phar.sha256';

$best = null;
$bestAssets = [];
foreach ($releases as $release) {
	$version = $pickTag($release);
	if ($version === null) {
		continue;
	}
	$assets = [];
	foreach ((array) ($release['assets'] ?? []) as $asset) {
		if (!is_array($asset) || !isset($asset['name'])) {
			continue;
		}
		$url = (string) ($asset['browser_download_url'] ?? '');
		if ($url !== '') {
			$assets[(string) $asset['name']] = $url;
		}
	}
	if (($assets[$pharKey] ?? '') === '' || ($assets[$sidecarKey] ?? '') === '') {
		continue; // the two required legs; same rule as SelfUpdate
	}
	if ($best === null || ($channel !== 'dev' && $cmp($version, $best) > 0)) {
		$best = $version;
		$bestAssets = $assets;
	}
}

if ($best === null) {
	fwrite(STDERR, 'no candy-code release carrying its phar and sidecar assets was found on the '
		. ($channel === 'dev' ? 'dev channel' : 'stable channel') . "\n");
	exit(1);
}

if (file_put_contents(
	$out,
	$best . "\n"
		. ($bestAssets[$pharKey] ?? '') . "\n"
		. ($bestAssets[$sidecarKey] ?? '') . "\n"
		. ($bestAssets['candy-code.phar.sha256.sig'] ?? '') . "\n"
		. ($bestAssets['candy-code.phar.minisig'] ?? '') . "\n"
) === false) {
	fwrite(STDERR, "could not write the selection file\n");
	exit(1);
}
PHPSELECTION

: >"$TMP/pages"
if [ -n "$BASE_URL" ]; then
	# The list origin swaps; the shape does not. file:// is accepted so the
	# fixture tests run the exact legs the live install runs.
	curl -fsSL -- "$BASE_URL/releases.json" >"$TMP/page-1.json" ||
		die "could not fetch $BASE_URL/releases.json"
	printf '%s\n' "$TMP/page-1.json" >>"$TMP/pages"
else
	page=1
	next="$API_URL"
	while [ -n "$next" ] && [ "$page" -le "$RELEASE_PAGE_LIMIT" ]; do
		curl -fsSL -D "$TMP/headers-$page" -- "$next" >"$TMP/page-$page.json" ||
			die "could not fetch the GitHub release list (page $page)"
		printf '%s\n' "$TMP/page-$page.json" >>"$TMP/pages"
		next=$(sed -n 's/.*<\([^>]*\)>;[^,]*rel="next".*/\1/p' "$TMP/headers-$page" | head -n 1)
		page=$((page + 1))
	done
fi

php "$TMP/select.php" "$TMP/selection" "$CHANNEL" "$TAG_PREFIX" "$DEV_TAG" "$TMP/pages" 2>"$TMP/err" || {
	sed 's/^/install.sh: /' "$TMP/err" >&2
	exit 1
}

release_version=$(sed -n '1p' "$TMP/selection")
phar_url=$(sed -n '2p' "$TMP/selection")
sidecar_url=$(sed -n '3p' "$TMP/selection")
sig_url=$(sed -n '4p' "$TMP/selection")
minisig_url=$(sed -n '5p' "$TMP/selection")

printf 'install.sh: selected %s%s\n' "$TAG_PREFIX" "$release_version"

cat >"$TMP/parse.php" <<'PHPPARSE'
<?php
// Mirrors SelfUpdate::parseSidecar and SelfUpdate::sidecarName over the raw
// sidecar FILE bytes (the pinned digests are lowercase-hex-first by the
// shipped format). Prints "<digest>\n<name-or-empty>\n"; exit 1 = malformed.

$body = trim((string) @file_get_contents($argv[1]));
if (preg_match('/^([0-9a-f]{64})(?:\s+\S+)?\s*$/', $body, $m) !== 1) {
	exit(1);
}
$named = '';
if (preg_match('/^[0-9a-f]{64}\s+(\S+)/', $body, $m2) === 1) {
	$named = $m2[1];
}
echo $m[1], "\n", $named, "\n";
PHPPARSE

cat >"$TMP/verify.php" <<'PHPVERIFY'
<?php
// Mirrors SelfUpdate::verifyDetached: strict base64, exactly 64 signature
// bytes, exactly 32 key bytes, and the payload as the EXACT bytes fetched
// (whitespace is tolerated only in the two base64 slots).

$sigFile = $argv[1];
$payloadFile = $argv[2];
$keyArg = $argv[3];

$sigBody = @file_get_contents($sigFile);
if ($sigBody === false) {
	exit(2);
}
$keyBody = base64_decode(trim($keyArg), true);
if ($keyBody === false || strlen($keyBody) !== 32) {
	exit(3);
}
$sig = base64_decode(trim($sigBody), true);
if ($sig === false || strlen($sig) !== 64) {
	exit(4);
}
$payload = @file_get_contents($payloadFile);
if ($payload === false) {
	exit(5);
}
try {
	$ok = sodium_crypto_sign_verify_detached($sig, $payload, $keyBody);
} catch (Throwable $e) {
	exit(6);
}
exit($ok ? 0 : 7);
PHPVERIFY

fetch_asset() {
	# $1 url, $2 destination, $3 label. curl's "--" ENDS option parsing, so
	# every option must precede it; the URL comes last.
	curl -fsSL -o "$2" -- "$1" || die "could not download $3"
	[ -s "$2" ] || die "$3 is empty after download"
}

# --------------------------------------------------- 3. download the legs ---

fetch_asset "$phar_url" "$TMP/$PHAR_ASSET" "$PHAR_ASSET"
fetch_asset "$sidecar_url" "$TMP/$SIDECAR_ASSET" "$SIDECAR_ASSET"

php "$TMP/parse.php" "$TMP/$SIDECAR_ASSET" >"$TMP/parsed" 2>/dev/null ||
	die "$SIDECAR_ASSET is malformed (expected: 64 lowercase hex digits, optionally one file name)"
expected_digest=$(sed -n '1p' "$TMP/parsed")
sidecar_name=$(sed -n '2p' "$TMP/parsed")
if [ -n "$sidecar_name" ] && [ "$sidecar_name" != "$PHAR_ASSET" ]; then
	die "$SIDECAR_ASSET names '$sidecar_name' but the phar asset is '$PHAR_ASSET'"
fi

# ---------------------------------------------- 5. verify signature, then ---
# ---------------------------------------------- 4. compare the digest ------

verified_with=""
if [ -n "$sig_url" ]; then
	fetch_asset "$sig_url" "$TMP/$SIDECAR_SIG_ASSET" "$SIDECAR_SIG_ASSET"
	php "$TMP/verify.php" "$TMP/$SIDECAR_SIG_ASSET" "$TMP/$SIDECAR_ASSET" "$PINNED_PUBLIC_KEY" ||
		die "$SIDECAR_SIG_ASSET does not verify the sidecar against the pinned key"
	verified_with="$SIDECAR_SIG_ASSET"
elif [ -n "$minisig_url" ]; then
	fetch_asset "$minisig_url" "$TMP/$MINISIG_ASSET" "$MINISIG_ASSET"
	printf 'install.sh: note: %s is a deprecated signature asset; the release should publish %s\n' "$MINISIG_ASSET" "$SIDECAR_SIG_ASSET" >&2
	php -d memory_limit=-1 "$TMP/verify.php" "$TMP/$MINISIG_ASSET" "$TMP/$PHAR_ASSET" "$PINNED_PUBLIC_KEY" ||
		die "$MINISIG_ASSET does not verify the phar against the pinned key"
	verified_with="$MINISIG_ASSET"
else
	die "the release carries no signature asset ($SIDECAR_SIG_ASSET or $MINISIG_ASSET); refusing to install an unverified phar"
fi

# The digest comparison runs BEHIND the signature: only bytes the pinned key
# vouched for are trusted to say what the phar must hash to.
compute_digest() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{print $1}'
	else
		die "neither sha256sum nor shasum is available to verify the download"
	fi
}
actual_digest=$(compute_digest "$TMP/$PHAR_ASSET" | tr 'A-Z' 'a-z')
[ "$actual_digest" = "$expected_digest" ] ||
	die "$PHAR_ASSET failed its sha256 check (the signature verified, so this is corruption in transit or at rest, not a key mismatch)"

printf 'install.sh: signature (%s) and sha256 verified\n' "$verified_with"

# ------------------------------------------------------------- 6. install ---

dest="$BIN_DIR/$BIN_NAME"
stage="$TMP/$BIN_NAME.stage"
cp "$TMP/$PHAR_ASSET" "$stage" || die "could not stage the download"
chmod 0755 "$stage" || die "could not set mode 0755 on the staged file"
mkdir -p "$BIN_DIR" || die "could not create $BIN_DIR"
mv -f "$stage" "$dest" || die "could not move the staged file to $dest"

printf 'installed candy-code %s to %s\n' "$release_version" "$dest"
case ":$PATH:" in
*":$BIN_DIR:"*) ;;
*) printf 'install.sh: note: %s is not on PATH; add it so the shell can find candy-code\n' "$BIN_DIR" >&2 ;;
esac
