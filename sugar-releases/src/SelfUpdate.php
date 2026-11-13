<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;

/**
 * Phar self-update engine: replace the running phar with the latest published
 * one, verified before the swap, with the pre-swap copy kept for a one-shot
 * rollback. Everything app-specific (repo, tag prefix, asset names, pinned key,
 * Composer package, display name) is the {@see ReleaseSource}; the host parses
 * its own argv into {@see UpdateOptions} and supplies the running version.
 *
 *     SelfUpdate::new($source)->run(UpdateOptions::new()->withCheck(), $currentVersion)
 *
 * Extracted from candy-code's `Cli/SelfUpdate`; the fail-closed laws are
 * unchanged and pinned in this lib's SelfUpdateTest:
 *
 *  - PHAR-MODE ONLY: `\Phar::running(false)` empty (a Composer install) is a
 *    LOUD refusal naming the update command that fits the install shape, exit 1.
 *    A silent no-op there would report success while changing nothing.
 *  - LATEST means the newest release of THIS tool's tag family carrying BOTH
 *    required assets (see {@see ReleaseSelector}); two channels, stable
 *    (ordered by {@see Version::compare()}) and latest (rolling `<prefix>latest`,
 *    judged by commit identity). The release list is walked via
 *    `Link: rel="next"` up to five pages.
 *  - VERIFY BEFORE RENAME: the sidecar is fetched first and must parse to a
 *    lowercase 64-hex digest (its optional name must match the phar asset); the
 *    phar streams to a temp file IN THE PHAR'S OWN DIRECTORY, its sha256 is
 *    compared with hash_equals(); with a key pinned the Ed25519 signature leg
 *    runs next (stream-safe signature over the sidecar body preferred, legacy
 *    raw-bytes signature accepted behind a deprecation notice, neither is a
 *    refusal; `--allow-unsigned` is the dev-only bypass and never skips the
 *    sha256 gate). Every download leg judges HTTP status.
 *  - ROLLBACK ONE-SHOT: the running phar is copied to `<phar>.old` before the
 *    rename; `--rollback` renames it back (consuming it) and touches no network.
 *    The live file's permission mode is carried onto temp and `.old`.
 *  - A shutdown hook sweeps the temp path, so a fatal between minting the temp
 *    and renaming it strands nothing.
 *  - WINDOWS CAVEAT: renaming over a running image fails on NT, so on Windows the
 *    verb never attempts that rename against itself: the verified bytes stage at
 *    `<phar>.new` and a detached {@see WindowsSwap} helper retries the rename
 *    until this process exits, logging to `<phar>.swap.log`; `--rollback` runs the
 *    same dance. The legs that CAN still die, die LOUD: a stage that cannot be
 *    placed reports `rename_failed`, an unspawnable helper `swap_spawn_failed`,
 *    with the running phar and `.old` untouched. A stage the helper abandoned is
 *    announced before the next update. On POSIX the fail-loud `rename_failed`
 *    leg is the documented answer, not a defect to route around.
 *  - TIME BOUNDS: connect ceiling, per-read ceiling and a low-speed stall pair,
 *    deliberately no total request timeout (a big phar on a slow link is
 *    legitimate work).
 *
 * EXITS: 0 up to date / swapped / rolled back; 1 refusals and failures, and
 * `--check` finding an update available; 2 usage errors.
 *
 * Report lines go to STDERR (or the stream given to {@see withErrorStream()}),
 * worded through the translator ({@see withTranslator()}, default {@see Lang}).
 * The `with*` seams below exist for the suite and for hosts that pin the world
 * (client, release URL, phar path, key, OS, swap launcher).
 */
final class SelfUpdate
{
    public const EXIT_OK = 0;

    public const EXIT_FAILURE = 1;

    public const EXIT_USAGE = 2;

    /**
     * DST-3-P3: the curl transport's stall killer — abort when the transfer has
     * moved fewer than this many bytes per second for
     * {@see LOW_SPEED_STALL_SECONDS} seconds (E646: these bound SLOTHOOD, not
     * length).
     */
    private const LOW_SPEED_LIMIT_BYTES_PER_SECOND = 1024;

    private const LOW_SPEED_STALL_SECONDS = 60;

    /** DST-3-P5: cap on `Link: rel="next"` hops so a hostile pagination loop cannot fetch forever. */
    private const RELEASE_PAGE_LIMIT = 5;

    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    private const READ_TIMEOUT_SECONDS = 60.0;

    /**
     * The legacy raw-bytes signature leg must hold the whole phar in memory;
     * this is the slack added over the file's size when its memory_limit must be
     * raised for the duration of that one leg.
     */
    private const LEGACY_SIG_HEADROOM_BYTES = 67108864;

    private ?ClientInterface $http = null;

    private ?string $releasesUrl = null;

    private ?string $pharPath = null;

    private ?string $publicKey = null;

    private ?bool $windows = null;

    /** @var resource|null */
    private $errorStream = null;

    /** @var (\Closure(string, array<string, string>): string)|null */
    private ?\Closure $translator = null;

    /** @var (\Closure(int, string, string): bool)|null */
    private ?\Closure $swapLauncher = null;

    private readonly ReleaseSelector $selector;

    private function __construct(private readonly ReleaseSource $source)
    {
        $this->selector = ReleaseSelector::for($source);
    }

    public static function new(ReleaseSource $source): self
    {
        return new self($source);
    }

    public function source(): ReleaseSource
    {
        return $this->source;
    }

    /** The HTTP client (a test mounts a fake release server as a Guzzle handler). */
    public function withHttp(?ClientInterface $http): self
    {
        $c = clone $this;
        $c->http = $http;

        return $c;
    }

    /** Override the first release-list page URL (default: {@see ReleaseSource::releasesUrl()}). */
    public function withReleasesUrl(?string $releasesUrl): self
    {
        $c = clone $this;
        $c->releasesUrl = $releasesUrl;

        return $c;
    }

    /** The phar to swap; null asks the runtime (`\Phar::running(false)`). */
    public function withPharPath(?string $pharPath): self
    {
        $c = clone $this;
        $c->pharPath = $pharPath;

        return $c;
    }

    /** Override the pinned key for this run; '' is the keyless state, null reads the source. */
    public function withPublicKey(?string $publicKey): self
    {
        $c = clone $this;
        $c->publicKey = $publicKey;

        return $c;
    }

    /** Force the Windows relaunch-dance leg on or off; null reads PHP_OS_FAMILY. */
    public function withWindows(?bool $windows): self
    {
        $c = clone $this;
        $c->windows = $windows;

        return $c;
    }

    /** @param resource|null $stream where report lines go; null is STDERR */
    public function withErrorStream(mixed $stream): self
    {
        $c = clone $this;
        $c->errorStream = $stream;

        return $c;
    }

    /**
     * Route wording through the host's catalog: `fn (string $key, array $params): string`
     * receiving the `selfupdate.*` keys of lang/en.php with `{app}`/`{package}` filled.
     *
     * @param (\Closure(string, array<string, string>): string)|null $translator
     */
    public function withTranslator(?\Closure $translator): self
    {
        $c = clone $this;
        $c->translator = $translator;

        return $c;
    }

    /**
     * Replace the detached Windows swap helper launch (default {@see WindowsSwap::launch()}).
     *
     * @param (\Closure(int, string, string): bool)|null $swapLauncher
     */
    public function withSwapLauncher(?\Closure $swapLauncher): self
    {
        $c = clone $this;
        $c->swapLauncher = $swapLauncher;

        return $c;
    }

    /**
     * Run the verb. `$currentVersion` is what the running install reports
     * (`v1.2.3` or a `dev-<sha>` stamp); the host resolves it, the engine only
     * orders it.
     */
    public function run(UpdateOptions $options, string $currentVersion): int
    {
        $problem = $options->validate();
        if ($problem !== null) {
            $this->stderr($this->source->app . ': ' . $this->t($problem[0], $problem[1]));
            $this->stderr($this->t('selfupdate.usage'));

            return self::EXIT_USAGE;
        }
        $check = $options->check;
        $rollback = $options->rollback;
        $allowUnsigned = $options->allowUnsigned;
        $channelArg = $options->channel;

        // null => ask the runtime what phar (if any) we are inside. A test
        // passes the stub it wants swapped; production never does.
        $phar = $this->pharPath ?? \Phar::running(false);
        if ($phar === '') {
            $this->stderr($this->composerRefusal());

            return self::EXIT_FAILURE;
        }

        // Spec (5): name the write problem before anything is downloaded —
        // a refusal after the fetch would waste the bytes it cannot install.
        $dir = \dirname($phar);
        if (!\is_dir($dir) || !\is_writable($dir)) {
            $this->stderr($this->t('selfupdate.dir_not_writable', ['dir' => $dir]));

            return self::EXIT_FAILURE;
        }
        if (!\is_file($phar) || !\is_writable($phar)) {
            $this->stderr($this->t('selfupdate.phar_not_writable', ['path' => $phar]));

            return self::EXIT_FAILURE;
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
            return $this->rollback($old, $phar, $pharMode);
        }

        // C-TUI-6.e: a `.new` stage still on disk means an earlier detached
        // swap gave up (an antivirus hold outlasting the budget, or a helper
        // that never landed). Announce the leftover rather than silently
        // overwriting it; this run's verified download replaces it below.
        if ($this->isWindows()) {
            $staged = $phar . '.new';
            if (\is_file($staged)) {
                $this->stderr($this->t('selfupdate.swap_leftover', ['new' => $staged]));
            }
        }

        // Resolution moved ahead of the fetch (choosing a channel needs the
        // current version): same seam, same arms, identical value.
        $current = \trim($currentVersion);
        $channel = $channelArg ?? (Version::isDev($current) ? UpdateOptions::CHANNEL_LATEST : UpdateOptions::CHANNEL_STABLE);

        $http = $this->http ?? $this->httpClient();
        // DST-3-P5: one page is not the whole train. GitHub paginates the
        // release list; the tool's tag the user wants can ride any page.
        // Follow `Link: rel="next"` hops up to RELEASE_PAGE_LIMIT pages and
        // select over the accumulated list. A page whose body is not a JSON
        // array contributes nothing (the old shape refused a non-array
        // single body outright — the accumulation answers the same way via
        // the selection below finding no release in an empty list).
        $releases = [];
        $pageUrl = $this->releasesUrl ?? $this->source->releasesUrl();
        for ($page = 1; $page <= self::RELEASE_PAGE_LIMIT; $page++) {
            try {
                $response = $http->request('GET', $pageUrl);
            } catch (TransferException $e) {
                $this->stderr($this->t('selfupdate.fetch_failed', ['reason' => $e->getMessage()]));

                return self::EXIT_FAILURE;
            }
            if ($response->getStatusCode() >= 400) {
                $this->stderr($this->t('selfupdate.fetch_failed', ['reason' => 'HTTP ' . $response->getStatusCode()]));

                return self::EXIT_FAILURE;
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
        if ($channel === UpdateOptions::CHANNEL_LATEST) {
            $release = $this->selector->selectLatestRelease($releases);
            if ($release === null) {
                $this->stderr($this->t('selfupdate.no_latest_release', [
                    'tag' => $this->source->latestTag(),
                    'phar' => $this->source->pharAsset,
                    'sidecar' => $this->source->sidecarAsset(),
                ]));

                return self::EXIT_FAILURE;
            }
            [$cmp, $latest] = $this->selector->latestDecision($current, $release);
        } else {
            $release = $this->selector->selectRelease($releases);
            if ($release === null) {
                $this->stderr($this->t('selfupdate.no_release', ['phar' => $this->source->pharAsset, 'sidecar' => $this->source->sidecarAsset()]));

                return self::EXIT_FAILURE;
            }
            $cmp = Version::compare($release['version'], $current);
            $latest = $release['version'];
        }

        if ($check) {
            if ($cmp === null) {
                $this->stderr($this->t('selfupdate.check_unknown', ['current' => $current, 'latest' => $latest]));

                return self::EXIT_FAILURE;
            }
            if ($cmp > 0) {
                $this->stderr($this->t('selfupdate.check_available', ['current' => $current, 'latest' => $latest]));

                return self::EXIT_FAILURE;
            }
            $this->stderr($this->t('selfupdate.check_up_to_date', ['current' => $current, 'latest' => $latest]));

            return self::EXIT_OK;
        }

        if ($cmp === null) {
            $this->stderr($this->t('selfupdate.uncomparable', ['current' => $current]));

            return self::EXIT_FAILURE;
        }
        if ($cmp === 0) {
            $this->stderr($this->t('selfupdate.up_to_date', ['current' => $current]));

            return self::EXIT_OK;
        }
        if ($cmp < 0) {
            // The sanctioned downgrade path is --rollback, which returned
            // above without consulting the network at all.
            // On the latest channel the decision is never behind (up-to-date or
            // available, by construction), so this arm is stable-only — and
            // the stable logic is byte-for-byte the one that shipped.
            $this->stderr($this->t('selfupdate.downgrade_refused', ['current' => $current, 'latest' => $latest]));

            return self::EXIT_FAILURE;
        }

        // Sidecar first: a shape it cannot answer with stops the verb before
        // a single phar byte is transferred.
        [$refusal, $sidecarBody] = $this->fetchOk($http, $release['sidecarUrl'], $this->source->sidecarAsset());
        if ($refusal !== null) {
            $this->stderr($refusal);

            return self::EXIT_FAILURE;
        }
        $expected = ReleaseSelector::parseSidecar($sidecarBody);
        if ($expected === null) {
            $this->stderr($this->t('selfupdate.sidecar_malformed', ['asset' => $this->source->sidecarAsset(), 'phar' => $this->source->pharAsset]));

            return self::EXIT_FAILURE;
        }
        $named = ReleaseSelector::sidecarName($sidecarBody);
        if ($named !== '' && $named !== $this->source->pharAsset) {
            $this->stderr($this->t('selfupdate.sidecar_names', ['found' => $named, 'phar' => $this->source->pharAsset]));

            return self::EXIT_FAILURE;
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
        [$refusal] = $this->fetchOk($http, $release['pharUrl'], $this->source->pharAsset, $temp);
        if ($refusal !== null) {
            $this->stderr($refusal);

            return self::EXIT_FAILURE;
        }
        if (!\is_file($temp) || \filesize($temp) === 0) {
            self::forget($temp);
            $this->stderr($this->t('selfupdate.empty_download', ['asset' => $this->source->pharAsset]));

            return self::EXIT_FAILURE;
        }

        // The one gate the swap cannot cross without: the bytes on disk must
        // BE the signed bytes. hash_equals, not ==, so the comparison does
        // not short-circuit on the first differing digit.
        $actual = \hash_file('sha256', $temp);
        if ($actual === false || !\hash_equals($expected, $actual)) {
            self::forget($temp);
            $this->stderr($this->t('selfupdate.hash_mismatch', [
                'expected' => $expected,
                'actual' => $actual === false ? $this->t('selfupdate.hash_unreadable') : $actual,
            ]));

            return self::EXIT_FAILURE;
        }

        // Pinned-key leg, after the hash (the temp bytes are the real
        // artifact now) and before the copy (nothing to roll back from if
        // the key rejects). Empty key = the keyless state (the pre-2026-10-20
        // shipped shape, reachable in tests by injecting ''): the leg
        // does not exist, and no --allow-unsigned notice is printed for it.
        $key = $this->publicKey ?? $this->source->publicKey;
        if ($key !== '') {
            if ($allowUnsigned) {
                $this->stderr($this->t('selfupdate.unsigned_allowed'));
            } else {
                $refusal = $this->verifySignature($http, $release, $temp, $key, $sidecarBody);
                if ($refusal !== null) {
                    self::forget($temp);
                    $this->stderr($refusal);

                    return self::EXIT_FAILURE;
                }
            }
        }

        // Keep the running phar BEFORE exchanging it: without a preserved
        // copy there is nothing --rollback could restore, and a verb that
        // takes away its own undo is not one this verb wants to be.
        if (!\copy($phar, $old)) {
            self::forget($temp);
            $this->stderr($this->t('selfupdate.preserve_failed', ['old' => $old]));

            return self::EXIT_FAILURE;
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

        // C-TUI-6.e (WINDOWS): the swap leg cannot run against the image this
        // process is executing from, so the verified bytes (mode already
        // carried by the chmod above — rename publishes it, same law as the
        // POSIX leg) are staged at `<phar>.new` and the detached
        // {@see WindowsSwap} helper lands them once this process exits. A
        // leftover stage from an abandoned swap is cleared first; refusing
        // the fresh update over it would strand the install behind a file
        // nobody asked for.
        if ($this->isWindows()) {
            $new = $phar . '.new';
            if (\is_file($new) && !@\unlink($new)) {
                self::forget($temp);
                $this->stderr($this->t('selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            if (!\rename($temp, $new)) {
                self::forget($temp);
                $this->stderr($this->t('selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            if (!$this->launchSwap(\getmypid(), $new, $phar)) {
                $this->stderr($this->t('selfupdate.swap_spawn_failed', ['new' => $new, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            $this->stderr($this->t('selfupdate.staged_windows', ['new' => $new, 'old' => $old]));

            return self::EXIT_OK;
        }

        if (!\rename($temp, $phar)) {
            self::forget($temp);
            $this->stderr($this->t('selfupdate.rename_failed', ['old' => $old, 'path' => $phar]));

            return self::EXIT_FAILURE;
        }

        $this->stderr($this->t('selfupdate.installed', [
            'path' => $phar,
            'from' => $current,
            'to' => $latest,
            'old' => $old,
        ]));

        return self::EXIT_OK;
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
    private function fetchOk(ClientInterface $http, string $url, string $asset, ?string $sink = null): array
    {
        $options = $sink === null ? [] : ['sink' => $sink];
        try {
            $response = $http->request('GET', $url, $options);
        } catch (TransferException $e) {
            if ($sink !== null) {
                self::forget($sink);
            }

            return [$this->t('selfupdate.download_failed', ['asset' => $asset, 'reason' => $e->getMessage()]), ''];
        }
        $status = $response->getStatusCode();
        if ($status >= 400) {
            if ($sink !== null) {
                self::forget($sink);
            }

            return [$this->t('selfupdate.download_failed', ['asset' => $asset, 'reason' => 'HTTP ' . $status]), ''];
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
    private function verifySignature(ClientInterface $http, array $release, string $temp, string $key, string $sidecarBody): ?string
    {
        if ($release['sigSidecarUrl'] !== '') {
            [$refusal, $signatureBody] = $this->fetchOk($http, $release['sigSidecarUrl'], $this->source->sidecarSignatureAsset());
            if ($refusal !== null) {
                return $refusal;
            }
            if (!Signature::verifyDetached($sidecarBody, $signatureBody, $key)) {
                return $this->t('selfupdate.sig.sidecar_invalid', [
                    'asset' => $this->source->sidecarSignatureAsset(),
                    'sidecar' => $this->source->sidecarAsset(),
                    'phar' => $this->source->pharAsset,
                ]);
            }

            return null;
        }
        if ($release['sigUrl'] === '') {
            return $this->t('selfupdate.signature_missing', [
                'asset' => $this->source->signatureAsset(),
                'sig_sidecar' => $this->source->sidecarSignatureAsset(),
                'phar' => $this->source->pharAsset,
            ]);
        }
        $this->stderr($this->t('selfupdate.sig.deprecated', [
            'asset' => $this->source->signatureAsset(),
            'replacement' => $this->source->sidecarSignatureAsset(),
        ]));
        [$refusal, $signatureBody] = $this->fetchOk($http, $release['sigUrl'], $this->source->signatureAsset());
        if ($refusal !== null) {
            return $refusal;
        }
        $restoreMemory = self::raiseMemoryForRawBytesVerify($temp);
        try {
            $payload = \file_get_contents($temp);
        } finally {
            $restoreMemory();
        }
        if ($payload === false || !Signature::verifyDetached($payload, $signatureBody, $key)) {
            return $this->t('selfupdate.signature_invalid', ['asset' => $this->source->signatureAsset(), 'phar' => $this->source->pharAsset]);
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
     * The loud composer-mode refusal, verbatim: what to run instead, stated as
     * the exact command — and the command fits the install shape: a consuming
     * project is pointed at its dependency update, a clone where the tool IS the
     * Composer root package at pulling the clone (`composer update <pkg>` there
     * updates the package's dependencies, not the package). Public and pure so the
     * wording is pinned without a non-phar "phar"; `$rootPackage` is the seam for
     * the runtime's root-package answer.
     */
    public function composerRefusal(?string $rootPackage = null): string
    {
        $package = $this->source->composerPackage;
        if ($package !== '' && ($rootPackage ?? self::rootPackageName()) === $package) {
            return $this->t('selfupdate.composer_mode_root', ['command' => ReleaseSource::PULL_AND_INSTALL_COMMAND]);
        }

        return $this->t('selfupdate.composer_mode', ['command' => $this->source->composerCommand()]);
    }

    /** The package the running code is the ROOT of, or '' when the runtime cannot answer. */
    private static function rootPackageName(): string
    {
        if (!\class_exists(InstalledVersions::class)) {
            return '';
        }
        $name = InstalledVersions::getRootPackage()['name'] ?? null;

        return \is_string($name) ? $name : '';
    }

    private function rollback(string $old, string $phar, ?int $pharMode = null): int
    {
        if (!\is_file($old)) {
            $this->stderr($this->t('selfupdate.rollback_missing', ['old' => $old]));

            return self::EXIT_FAILURE;
        }

        // C-TUI-6.e (WINDOWS): the same dance in reverse — `.old` cannot be
        // renamed over the running image either, so it is staged as
        // `<phar>.new` and the detached helper lands it when this process
        // exits. The rename still CONSUMES `.old` (one-shot by construction,
        // the same law the POSIX leg pins), and the mode re-assert rides the
        // stage before it is handed over, so the helper's final rename
        // publishes it onto the target just as the POSIX rename would.
        if ($this->isWindows()) {
            $new = $phar . '.new';
            if (\is_file($new) && !@\unlink($new)) {
                $this->stderr($this->t('selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            if (!\rename($old, $new)) {
                $this->stderr($this->t('selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            if ($pharMode !== null) {
                @\chmod($new, $pharMode);
            }
            if (!$this->launchSwap(\getmypid(), $new, $phar)) {
                $this->stderr($this->t('selfupdate.swap_spawn_failed', ['new' => $new, 'path' => $phar]));

                return self::EXIT_FAILURE;
            }
            $this->stderr($this->t('selfupdate.staged_windows', ['new' => $new, 'old' => $old]));

            return self::EXIT_OK;
        }

        if (!\rename($old, $phar)) {
            $this->stderr($this->t('selfupdate.rollback_failed', ['old' => $old, 'path' => $phar]));

            return self::EXIT_FAILURE;
        }
        // DST-3-P2: the rename carries `.old`'s mode onto the target. A copy
        // preserved by the current code already matches; a LEGACY `.old`
        // (made before the mode was pinned) does not, so re-assert the mode
        // the live phar had when this command started.
        if ($pharMode !== null) {
            @\chmod($phar, $pharMode);
        }
        $this->stderr($this->t('selfupdate.rollback_restored', ['old' => $old, 'path' => $phar]));

        return self::EXIT_OK;
    }

    private function httpClient(): ClientInterface
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
                'User-Agent' => $this->source->userAgent,
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

    private function isWindows(): bool
    {
        return $this->windows ?? (\PHP_OS_FAMILY === 'Windows');
    }

    private function launchSwap(int $parentPid, string $new, string $phar): bool
    {
        return $this->swapLauncher !== null
            ? ($this->swapLauncher)($parentPid, $new, $phar)
            : WindowsSwap::launch($parentPid, $new, $phar);
    }

    /** @param array<string, string> $params */
    private function t(string $key, array $params = []): string
    {
        $params += ['app' => $this->source->app, 'package' => $this->source->composerPackage];

        return $this->translator !== null ? ($this->translator)($key, $params) : Lang::t($key, $params);
    }

    private static function forget(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    private function stderr(string $text): void
    {
        if ($text !== '') {
            \fwrite($this->errorStream ?? \STDERR, $text . "\n");
        }
    }
}
