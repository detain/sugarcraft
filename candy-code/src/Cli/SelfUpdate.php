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
            ->withTranslator(static fn(string $key, array $params = []): string => Lang::t('cli.' . $key, $params))
            ->withWindows(WindowsShell::isWindows())
            ->withSwapLauncher(static fn(int $parentPid, string $new, string $phar): bool => WindowsSwap::launch($parentPid, $new, $phar));
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
                'candy-code: ' . Lang::t('cli.' . $problem[0], $problem[1]),
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
