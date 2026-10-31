<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use SugarCraft\Code\Lang;

/**
 * C-TUI-6.f: the `server.strings` bundle — the web UI's fixed chrome, read
 * out of the installation's own language catalogues.
 *
 * The owner ruling scopes this class: chrome/UI strings ONLY. Server-side,
 * tool and model text stay untranslated and never enter a bundle; the
 * `web.` prefix is the whole contract, enforced here at the choke so a
 * future `cli.` or `tool.` key cannot leak onto the wire by accident.
 *
 * Containment law (mirrors {@see Lang}'s catalogue discipline): a requested
 * tag is NEVER spliced into a path. The catalogue set is enumerated once per
 * directory from the installation's `lang/` listing, filtered to the locale
 * grammar, and a request can only SELECT among those names — exactly the
 * ladder `Lang` itself walks (exact → base-before-dash → `en`). Paths that
 * reach `require` therefore originate from the shipped installation, not
 * from request content.
 */
final class WebStrings
{
    /**
     * The grammar a locale tag must satisfy to name a catalogue — the same
     * shape {@see Lang::useLaunchLocale()} sanitizes the launch env down to.
     * It rejects every path-ish spelling outright: slashes, dots, `..`.
     */
    public const TAG_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/';

    /**
     * The prefix a key must carry to travel to the browser. Nothing else in
     * `lang/en.php` is ever served (ruling: chrome only).
     */
    public const PREFIX = 'web.';

    /** @var array<string, array<string, string>> per "dir|served-tag", filtered web-chrome map */
    private static array $memo = [];

    /** @var array<string, array<string, string>> per dir, catalogue tag => absolute path */
    private static array $catalogues = [];

    private function __construct()
    {
    }

    /**
     * Normalize a request the way the launch locale is normalized, then
     * resolve the catalogue the ladder would serve. Returns the catalogue
     * tag actually chosen (what the wire echoes as `locale`).
     *
     * @throws \InvalidArgumentException when the tag is not a locale name at
     *                                   all — `../x`, `en/../../y`, or any
     *                                   spelling the grammar refuses.
     */
    public static function negotiate(string $tag, ?string $dir = null): string
    {
        $dir ??= \dirname(__DIR__, 2) . '/lang';
        $candidates = self::candidates($tag);

        return self::ladder($candidates, $dir);
    }

    /**
     * The chrome bundle for a requested tag: only `web.`-prefixed keys of
     * the catalogue the ladder selects, placeholders (`{n}`, `{lastError}`)
     * passed through raw for the client to interpolate.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException on a malformed tag (see {@see negotiate()}).
     */
    public static function for(string $tag, ?string $dir = null): array
    {
        $dir ??= \dirname(__DIR__, 2) . '/lang';
        $served = self::ladder(self::candidates($tag), $dir);
        $key = $dir . '|' . $served;

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $paths = self::cataloguePaths($dir);
        $strings = [];
        if (isset($paths[$served])) {
            /** @var array<string, mixed> $catalogue */
            $catalogue = require $paths[$served];
            foreach ($catalogue as $name => $value) {
                if (\is_string($value) && \str_starts_with((string) $name, self::PREFIX)) {
                    $strings[(string) $name] = $value;
                }
            }
            \ksort($strings);
        }

        return self::$memo[$key] = $strings;
    }

    /**
     * The requested tag reduced to the ladder's candidate list: its own
     * spelling, then the base before the first dash. `''` asks for nothing
     * in particular and means the server default, English.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function candidates(string $tag): array
    {
        $normalized = self::sanitize($tag);
        if ($normalized === 'en') {
            return ['en'];
        }
        $base = \explode('-', $normalized, 2)[0];

        return $base === $normalized ? [$normalized] : [$normalized, $base];
    }

    /**
     * Lowercase, `_` to `-`, and drop any `@`/`.` modifier tail — the same
     * normalization {@see Lang::useLaunchLocale()} applies to the env locale;
     * `c`/`posix` (the "no locale set" spellings) mean English, as there.
     *
     * @throws \InvalidArgumentException
     */
    private static function sanitize(string $tag): string
    {
        $tag = \trim($tag);
        if ($tag === '') {
            return 'en';
        }
        $tag = \mb_strtolower(\str_replace('_', '-', $tag));
        $tag = (string) \preg_replace('/[.@].*$/', '', $tag);
        if ($tag === 'c' || $tag === 'posix') {
            return 'en';
        }
        if (\preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a locale tag', $tag));
        }

        return $tag;
    }

    /**
     * Pick the first candidate with a shipped catalogue, English as the
     * floor. Only names the installation itself lists can win.
     *
     * @param list<string> $candidates
     */
    private static function ladder(array $candidates, string $dir): string
    {
        $paths = self::cataloguePaths($dir);
        foreach ($candidates as $candidate) {
            if (isset($paths[$candidate])) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * Enumerate this installation's catalogues: the directory listing, names
     * that are exactly `<locale>.php` under the tag grammar. The listing is
     * memoized per directory — the shipped language set does not move under
     * a running server, and a request never consults it for anything but a
     * selection among these names.
     *
     * @return array<string, string> catalogue tag => absolute path
     */
    private static function cataloguePaths(string $dir): array
    {
        if (isset(self::$catalogues[$dir])) {
            return self::$catalogues[$dir];
        }

        $paths = [];
        $entries = \scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if (!\is_string($entry) || !\str_ends_with($entry, '.php')) {
                    continue;
                }
                $tag = \substr($entry, 0, -4);
                if ($tag !== '' && \preg_match(self::TAG_PATTERN, $tag) === 1) {
                    $paths[$tag] = $dir . '/' . $entry;
                }
            }
        }

        return self::$catalogues[$dir] = $paths;
    }

    /**
     * The generated `docs/protocol/web-strings.en.json` bytes: the English
     * chrome map, keys sorted, pretty JSON, no escaped slashes or unicode.
     * The generator script and WebStringsDriftTest both render through THIS
     * method — one derivation, exactly how gen-protocol-schema.php shares
     * {@see \SugarCraft\Code\Protocol\Schema\ProtocolSchema} with its test.
     */
    public static function englishBundleJson(): string
    {
        $strings = self::for('en');
        /** @var string $json */
        $json = \json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json . "\n";
    }

    /** Drop the memos (tests that plant their own catalogue dirs). */
    public static function resetForTesting(): void
    {
        self::$memo = [];
        self::$catalogues = [];
    }
}
