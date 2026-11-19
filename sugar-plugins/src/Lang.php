<?php

declare(strict_types=1);

namespace SugarCraft\Plugins;

use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\Core\I18n\T;

/**
 * Translation base for a plugin's own catalog.
 *
 * A plugin owns its strings (`<plugin>/lang/`), so the host's catalog never
 * has to carry them. Each plugin subclasses this and sets two constants:
 * NAMESPACE, the first segment of its keys, and DIR, its `lang/` directory.
 * Lookup order is inherited from {@see \SugarCraft\Core\I18n\T}: exact locale,
 * then base language, then `en`, then the raw key.
 *
 * The registry behind T is first-registration-wins, so a plugin that claimed
 * a host namespace would silently own that catalog for the whole process.
 * {@see t()} therefore refuses, before anything is registered, an empty or
 * dotted NAMESPACE, a reserved host or library namespace, an empty or missing
 * DIR, and a NAMESPACE that another plugin directory already holds with a
 * different catalog. A second copy of the same catalog is accepted.
 *
 * Mirrors the sugar-pty `Lang` facade. The plugin catalog is approved under
 * O4 (plan_plugin.md section 4.11).
 *
 * @extends BaseLang
 */
abstract class Lang extends BaseLang
{
    /**
     * Namespaces the host and the monorepo libraries register for themselves.
     * A plugin must not take one: the first registration wins, so a plugin
     * that got there first would shadow the owner's catalog without an error.
     * Kept in step with each library's `Lang::NAMESPACE`.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'animation', 'ansiinspect', 'async', 'candy-image', 'charts',
        'cellbuffer', 'code', 'codeshot', 'codetime', 'colorprofile', 'core',
        'datepicker', 'files', 'flappy', 'flexbox', 'forms', 'gallery', 'gifview',
        'git', 'gitserver', 'image', 'input', 'kv', 'log', 'mail', 'markdown',
        'mdview', 'metrics', 'minesweeper', 'mouse', 'navstack', 'pty', 'quickpick',
        'readline', 'releases', 'scriptui', 'spring', 'sql', 'sshlauncher',
        'sshserver', 'starter', 'style', 'table', 'testing', 'tetris', 'toast',
        'top', 'treelist', 'vcr', 'video', 'widgets',
    ];

    /**
     * The directory and catalog fingerprint each plugin namespace was claimed
     * with in this process. T itself keeps only the first registration, so a
     * second plugin asking for the same namespace is compared here. The same
     * catalog from another directory (a fresh copy of one bundle, say) is
     * harmless and accepted; a different catalog would be served silently
     * wrong and is refused. Once the claimed copy has gone from disk, the
     * accepted copy is the one T reads, so lookups do not fall back to raw keys.
     *
     * @var array<string, array{dir: string, fingerprint: string}>
     */
    private static array $claimed = [];

    /**
     * Identical copies already accepted, per namespace, keyed by directory.
     * A copy is hashed once; later lookups for it skip the hash and only
     * re-check that the claimed copy is still on disk.
     *
     * @var array<string, array<string, true>>
     */
    private static array $accepted = [];

    /**
     * Translate a key in this plugin's namespace, after checking the
     * NAMESPACE and DIR contract.
     *
     * @param string                          $key    Dot-separated key relative to NAMESPACE.
     * @param array<string, string|int|float> $params Placeholder values for `{name}` substitution.
     *
     * @throws \InvalidArgumentException when NAMESPACE or DIR breaks the contract above.
     */
    public static function t(string $key, array $params = []): string
    {
        self::claim(static::class, static::NAMESPACE, static::DIR);

        return parent::t($key, $params);
    }

    private static function claim(string $class, string $namespace, string $dir): void
    {
        if ($namespace === '') {
            throw new \InvalidArgumentException("$class must declare a non-empty NAMESPACE.");
        }
        if (str_contains($namespace, '.')) {
            throw new \InvalidArgumentException("$class NAMESPACE must not contain '.': $namespace");
        }
        if (in_array($namespace, self::RESERVED, true)) {
            throw new \InvalidArgumentException(
                "$class NAMESPACE '$namespace' is reserved for the host or a library."
            );
        }
        if ($dir === '') {
            throw new \InvalidArgumentException("$class must declare a non-empty DIR.");
        }
        $held = self::$claimed[$namespace] ?? null;
        if ($held !== null && $held['dir'] === $dir) {
            return;
        }
        // A mistyped DIR would otherwise register fine and then echo raw keys for every lookup.
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("$class DIR '$dir' is not a directory.");
        }
        // en.php is T's universal fallback; a directory without it (say a plugin's src/ instead of lang/) would echo raw keys too.
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'en.php')) {
            throw new \InvalidArgumentException("$class DIR '$dir' has no en.php catalog.");
        }
        if ($held !== null && isset(self::$accepted[$namespace][$dir])) {
            self::takeOverIfClaimedCopyIsGone($namespace, $held, $dir, $held['fingerprint']);
            return;
        }
        $fingerprint = self::fingerprint($dir);
        if ($held !== null) {
            if ($held['fingerprint'] !== $fingerprint) {
                throw new \InvalidArgumentException(
                    "$class NAMESPACE '$namespace' is already claimed by another plugin directory."
                );
            }
            self::$accepted[$namespace][$dir] = true;
            self::takeOverIfClaimedCopyIsGone($namespace, $held, $dir, $fingerprint);
            return;
        }
        self::$claimed[$namespace] = ['dir' => $dir, 'fingerprint' => $fingerprint];
    }

    /**
     * T keeps reading the claimed directory, so once that copy is gone the
     * accepted copy takes over.
     *
     * @param array{dir: string, fingerprint: string} $held
     */
    private static function takeOverIfClaimedCopyIsGone(string $namespace, array $held, string $dir, string $fingerprint): void
    {
        if (is_file($held['dir'] . DIRECTORY_SEPARATOR . 'en.php')) {
            return;
        }
        self::$claimed[$namespace] = ['dir' => $dir, 'fingerprint' => $fingerprint];
        T::overrideNamespace($namespace, $dir);
    }

    /**
     * Hashes the catalog files T reads from $dir (its top-level *.php files,
     * by name, with their bytes), so two copies of one plugin hash alike and
     * a copy that differs in any locale does not.
     */
    private static function fingerprint(string $dir): string
    {
        $names = array_filter(
            scandir($dir) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.php') && is_file($dir . DIRECTORY_SEPARATOR . $name),
        );
        sort($names, SORT_STRING);

        $payload = '';
        foreach ($names as $name) {
            $contents = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . $name);
            // Length-prefixed so no name and contents pair can run into the next.
            $payload .= $name . "\0" . strlen($contents) . "\0" . $contents;
        }

        return hash('sha256', $payload);
    }
}
