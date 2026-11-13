<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

/**
 * Where a phar-shipped tool publishes its releases, and what its artifacts are
 * called. Every candy-code-specific literal the self-update engine used to carry
 * (`candy-code/` tag prefix, `candy-code.phar` asset family, `detain/sugarcraft`
 * repo, the Composer package named in the refusal) is an input here.
 *
 * The release trains in a monorepo are per-app tags sharing ONE GitHub release
 * stream (`<app>/v1.2.3`, `<app>/dev`), so the tag prefix is what separates one
 * tool's releases from its siblings' — the repo-wide `/releases/latest` answer
 * is usually some other tool's tag.
 *
 * Extracted from candy-code's `Cli/SelfUpdate` constants.
 */
final class ReleaseSource
{
    /**
     * The other honest update command, for the install shape where the tool IS
     * the Composer root package (a clone): `composer update <pkg>` refreshes
     * that package's DEPENDENCIES, never the package itself.
     */
    public const PULL_AND_INSTALL_COMMAND = 'git pull && composer install';

    private function __construct(
        public readonly string $repo,
        public readonly string $app,
        public readonly string $tagPrefix,
        public readonly string $pharAsset,
        public readonly string $publicKey,
        public readonly string $composerPackage,
        public readonly string $userAgent,
    ) {
    }

    /**
     * @param string $repo `owner/name` of the GitHub repository publishing the releases
     * @param string $app  the tool's name: binary/display name and the default tag/asset stem
     */
    public static function new(string $repo, string $app): self
    {
        if (\preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1) {
            throw new \InvalidArgumentException('repo must be "owner/name", got "' . $repo . '"');
        }
        if ($app === '' || \preg_match('#[\s/]#', $app) === 1) {
            throw new \InvalidArgumentException('app must be a non-empty name without whitespace or "/"');
        }

        return new self($repo, $app, $app . '/', $app . '.phar', '', '', $app . '-self-update');
    }

    public function withTagPrefix(string $tagPrefix): self
    {
        return $this->mutate(tagPrefix: $tagPrefix);
    }

    public function withPharAsset(string $pharAsset): self
    {
        return $this->mutate(pharAsset: $pharAsset);
    }

    /** Base64 of the raw 32-byte Ed25519 public key; '' is the keyless state. */
    public function withPublicKey(string $publicKey): self
    {
        return $this->mutate(publicKey: $publicKey);
    }

    /** The Composer package the tool installs as (`vendor/name`), named in the composer-mode refusal. */
    public function withComposerPackage(string $composerPackage): self
    {
        return $this->mutate(composerPackage: $composerPackage);
    }

    public function withUserAgent(string $userAgent): self
    {
        return $this->mutate(userAgent: $userAgent);
    }

    /** First page of the release list (`?per_page=100`). */
    public function releasesUrl(): string
    {
        return 'https://api.github.com/repos/' . $this->repo . '/releases?per_page=100';
    }

    /** The only path a `Link: rel="next"` hop may name on api.github.com. */
    public function releasesPath(): string
    {
        return '/repos/' . $this->repo . '/releases';
    }

    public function sidecarAsset(): string
    {
        return $this->pharAsset . '.sha256';
    }

    /** The LEGACY detached signature over the raw phar bytes. */
    public function signatureAsset(): string
    {
        return $this->pharAsset . '.minisig';
    }

    /** The stream-safe detached signature over the sidecar body. */
    public function sidecarSignatureAsset(): string
    {
        return $this->pharAsset . '.sha256.sig';
    }

    /** The exact tag the rolling dev release is moved under. */
    public function devTag(): string
    {
        return $this->tagPrefix . 'dev';
    }

    /** `composer update <package>` — printed verbatim in the composer-mode refusal. */
    public function composerCommand(): string
    {
        return 'composer update ' . $this->composerPackage;
    }

    private function mutate(
        ?string $tagPrefix = null,
        ?string $pharAsset = null,
        ?string $publicKey = null,
        ?string $composerPackage = null,
        ?string $userAgent = null,
    ): self {
        return new self(
            $this->repo,
            $this->app,
            $tagPrefix ?? $this->tagPrefix,
            $pharAsset ?? $this->pharAsset,
            $publicKey ?? $this->publicKey,
            $composerPackage ?? $this->composerPackage,
            $userAgent ?? $this->userAgent,
        );
    }
}
