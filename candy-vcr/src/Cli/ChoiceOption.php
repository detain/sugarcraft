<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use InvalidArgumentException;
use SugarCraft\Vcr\Lang;

/**
 * Parses a flagged choice option against its closed set of known values.
 *
 * Carries no tool prefix (see FpsOption for the message law). Before this
 * class the renderer options accepted anything: a misspelling was silently
 * COERCED to the default, so `--backend=imagickk` quietly rendered through
 * gd and `--encoder=ffmpe` quietly encoded through ffmpeg — the run looked
 * fine and was not the run asked for. A known-value list is therefore part
 * of the contract: refuse the typo, name every accepted spelling, keep the
 * absent-flag default.
 */
final class ChoiceOption
{
    /**
     * @param list<string> $known the closed set of accepted values
     * @param string $default the value an absent flag takes (must be in $known)
     *
     * @throws InvalidArgumentException when the spelling is not one of $known
     */
    public static function parse(mixed $raw, string $flag, array $known, string $default): string
    {
        // An absent flag, a bare `--backend=`, a false/[] option shape all
        // mean "not supplied" and take the documented default; anything
        // else must be a member of the closed set.
        if ($raw === null || $raw === false || $raw === '' || (is_array($raw) && $raw === [])) {
            return $default;
        }

        if (is_string($raw)) {
            if (in_array($raw, $known, true)) {
                return $raw;
            }

            throw new InvalidArgumentException(Lang::t('choice.invalid', [
                'flag' => $flag,
                'known' => implode(', ', $known),
                'got' => $raw,
            ]));
        }

        throw new InvalidArgumentException(Lang::t('choice.invalid_type', [
            'flag' => $flag,
            'known' => implode(', ', $known),
            'got' => get_debug_type($raw),
        ]));
    }
}
