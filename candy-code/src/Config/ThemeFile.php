<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use SugarCraft\Core\Util\Color;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Style\Theme as StyleTheme;

/**
 * One custom theme read from `~/.candy-code/themes/<name>.json`.
 *
 * The file shape is `{"extends": "dark", "tokens": {"border": "#ff0000"}}`:
 * an optional `extends` naming a BUILT-IN palette to start from, and an
 * optional `tokens` object overriding individual {@see StyleTheme} tokens by
 * `#rrggbb` colour. Everything the class refuses is collected as a note (or a
 * fatal `error`) rather than thrown — {@see \SugarCraft\Code\Theme::byName()}
 * turns those into launch notices and keeps painting, because a half-written
 * theme file is a cosmetic accident, not a reason to lose the terminal.
 *
 * The directory is the USER's alone: resolved from
 * {@see HomeDirectory::owned()}, never from the project root, and deliberately
 * NOT from `Bootstrap::configDirPath()` — that one follows `--config` to
 * wherever the operator points it, and a theme list is a property of the
 * person, not of this invocation. A project therefore can NAME a theme file
 * (the `theme` setting reaches it through the layered merge) but can never
 * supply its bytes; a name with no file in the owned home degrades to the
 * default, exactly as a typo does.
 *
 * Colours from a file are not trusted to be legible: they enter the app only
 * through `Theme::pair()`, which projects every token for contrast against
 * the terminal's real background like any built-in palette.
 */
final class ThemeFile
{
    /** Directory under the owned home holding the custom theme files. */
    public const SUBDIRECTORY = '.candy-code/themes';

    /** The token names a `tokens` object may override — the {@see StyleTheme} set. */
    public const TOKENS = [
        'foreground', 'background', 'primary', 'secondary', 'accent', 'muted',
        'error', 'warning', 'success', 'info', 'border', 'separator', 'cursor',
    ];

    /**
     * A theme name usable as a file stem: starts alphanumeric, then letters,
     * digits, dot, underscore, plus or hyphen. No slash is possible, so a
     * listed name can never traverse; a leading dot is impossible, so stray
     * hidden files and `..` are simply not names.
     */
    private const NAME_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N}._+-]*$/u';

    /** One token's colour: six-digit hex with the leading hash, either case. */
    private const COLOUR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** @var array<string, list<string>> discovered stems, memoised per directory */
    private static array $discovered = [];

    /** @var array<string, self|null> parse results, memoised per directory|stem */
    private static array $loaded = [];

    /**
     * @param array<string, string> $tokens validated token => `#rrggbb` overrides
     * @param list<string>          $notes  per-field refusals that cost one token, not the theme
     */
    private function __construct(
        public readonly string $name,
        public readonly string $extends,
        public readonly array $tokens,
        public readonly array $notes,
        public readonly ?string $error,
    ) {
    }

    /**
     * The directory custom themes load from, or null when no owned home can
     * be established. Null is the "no customs exist" answer everywhere it is
     * consumed — the app paints with built-ins and says nothing.
     */
    public static function directoryPath(): ?string
    {
        $home = HomeDirectory::owned();

        return $home === null ? null : $home . '/' . self::SUBDIRECTORY;
    }

    /**
     * The discovered theme stems, sorted, canonical spelling.
     *
     * A directory scan is one `scandir()` per process: {@see Theme::names()}
     * feeds the palette picker, which asks per frame, and `Chat::theme()`
     * resolves per paint. A file created or deleted mid-session is therefore
     * picked up at the next launch, not the next keystroke — the same
     * freshness contract the `disabledSkills`-style lists already keep.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $directory = self::directoryPath();
        if ($directory === null || !is_dir($directory)) {
            return [];
        }

        if (isset(self::$discovered[$directory])) {
            return self::$discovered[$directory];
        }

        $stems = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $stem = substr($entry, 0, -5);
            if ($stem !== '' && preg_match(self::NAME_PATTERN, $stem) === 1) {
                $stems[$stem] = true;
            }
        }

        $names = array_keys($stems);
        sort($names, SORT_STRING);

        return self::$discovered[$directory] = $names;
    }

    /** The case-folded canonical stem for a requested name, or null if none. */
    public static function resolve(string $requested): ?string
    {
        $folded = self::fold($requested);
        foreach (self::names() as $stem) {
            if (self::fold($stem) === $folded) {
                return $stem;
            }
        }

        return null;
    }

    public static function exists(string $requested): bool
    {
        return self::resolve($requested) !== null;
    }

    /**
     * Read and parse one theme file by canonical stem. Null means no file (the
     * caller treats that as "not a custom theme"); a present file always
     * returns an instance, possibly carrying an {@see self::$error}.
     */
    public static function load(string $stem): ?self
    {
        $directory = self::directoryPath();
        if ($directory === null) {
            return null;
        }

        $key = $directory . '|' . $stem;
        if (\array_key_exists($key, self::$loaded)) {
            return self::$loaded[$key];
        }

        $path = $directory . '/' . $stem . '.json';
        $root = realpath($directory);
        $real = realpath($path);
        if (!is_file($path) || $root === false || $real !== $root . '/' . $stem . '.json') {
            // The canonical path must BE the listed file in this directory: a
            // per-file symlink that walks out is refused, not followed. The
            // directory itself may legitimately be a link (an operator keeping
            // their themes elsewhere) because the listing the name came from is
            // the same tree realpath resolves to.
            return self::$loaded[$key] = null;
        }

        $bytes = @file_get_contents($path);

        return self::$loaded[$key] = self::parse($stem, $bytes);
    }

    /**
     * Apply a file's token overrides onto a base palette. Only validated
     * tokens are here, so every write is a legal {@see StyleTheme} value; the
     * list is fixed by {@see TOKENS} and matched explicitly — no dynamic
     * `with*` calls for the token scanners to miss.
     */
    public static function project(StyleTheme $base, array $tokens): StyleTheme
    {
        foreach ($tokens as $token => $hex) {
            $colour = Color::hex($hex);
            $base = match ($token) {
                'foreground' => $base->withForeground($colour),
                'background' => $base->withBackground($colour),
                'primary' => $base->withPrimary($colour),
                'secondary' => $base->withSecondary($colour),
                'accent' => $base->withAccent($colour),
                'muted' => $base->withMuted($colour),
                'error' => $base->withError($colour),
                'warning' => $base->withWarning($colour),
                'success' => $base->withSuccess($colour),
                'info' => $base->withInfo($colour),
                'border' => $base->withBorder($colour),
                'separator' => $base->withSeparator($colour),
                'cursor' => $base->withCursor($colour),
                default => $base,
            };
        }

        return $base;
    }

    /**
     * Drop every memo. A launch reads its files once; tests that move HOME
     * between cases (each with its own themes directory) reset through here.
     */
    public static function resetForTesting(): void
    {
        self::$discovered = [];
        self::$loaded = [];
    }

    /**
     * @param string|false $bytes what the read returned; false is the unreadable case
     */
    private static function parse(string $stem, string|false $bytes): self
    {
        if ($bytes === false) {
            return new self($stem, 'dark', [], [], 'the file could not be read');
        }

        $decoded = json_decode($bytes, true);
        if ($bytes === '') {
            return new self($stem, 'dark', [], [], 'the file is empty');
        }

        if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
            // `{}` decodes to [] and is a legal theme that simply overrides
            // nothing; `[1,2]` decodes to a non-empty list and is not an object.
            return new self($stem, 'dark', [], [], 'the file is not a JSON object');
        }

        $notes = [];

        $extends = 'dark';
        if (\array_key_exists('extends', $decoded)) {
            $claimed = $decoded['extends'];
            $base = \is_string($claimed) ? Theme::canonicalBaseName($claimed) : null;
            if ($base !== null) {
                $extends = $base;
            } else {
                $notes[] = sprintf("unknown extends '%s', starting from 'dark'", is_scalar($claimed) ? (string) $claimed : get_debug_type($claimed));
            }
        }

        $tokens = [];
        $rawTokens = $decoded['tokens'] ?? [];
        if (!\is_array($rawTokens) || ($rawTokens !== [] && \array_is_list($rawTokens))) {
            if (\array_key_exists('tokens', $decoded)) {
                $notes[] = sprintf("tokens is %s, ignored", \is_array($rawTokens) ? 'a list' : get_debug_type($rawTokens));
            }
        } else {
            foreach ($rawTokens as $token => $colour) {
                if (!\is_string($token) || !\in_array($token, self::TOKENS, true)) {
                    $notes[] = sprintf("unknown token '%s' ignored", \is_string($token) ? $token : get_debug_type($token));

                    continue;
                }

                if (!\is_string($colour) || preg_match(self::COLOUR_PATTERN, $colour) !== 1) {
                    $notes[] = sprintf("token '%s' needs a #rrggbb colour, %s ignored", $token, \is_scalar($colour) ? var_export($colour, true) : get_debug_type($colour));

                    continue;
                }

                $tokens[$token] = $colour;
            }
        }

        return new self($stem, $extends, $tokens, $notes, null);
    }

    private static function fold(string $name): string
    {
        return mb_strtolower($name);
    }
}
