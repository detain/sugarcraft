<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Codec;

/**
 * Shared escape-and-clip excerpt helper for the {@see Dcs}, {@see Iterm1337}
 * and {@see KittyApc} label summaries.
 *
 * All three codecs put attacker-controlled bytes into a one-line, printed
 * summary, and each had its own copy of the identical rule: clip to a byte
 * budget first (so the escape expansion cannot grow a label past its own
 * excerpt limit), then render bytes outside printable ASCII 0x20-0x7E as
 * `\xHH`, and mark a clipped excerpt with the trailing '…'. One home keeps
 * the three label shapes from drifting apart byte-wise.
 *
 * @internal Library-internal helper; not covered by semver promises.
 */
final class LabelSanitizer
{
    /**
     * @param int $limit Longest excerpt in bytes before clipping.
     */
    public static function clip(string $bytes, int $limit): string
    {
        $clip = \strlen($bytes) > $limit;
        if ($clip) {
            $bytes = substr($bytes, 0, $limit);
        }
        $out = '';
        $len = \strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $byte = \ord($bytes[$i]);
            $out .= ($byte < 0x20 || $byte > 0x7E) ? \sprintf('\\x%02X', $byte) : $bytes[$i];
        }

        return $clip ? $out . '…' : $out;
    }
}
