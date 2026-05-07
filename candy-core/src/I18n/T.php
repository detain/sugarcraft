<?php

declare(strict_types=1);

namespace SugarCraft\Core\I18n;

/**
 * Tiny, zero-dependency translation helper for the SugarCraft monorepo.
 *
 * Every SugarCraft library shares this single registry so that user-facing
 * strings — exception messages, prompt labels, CLI usage banners — can be
 * swapped out by locale without bringing in a heavy i18n framework.
 *
 * ## Mental model
 *
 * 1.  Each library owns a **namespace** (e.g. `'core'`, `'charts'`,
 *     `'prompt'`) and a **lang directory** containing one PHP file per
 *     locale: `lang/en.php`, `lang/fr.php`, …
 * 2.  Every lang file `return`s a flat `array<string,string>` keyed by
 *     sub-key (no namespace prefix). Values may contain `{name}`
 *     placeholders.
 * 3.  Libraries register themselves via {@see register()} (idempotent),
 *     and call sites look strings up via {@see translate()} or its
 *     short alias {@see t()} using fully-qualified `'<ns>.<key>'` keys.
 * 4.  Lookup falls back from the active locale → `'en'` → the raw key
 *     itself, so a missing translation never throws.
 *
 * ## Example — registering a library
 *
 * Each library typically ships a small `Lang` helper that wraps this
 * registry with its own namespace baked in:
 *
 * ```php
 * // sugar-charts/src/Lang.php
 * final class Lang
 * {
 *     public static function t(string $key, array $params = []): string
 *     {
 *         T::register('charts', __DIR__ . '/../lang');
 *         return T::translate('charts.' . $key, $params);
 *     }
 * }
 * ```
 *
 * Call sites then read naturally:
 *
 * ```php
 * throw new \InvalidArgumentException(Lang::t('heatmap.invalid_dimensions'));
 * ```
 *
 * ## Example — switching locale
 *
 * ```php
 * T::setLocale('fr');                       // explicit
 * T::setLocale(T::detect());                // from $LANG / $LC_ALL
 * echo T::t('core.color.invalid_hex', ['hex' => '#zz']);
 * ```
 *
 * ## Placeholder syntax
 *
 * Values use `{name}` placeholders, replaced from the `$params` array:
 *
 * ```php
 * // lang/en.php
 * return ['color.invalid_hex' => 'invalid hex color: {hex}'];
 *
 * T::t('core.color.invalid_hex', ['hex' => '#zz']);
 * // => "invalid hex color: #zz"
 * ```
 *
 * Unmatched placeholders are left intact so that missing context is
 * visible rather than silently dropped.
 *
 * ## Why not symfony/translation?
 *
 * SugarCraft libraries deliberately keep zero runtime dependencies
 * outside `react/event-loop`. Trading ICU MessageFormat / XLIFF tooling
 * for a ~150-line helper keeps every downstream lib free to be required
 * standalone.
 */
final class T
{
    /**
     * Map of namespace → lang directory absolute path.
     *
     * Populated lazily by {@see register()}; consulted by {@see translate()}
     * to resolve the file that backs a given key.
     *
     * @var array<string, string>
     */
    private static array $namespaces = [];

    /**
     * In-memory cache of loaded lang files.
     *
     * Shape: `$cache[namespace][locale] = ['key.path' => 'translated', …]`.
     * Each `(namespace, locale)` pair is loaded at most once per process.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private static array $cache = [];

    /**
     * The active locale. Defaults to `'en'`; override via {@see setLocale()}
     * or pass an explicit `$locale` argument to {@see translate()}.
     */
    private static string $locale = 'en';

    /**
     * Register a library's translation directory.
     *
     * Calling more than once for the same `$namespace` is a no-op so the
     * call is safe to put inline at the top of every lookup helper. The
     * directory does not need to exist at registration time — files are
     * only opened on first lookup.
     *
     * @param string $namespace First segment of the translation key (e.g.
     *                          `'core'`, `'charts'`). Must not contain
     *                          a `.` separator.
     * @param string $dir       Absolute path to the directory containing
     *                          per-locale PHP files (`en.php`, `fr.php`, …).
     */
    public static function register(string $namespace, string $dir): void
    {
        if (str_contains($namespace, '.')) {
            throw new \InvalidArgumentException(
                "i18n namespace must not contain '.': $namespace"
            );
        }
        // First registration wins — later attempts (e.g. shadowed by a
        // downstream consumer that wants to override translations) should
        // re-register intentionally via overrideNamespace().
        self::$namespaces[$namespace] ??= $dir;
    }

    /**
     * Replace the directory backing an already-registered namespace.
     *
     * Intended for application-level overrides (e.g. an end-user app
     * wants to ship its own translations of `charts.*` strings without
     * patching the upstream library).
     */
    public static function overrideNamespace(string $namespace, string $dir): void
    {
        self::$namespaces[$namespace] = $dir;
        unset(self::$cache[$namespace]);
    }

    /**
     * Translate a fully-qualified key.
     *
     * The key is split on the **first** `.` — everything before it is the
     * namespace, everything after is the lookup key inside that namespace's
     * lang file. Keys with no `.` are returned untranslated.
     *
     * Resolution order:
     *
     *  1. `lang/$locale.php` for the namespace
     *  2. `lang/en.php` for the namespace (fallback)
     *  3. The raw key (so a missing translation surfaces visibly)
     *
     * @param string                          $key    e.g. `'core.color.invalid_hex'`.
     * @param array<string, string|int|float> $params Placeholder values for `{name}` substitution.
     * @param string|null                     $locale Override the active locale for this call only.
     */
    public static function translate(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= self::$locale;
        $dot = strpos($key, '.');
        if ($dot === false) {
            return self::interpolate($key, $params);
        }

        $namespace = substr($key, 0, $dot);
        $subKey    = substr($key, $dot + 1);

        $value = self::lookup($namespace, $subKey, $locale)
              ?? self::lookup($namespace, $subKey, 'en')
              ?? $key;

        return self::interpolate($value, $params);
    }

    /**
     * Convenience alias for {@see translate()}.
     *
     * @param array<string, string|int|float> $params
     */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        return self::translate($key, $params, $locale);
    }

    /** Set the process-wide active locale (e.g. `'en'`, `'fr'`, `'de'`). */
    public static function setLocale(string $locale): void
    {
        self::$locale = self::normalize($locale);
    }

    /** Return the process-wide active locale. */
    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Detect a sensible locale from the environment.
     *
     * Reads `$_SERVER['LC_ALL']`, `$_SERVER['LC_MESSAGES']`, then
     * `$_SERVER['LANG']`, falling back to `'en'`. Strips encoding
     * suffixes (`fr_FR.UTF-8` → `fr`) and lowercases the result.
     *
     * Useful as `T::setLocale(T::detect())` at app startup.
     */
    public static function detect(): string
    {
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $var) {
            $raw = $_SERVER[$var] ?? getenv($var) ?: null;
            if (is_string($raw) && $raw !== '' && $raw !== 'C' && $raw !== 'POSIX') {
                return self::normalize($raw);
            }
        }
        return 'en';
    }

    /**
     * Reset all internal state — registered namespaces, cached files, and
     * the active locale.
     *
     * Test-only hook; production code should never need this.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$namespaces = [];
        self::$cache      = [];
        self::$locale     = 'en';
    }

    /**
     * Look up a key inside a `(namespace, locale)` pair, loading the
     * underlying file lazily and returning `null` on miss.
     */
    private static function lookup(string $namespace, string $key, string $locale): ?string
    {
        if (!isset(self::$namespaces[$namespace])) {
            return null;
        }
        if (!isset(self::$cache[$namespace][$locale])) {
            self::$cache[$namespace][$locale] = self::load(self::$namespaces[$namespace], $locale);
        }
        return self::$cache[$namespace][$locale][$key] ?? null;
    }

    /**
     * Read and validate the lang file for a single locale.
     *
     * @return array<string, string>
     */
    private static function load(string $dir, string $locale): array
    {
        $path = $dir . DIRECTORY_SEPARATOR . $locale . '.php';
        if (!is_file($path)) {
            return [];
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = require $path;
        if (!is_array($data)) {
            return [];
        }
        // Coerce non-string values to strings rather than blowing up — a
        // bad lang file shouldn't take down a running program.
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $out[$k] = is_string($v) ? $v : (string) $v;
            }
        }
        return $out;
    }

    /**
     * Replace `{name}` placeholders in `$value` with values from `$params`.
     *
     * Unmatched placeholders are left literal so that a missing parameter
     * is obvious in the rendered output instead of vanishing.
     *
     * @param array<string, string|int|float> $params
     */
    private static function interpolate(string $value, array $params): string
    {
        if ($params === [] || !str_contains($value, '{')) {
            return $value;
        }
        $replacements = [];
        foreach ($params as $name => $val) {
            $replacements['{' . $name . '}'] = (string) $val;
        }
        return strtr($value, $replacements);
    }

    /**
     * Normalize a locale string from the environment to our canonical
     * `lang/<locale>.php` form: lowercase, dashes only, no encoding.
     */
    private static function normalize(string $raw): string
    {
        // Drop encoding suffix: "fr_FR.UTF-8@euro" → "fr_FR"
        $raw = preg_replace('/[.@].*$/', '', $raw) ?? $raw;
        $raw = strtolower(str_replace('_', '-', $raw));
        return $raw === '' ? 'en' : $raw;
    }
}
