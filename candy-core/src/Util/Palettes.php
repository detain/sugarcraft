<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Raw-hex single-source-of-truth for the named theme colour schemes that
 * SugarCraft consumer libs (candy-sprinkles, candy-freeze, candy-vt, …) all
 * draw from.
 *
 * Keys are the CANONICAL COLOUR NAMES of each scheme (Dracula's `pink`,
 * One Dark's `magenta`, …) rather than semantic slots like `accent` or
 * `primary`. That is deliberate: different consumers bind the SAME named
 * colour onto DIFFERENT semantic roles — candy-sprinkles maps Dracula `pink`
 * to `accent` and `purple` to `primary`, while another lib may wire the same
 * `pink` somewhere else. Keying on the scheme's own colour names keeps this
 * class a neutral palette that every consumer can re-map for itself, so the
 * hex literals live in exactly one place and stop drifting as they are
 * hand-copied around the monorepo.
 *
 * Values are plain lowercase `#rrggbb` strings on purpose — no dependency on
 * {@see Color} — so each consumer can wrap them however it needs (Color,
 * SGR bytes, a VT cell attribute, …) without this class dictating a type.
 */
final class Palettes
{
    public const DRACULA = [
        'background'  => '#282a36',
        'foreground'  => '#f8f8f2',
        'currentLine' => '#44475a', // sprinkles 'border'
        'comment'     => '#6272a4', // sprinkles 'muted' / freeze lineNumber
        'cyan'        => '#8be9fd',
        'green'       => '#50fa7b',
        'orange'      => '#ffb86c', // sprinkles 'warning'
        'pink'        => '#ff79c6', // sprinkles 'accent'
        'purple'      => '#bd93f9', // sprinkles 'primary'
        'red'         => '#ff5555',
        'yellow'      => '#f1fa8c',
        'separator'   => '#383a46', // sprinkles-only tint
    ];

    public const ONE_DARK = [
        'background'  => '#282c34',
        'foreground'  => '#abb2bf',
        'currentLine' => '#3e4451', // border
        'comment'     => '#5c6370', // muted
        'blue'        => '#61afef', // primary
        'green'       => '#98c379', // secondary/success
        'magenta'     => '#c678dd', // accent
        'red'         => '#e06c75',
        'yellow'      => '#e5c07b', // warning
        'cyan'        => '#56b6c2', // info
        'separator'   => '#2c313a',
        'cursor'      => '#528bff',
    ];

    public const GITHUB_DARK = [
        'background'  => '#0d1117',
        'foreground'  => '#c9d1d9',
        'currentLine' => '#30363d', // border
        'comment'     => '#8b949e', // muted
        'blue'        => '#58a6ff', // primary
        'green'       => '#3fb950', // secondary/success
        'pink'        => '#f778ba', // accent
        'red'         => '#f85149',
        'yellow'      => '#d29922', // warning
        'cyan'        => '#79c0ff', // info
        'separator'   => '#161b22',
    ];

    /**
     * Look up a single hex string by scheme + colour name.
     *
     * Optional convenience over the raw constants; throws rather than
     * returning null so a typo in a scheme or colour name fails loudly.
     *
     * @param string $scheme One of `dracula`, `one_dark`, `github_dark` (case-insensitive).
     * @param string $name   A colour-name key within that scheme.
     */
    public static function hex(string $scheme, string $name): string
    {
        $schemes = [
            'dracula'     => self::DRACULA,
            'one_dark'    => self::ONE_DARK,
            'github_dark' => self::GITHUB_DARK,
        ];

        $key = strtolower($scheme);
        if (!isset($schemes[$key])) {
            throw new \InvalidArgumentException("Unknown palette scheme: {$scheme}");
        }
        if (!isset($schemes[$key][$name])) {
            throw new \InvalidArgumentException("Unknown colour '{$name}' in scheme '{$scheme}'");
        }

        return $schemes[$key][$name];
    }
}
