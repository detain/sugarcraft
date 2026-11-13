<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use GuzzleHttp\ClientInterface;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\Version;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Releases\ReleaseSelector;
use SugarCraft\Releases\ReleaseSource;
use SugarCraft\Releases\SelfUpdate as LibSelfUpdate;
use SugarCraft\Releases\Signature;
use SugarCraft\Releases\UpdateOptions;

/**
 * `candy-code self-update` — replace the running phar with the latest
 * published one, verified before the swap, with the pre-swap copy kept for a
 * one-shot rollback (plan_comparison DST-3).
 *
 *     self-update [--check] [--rollback] [--allow-unsigned] [--channel=stable|dev]
 *
 * S-L01 (G-10=A): the ENGINE — phar-only refusal, per-library tag selection on
 * the shared GitHub release stream, the stable and rolling dev channels,
 * sidecar-then-phar download judged per HTTP status, sha256 `hash_equals`,
 * Ed25519 verification before the rename, the one-shot `.old` rollback, the
 * Windows relaunch dance, the exit-code contract (0 / 1 / 2) and every
 * trust-model law — now lives in sugar-releases ({@see \SugarCraft\Releases\SelfUpdate},
 * where its suite owns those pins). This class is the app-side adapter and
 * owns only what is candy-code's: the release coordinates
 * ({@see source()}), the pinned public key ({@see PUBLIC_KEY_BASE64}), argv
 * parsing into {@see UpdateOptions}, the structured-usage JSON failure path
 * ({@see NonInteractive::failUsage()}), the `cli.selfupdate.*` catalogue
 * wording, and process containment for the detached Windows helper.
 *
 * TRUST MODEL: the shipped build is in the PINNED state (key below, since
 * 2026-10-20): a release without a verifying signature is refused, and the
 * sha256 sidecar alone never gates the swap. `--allow-unsigned` is the
 * dev-only bypass of the signature leg and never bypasses the sidecar.
 *
 * The $http / $releasesUrl / $pharPath / $currentVersion / $publicKey
 * parameters of {@see run()} exist for the suite (a fake release server as a
 * Guzzle handler, a stub archive standing in for the phar); production
 * dispatch supplies none of them. {@see useErrorStreamForTesting} redirects
 * the report lines.
 */
final class SelfUpdate
{
    public const TAG_PREFIX = Version::TAG_PREFIX;

    public const CHANNEL_STABLE = UpdateOptions::CHANNEL_STABLE;

    public const CHANNEL_DEV = UpdateOptions::CHANNEL_DEV;

    /** The rolling dev release's tag: the stable prefix plus `dev`. */
    public const DEV_TAG = self::TAG_PREFIX . 'dev';

    public const PHAR_ASSET = 'candy-code.phar';

    public const SIDECAR_ASSET = 'candy-code.phar.sha256';

    /** Legacy raw-bytes signature asset (accepted for one more release cycle). */
    public const SIGNATURE_ASSET = 'candy-code.phar.minisig';

    /** Ed25519 signature over the sidecar body (the stream-safe path). */
    public const SIDECAR_SIGNATURE_ASSET = 'candy-code.phar.sha256.sig';

    /**
     * The pinned release public key: base64 of the raw 32-byte Ed25519 key
     * (the shipped state since 2026-10-20). With a key pinned every update is
     * refused unless its signature verifies; the empty string is the keyless
     * state, where only the sha256 sidecar binds the bytes.
     */
    public const PUBLIC_KEY_BASE64 = 'izi+7OlG5PTqGBpv03s2jnc5vRdxnChdBZIonN67T6o=';

    public const RELEASES_URL = 'https://api.github.com/repos/detain/sugarcraft/releases?per_page=100';

    /** The Composer-install update command (inside a consuming project's vendor tree). */
    public const COMPOSER_COMMAND = 'composer update sugarcraft/candy-code';

    /** The clone-as-root update command (a root package cannot refresh itself). */
    public const PULL_AND_INSTALL_COMMAND = ReleaseSource::PULL_AND_INSTALL_COMMAND;

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
     * candy-code's release coordinates for the engine: `detain/sugarcraft`,
     * tags `candy-code/v*`, asset `candy-code.phar`, the pinned key above.
     */
    public static function source(?string $publicKey = null): ReleaseSource
    {
        return ReleaseSource::new('detain/sugarcraft', 'candy-code')
            ->withComposerPackage('sugarcraft/candy-code')
            ->withPublicKey($publicKey ?? self::PUBLIC_KEY_BASE64);
    }

    /** The engine over the app's source, wired to the `cli.*` catalogue and containment. */
    public static function engine(?string $publicKey = null): LibSelfUpdate
    {
        return LibSelfUpdate::new(self::source($publicKey))
            ->withTranslator(static fn(string $key, array $params = []): string => self::translate($key, $params))
            ->withWindows(WindowsShell::isWindows())
            ->withSwapLauncher(static fn(int $parentPid, string $new, string $phar): bool => WindowsSwap::launch($parentPid, $new, $phar));
    }

    /**
     * The engine's translator: every library key maps to a LITERAL
     * `Lang::t()` call on a full `cli.selfupdate.*` key so the static catalogue scanners (which cannot
     * see a concatenated key) keep proving each key is both used and defined.
     *
     * @param array<string, mixed> $params
     */
    public static function translate(string $key, array $params = []): string
    {
        return match ($key) {
            'selfupdate.channel_invalid' => Lang::t('cli.selfupdate.channel_invalid', $params),
            'selfupdate.check_available' => Lang::t('cli.selfupdate.check_available', $params),
            'selfupdate.check_unknown' => Lang::t('cli.selfupdate.check_unknown', $params),
            'selfupdate.check_up_to_date' => Lang::t('cli.selfupdate.check_up_to_date', $params),
            'selfupdate.composer_mode' => Lang::t('cli.selfupdate.composer_mode', $params),
            'selfupdate.composer_mode_root' => Lang::t('cli.selfupdate.composer_mode_root', $params),
            'selfupdate.dir_not_writable' => Lang::t('cli.selfupdate.dir_not_writable', $params),
            'selfupdate.downgrade_refused' => Lang::t('cli.selfupdate.downgrade_refused', $params),
            'selfupdate.download_failed' => Lang::t('cli.selfupdate.download_failed', $params),
            'selfupdate.empty_download' => Lang::t('cli.selfupdate.empty_download', $params),
            'selfupdate.fetch_failed' => Lang::t('cli.selfupdate.fetch_failed', $params),
            'selfupdate.flags_exclusive' => Lang::t('cli.selfupdate.flags_exclusive', $params),
            'selfupdate.hash_mismatch' => Lang::t('cli.selfupdate.hash_mismatch', $params),
            'selfupdate.hash_unreadable' => Lang::t('cli.selfupdate.hash_unreadable', $params),
            'selfupdate.installed' => Lang::t('cli.selfupdate.installed', $params),
            'selfupdate.no_dev_release' => Lang::t('cli.selfupdate.no_dev_release', $params),
            'selfupdate.no_release' => Lang::t('cli.selfupdate.no_release', $params),
            'selfupdate.phar_not_writable' => Lang::t('cli.selfupdate.phar_not_writable', $params),
            'selfupdate.preserve_failed' => Lang::t('cli.selfupdate.preserve_failed', $params),
            'selfupdate.rename_failed' => Lang::t('cli.selfupdate.rename_failed', $params),
            'selfupdate.rollback_failed' => Lang::t('cli.selfupdate.rollback_failed', $params),
            'selfupdate.rollback_missing' => Lang::t('cli.selfupdate.rollback_missing', $params),
            'selfupdate.rollback_restored' => Lang::t('cli.selfupdate.rollback_restored', $params),
            'selfupdate.sidecar_malformed' => Lang::t('cli.selfupdate.sidecar_malformed', $params),
            'selfupdate.sidecar_names' => Lang::t('cli.selfupdate.sidecar_names', $params),
            'selfupdate.sig.deprecated' => Lang::t('cli.selfupdate.sig.deprecated', $params),
            'selfupdate.sig.sidecar_invalid' => Lang::t('cli.selfupdate.sig.sidecar_invalid', $params),
            'selfupdate.signature_invalid' => Lang::t('cli.selfupdate.signature_invalid', $params),
            'selfupdate.signature_missing' => Lang::t('cli.selfupdate.signature_missing', $params),
            'selfupdate.staged_windows' => Lang::t('cli.selfupdate.staged_windows', $params),
            'selfupdate.swap_leftover' => Lang::t('cli.selfupdate.swap_leftover', $params),
            'selfupdate.swap_spawn_failed' => Lang::t('cli.selfupdate.swap_spawn_failed', $params),
            'selfupdate.uncomparable' => Lang::t('cli.selfupdate.uncomparable', $params),
            'selfupdate.unexpected_operand' => Lang::t('cli.selfupdate.unexpected_operand', $params),
            'selfupdate.unsigned_allowed' => Lang::t('cli.selfupdate.unsigned_allowed', $params),
            'selfupdate.up_to_date' => Lang::t('cli.selfupdate.up_to_date', $params),
            'selfupdate.usage' => Lang::t('cli.selfupdate.usage', $params),
            default => $key,
        };
    }

    public static function run(
        ParsedArgs $args,
        ?ClientInterface $http = null,
        ?string $releasesUrl = null,
        ?string $pharPath = null,
        ?string $currentVersion = null,
        ?string $publicKey = null,
    ): int {
        $options = UpdateOptions::new()
            ->withCheck(isset($args->subcommandFlags['--check']))
            ->withRollback(isset($args->subcommandFlags['--rollback']))
            ->withAllowUnsigned(isset($args->subcommandFlags['--allow-unsigned']))
            ->withChannel(isset($args->subcommandFlags['--channel']) ? (string) $args->subcommandFlags['--channel'] : null)
            ->withOperands(\array_map('strval', $args->subcommandArgs));

        // The structured-usage JSON failure path is the app's, not the engine's.
        $problem = $options->validate();
        if ($problem !== null) {
            return NonInteractive::failUsage(
                'candy-code: ' . self::translate($problem[0], $problem[1]),
                $args->outputFormat,
                Lang::t('cli.selfupdate.usage'),
            );
        }

        $engine = self::engine($publicKey)
            ->withHttp($http)
            ->withReleasesUrl($releasesUrl)
            ->withPharPath($pharPath)
            ->withErrorStream(self::$errorStream);

        return $engine->run($options, $currentVersion ?? \trim(Help::versionString()));
    }

    /**
     * Newest non-draft, non-prerelease `candy-code/v*` release carrying both the
     * phar and its sidecar, or null.
     *
     * @param array<int, mixed> $releases decoded GitHub release list
     *
     * @return array<string, mixed>|null
     */
    public static function selectRelease(array $releases): ?array
    {
        return ReleaseSelector::for(self::source())->selectRelease($releases);
    }

    /**
     * The rolling `candy-code/dev` release (prerelease allowed, draft skipped), or null.
     *
     * @param array<int, mixed> $releases
     *
     * @return array<string, mixed>|null
     */
    public static function selectDevRelease(array $releases): ?array
    {
        return ReleaseSelector::for(self::source())->selectDevRelease($releases);
    }

    /** The lowercase 64-hex digest a sidecar body carries, or null. */
    public static function parseSidecar(string $body): ?string
    {
        return ReleaseSelector::parseSidecar($body);
    }

    /** The asset name a sidecar body names (empty when it names none). */
    public static function sidecarName(string $body): string
    {
        return ReleaseSelector::sidecarName($body);
    }

    /** Is $signatureBody a valid Ed25519 signature by $publicKeyBase64 over exactly $payload? */
    public static function verifyDetached(string $payload, string $signatureBody, string $publicKeyBase64): bool
    {
        return Signature::verifyDetached($payload, $signatureBody, $publicKeyBase64);
    }

    /** The refusal text for a Composer-installed copy (names the command that fits the install shape). */
    public static function composerRefusal(?string $rootPackage = null): string
    {
        return self::engine()->composerRefusal($rootPackage);
    }
}
