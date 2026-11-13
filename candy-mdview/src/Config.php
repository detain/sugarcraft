<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * The persisted-preferences file: `mdview.json` under the XDG config home
 * (glow keeps `~/.config/glow/glow.yml`; this port uses JSON to avoid a
 * YAML dependency). Every setting the CLI takes as a flag or environment
 * fact can live here instead — theme, style, width, pager, keymap — and
 * the resolution precedence is flag > environment > file > default.
 *
 * The file is strictly optional: no file, no opinions. A file that IS
 * present must be honest — a regular file within the size cap, decoding to
 * a JSON object, carrying only known keys with well-typed values. Anything
 * else refuses loudly naming the path: silently ignoring a typo'd key is
 * how a persisted preference dies (finding 036's complaint about glow
 * migrants was that NOTHING they wrote was read).
 */
final class Config
{
    /** A preferences file is a few hundred bytes; 64 KiB is generous. */
    public const MAX_BYTES = 65_536;

    /** The only top-level keys the reader knows; anything else is a refusal. */
    public const KNOWN_KEYS = ['theme', 'style', 'width', 'pager', 'keymap'];

    private function __construct(
        public readonly ?string $theme = null,
        public readonly ?string $style = null,
        public readonly ?int $width = null,
        public readonly ?bool $pager = null,
        /** @var array<string,string> remapped chords, action => chord */
        public readonly array $keymap = [],
        public readonly ?string $path = null,
    ) {
    }

    /** The empty config — every lookup answers "no position". */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Resolve the file location: MDVIEW_CONFIG names it directly (the test
     * seam and the `--config`-equivalent door); an explicitly EMPTY value
     * disables file lookup entirely — the hermetic escape the suite uses.
     * Otherwise $XDG_CONFIG_HOME or ~/.config, under `mdview/mdview.json`.
     * Returns null when even HOME is unknown — there is simply no file to
     * consult.
     */
    public static function defaultPath(): ?string
    {
        $direct = self::env('MDVIEW_CONFIG');
        if ($direct !== null) {
            return $direct === '' ? null : $direct;
        }
        $base = self::env('XDG_CONFIG_HOME');
        if ($base === null || $base === '') {
            $home = self::env('HOME');
            if ($home === null || $home === '') {
                return null;
            }
            $base = $home . '/.config';
        }
        return $base . '/mdview/mdview.json';
    }

    /**
     * Load the file at $path (or the default location when null). Absent
     * files and absent directories yield the empty config; a present file
     * is held to the door law (regular file, capped, JSON object, known
     * keys, typed values) or the run refuses with exit code 2.
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();
        if ($path === null || is_file($path) === false) {
            if ($path !== null && (is_dir($path) || file_exists($path))) {
                // The user pointed at something that is NOT a regular file:
                // same named refusal the theme-config door practices —
                // directories and FIFOs must never look like "no config".
                throw new \InvalidArgumentException(Lang::t('render.config_unreadable', ['path' => $path]));
            }
            return self::empty();
        }

        $raw = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw new \InvalidArgumentException(Lang::t('render.config_unreadable', ['path' => $path]));
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(Lang::t('render.config_too_large', [
                'path'  => $path,
                'limit' => self::MAX_BYTES,
            ]));
        }

        $decoded = json_decode($raw, associative: true);
        // An empty object is a legal (empty) config; a JSON LIST is not —
        // its integer keys would sail past the unknown-key gate below.
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new \InvalidArgumentException(Lang::t('render.config_invalid', [
                'path'  => $path,
                'error' => json_last_error_msg(),
            ]));
        }

        $unknown = array_diff(array_keys($decoded), self::KNOWN_KEYS);
        if ($unknown !== []) {
            sort($unknown);
            throw new \InvalidArgumentException(Lang::t('render.config_unknown_key', [
                'keys' => implode(', ', $unknown),
                'path' => $path,
            ]));
        }

        return new self(
            theme:  self::stringOrNull($decoded, 'theme', $path),
            style:  self::stringOrNull($decoded, 'style', $path),
            width:  self::widthOrNull($decoded, $path),
            pager:  self::boolOrNull($decoded, 'pager', $path),
            keymap: self::keymap($decoded, $path),
            path:   $path,
        );
    }

    /** @param array<string,mixed> $decoded */
    private static function stringOrNull(array $decoded, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $decoded)) {
            return null;
        }
        $value = $decoded[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(Lang::t('render.config_bad_value', [
                'key'      => $key,
                'expected' => 'a non-empty string',
                'path'     => $path,
            ]));
        }
        return $value;
    }

    /** @param array<string,mixed> $decoded */
    private static function widthOrNull(array $decoded, string $path): ?int
    {
        if (!array_key_exists('width', $decoded)) {
            return null;
        }
        $value = $decoded['width'];
        // 0 means the EXPLICIT no-wrap opt-out (same three-state contract
        // the flag keeps after S11); negative or absurd is a typed refusal.
        if (!is_int($value) || $value < 0 || $value > 9999) {
            throw new \InvalidArgumentException(Lang::t('render.config_bad_value', [
                'key'      => 'width',
                'expected' => 'an integer 0-9999',
                'path'     => $path,
            ]));
        }
        return $value;
    }

    /** @param array<string,mixed> $decoded */
    private static function boolOrNull(array $decoded, string $key, string $path): ?bool
    {
        if (!array_key_exists($key, $decoded)) {
            return null;
        }
        $value = $decoded[$key];
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(Lang::t('render.config_bad_value', [
                'key'      => $key,
                'expected' => 'a boolean',
                'path'     => $path,
            ]));
        }
        return $value;
    }

    /**
     * The keymap block: action => chord, chords spelled `x`, `ctrl-x` or
     * `alt-x`. Only the actions this model owns are remappable; anything
     * else refuses by name (a silent no-op remap is finding 036 again).
     *
     * @param array<string,mixed> $decoded
     * @return array<string,string>
     */
    private static function keymap(array $decoded, string $path): array
    {
        if (!array_key_exists('keymap', $decoded)) {
            return [];
        }
        $block = $decoded['keymap'];
        if (!is_array($block) || (array_is_list($block) && $block !== [])) {
            throw new \InvalidArgumentException(Lang::t('render.config_bad_value', [
                'key'      => 'keymap',
                'expected' => 'an object of action: chord pairs',
                'path'     => $path,
            ]));
        }
        $known = array_keys(MdViewModel::DEFAULT_KEYMAP);
        $map   = [];
        foreach ($block as $action => $chord) {
            if (!is_string($action) || !in_array($action, $known, true)) {
                throw new \InvalidArgumentException(Lang::t('render.config_unknown_key', [
                    'keys' => 'keymap.' . (is_string($action) ? $action : gettype($action)),
                    'path' => $path,
                ]));
            }
            if (!is_string($chord) || preg_match('/^(?:ctrl-|alt-)?[a-z]$/', $chord) !== 1) {
                throw new \InvalidArgumentException(Lang::t('render.config_bad_value', [
                    'key'      => 'keymap.' . $action,
                    'expected' => 'a chord like "x", "ctrl-x" or "alt-x"',
                    'path'     => $path,
                ]));
            }
            $map[$action] = $chord;
        }
        return $map;
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? getenv($key);
        return is_string($value) ? $value : null;
    }
}
