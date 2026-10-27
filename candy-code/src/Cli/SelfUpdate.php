<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\Version;

/**
 * `candy-code self-update` — replace the running phar with the latest
 * published one, verified before the swap, with the pre-swap copy kept for a
 * one-shot rollback (plan_comparison DST-3).
 *
 *     self-update [--check] [--rollback] [--allow-unsigned] [--channel=stable|dev]
 *
 * PHAR-MODE ONLY. The verb acts on the file `bin/candy-code` was launched
 * from: `\Phar::running(false)`. On a Composer install that answer is the
 * empty string, and this command REFUSES LOUDLY — prints the update command
 * that fits the install shape and exits 1: inside a consuming project's
 * vendor tree that is the exact `composer update sugarcraft/candy-code`;
 * inside a clone where candy-code IS the Composer root package it is
 * `git pull && composer install` instead, because a root package cannot
 * pull an update to itself through `composer update <its own name>` — that
 * command updates the package's dependencies, not the package. A silent
 * no-op on the Composer path is the banned failure mode: it would report
 * success while changing nothing.
 *
 * WHAT "LATEST" MEANS HERE. The release trains out of detain/sugarcraft are
 * per-library tags (`candy-code/v0.1.0`, `sugar-core/v3.2.0`, …) sharing one
 * GitHub release stream, so the repo-wide `/releases/latest` answer is
 * usually some other library's tag. The lookup walks the release list
 * forward — the first page is `?per_page=100`, and a `Link: rel="next"` hop
 * is followed up to five pages deep — filtered to non-draft, non-prerelease
 * releases whose tag starts with {@see Version::TAG_PREFIX}, that carry BOTH
 * required assets (`candy-code.phar` and `candy-code.phar.sha256`), and
 * highest by {@see Version::compare()} — the newest CANDY-CODE release, not
 * the newest release. The signature assets (`candy-code.phar.sha256.sig`, or
 * the legacy `candy-code.phar.minisig`) are OPTIONAL at selection: whether
 * one is REQUIRED is a fact about the
 * pinned-key state, not about the release, and the two must not be
 * conflated.
 *
 * TWO CHANNELS. The same fetch answers on two release trains. STABLE (what
 * the verb always did): non-draft, non-prerelease `candy-code/v*` tags,
 * highest by compare — byte-for-byte the selection described above. DEV:
 * the single rolling `candy-code/dev` release the CI dev workflow moves
 * (prerelease ALLOWED — it is one; draft still skipped), selected by exact
 * tag equality and judged by COMMIT, not by ordering: it is current iff
 * the `target_commitish` it names starts with the sha embedded in this
 * install's `dev-<shortsha>` stamp. A target that is missing or not a git
 * object name (a branch label like `main`) cannot answer that question and
 * is refused fail-closed, exactly like the stable channel's unorderable
 * version; the downgrade arm cannot fire on dev by construction (rolling
 * channel: the decision is up-to-date or available, never behind), and the
 * asset, sha256 and minisign rules are identical between channels — the
 * signature stays required iff a key is pinned. The DEFAULT channel is read
 * from the current version ({@see Version::isDev}: dev stamps ride dev,
 * everything else rides stable); `--channel` overrides either way and
 * composes with `--check`.
 *
 * HOW THE SWAP HAPPENS. The sidecar is fetched first and must parse to a
 * lowercase 64-hex-digit digest (its optional name field must match the phar
 * asset it signs). The phar is then downloaded to a temp file IN THE PHAR'S
 * OWN DIRECTORY (a rename only exchanges atoms inside one filesystem), its
 * sha256 is computed over the finished file and compared with
 * `hash_equals()`. If a signing key is pinned (see TRUST MODEL below) the
 * detached signature leg runs next: on the stream-safe path it signs the
 * sidecar body, so the phar bytes never enter memory; only the legacy
 * one-cycle fallback signs those bytes directly, and that leg raises the
 * memory limit for its own duration. Every
 * download leg — list, sidecar, phar, signature — judges HTTP status: with
 * the client's `http_errors` off, a 404 arrives as a plain response, and a
 * leg that read only the body would install GitHub's JSON error document as
 * the artifact. Only then is the running phar copied to `<phar>.old` and the
 * temp renamed over it; the live file's permission mode, captured before
 * anything is written, is carried onto both the temp (rename would otherwise
 * publish the temp's umask mode) and the `.old` copy (copy() does not
 * preserve it). Every refusal before the rename deletes the temp and leaves
 * the running phar byte-identical; a mismatch that fires after the copy
 * still leaves `.old` as the exact prior bytes; and a fatal that dies
 * between minting the temp and renaming it leaves no stranded file behind —
 * a shutdown hook sweeps the path. `--rollback` renames `<phar>.old` back
 * over the phar — consuming it in the rename, so the restore is one-shot by
 * construction and touches no network — and re-asserts the captured mode,
 * because the rename carries `.old`'s mode onto the target and legacy
 * `.old` copies predate the preservation above.
 *
 * WINDOWS CAVEAT (DST-3). On NT, renaming over the image a live process is
 * executing from fails while that process runs — so on Windows the LAST leg
 * of the sequence above is the one that dies, and it dies LOUD: the temp is
 * deleted, the preserved `.old` copy and the untouched running phar remain
 * exactly as they were, `rename_failed` goes to stderr, and the verb exits
 * 1. There is no silent half-swap to recover from. Updating a Windows
 * install stays a manual file replace until someone rules a relaunch dance;
 * the fail-loud leg is the documented answer, not a defect to route around.
 *
 * TRUST MODEL, HONESTLY — TWO STATES. Both the phar and its sidecar come
 * from the same origin (GitHub release assets, over TLS). Keyless state (a
 * build whose {@see SelfUpdate::PUBLIC_KEY_BASE64} is the empty string —
 * every phar shipped before 2026-10-20): the hash bounds transport
 * corruption, asset mixups and tampering with the phar ALONE; a party who
 * controls the release can replace the sidecar in the same breath. The
 * residual is named: trust in the GitHub account that owns the repo.
 * Pinned state (THE SHIPPED ONE since 2026-10-20 — the owner action in the
 * constant's docblock is done): a release whose signature assets are ABSENT,
 * or do not verify against the pinned Ed25519 key, is REFUSED
 * LOUDLY — the sha256 sidecar alone no longer gates the swap, and an
 * attacker who owns the release stream can no longer sign their own poisoned
 * bytes. The pinned key can be rotated by shipping a new phar; until that
 * first self-update, a wrong-key emergency needs the fresh-installer path
 * (download + checksum by hand) — the same window every pinned-key updater
 * carries. Releases tagged before the keypair existed carry no signature
 * asset and are therefore unupdatable from a pinned build: re-tag or use
 * `--allow-unsigned` by hand during the transition.
 *
 * EXITS. 0: up to date (--check or not), a verified swap completed, a
 * rollback restored. 1: composer-mode refusal, an unwritable target, a
 * refused downgrade, a comparison that cannot be made, a fetch, hash or
 * signature failure, --check finding an update available (that is the
 * scripted answer to "should I update?"). 2: usage errors (unknown
 * operand, a `--channel` that is neither `stable` nor `dev`, `--check`
 * together with `--rollback` or `--allow-unsigned`).
 * `--allow-unsigned` is the dev-only bypass of the signature leg in pinned
 * state; it never bypasses the sha256 sidecar and is refused next to
 * `--check` (there is nothing to bypass when nothing is swapped).
 *
 * TIME BOUNDS follow the E646 rule: a connect ceiling, a per-read ceiling,
 * and a low-speed stall pair, deliberately no total request timeout — a
 * 100 MB phar download on a slow link is legitimate work that a blanket
 * clock would abort. On the curl transport the low-speed pair is what
 * actually kills a stalled transfer (abort once the link moves under a
 * kilobyte per second for a minute); `read_timeout` alone does NOT — a peer
 * that trickles a byte now and then keeps resetting it forever. On a
 * StreamHandler runtime (no ext-curl) the pair does not exist, and
 * `read_timeout` stays the ceiling in force there.
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
    /**
     * Every candy-code release tag on the shared repo stream starts with
     * this; the tag's remainder is the version {@see Version} can order.
     * The single home of the literal is {@see Version::TAG_PREFIX} — the
     * phar stamp and the release filter must strip the same string.
     */
    public const TAG_PREFIX = Version::TAG_PREFIX;

    /** The stable train: tagged `candy-code/v*` releases, ordered by {@see Version::compare()}. */
    public const CHANNEL_STABLE = 'stable';

    /** The rolling train: the one `candy-code/dev` release, judged by commit identity. */
    public const CHANNEL_DEV = 'dev';

    /**
     * The exact tag the dev release is moved under. Equality, not the
     * TAG_PREFIX family test the stable channel uses: 'dev' is a channel
     * name, never a version {@see Version} could order, so the strip-and-
     * compare-triple rule has nothing to say about it either way.
     */
    public const DEV_TAG = self::TAG_PREFIX . 'dev';

    public const PHAR_ASSET = 'candy-code.phar';

    public const SIDECAR_ASSET = 'candy-code.phar.sha256';

    /**
     * The LEGACY detached-signature asset ({@see verifyDetached} consumes
     * it). Base64 of a 64-byte Ed25519 signature over the RAW PHAR BYTES —
     * which forces the verifying leg to hold the whole artifact in memory,
     * fatal under a small memory_limit for a ~119 MB phar. Accepted for one
     * further release cycle through the raise-limit fallback in
     * {@see verifySignature()}; {@see SIDECAR_SIGNATURE_ASSET} replaces it.
     * Both file names borrow minisign's vocabulary, but neither carries
     * minisign's key-id framing: these are raw sodium signatures in base64,
     * and sodium is the smallest path in the standard library that verifies
     * a detached signature.
     */
    public const SIGNATURE_ASSET = 'candy-code.phar.minisig';

    /**
     * The stream-safe detached-signature asset: base64 of a 64-byte Ed25519
     * signature over the BODY OF THE SIDECAR ({@see SIDECAR_ASSET}'s text),
     * not over the phar bytes. Verifying it costs one small fetch and one
     * {@see verifyDetached} over a few dozen bytes — the phar itself never
     * enters memory; the temp file's digest was already established by the
     * streaming {@see \hash_file()} gate, and a signature over the sidecar
     * transitively signs those bytes. Same pinned key, same raw-sodium
     * base64 shape as the legacy asset.
     */
    public const SIDECAR_SIGNATURE_ASSET = 'candy-code.phar.sha256.sig';

    /**
     * PINNED 2026-10-20 (the owner action below is complete): this is the
     * public half of the release-signing Ed25519 keypair; the secret half
     * lives as the repository secret SUGARCRAFT_SIGNING_KEY, which the
     * release workflows' signing steps read to emit the signature assets —
     * the stream-safe {@see SIDECAR_SIGNATURE_ASSET} and, through one further
     * release cycle, the legacy {@see SIGNATURE_ASSET}. The
     * secret is sugarcraft-wide by design — one owner keypair signs the
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
     *   3. put the second line here, as this constant's value.
     *
     * Secret and constant are both-or-neither: a populated constant with no
     * secret means every release lacks the asset and self-update refuses
     * them all (loudly, by design). Until tagged releases carry the asset,
     * enabling the key makes older releases unupdatable — re-tag or use
     * `--allow-unsigned` by hand during the transition.
     */
    public const PUBLIC_KEY_BASE64 = 'izi+7OlG5PTqGBpv03s2jnc5vRdxnChdBZIonN67T6o=';

    /**
     * The release list of the repository that publishes this tool's phar.
     */
    public const RELEASES_URL = 'https://api.github.com/repos/detain/sugarcraft/releases?per_page=100';

    /**
     * The command a Composer-installed candy-code must run INSTEAD of this
     * verb — printed verbatim in the refusal, which is the point of
     * refusing loudly.
     */
    public const COMPOSER_COMMAND = 'composer update sugarcraft/candy-code';

    /**
     * The OTHER honest update command, for the install shape where candy-code
     * IS the Composer root package (a clone, or the repo checked out and
     * installed in place): `composer update sugarcraft/candy-code` refreshes
     * that package's DEPENDENCIES, never the package itself, so there the
     * answer is to pull the clone and rebuild the vendor tree.
     */
    public const PULL_AND_INSTALL_COMMAND = 'git pull && composer install';

    /**
     * The Composer root package name that triggers
     * {@see PULL_AND_INSTALL_COMMAND}. Help and Version carry the same
     * literal in their own families; this class owns its copy because the
     * refusal wording is decided here.
     */
    private const PACKAGE = 'sugarcraft/candy-code';

    /**
     * DST-3-P3: the curl transport's stall killer — abort when the transfer
     * has moved fewer than this many bytes per second for
     * {@see LOW_SPEED_STALL_SECONDS} seconds. A peer that completes the TLS
     * handshake and then dies no longer hangs the verb forever, while a
     * slow-but-live link stays legitimate work (E646: the total-request
     * clock is still deliberately absent — these two bound SLOTHOOD, not
     * length).
     */
    private const LOW_SPEED_LIMIT_BYTES_PER_SECOND = 1024;

    private const LOW_SPEED_STALL_SECONDS = 60;

    /**
     * DST-3-P5: how many release-list pages the selection walk may follow
     * via `Link: rel="next"`. Five pages of one hundred is far past this
     * repo's release count; the cap exists so a broken or hostile
     * pagination loop can never fetch forever.
     */
    private const RELEASE_PAGE_LIMIT = 5;

    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    private const READ_TIMEOUT_SECONDS = 60.0;

    /**
     * The legacy raw-bytes signature leg must hold the whole phar in memory
     * to verify it; this is the slack added over the file's own size when
     * the leg needs its memory_limit raised for the duration (the current
     * runtime, allocator overhead, and the signature/key copies all live
     * inside it). {@see SIDECAR_SIGNATURE_ASSET} removes the need entirely;
     * the constant rides out the legacy asset's last release cycle.
     */
    private const LEGACY_SIG_HEADROOM_BYTES = 67108864;

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

    public static function run(
        ParsedArgs $args,
        ?ClientInterface $http = null,
        ?string $releasesUrl = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?string $publicKey = null,
    ): int {
        $check = isset($args->subcommandFlags['--check']);
        $rollback = isset($args->subcommandFlags['--rollback']);
        $allowUnsigned = isset($args->subcommandFlags['--allow-unsigned']);
        if ($check && ($rollback || $allowUnsigned)) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.selfupdate.flags_exclusive'),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }
        $channelArg = isset($args->subcommandFlags['--channel'])
            ? (string) $args->subcommandFlags['--channel']
            : null;
        if ($channelArg !== null && $channelArg !== self::CHANNEL_STABLE && $channelArg !== self::CHANNEL_DEV) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.selfupdate.channel_invalid', ['value' => $channelArg]),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }
        if ($args->subcommandArgs !== []) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.selfupdate.unexpected_operand', ['operand' => (string) $args->subcommandArgs[0]]),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }

        // null => ask the runtime what phar (if any) we are inside. A test
        // passes the stub it wants swapped; production never does.
        $phar = $pharPath ?? \Phar::running(false);
        if ($phar === '') {
            self::stderr(self::composerRefusal());

            return NonInteractive::EXIT_FAILURE;
        }

        // Spec (5): name the write problem before anything is downloaded —
        // a refusal after the fetch would waste the bytes it cannot install.
        $dir = \dirname($phar);
        if (!\is_dir($dir) || !\is_writable($dir)) {
            self::stderr(Lang::t('cli.selfupdate.dir_not_writable', ['dir' => $dir]));

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\is_file($phar) || !\is_writable($phar)) {
            self::stderr(Lang::t('cli.selfupdate.phar_not_writable', ['path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }

        // DST-3-P2: the live file's mode, captured before anything is
        // written. rename() publishes the TEMP's mode onto the target and
        // copy() gives `.old` the umask's mode, so without pinning the
        // original to both, one self-update could turn a 0700 install into
        // 0755 — or a 0755 phar into something the shell refuses to exec.
        // false (raced unlink between the gate above and this call) reads
        // as "no mode to preserve", never as zero.
        $perms = \fileperms($phar);
        $pharMode = $perms === false ? null : $perms & 0777;

        $old = $phar . '.old';
        if ($rollback) {
            return self::rollback($old, $phar, $pharMode);
        }

        // Resolution moved ahead of the fetch (choosing a channel needs the
        // current version): same seam, same arms, identical value.
        $current = $currentVersion ?? \trim(Help::versionString());
        $channel = $channelArg ?? (Version::isDev($current) ? self::CHANNEL_DEV : self::CHANNEL_STABLE);

        $http ??= self::httpClient();
        // DST-3-P5: one page is not the whole train. GitHub paginates the
        // release list; the candy-code tag the user wants can ride any page.
        // Follow `Link: rel="next"` hops up to RELEASE_PAGE_LIMIT pages and
        // select over the accumulated list. A page whose body is not a JSON
        // array contributes nothing (the old shape refused a non-array
        // single body outright — the accumulation answers the same way via
        // the selection below finding no release in an empty list).
        $releases = [];
        $pageUrl = $releasesUrl ?? self::RELEASES_URL;
        for ($page = 1; $page <= self::RELEASE_PAGE_LIMIT; $page++) {
            try {
                $response = $http->request('GET', $pageUrl);
            } catch (TransferException $e) {
                self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => $e->getMessage()]));

                return NonInteractive::EXIT_FAILURE;
            }
            if ($response->getStatusCode() >= 400) {
                self::stderr(Lang::t('cli.selfupdate.fetch_failed', ['reason' => 'HTTP ' . $response->getStatusCode()]));

                return NonInteractive::EXIT_FAILURE;
            }
            $decoded = \json_decode((string) $response->getBody()->getContents(), true);
            if (\is_array($decoded)) {
                $releases = \array_merge($releases, $decoded);
            }
            $pageUrl = $page < self::RELEASE_PAGE_LIMIT
                ? self::nextPageUrl($response->getHeaderLine('Link'))
                : null;
            if ($pageUrl === null) {
                break;
            }
        }
        if ($channel === self::CHANNEL_DEV) {
            $release = self::selectDevRelease($releases);
            if ($release === null) {
                self::stderr(Lang::t('cli.selfupdate.no_dev_release', [
                    'tag' => self::DEV_TAG,
                    'phar' => self::PHAR_ASSET,
                    'sidecar' => self::SIDECAR_ASSET,
                ]));

                return NonInteractive::EXIT_FAILURE;
            }
            [$cmp, $latest] = self::devDecision($current, $release);
        } else {
            $release = self::selectRelease($releases);
            if ($release === null) {
                self::stderr(Lang::t('cli.selfupdate.no_release', ['phar' => self::PHAR_ASSET, 'sidecar' => self::SIDECAR_ASSET]));

                return NonInteractive::EXIT_FAILURE;
            }
            $cmp = Version::compare($release['version'], $current);
            $latest = $release['version'];
        }

        if ($check) {
            if ($cmp === null) {
                self::stderr(Lang::t('cli.selfupdate.check_unknown', ['current' => $current, 'latest' => $latest]));

                return NonInteractive::EXIT_FAILURE;
            }
            if ($cmp > 0) {
                self::stderr(Lang::t('cli.selfupdate.check_available', ['current' => $current, 'latest' => $latest]));

                return NonInteractive::EXIT_FAILURE;
            }
            self::stderr(Lang::t('cli.selfupdate.check_up_to_date', ['current' => $current, 'latest' => $latest]));

            return NonInteractive::EXIT_OK;
        }

        if ($cmp === null) {
            self::stderr(Lang::t('cli.selfupdate.uncomparable', ['current' => $current]));

            return NonInteractive::EXIT_FAILURE;
        }
        if ($cmp === 0) {
            self::stderr(Lang::t('cli.selfupdate.up_to_date', ['current' => $current]));

            return NonInteractive::EXIT_OK;
        }
        if ($cmp < 0) {
            // The sanctioned downgrade path is --rollback, which returned
            // above without consulting the network at all.
            // On the dev train the decision is never behind (up-to-date or
            // available, by construction), so this arm is stable-only — and
            // the stable logic is byte-for-byte the one that shipped.
            self::stderr(Lang::t('cli.selfupdate.downgrade_refused', ['current' => $current, 'latest' => $latest]));

            return NonInteractive::EXIT_FAILURE;
        }

        // Sidecar first: a shape it cannot answer with stops the verb before
        // a single phar byte is transferred.
        [$refusal, $sidecarBody] = self::fetchOk($http, $release['sidecarUrl'], self::SIDECAR_ASSET);
        if ($refusal !== null) {
            self::stderr($refusal);

            return NonInteractive::EXIT_FAILURE;
        }
        $expected = self::parseSidecar($sidecarBody);
        if ($expected === null) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_malformed', ['asset' => self::SIDECAR_ASSET, 'phar' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }
        $named = self::sidecarName($sidecarBody);
        if ($named !== '' && $named !== self::PHAR_ASSET) {
            self::stderr(Lang::t('cli.selfupdate.sidecar_names', ['found' => $named, 'phar' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
        }

        $temp = $phar . '.tmp-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        // DST-3-P1: every explicit refusal below unlinks the temp, but a
        // FATAL (or any exit path we did not write) used to leave it
        // stranded next to the live phar forever. The sweep hook runs on
        // shutdown regardless; after a successful rename the path is gone
        // and the hook is inert (forget() checks is_file first).
        \register_shutdown_function(static function () use ($temp): void {
            self::forget($temp);
        });
        [$refusal] = self::fetchOk($http, $release['pharUrl'], self::PHAR_ASSET, $temp);
        if ($refusal !== null) {
            self::stderr($refusal);

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\is_file($temp) || \filesize($temp) === 0) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.empty_download', ['asset' => self::PHAR_ASSET]));

            return NonInteractive::EXIT_FAILURE;
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

            return NonInteractive::EXIT_FAILURE;
        }

        // Pinned-key leg, after the hash (the temp bytes are the real
        // artifact now) and before the copy (nothing to roll back from if
        // the key rejects). Empty key = the keyless state (the pre-2026-10-20
        // shipped shape, reachable in tests by injecting ''): the leg
        // does not exist, and no --allow-unsigned notice is printed for it.
        $key = $publicKey ?? self::PUBLIC_KEY_BASE64;
        if ($key !== '') {
            if ($allowUnsigned) {
                self::stderr(Lang::t('cli.selfupdate.unsigned_allowed'));
            } else {
                $refusal = self::verifySignature($http, $release, $temp, $key, $sidecarBody);
                if ($refusal !== null) {
                    self::forget($temp);
                    self::stderr($refusal);

                    return NonInteractive::EXIT_FAILURE;
                }
            }
        }

        // Keep the running phar BEFORE exchanging it: without a preserved
        // copy there is nothing --rollback could restore, and a verb that
        // takes away its own undo is not one this verb wants to be.
        if (!\copy($phar, $old)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.preserve_failed', ['old' => $old]));

            return NonInteractive::EXIT_FAILURE;
        }

        // DST-3-P2: carry the captured mode onto both halves of the swap —
        // the temp BEFORE the rename (rename is what publishes its mode onto
        // the target) and the preserved `.old` after the copy (copy() takes
        // the umask, not the source mode). Best-effort with @: a filesystem
        // that refuses chmod must not fail a validated update, it just keeps
        // the mode it has.
        if ($pharMode !== null) {
            @\chmod($old, $pharMode);
            @\chmod($temp, $pharMode);
        }

        if (!\rename($temp, $phar)) {
            self::forget($temp);
            self::stderr(Lang::t('cli.selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }

        self::stderr(Lang::t('cli.selfupdate.installed', [
            'path' => $phar,
            'from' => $current,
            'to' => $latest,
            'old' => $old,
        ]));

        return NonInteractive::EXIT_OK;
    }

    /**
     * One GET for one artifact, judged the same way everywhere (DST-3-P4).
     * The client runs with `http_errors` off, so a 404 arrives as a plain
     * response — a leg that read only the body would happily take GitHub's
     * JSON error document as the sidecar, the phar bytes, or the signature.
     * Every download leg therefore runs through here: a TransferException or
     * a status of 400 or more is a `download_failed` refusal naming the
     * status, and a sink the transport half-filled is deleted before the
     * refusal is handed back, so the caller's error path never has to
     * remember it.
     *
     * @return array{0: ?string, 1: string} [refusal text (null on success), body (empty when a sink was used)]
     */
    private static function fetchOk(ClientInterface $http, string $url, string $asset, ?string $sink = null): array
    {
        $options = $sink === null ? [] : ['sink' => $sink];
        try {
            $response = $http->request('GET', $url, $options);
        } catch (TransferException $e) {
            if ($sink !== null) {
                self::forget($sink);
            }

            return [Lang::t('cli.selfupdate.download_failed', ['asset' => $asset, 'reason' => $e->getMessage()]), ''];
        }
        $status = $response->getStatusCode();
        if ($status >= 400) {
            if ($sink !== null) {
                self::forget($sink);
            }

            return [Lang::t('cli.selfupdate.download_failed', ['asset' => $asset, 'reason' => 'HTTP ' . $status]), ''];
        }

        return [null, $sink === null ? (string) $response->getBody()->getContents() : ''];
    }

    /**
     * The `next` hop of an RFC 8288 Link header, or null to end the walk.
     * GitHub pages the release list as `Link: <url>; rel="next", <url>;
     * rel="last"`; only the next relation is followed, and a header without
     * one (or a malformed one) is simply the end of the list.
     */
    private static function nextPageUrl(string $linkHeader): ?string
    {
        if ($linkHeader === '') {
            return null;
        }
        foreach (\explode(',', $linkHeader) as $part) {
            // '<target>; rel="next"' — params follow the first ';'. A
            // quoted rel needs the closing quote OR a word end to be
            // exactly 'next' (rel="next-page" is not a next hop).
            if (
                \preg_match('/<([^>]+)>;?\s*(.*)/', \trim($part), $m) === 1
                && \preg_match('/\brel\s*=\s*"?next(?![\w-])/', $m[2]) === 1
            ) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The newest candy-code release in a decoded GitHub release list that
     * carries both required assets, or null when none does.
     *
     * Pure over the decoded JSON so the selection rules — prefix, draft and
     * prerelease skipping, the two-asset requirement, numeric ordering — are
     * pinned directly, independently of any transport. The signature URLs are
     * REPORTED (`''` when absent) but never REQUIRED here: keyless releases
     * stay selectable in keyless state, and requiring the asset is the
     * pinned-key leg's decision at swap time.
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string}|null
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
            $urls = self::releaseAssetUrls($release['assets'] ?? []);
            if ($urls === null) {
                continue;
            }
            if ($best !== null && (Version::compare($version, $best['version']) ?? -1) <= 0) {
                continue;
            }
            $best = ['version' => $version, ...$urls];
        }

        return $best;
    }

    /**
     * The dev-channel selection: the rolling `candy-code/dev` release that
     * carries both required assets. Draft is skipped; prerelease is ALLOWED
     * (the dev release is one — the stable channel's exclusion of it is
     * exactly why the dev channel needs a sibling, not a widened filter).
     * The target commit rides along lower-cased ('' when the entry names
     * none) for {@see self::devDecision()} to judge: selection stays
     * decidable on assets alone, so a malformed target is the loud
     * cannot-compare refusal, never a silent no-release.
     *
     * GitHub makes tag names unique per repository, so at most one entry
     * can match; the first with both assets wins (an asset-less dev entry
     * is skipped, mirroring the stable channel's treatment).
     *
     * @param array<array-key, mixed> $releases
     *
     * @return array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string, commitish: string}|null
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
            $urls = self::releaseAssetUrls($release['assets'] ?? []);
            if ($urls === null) {
                continue;
            }
            $commitish = $release['target_commitish'] ?? '';

            return [
                'version' => 'dev',
                'commitish' => \is_string($commitish) ? \strtolower(\trim($commitish)) : '',
                ...$urls,
            ];
        }

        return null;
    }

    /**
     * Is the running dev build already the commit the dev release names?
     * [decision, label]: 0 up to date, 1 a newer commit is published, null
     * INDECIDABLE — the current version carries no commit to match (a
     * `dev-` stamp whose remainder is not a hex abbreviation, or a stable
     * install forced onto the dev channel by --channel), or the release
     * names a target that is missing, shorter than git's own abbreviation
     * floor, or not a hex object name at all (a branch label). Indecidable
     * is the fail-closed shape the stable channel spells with a null
     * compare: --check reports cannot-compare, a swap refuses.
     *
     * The match is the prefix law {@see Version::DEV_PREFIX} embeds: the
     * canonical stamp `dev-<shortsha>` — its commit read by
     * {@see Version::devShaOf()}, the hex after the 4-byte prefix (this is
     * the spec's 'sha embedded after dev-', with the offset counted off the
     * prefix's true length) — names the leading digits of the release's
     * full target_commitish. A mismatch is not "behind": the rolling
     * channel answers now or not-yet, never older.
     *
     * @param array{version: string, pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string, commitish: string} $release
     *
     * @return array{0: ?int, 1: string}
     */
    private static function devDecision(string $current, array $release): array
    {
        $target = $release['commitish'];
        $label = 'dev@' . ($target === '' ? '?' : $target);
        $embedded = Version::devShaOf($current);
        if ($embedded === null || $target === '' || \preg_match(Version::HEX_SHA_PATTERN, $target) !== 1) {
            return [null, $label];
        }

        return [\str_starts_with($target, $embedded) ? 0 : 1, $label];
    }

    /**
     * The asset quartet of one release entry: the two required download
     * routes plus the two signature routes ('' when absent — required only
     * by the pinned-key leg, never by selection; the sidecar signature
     * {@see SIDECAR_SIGNATURE_ASSET} is preferred, the raw-bytes
     * {@see SIGNATURE_ASSET} is its legacy fallback for one release cycle).
     * Null when either required
     * asset is missing or the entry is not shaped like an asset list. The
     * ONE walk both channels read (E174 discipline: a byte-copied second
     * copy of this loop is the drift guards' favorite snack).
     *
     * @param mixed $assets
     *
     * @return ?array{pharUrl: string, sidecarUrl: string, sigUrl: string, sigSidecarUrl: string}
     */
    private static function releaseAssetUrls(mixed $assets): ?array
    {
        if (!\is_array($assets)) {
            return null;
        }
        $pharUrl = null;
        $sidecarUrl = null;
        $sigUrl = '';
        $sigSidecarUrl = '';
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
            } elseif ((string) ($asset['name'] ?? '') === self::SIDECAR_SIGNATURE_ASSET) {
                $sigSidecarUrl = $url;
            }
        }
        if ($pharUrl === null || $sidecarUrl === null) {
            return null;
        }

        return ['pharUrl' => $pharUrl, 'sidecarUrl' => $sidecarUrl, 'sigUrl' => $sigUrl, 'sigSidecarUrl' => $sigSidecarUrl];
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
     * Verify the downloaded release's detached signature against the pinned
     * key. Returns the refusal text (never silent) or null when the bytes
     * are signed by the key. THREE legs, decided by what the release carries.
     *
     * STREAM-SAFE PATH (the preferred one): {@see SIDECAR_SIGNATURE_ASSET}
     * signs the sidecar BODY, so this leg fetches a few dozen bytes and
     * verifies them against the sidecar text already in hand. The sidecar's
     * digest was already pinned to the temp bytes by the streaming
     * {@see \hash_file()} gate above, so a key that signs the sidecar signs
     * those bytes transitively — and the phar itself never enters memory,
     * whatever the process' memory_limit says.
     *
     * LEGACY PATH (one further release cycle): {@see SIGNATURE_ASSET} signs
     * the raw phar bytes, which forces a whole-file read. That read used to
     * be the one fatal in the verb — a ~119 MB phar under the default 128 MB
     * limit died mid-verify with bytes already streamed and nothing swapped.
     * The leg now raises memory_limit to filesize plus headroom for its own
     * duration and restores it afterwards, and prints a deprecation notice
     * so the window is loud rather than silent. When a release carries BOTH
     * signature assets, the stream-safe leg is the one that runs; the legacy
     * asset is then dead weight, which is exactly how it ages out.
     *
     * NEITHER: a refusal in pinned state — a key that only vets artifacts
     * which happen to ship signatures is a key an attacker can route around
     * by deleting one asset.
     */
    private static function verifySignature(ClientInterface $http, array $release, string $temp, string $key, string $sidecarBody): ?string
    {
        if ($release['sigSidecarUrl'] !== '') {
            [$refusal, $signatureBody] = self::fetchOk($http, $release['sigSidecarUrl'], self::SIDECAR_SIGNATURE_ASSET);
            if ($refusal !== null) {
                return $refusal;
            }
            if (!self::verifyDetached($sidecarBody, $signatureBody, $key)) {
                return Lang::t('cli.selfupdate.sig.sidecar_invalid', [
                    'asset' => self::SIDECAR_SIGNATURE_ASSET,
                    'sidecar' => self::SIDECAR_ASSET,
                    'phar' => self::PHAR_ASSET,
                ]);
            }

            return null;
        }
        if ($release['sigUrl'] === '') {
            return Lang::t('cli.selfupdate.signature_missing', [
                'asset' => self::SIGNATURE_ASSET,
                'sig_sidecar' => self::SIDECAR_SIGNATURE_ASSET,
                'phar' => self::PHAR_ASSET,
            ]);
        }
        self::stderr(Lang::t('cli.selfupdate.sig.deprecated', [
            'asset' => self::SIGNATURE_ASSET,
            'replacement' => self::SIDECAR_SIGNATURE_ASSET,
        ]));
        [$refusal, $signatureBody] = self::fetchOk($http, $release['sigUrl'], self::SIGNATURE_ASSET);
        if ($refusal !== null) {
            return $refusal;
        }
        $restoreMemory = self::raiseMemoryForRawBytesVerify($temp);
        try {
            $payload = \file_get_contents($temp);
        } finally {
            $restoreMemory();
        }
        if ($payload === false || !self::verifyDetached($payload, $signatureBody, $key)) {
            return Lang::t('cli.selfupdate.signature_invalid', ['asset' => self::SIGNATURE_ASSET, 'phar' => self::PHAR_ASSET]);
        }

        return null;
    }

    /**
     * The legacy raw-bytes leg holds the whole phar in memory to verify it,
     * so a small memory_limit used to turn that leg into a fatal at the
     * worst possible moment of the swap. Raise JUST for that leg: filesize
     * plus {@see LEGACY_SIG_HEADROOM_BYTES}, and only when the current limit
     * is finite and below what the read needs. Returns a closure that puts
     * the old value back — the caller runs it in a finally, so the widening
     * never outlives one signature check. An unparseable, empty, or
     * unlimited current value answers with the no-op closure: the raise is
     * best-effort, the verification is not.
     */
    private static function raiseMemoryForRawBytesVerify(string $temp): \Closure
    {
        $size = \filesize($temp);
        $needed = ($size === false ? 0 : $size) + self::LEGACY_SIG_HEADROOM_BYTES;
        $previous = (string) \ini_get('memory_limit');
        $current = self::parseShorthandBytes($previous);
        if ($current <= 0 || $current >= $needed || \ini_set('memory_limit', (string) $needed) === false) {
            return static function (): void {};
        }

        return static function () use ($previous): void {
            \ini_set('memory_limit', $previous);
        };
    }

    /**
     * PHP's shorthand-byte notation ('128M', '2G', '1024', '-1') as an int;
     * <= 0 means "no finite limit", the state where nothing must be raised.
     */
    private static function parseShorthandBytes(string $value): int
    {
        $value = \trim($value);
        if ($value === '') {
            return -1;
        }
        $number = (int) $value;

        return match (\strtoupper(\substr($value, -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 * 1024,
            'G' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
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
     * as the exact command — and the command fits the install shape (see
     * PHAR-MODE ONLY in the class docblock): a consuming project is pointed
     * at its dependency update, a candy-code clone at pulling the clone.
     * Public and pure so the refusal text is pinned without needing a
     * non-phar "phar" to point the swap machinery at; the optional seam
     * names the root package directly, the way production resolution reads
     * it from the runtime.
     */
    public static function composerRefusal(?string $rootPackage = null): string
    {
        if (($rootPackage ?? self::rootPackageName()) === self::PACKAGE) {
            return Lang::t('cli.selfupdate.composer_mode_root', ['command' => self::PULL_AND_INSTALL_COMMAND]);
        }

        return Lang::t('cli.selfupdate.composer_mode', ['command' => self::COMPOSER_COMMAND]);
    }

    /**
     * The package the running code is the ROOT of, or '' when the runtime
     * cannot answer. A shipped phar never reaches this call (the refusal
     * fires only when `\Phar::running(false)` is empty), so the answer read
     * here is always the real install's root: the consuming project's name
     * in a vendor tree, `sugarcraft/candy-code` in a clone.
     */
    private static function rootPackageName(): string
    {
        if (!\class_exists(InstalledVersions::class)) {
            return '';
        }
        $name = InstalledVersions::getRootPackage()['name'] ?? null;

        return \is_string($name) ? $name : '';
    }

    private static function rollback(string $old, string $phar, ?int $pharMode = null): int
    {
        if (!\is_file($old)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_missing', ['old' => $old]));

            return NonInteractive::EXIT_FAILURE;
        }
        if (!\rename($old, $phar)) {
            self::stderr(Lang::t('cli.selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

            return NonInteractive::EXIT_FAILURE;
        }
        // DST-3-P2: the rename carries `.old`'s mode onto the target. A copy
        // preserved by the current code already matches; a LEGACY `.old`
        // (made before the mode was pinned) does not, so re-assert the mode
        // the live phar had when this command started.
        if ($pharMode !== null) {
            @\chmod($phar, $pharMode);
        }
        self::stderr(Lang::t('cli.selfupdate.rollback_restored', ['old' => $old, 'path' => $phar]));

        return NonInteractive::EXIT_OK;
    }

    private static function httpClient(): ClientInterface
    {
        $options = [
            // E646: ceilings on the two blocking operations, not a clock on
            // the whole request — see the class docblock. read_timeout is
            // the StreamHandler ceiling; on curl the low-speed pair below
            // is what catches the stalled bulk transfer.
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_timeout' => self::READ_TIMEOUT_SECONDS,
            // The suite's client asserts its own answers (status >= 400 is
            // checked by hand) instead of running on Guzzle's exception
            // magic, so the failure text is ours in both paths.
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                // The GitHub API rejects a request with no user agent.
                'User-Agent' => 'candy-code-self-update',
            ],
        ];
        // DST-3-P3: the constants exist only when ext-curl is loaded, and
        // only the curl handler reads them. A curl-less runtime keeps the
        // StreamHandler shape (read_timeout in force) instead of fataling
        // on a constant lookup. Raw CURLOPT_* values travel in the 'curl'
        // option (the handler ignores top-level ints), and this pair sits
        // on Guzzle's supported allow-list — the named-option machinery has
        // no low-speed equivalents to route through.
        if (\defined('CURLOPT_LOW_SPEED_LIMIT') && \defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [
                \CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BYTES_PER_SECOND,
                \CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_STALL_SECONDS,
            ];
        }

        return new Client($options);
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
            \fwrite(self::$errorStream ?? \STDERR, $text . "\n");
        }
    }
}
