<?php

declare(strict_types=1);

namespace SugarCraft\CrushWeb;

/**
 * Locates the pre-built sugar-crush-web bundle on disk.
 *
 * This is the whole PHP surface of the package. `sugarcrush serve` asks
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
     * Absolute path of the committed bundle (no trailing slash).
     *
     * Resolved through `realpath()` so a server that path-jails requests to
     * this directory compares against the same canonical prefix the kernel
     * reports for the files inside it, even when vendor/ is a symlink farm
     * (path repositories install packages as symlinks).
     *
     * @throws \RuntimeException when the bundle is missing — a broken install,
     *                           never a state the server should paper over.
     */
    public static function distPath(): string
    {
        $dist = \realpath(\dirname(__DIR__) . '/dist');
        if ($dist === false || !\is_file($dist . '/index.html')) {
            throw new \RuntimeException(
                'sugar-crush-web: dist/index.html is missing; the package install is incomplete.',
            );
        }

        return $dist;
    }

    /**
     * The decoded Vite manifest: source entry => {file, css?, isEntry?, …}.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function manifest(): array
    {
        /** @var array<string, array<string, mixed>> */
        return self::readJson(self::MANIFEST);
    }

    /**
     * The bundle's release version, as package.json declared it at build time.
     *
     * package.json is excluded from the Packagist archive, so the version
     * travels inside dist/ instead of being read from the manifest of record.
     */
    public static function version(): string
    {
        $info = self::readJson(self::BUILD_INFO);
        $version = $info['version'] ?? null;
        if (!\is_string($version) || $version === '') {
            throw new \RuntimeException('sugar-crush-web: dist/' . self::BUILD_INFO . ' carries no version.');
        }

        return $version;
    }

    /** @return array<mixed> */
    private static function readJson(string $name): array
    {
        $path = self::distPath() . '/' . $name;
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("sugar-crush-web: dist/{$name} is missing.");
        }
        $decoded = \json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException("sugar-crush-web: dist/{$name} is not a JSON object.");
        }

        return $decoded;
    }
}
