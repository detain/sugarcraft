<?php

declare(strict_types=1);

/**
 * candy-tetris/src/Support/Paths.php
 *
 * The per-user config slots under the candy-* XDG convention — the same
 * shape candy-minesweeper's savegame uses (resolved from the environment at
 * call time, NEVER cached: a process that learned its HOME after boot should
 * still find its files, and tests sandbox HOME per test).
 */
namespace SugarCraft\Tetris\Support;

final class Paths
{
    private const APP_DIR = 'candy-tetris';

    /**
     * Absolute path of the key-remap file, or null when the environment
     * names no home (then there is simply no user table).
     */
    public static function keys(): ?string
    {
        return self::dir() === null ? null : self::dir() . '/keys.json';
    }

    /**
     * Absolute path of the high-score/onboarding store (the scoring record
     * and the onboarding flag share one file, so one corrupt file costs one
     * honest notice).
     */
    public static function scores(): ?string
    {
        return self::dir() === null ? null : self::dir() . '/scores.json';
    }

    private static function dir(): ?string
    {
        // Only absolute values are honoured: the XDG spec defines a relative
        // $XDG_CONFIG_HOME as invalid (it would resolve differently from every
        // working directory, so the same user's config would depend on where
        // the game was launched), and a relative $HOME is no home at all.
        // Ignoring such values answers "no user table" — the same door that
        // an unset environment already closes.
        $xdg = getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '' && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/' . self::APP_DIR;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '' || !str_starts_with($home, '/')) {
            return null;
        }

        return rtrim($home, '/') . '/.config/' . self::APP_DIR;
    }
}
