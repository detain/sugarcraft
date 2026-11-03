<?php

declare(strict_types=1);

namespace SugarCraft\CodeWeb;

/**
 * Locates the pre-built candy-code-web bundle on disk.
 *
 * This is the whole PHP surface of the package. `candy-code serve` asks
 * `class_exists(Assets::class)` and, when the package is installed, serves
 * {@see distPath()} as its web root, so a PHP user gets the browser UI from
 * `composer require` alone and never needs Node. The bundle itself is
 * committed under `dist/` and rebuilt from `src-web/` by `npm run build`;
 * CI fails when the two drift.
 *
 * First-party: there is no upstream to mirror. The closest analogues are the
 * opencode web UI and the OpenClaw Control UI, which ship the same way (a
 * static bundle served by the agent's own server).
 */
final class Assets
{
    /** The Vite manifest, written by `build.manifest` in vite.config.ts. */
    public const MANIFEST = 'manifest.json';

    /** Name + version stamp, written by the build-info plugin in vite.config.ts. */
    public const BUILD_INFO = 'build-info.json';

    private function __construct()
    {
    }

    /**
     * Absolute path of the committed bundle (no trailing slash), or null
     * when the install is incomplete.
     *
     * Resolved through `realpath()` so a server that path-jails requests to
     * this directory compares against the same canonical prefix the kernel
     * reports for the files inside it, even when vendor/ is a symlink farm
     * (path repositories install packages as symlinks).
     *
     * The soft variant of {@see distPath()}: a consumer that treats a missing
     * bundle as "serve the API without a UI" (candy-code's `serve`) must not
     * have to catch a RuntimeException to branch on it. `distPath()` keeps
     * throwing for callers that want the hard contract.
     */
    public static function tryDistPath(): ?string
    {
        return self::tryResolveDist(\dirname(__DIR__));
    }

    /**
     * Absolute path of the committed bundle (no trailing slash).
     *
     * @throws \RuntimeException when the bundle is missing — a broken install,
     *                           never a state the server should paper over.
     */
    public static function distPath(): string
    {
        return self::resolveDist(\dirname(__DIR__));
    }

    /**
     * The decoded Vite manifest: source entry => {file, css?, isEntry?, …}.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function manifest(): array
    {
        /** @var array<string, array<string, mixed>> */
        return self::readJson(self::distPath(), self::MANIFEST);
    }

    /**
     * The bundle's release version, as package.json declared it at build time.
     *
     * package.json is excluded from the Packagist archive, so the version
     * travels inside dist/ instead of being read from the manifest of record.
     */
    public static function version(): string
    {
        return self::versionFrom(self::readJson(self::distPath(), self::BUILD_INFO));
    }

    /**
     * Structured health report for any package root — the whole failure
     * surface of {@see distPath()}, {@see manifest()} and {@see version()}
     * made observable without a thrown exception. Written for tests and a
     * future `serve` doctor; the package's own root is just one argument.
     *
     * @return array{dist: ?string, manifest: ?array<string, array<string, mixed>>, version: ?string, error: ?string}
     */
    public static function diagnose(string $packageRoot): array
    {
        $dist = self::tryResolveDist($packageRoot);
        if ($dist === null) {
            return [
                'dist' => null,
                'manifest' => null,
                'version' => null,
                'error' => self::MISSING_INDEX_MESSAGE,
            ];
        }

        try {
            /** @var array<string, array<string, mixed>> $manifest */
            $manifest = self::readJson($dist, self::MANIFEST);
            $version = self::versionFrom(self::readJson($dist, self::BUILD_INFO));
        } catch (\RuntimeException $failure) {
            return ['dist' => $dist, 'manifest' => null, 'version' => null, 'error' => $failure->getMessage()];
        }

        return ['dist' => $dist, 'manifest' => $manifest, 'version' => $version, 'error' => null];
    }

    /** Message shared by the throwing and reporting paths so they cannot drift. */
    private const MISSING_INDEX_MESSAGE = 'candy-code-web: dist/index.html is missing; the package install is incomplete.';

    private static function resolveDist(string $packageRoot): string
    {
        return self::tryResolveDist($packageRoot) ?? throw new \RuntimeException(self::MISSING_INDEX_MESSAGE);
    }

    private static function tryResolveDist(string $packageRoot): ?string
    {
        $dist = \realpath($packageRoot . '/dist');

        return $dist !== false && \is_file($dist . '/index.html') ? $dist : null;
    }

    /** @param array<mixed> $info */
    private static function versionFrom(array $info): string
    {
        $version = $info['version'] ?? null;
        if (!\is_string($version) || $version === '') {
            throw new \RuntimeException('candy-code-web: dist/' . self::BUILD_INFO . ' carries no version.');
        }

        return $version;
    }

    /** @return array<mixed> */
    private static function readJson(string $dist, string $name): array
    {
        $path = $dist . '/' . $name;
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("candy-code-web: dist/{$name} is missing.");
        }
        try {
            $decoded = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $malformed) {
            // Keep the offending file in the message: a bare "Syntax error"
            // cannot tell an operator which of the two stamps is broken.
            throw new \RuntimeException("candy-code-web: dist/{$name} is not valid JSON: " . $malformed->getMessage(), previous: $malformed);
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException("candy-code-web: dist/{$name} is not a JSON object.");
        }

        return $decoded;
    }
}
