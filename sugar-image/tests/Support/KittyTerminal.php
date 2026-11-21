<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Support;

use SugarCraft\Image\Detect;

/**
 * A stand-in for a terminal that speaks the Kitty graphics protocol (P1-13b).
 *
 * It answers the APC `a=q` query for image 31 with an OK reply, then the DA1
 * answer that closes the reply window. Detect::probe() confirms a Kitty
 * environment claim only through that reply, so a test that expects the claim
 * to stand must answer the query the way a real Kitty terminal would.
 */
final class KittyTerminal
{
    public const OK_REPLY  = "\x1b_Gi=31;OK\x1b\\";
    public const DA1_REPLY = "\x1b[?62;4c";

    /**
     * Route the probe's stdin and stdout through in-memory streams and answer
     * the Kitty query. $extra is appended after the DA1 answer, where a later
     * stage (the font-size query, for instance) reads it.
     *
     * The caller clears the seam with {@see clear()}.
     */
    public static function answer(string $extra = ''): void
    {
        $in = fopen('php://temp', 'r+b');
        fwrite($in, self::OK_REPLY . self::DA1_REPLY . $extra);
        rewind($in);
        Detect::setProbeStdin($in);
        Detect::setProbeStdout(fopen('php://temp', 'r+b'));
    }

    public static function clear(): void
    {
        Detect::setProbeStdin(null);
        Detect::setProbeStdout(null);
    }
}
