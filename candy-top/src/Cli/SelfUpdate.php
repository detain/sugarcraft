<?php

declare(strict_types=1);

namespace SugarCraft\Top\Cli;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Support\Version;

/**
 * `candy-top self-update` — replace the running phar with the latest
 * published one, verified before the swap, with the pre-swap copy kept for a
 * one-shot rollback.
 *
 * Mirrors candy-code/src/Cli/SelfUpdate.php (plan_comparison DST-3 shipped
 * there; this is the port to candy-top, kept self-contained — no cross-app
 * shared refactor). The one shape deviation: candy-top's bin has no
 * ParsedArgs/SUBCOMMAND_FLAGS machinery, so {@see run()} takes the raw argv
 * slice after the verb and parses the three known flags itself. Every
 * refusal ORDER and security law is identical.
 *
 *     self-update [--check] [--rollback] [--allow-unsigned] [--channel=stable|dev]
 *
 * PHAR-MODE ONLY. The verb acts on the file `bin/candy-top` was launched
 * from: `\Phar::running(false)`. On a Composer install that answer is the
 * empty string, and this command REFUSES LOUDLY — prints the exact
 * `composer update sugarcraft/candy-top` to run instead and exits 1. A
 * silent no-op on the Composer path is the banned failure mode: it would
 * report success while changing nothing.
 *
 * WHAT "LATEST" MEANS HERE. The release trains out of detain/sugarcraft are
 * per-library tags (`candy-top/v0.1.0`, `candy-code/v0.1.1`, …) sharing one
 * GitHub release stream, so the repo-wide `/releases/latest` answer is
 * usually some other library's tag. The lookup is the one-page list
 * (`?per_page=100`), filtered to non-draft, non-prerelease releases whose
 * tag starts with {@see Version::TAG_PREFIX}, that carry BOTH required
 * assets (`candy-top.phar` and `candy-top.phar.sha256`), and highest by
 * {@see Version::compare()} — the newest CANDY-TOP release, not the newest
 * release. The signature asset (`candy-top.phar.minisig`) is OPTIONAL at
 * selection: whether it is REQUIRED is a fact about the pinned-key state,
 * not about the release, and the two must not be conflated.
 *
 * CHANNELS — WHICH TRAIN, DECIDED BEFORE SELECTION. `--channel=stable|dev`
 * says which release train to consult; omitted, what this build IS decides:
 * a dev-stamped archive (`dev-<sha>` through {@see Version::isDev()}, the
 * shape CI's dev builds carry) defaults to the DEV train, everything else
 * to STABLE. The stable story is the paragraph above, unchanged. The dev
 * train is the SINGLE moving release tagged exactly `candy-top/dev`
 * ({@see self::DEV_TAG}), prerelease flag allowed, draft refused, carrying
 * the same two required assets; its `target_commitish` names the commit the
 * archive was cut from, and "up to date" there means this build's stamp is
 * a prefix of that commit. Dev stamps are LOCATED, never ordered (the
 * Version law) — so the dev channel has no ordering compare and no
 * downgrade refusal: the moving tag is the whole answer, and a build whose
 * stamp cannot be located against it is REFUSED rather than silently
 * switched to the stable train. The verified swap legs are shared.
 *
 * HOW THE SWAP HAPPENS. The sidecar is fetched first and must parse to a
 * lowercase 64-hex-digit digest (its optional name field must match the phar
 * asset it signs). The phar is then downloaded to a temp file IN THE PHAR'S
 * OWN DIRECTORY (a rename only exchanges atoms inside one filesystem), its
 * sha256 is computed over the finished file and compared with
 * `hash_equals()`. If a signing key is pinned (see TRUST MODEL below) the
 * detached signature leg runs next, against those same temp bytes. Only then
 * is the running phar copied to `<phar>.old` and the temp renamed over it.
 * Every refusal before the rename deletes the temp and leaves the running
 * phar byte-identical; a mismatch that fires after the copy still leaves
 * `.old` as the exact prior bytes. `--rollback` renames `<phar>.old` back
 * over the phar — consuming it in the rename, so the restore is one-shot by
 * construction and touches no network.
 *
 * TRUST MODEL, HONESTLY — TWO STATES. Both the phar and its sidecar come
 * from the same origin (GitHub release assets, over TLS). Keyless state (a
 * build whose {@see self::PUBLIC_KEY_BASE64} is the empty string, or whose
 * release carries no signature asset while the key leg is off): the hash
 * bounds transport corruption, asset mixups and tampering with the phar
 * ALONE; a party who controls the release can replace the sidecar in the
 * same breath. The residual is named: trust in the GitHub account that owns
 * the repo. Pinned state (the shipped one — this constant carries the
 * sugarcraft-wide release key): a release whose `candy-top.phar.minisig` is
 * ABSENT, or does not verify against the pinned Ed25519 key, is REFUSED
 * LOUDLY — the sha256 sidecar alone no longer gates the swap, and an
 * attacker who owns the release stream can no longer sign their own poisoned
 * bytes. candy-top ships pinned from its first release: the keypair existed
 * before this verb did, so there is no candy-top release stream to distrust.
 * The pinned key can be rotated by shipping a new phar; until that first
 * self-update, a wrong-key emergency needs the fresh-installer path
 * (download + checksum by hand) — the same window every pinned-key updater
 * carries.
 *
 * EXITS. 0: up to date (--check or not), a verified swap completed, a
 * rollback restored. 1: composer-mode refusal, an unwritable target, a
 * refused downgrade, a comparison that cannot be made, a fetch, hash or
 * signature failure, --check finding an update available (that is the
 * scripted answer to "should I update?"). 2: usage errors (unknown operand,
 * `--channel=` with anything other than `stable` or `dev`, `--check`
 * together with `--rollback` or `--allow-unsigned`).
 * `--allow-unsigned` is the dev-only bypass of the signature leg in pinned
 * state; it never bypasses the sha256 sidecar and is refused next to
 * `--check` (there is nothing to bypass when nothing is swapped).
 *
 * TIME BOUNDS follow the E646 rule: a connect ceiling and a per-read
 * ceiling, deliberately no total request timeout — a large TUI phar download
 * on a slow link is legitimate work that a blanket clock would abort. The
 * read ceiling is what kills a stalled transfer.
 *
 * The $http / $releasesUrl / $pharPath / $currentVersion / $publicKey
 * parameters exist for the suite: `SelfUpdateTest` mounts a fake release
 * server as a Guzzle handler on a real client's middleware stack (canned
 * release JSON and asset bytes over canonical http routes, zero sockets,
 * zero real network), swaps a stub archive standing in for the phar, and may
 * name the installed version or the pinned key directly. When
 * $currentVersion is NOT injected, resolution runs for real through
 * `Help::versionString()`, which reads the host archive's stamped metadata
 * (DST-1) — the end-to-end shape the shipped phar actually takes.
 * {@see useErrorStreamForTesting} redirects the report lines. Production
 * dispatch supplies none of them.
 */
final class SelfUpdate
{
    public const EXIT_OK = 0;

    public const EXIT_FAILURE = 1;

    public const EXIT_USAGE = 2;

    /**
     * Every candy-top release tag on the shared repo stream starts with
     * this; the tag's remainder is the version {@see Version} can order.
     * The single home of the literal is {@see Version::TAG_PREFIX} — the
     * phar stamp and the release filter must strip the same string.
     */
    public const TAG_PREFIX = Version::TAG_PREFIX;

    /**
     * The release train carrying tagged, ordered, permanent versions — the
     * default for every stable install, and the only train {@see
     * selectRelease()} orders.
     */
    public const CHANNEL_STABLE = 'stable';

    /**
     * The moving-tag train built from every master push by CI, stamped into
     * the phar metadata as `dev-<shortsha>`. Default for dev-built archives
     * only; never consulted by ordering.
     */
    public const CHANNEL_DEV = 'dev';

    /**
     * The dev train's one and only tag. Matched EXACTLY by {@see
     * selectDevRelease()} — unlike stable tags, whose `candy-top/` prefix
     * carries the version itself, `candy-top/dev` is the whole name, so a
     * startswith filter would silently invent a grammar.
     */
    public const DEV_TAG = 'candy-top/dev';

    /**
     * The prefix a dev version stamp must carry to be dev ({@see
     * Version::isDev()}) — and the exact number of characters to cut off the
     * front before what remains can be matched against the dev train's
     * commit. One spelling, two readers.
     */
    private const DEV_PREFIX = 'dev-';

    /**
     * What a dev stamp's suffix, or the dev release's `target_commitish`,
     * must look like to be LOCATABLE: a git hex commit id of the shortest
     * unambiguous length GitHub can hand back (7) up to a full sha (40).
     * Same grammar the `--version` checkout probe in Help.php uses.
     */
    private const LOCATABLE_COMMIT = '/^[0-9a-f]{7,40}$/';

    public const PHAR_ASSET = 'candy-top.phar';

    public const SIDECAR_ASSET = 'candy-top.phar.sha256';

    /**
     * The detached-signature asset ({@see verifyDetached} consumes it).
     * Base64 of a 64-byte Ed25519 signature over the phar bytes, the
     * minisign public-key flavour of "sign the artifact" without shipping a
     * parser for minisign's key-id framing — sodium is the smallest path in
     * the standard library that verifies a detached signature.
     */
    public const SIGNATURE_ASSET = 'candy-top.phar.minisig';

    /**
     * PINNED AT BIRTH (2026-10-21): this is the public half of the
     * sugarcraft-wide release-signing Ed25519 keypair — the same key that
     * signs candy-code's phars. The secret half lives as the repository
     * secret SUGARCRAFT_SIGNING_KEY, which the release workflows' signing
     * steps read to emit the minisig asset. One owner keypair signs the
     * phars of candy-code, candy-top, and future app releases; each app
     * still verifies against its own pinned copy of THIS public half.
     * Base64 of the raw 32-byte sodium public key — exactly what
     * {@see verifyDetached()} parses.
     *
     * To ROTATE the keypair the owner:
     *
     *   1. `php -r '$k=sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k)),"\n",base64_encode(sodium_crypto_sign_publickey($k)),"\n";'`
     *   2. put the first line in the `SUGARCRAFT_SIGNING_KEY` repository
     *      secret (every release workflow's signing step reads it);
     *   3. put the second line here — and in candy-code's copy — as the
     *      constant's value.
     *
     * Secret and constant are both-or-neither: a populated constant with no
     * secret means every release lacks the asset and self-update refuses
     * them all (loudly, by design). A release built while the secret was
     * momentarily absent carries no signature asset and is unupdatable from
     * a pinned build — re-tag or use `--allow-unsigned` by hand during the
     * transition.
     */
    public const PUBLIC_KEY_BASE64 = 'izi+7OlG5PTqGBpv03s2jnc5vRdxnChdBZIonN67T6o=';

    /**
     * The release list of the repository that publishes this tool's phar.
     */
    public const RELEASES_URL = 'https://api.github.com/repos/detain/sugarcraft/releases?per_page=100';

    /**
     * The command a Composer-installed candy-top must run INSTEAD of this
     * verb — printed verbatim in the refusal, which is the point of
     * refusing loudly.
     */
    public const COMPOSER_COMMAND = 'composer update sugarcraft/candy-top';

    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    private const READ_TIMEOUT_SECONDS = 60.0;

    /** The three flags this verb knows; anything else is a usage error. */
    private const KNOWN_FLAGS = ['--check', '--rollback', '--allow-unsigned'];

    /**
     * Test-only stand-in for STDERR so the suite can read the report lines;
     * null means the real stream. Swapped via {@see useErrorStreamForTesting}.
     */
    private static mixed $errorStream = null;

    /**
     * Routes this verb's stderr lines to $stream (pass null to hand the
     * stream back). The suite reads the refusal/mismatch wording this way
     * without spawning a child process.
     */
    public static function useErrorStreamForTesting(mixed $stream): void
    {
        self::$errorStream = $stream;
    }

    private function __construct()
    {
    }

    /**
     * @param list<string> $options the argv slice after the `self-update` verb
     */
    public static function run(
        array $options = [],
        ?ClientInterface $http = null,
        ?string $releasesUrl = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?string $publicKey = null,
    ): int {
        // Gate ORDER mirrors candy-code exactly: flag exclusivity is judged
        // before the operand refusal, so `--check --rollback junk` reports
        // the exclusivity, not the junk. A bad --channel value is a usage
        // error too — judged next, before an operand that may sit beside it.
        $check = false;
        $rollback = false;
        $allowUnsigned = false;
        $channel = null;
        $invalidChannel = null;
        $unexpectedOperand = null;
        foreach ($options as $token) {
            if ($token === '--check') {
                $check = true;
            } elseif ($token === '--rollback') {
                $rollback = true;
            } elseif ($token === '--allow-unsigned') {
                $allowUnsigned = true;
            } elseif (\str_starts_with($token, '--channel=')) {
                // The `=`-form only; a bare `--channel` answers as the
                // operand it is. Last valid value wins, first bad value is
                // the one reported — a train is a choice, not a position.
                $value = \substr($token, \strlen('--channel='));
                if ($value === self::CHANNEL_STABLE || $value === self::CHANNEL_DEV) {
                    $channel = $value;
                } elseif ($invalidChannel === null) {
                    $invalidChannel = $value;
                }
            } elseif ($unexpectedOperand === null) {
                $unexpectedOperand = $token;
            }
        }
        if ($check && ($rollback || $allowUnsigned)) {
            return self::failUsage(
                'candy-top: ' . Lang::t('cli.selfupdate.flags_exclusive'),
            );
        }
        if ($invalidChannel !== null) {
            return self::failUsage(
                'candy-top: ' . Lang::t('cli.selfupdate.channel_invalid', ['value' => $invalidChannel]),
            );
        }
        if ($unexpectedOperand !== null) {
            return self::failUsage(
                'candy-top: ' . Lang::t('cli.selfupdate.unexpected_operand', ['operand' => $unexpectedOperand]),
            );
        }

        // null => ask the runtime what phar (if any) we are inside. A test
        // passes the stub it wants swapped; production never does.
        $phar = $pharPath ?? \Phar::running(false);
        if ($phar === '') {
            self::stderr(self::composerRefusal());

            return self::EXIT_FAILURE;
        }

        // Spec (5): name the write problem before anything is downloaded —
        // a refusal after the fetch would waste the bytes it cannot install.
        $dir = \dirname($phar);
        if (!\is_dir($dir) || !\is_writable($dir)) {
            self::stderr(Lang::t('cli.selfupdate.dir_not_writable', ['dir' => $dir]));

            return self::EXIT_FAILURE;
        }
        if (!\is_file($phar) || !\is_writable($phar)) {
            self::stderr(Lang::t('cli.selfupdate.phar_not_writable', ['path' => $phar]));

            return self::EXIT_FAILURE;
        }

        $old = $phar . '.old';
        if ($rollback) {
            return self::rollback($old, $phar);
        }

        $http ??= self::httpClient();
        try {
            $response = $http->request('GET', $releasesUrl ?? self::RELEASES_URL);
        } catch (TransferException $e) {
            self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => $e->getMessage()]));

            return self::EXIT_FAILURE;
        }
        if ($response->getStatusCode() >= 400) {
            self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => 'HTTP ' . $response->getStatusCode()]));

            return self::EXIT_FAILURE;
        }
        $decoded = \json_decode((string) $response->getBody()->getContents(), true);

        // The channel is decided by WHAT THIS BUILD IS, so the current
        // version is resolved BEFORE selection: asking the stable train
        // "what is new" from a dev build answers "downgrade to the last
        // tag" every single time. The injected $currentVersion is the
        // suite's window; production reads the build manifest through
        // Help::versionString() (DST-1) — which is exactly where the dev
        // stamp lives, and this is the line that turns that stamp into a
        // channel choice.
        $current = $currentVersion ?? \trim(Help::versionString());
        $channel ??= Version::isDev($current) ? self::CHANNEL_DEV : self::CHANNEL_STABLE;
        $release = \is_array($decoded)
            ? ($channel === self::CHANNEL_DEV ? self::selectDevRelease($decoded) : self::selectRelease($decoded))
            : null;
        if ($release === null) {
            self::stderr(Lang::t('cli.selfupdate.no_release', ['phar' => self::PHAR_ASSET, 'sidecar' => self::SIDECAR_ASSET]));

            return self::EXIT_FAILURE;
        }

        if ($channel === self::CHANNEL_DEV) {
            // The dev train answers "is this build AT the position the
            // moving tag points to", never "which is newer" — dev stamps
            // have no ordering (the Version law: located, not ordered).
            $stamp = \substr(\strtolower(\trim($current)), \strlen(self::DEV_PREFIX));
            if (!Version::isDev($current) || \preg_match(self::LOCATABLE_COMMIT, $stamp) !== 1) {
                // Also the shape of an explicit `--channel=dev` on a
                // stable build: this install has no position on the dev
                // train, and refusing beats quietly consulting the wrong
                // train instead.
                self::stderr(Lang::t('cli.selfupdate.dev_channel_unlocatable', ['current' => $current]));

                return self::EXIT_FAILURE;
            }
            $target = $release['version'];
            if (\preg_match(self::LOCATABLE_COMMIT, $target) !== 1) {
                // A dev release that names no well-formed commit cannot
                // be located against — and located-against is the only
                // thing gating this swap. Fail closed on the release's
                // broken answer, not on our silence.
                self::stderr(Lang::t('cli.selfupdate.dev_target_unusable', [
                    'target' => $target === '' ? Lang::t('cli.selfupdate.dev_target_none') : $target,
                ]));

                return self::EXIT_FAILURE;
            }
            if (\str_starts_with($target, $stamp)) {
                self::stderr($check
                    ? Lang::t('cli.selfupdate.check_up_to_date', ['current' => $current, 'latest' => $target])
                    : Lang::t('cli.selfupdate.up_to_date', ['current' => $current]));

                return self::EXIT_OK;
            }
            if ($check) {
                self::stderr(Lang::t('cli.selfupdate.check_available', ['current' => $current, 'latest' => $target]));

                return self::EXIT_FAILURE;
            }
            // Off the train tip and not checking: fall through to the
            // shared swap legs. There is no downgrade concept here — the
            // moving tag IS the answer — and the hash, sidecar and
            // pinned-key gates are channel-agnostic by construction.
        } else {
            $cmp = Version::compare($release['version'], $current);

            if ($check) {
                if ($cmp === null) {
                    self::stderr(Lang::t('cli.selfupdate.check_unknown', ['current' => $current, 'latest' => $release['version']]));

                    return self::EXIT_FAILURE;
                }
                if ($cmp > 0) {
                    self::stderr(Lang::t('cli.selfupdate.check_available', ['current' => $current, 'latest' => $release['version']]));

                    return self::EXIT_FAILURE;
                }
                self::stderr(Lang::t('cli.selfupdate.check_up_to_date', ['current' => $current, 'latest' => $release['version']]));

                return self::EXIT_OK;
            }

            if ($cmp === null) {
                self::stderr(Lang::t('cli.selfupdate.uncomparable', ['current' => $current]));

                return self::EXIT_FAILURE;
            }
            if ($cmp === 0) {
                self::stderr(Lang::t('cli.selfupdate.up_to_date', ['current' => $current]));

                return self::EXIT_OK;
            }
            if ($cmp < 0) {
                // The sanctioned downgrade path is --rollback, which returned
                // above without consulting the network at all.
                self::stderr(Lang::t('cli.selfupdate.downgrade_refused', ['current' => $current, 'latest' => $release['version']]));

                return self::EXIT_FAILURE;
            }
        }

        // Sidecar first: a shape it cannot answer with stops the verb before
        // a single phar byte is transferred.
        try {
            $sidecarBody = (string) $http->request('GET', $release['sidecarUrl'])->getBody()->getContents();
        } catch (TransferException $e) {
            self::stderr(Lang::t('cli.selfupdate.download_failed', ['asset' => self::SIDECAR_ASSET, 'reason' => $e->getMessage()]));

            return self::EXIT_FAILURE;
        }
        $expected = self::parseSidecar($sidecarBody);
        if ($expected === null) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_malformed', ['asset' => self::SIDECAR_ASSET, 'phar' => self::PHAR_ASSET]));

            return self::EXIT_FAILURE;
        }
        $named = self::sidecarName($sidecarBody);
        if ($named !== '' && $named !== self::PHAR_ASSET) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_names', ['found' => $named, 'phar' => self::PHAR_ASSET]));

            return self::EXIT_FAILURE;
        }

        $temp = $phar . '.tmp-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        try {
            $http->request('GET', $release['pharUrl'], ['sink' => $temp]);
        } catch (TransferException $e) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.download_failed', ['asset' => self::PHAR_ASSET, 'reason' => $e->getMessage()]));

            return self::EXIT_FAILURE;
        }
        if (!\is_file($temp) || \filesize($temp) === 0) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.empty_download', ['asset' => self::PHAR_ASSET]));

            return self::EXIT_FAILURE;
        }

        // The one gate the swap cannot cross without: the bytes on disk must
        // BE the signed bytes. hash_equals, not ==, so the comparison does
        // not short-circuit on the first differing digit.
        $actual = \hash_file('sha256', $temp);
        if ($actual === false || !\hash_equals($expected, $actual)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.hash_mismatch', [
                'expected' => $expected,
                'actual' => $actual === false ? Lang::t('cli.selfupdate.hash_unreadable') : $actual,
            ]));

            return self::EXIT_FAILURE;
        }

        // Pinned-key leg, after the hash (the temp bytes are the real
        // artifact now) and before the copy (nothing to roll back from if
        // the key rejects). Empty key = the keyless state (reachable in
        // tests by injecting ''): the leg does not exist, and no
        // --allow-unsigned notice is printed for it.
        $key = $publicKey ?? self::PUBLIC_KEY_BASE64;
        if ($key !== '') {
            if ($allowUnsigned) {
                self::stderr(Lang::t('cli.selfupdate.unsigned_allowed'));
            } else {
                $refusal = self::verifySignature($http, $release, $temp, $key);
                if ($refusal !== null) {
                    self::forget($temp);
                    self::stderr($refusal);

                    return self::EXIT_FAILURE;
                }
            }
        }

        // Keep the running phar BEFORE exchanging it: without a preserved
        // copy there is nothing --rollback could restore, and a verb that
        // takes away its own undo is not one this verb wants to be.
        if (!\copy($phar, $old)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.preserve_failed', ['old' => $old]));

            return self::EXIT_FAILURE;
        }

        if (!\rename($temp, $phar)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

            return self::EXIT_FAILURE;
        }

        self::stderr(Lang::t('cli.selfupdate.installed', [
            'path' => $phar,
            'from' => $current,
            'to' => $release['version'],
            'old' => $old,
        ]));

        return self::EXIT_OK;
    }

    /**
     * The newest candy-top release in a decoded GitHub release list that
     * carries both required assets, or null when none does.
     *
     * Pure over the decoded JSON so the selection rules — prefix, draft and
     * prerelease skipping, the two-asset requirement, numeric ordering — are
     * pinned directly, independently of any transport. The signature URL is
     * REPORTED (`''` when absent) but never REQUIRED here: keyless releases
     * stay selectable in keyless state, and requiring the asset is the
     * pinned-key leg's decision at swap time.
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string}|null
     */
    public static function selectRelease(array $releases): ?array
    {
        $best = null;
        foreach ($releases as $release) {
            if (!\is_array($release) || ($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
                continue;
            }
            $tag = (string) ($release['tag_name'] ?? '');
            if (!\str_starts_with($tag, self::TAG_PREFIX)) {
                continue;
            }
            $version = \substr($tag, \strlen(self::TAG_PREFIX));
            if (!Version::isComparable($version)) {
                continue;
            }
            $pharUrl = null;
            $sidecarUrl = null;
            $sigUrl = '';
            $assets = $release['assets'] ?? [];
            if (!\is_array($assets)) {
                continue;
            }
            foreach ($assets as $asset) {
                if (!\is_array($asset)) {
                    continue;
                }
                $url = (string) ($asset['browser_download_url'] ?? '');
                if ($url === '') {
                    continue;
                }
                if ((string) ($asset['name'] ?? '') === self::PHAR_ASSET) {
                    $pharUrl = $url;
                } elseif ((string) ($asset['name'] ?? '') === self::SIDECAR_ASSET) {
                    $sidecarUrl = $url;
                } elseif ((string) ($asset['name'] ?? '') === self::SIGNATURE_ASSET) {
                    $sigUrl = $url;
                }
            }
            if ($pharUrl === null || $sidecarUrl === null) {
                continue;
            }
            if ($best !== null && (Version::compare($version, $best['version']) ?? -1) <= 0) {
                continue;
            }
            $best = ['version' => $version, 'pharUrl' => $pharUrl, 'sidecarUrl' => $sidecarUrl, 'sigUrl' => $sigUrl];
        }

        return $best;
    }

    /**
     * The dev train's release in a decoded GitHub release list, or null when
     * it carries none with both required assets.
     *
     * The dev train is ONE moving release tagged exactly {@see self::DEV_TAG}
     * — a full-string equality, not the prefix filter the stable stream uses,
     * because on this train the tag is a NAME, not a version in disguise.
     * A PRERELEASE flag is ALLOWED here (the moving tag is conventionally
     * marked prerelease precisely so stable consumers' "latest" routes skip
     * it — that flag is part of the train's design, not a doubt about it);
     * a DRAFT never is (drafts are unpublished scaffolding). Required and
     * optional assets are collected under the same names, by the same rules,
     * as {@see self::selectRelease()}.
     *
     * GitHub answers the list newest-created first, so the first matching
     * entry is the train head. There is no ordering among dev tags — the
     * payload this selector reports as `version` is the release's
     * `target_commitish` lowercased, the commit the archive was built from,
     * which the caller LOCATES the current stamp against.
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string}|null
     */
    public static function selectDevRelease(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (!\is_array($release) || ($release['draft'] ?? false) === true) {
                continue;
            }
            if ((string) ($release['tag_name'] ?? '') !== self::DEV_TAG) {
                continue;
            }
            $pharUrl = null;
            $sidecarUrl = null;
            $sigUrl = '';
            $assets = $release['assets'] ?? [];
            if (!\is_array($assets)) {
                continue;
            }
            foreach ($assets as $asset) {
                if (!\is_array($asset)) {
                    continue;
                }
                $url = (string) ($asset['browser_download_url'] ?? '');
                if ($url === '') {
                    continue;
                }
                if ((string) ($asset['name'] ?? '') === self::PHAR_ASSET) {
                    $pharUrl = $url;
                } elseif ((string) ($asset['name'] ?? '') === self::SIDECAR_ASSET) {
                    $sidecarUrl = $url;
                } elseif ((string) ($asset['name'] ?? '') === self::SIGNATURE_ASSET) {
                    $sigUrl = $url;
                }
            }
            if ($pharUrl === null || $sidecarUrl === null) {
                continue;
            }

            return [
                'version' => \strtolower(\trim((string) ($release['target_commitish'] ?? ''))),
                'pharUrl' => $pharUrl,
                'sidecarUrl' => $sidecarUrl,
                'sigUrl' => $sigUrl,
            ];
        }

        return null;
    }

    /**
     * The lowercase 64-hex-digit digest a sidecar signs with, or null
     * when it is not that shape. Fails closed on uppercase hex, on a short
     * digest, and on anything the sha256sum spelling does not produce.
     */
    public static function parseSidecar(string $body): ?string
    {
        if (\preg_match('/^([0-9a-f]{64})(?:\s+\S+)?\s*$/', \trim($body), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * The file name a sidecar declares after its digest ('' when it names
     * nothing). The digest alone would verify bytes of UNKNOWN provenance;
     * the name is what ties it to the phar asset specifically.
     */
    public static function sidecarName(string $body): string
    {
        if (\preg_match('/^[0-9a-f]{64}\s+(\S+)/', \trim($body), $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /**
     * Verify the temp phar's detached signature against the pinned key.
     * Returns the refusal text (never silent) or null when the bytes are
     * signed by the key. A release that carries NO signature asset is a
     * refusal in pinned state — a key that only vets artifacts which happen
     * to ship signatures is a key an attacker can route around by deleting
     * one asset.
     */
    private static function verifySignature(ClientInterface $http, array $release, string $temp, string $key): ?string
    {
        if ($release['sigUrl'] === '') {
            return Lang::t('cli.selfupdate.signature_missing', ['asset' => self::SIGNATURE_ASSET, 'phar' => self::PHAR_ASSET]);
        }
        try {
            $signatureBody = (string) $http->request('GET', $release['sigUrl'])->getBody()->getContents();
        } catch (TransferException $e) {
            return Lang::t('cli.selfupdate.download_failed', ['asset' => self::SIGNATURE_ASSET, 'reason' => $e->getMessage()]);
        }
        $payload = \file_get_contents($temp);
        if ($payload === false || !self::verifyDetached($payload, $signatureBody, $key)) {
            return Lang::t('cli.selfupdate.signature_invalid', ['asset' => self::SIGNATURE_ASSET, 'phar' => self::PHAR_ASSET]);
        }

        return null;
    }

    /**
     * Pure Ed25519 detached verification: is $signatureBody the base64 of a
     * signature by $publicKeyBase64 over exactly $payload? Every malformed
     * shape — empty payload, non-strict base64, wrong-length raw halves, a
     * missing sodium extension — answers FALSE (refuse), never an exception
     * and never a trust. Surrounding whitespace is tolerated in both base64
     * slots because release-asset bodies carry trailing newlines.
     */
    public static function verifyDetached(string $payload, string $signatureBody, string $publicKeyBase64): bool
    {
        // Guard FIRST: the SODIUM_CRYPTO_SIGN_* constants below only exist
        // when the extension does, and an extension-less PHP must answer
        // "refuse" rather than fatal on a constant lookup.
        if ($payload === '' || !\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $signature = \base64_decode(\trim($signatureBody), true);
        $publicKey = \base64_decode(\trim($publicKeyBase64), true);
        if (
            $signature === false
            || $publicKey === false
            || \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || \strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return false;
        }

        try {
            return \sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The loud composer-mode refusal, verbatim: what to run instead, stated
     * as the exact command. Public and pure so the refusal text is pinned
     * without needing a non-phar "phar" to point the swap machinery at.
     */
    public static function composerRefusal(): string
    {
        return Lang::t('cli.selfupdate.composer_mode', ['command' => self::COMPOSER_COMMAND]);
    }

    /**
     * The shape candy-code's NonInteractive::failUsage has there — message
     * line, then the usage line, both to stderr — carried here because
     * candy-top has no one-shot JSON document envelope to emit alongside.
     */
    private static function failUsage(string $message): int
    {
        self::stderrRaw($message . "\n" . Lang::t('cli.selfupdate.usage') . "\n");

        return self::EXIT_USAGE;
    }

    private static function rollback(string $old, string $phar): int
    {
        if (!\is_file($old)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_missing', ['old' => $old]));

            return self::EXIT_FAILURE;
        }
        if (!\rename($old, $phar)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

            return self::EXIT_FAILURE;
        }
        self::stderr(Lang::t('cli.selfupdate.rollback_restored', ['old' => $old, 'path' => $phar]));

        return self::EXIT_OK;
    }

    private static function httpClient(): ClientInterface
    {
        return new Client([
            // E646: ceilings on the two blocking operations, not a clock on
            // the whole request — see the class docblock.
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_timeout' => self::READ_TIMEOUT_SECONDS,
            // The suite's client asserts its own answers (status >= 400 is
            // checked by hand) instead of running on Guzzle's exception
            // magic, so the failure text is ours in both paths.
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                // The GitHub API rejects a request with no user agent.
                'User-Agent' => 'candy-top-self-update',
            ],
        ]);
    }

    private static function forget(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    private static function stderr(string $text): void
    {
        if ($text !== '') {
            self::stderrRaw($text . "\n");
        }
    }

    private static function stderrRaw(string $text): void
    {
        \fwrite(self::$errorStream ?? \STDERR, $text);
    }
}
