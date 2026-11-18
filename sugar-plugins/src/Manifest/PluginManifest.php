<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Manifest;

use SugarCraft\Core\Util\PathJail;

/**
 * A plugin's `plugin.json`, read into the fields a host needs to decide
 * whether to load it.
 *
 * Two shapes are accepted: `.claude-plugin/plugin.json`, the convention the
 * upstream format uses, and a bare `plugin.json` at the root. Unknown fields
 * are ignored, which is what lets a manifest written for another tool load.
 *
 * Refusals answer null rather than a partial value: a non-object document, a
 * name that starts with a dot (such a bundle would sit invisible to every
 * later directory scan), or a `contract` that is not a non-negative integer.
 * Entrypoints are the one payload that is code, so each file is resolved
 * here to a realpath contained by the plugin directory, and anything that
 * escapes it (including through a planted symlink) is dropped before any
 * file is opened.
 */
final readonly class PluginManifest
{
    /** Where the format's own convention puts the manifest. */
    public const MANIFEST_RELATIVE_PATH = '.claude-plugin/plugin.json';

    /** The tolerated bare shape: `plugin.json` at the plugin root. */
    public const BARE_MANIFEST_RELATIVE_PATH = 'plugin.json';

    /** @param array<string, list<Entrypoint>> $entrypoints kind => entrypoints, first declaration per path wins */
    private function __construct(
        private string $dir,
        private string $name,
        private string $version,
        private string $description,
        private ?int $contract,
        private array $entrypoints,
    ) {
    }

    /** The manifest of $dir, or null when there is no readable, acceptable one. */
    public static function fromDir(string $dir): ?self
    {
        $path = self::manifestPath($dir);

        if ($path === null) {
            return null;
        }

        $raw = @\file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = \json_decode($raw, true);

        // A non-object document is refused. The one list an object can
        // decode to is the empty one, which reads as an empty object.
        if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
            return null;
        }

        $name = self::scalar($decoded['name'] ?? null);
        if ($name === '') {
            // The directory is what the operator installed, so it carries the
            // identity when the manifest does not name itself.
            $name = \basename($dir);
        }

        if (\str_starts_with($name, '.')) {
            return null;
        }

        $contract = $decoded['contract'] ?? null;
        if ($contract !== null && (!\is_int($contract) || $contract < 0)) {
            return null;
        }

        return new self(
            dir: $dir,
            name: $name,
            version: self::scalar($decoded['version'] ?? null),
            description: self::scalar($decoded['description'] ?? null),
            contract: $contract,
            entrypoints: self::entrypointsOf($dir, $decoded['entrypoints'] ?? null),
        );
    }

    /** True when $dir carries either manifest shape. */
    public static function hasManifest(string $dir): bool
    {
        return self::manifestPath($dir) !== null;
    }

    public static function manifestPath(string $dir): ?string
    {
        foreach ([self::MANIFEST_RELATIVE_PATH, self::BARE_MANIFEST_RELATIVE_PATH] as $relative) {
            if (\is_file($dir . '/' . $relative)) {
                return $dir . '/' . $relative;
            }
        }

        return null;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** The host contract integer this plugin needs, or null when it declares none. */
    public function contract(): ?int
    {
        return $this->contract;
    }

    /** @return array<string, list<Entrypoint>> */
    public function entrypoints(): array
    {
        return $this->entrypoints;
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? \trim((string) $value) : '';
    }

    /**
     * @return array<string, list<Entrypoint>>
     */
    private static function entrypointsOf(string $dir, mixed $declared): array
    {
        if (!\is_array($declared) || \array_is_list($declared)) {
            return [];
        }

        $kinds = [];

        foreach ($declared as $kind => $value) {
            $kind = \is_string($kind) ? \trim($kind) : '';

            if ($kind === '') {
                // An un-kinded entrypoint names no consumer, so it is refused.
                continue;
            }

            foreach (self::items($value) as $item) {
                $path = self::containedPath($dir, $item);

                if ($path === null) {
                    continue;
                }

                $seen = \array_map(static fn (Entrypoint $e): string => $e->path, $kinds[$kind] ?? []);

                if (!\in_array($path, $seen, true)) {
                    $kinds[$kind][] = new Entrypoint($kind, $path, self::classOf($item));
                }
            }
        }

        return $kinds;
    }

    /**
     * One kind value shaped into a list of items: a path string, an object, or
     * a list of either. A nested map rides the object arm and is refused at the
     * missing-`file` door, never guessed into a second kind level.
     *
     * @return list<array<string, mixed>|string>
     */
    private static function items(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        if (\array_is_list($value)) {
            $items = [];

            foreach ($value as $item) {
                if (\is_string($item) || \is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        return [$value];
    }

    /**
     * The existing, contained realpath of one item's `file`, or null when it
     * names nothing loadable. The escape door compares REALPATHS, so `..` hops,
     * absolute paths outside the plugin directory, and a symlink pointing out
     * all answer null before any file is opened.
     *
     * @param array<string, mixed>|string $item
     */
    private static function containedPath(string $dir, array|string $item): ?string
    {
        $raw = \is_string($item) ? $item : ($item['file'] ?? '');
        $file = \is_string($raw) ? \trim($raw) : '';

        // realpath() throws ValueError on a NUL byte, and that throw would abort
        // the load for every other trusted plugin, so the byte is refused first.
        if ($file === '' || \str_contains($file, "\0")) {
            return null;
        }

        $candidate = \str_starts_with($file, '/') ? $file : $dir . '/' . $file;
        $real = \realpath($candidate);

        if ($real === false || !\is_file($real)) {
            return null;
        }

        return PathJail::below($real, $dir) ? $real : null;
    }

    /**
     * The class an item promises, '' when it names none. A class is never
     * guessed from a file path.
     *
     * @param array<string, mixed>|string $item
     */
    private static function classOf(array|string $item): string
    {
        if (\is_string($item)) {
            return '';
        }

        return self::scalar($item['class'] ?? null);
    }
}
