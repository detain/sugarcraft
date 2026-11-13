<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use SugarCraft\Testing\Lang;

/**
 * Decode budgets shared by the graphics stream decoders.
 *
 * These decoders parse HOSTILE-ish input: captured terminal output that a
 * test feeds them verbatim. Without budgets, one crafted header turns a
 * test failure into a killed runner — a Sixel `"10000;10000` declaration
 * pre-allocates a 100-million-cell raster, and a small `o=z` Kitty payload
 * can inflate a thousand-fold (both measured). The budgets sit far above
 * anything a real terminal ever carried (cell graphics are small by
 * nature) and below anything that can exhaust a CI runner, and every
 * breach raises a {@see MalformedGraphicsException} — a loud test failure,
 * never a fatal.
 */
final class DecodeLimits
{
    /**
     * Maximum declared Sixel raster in cells (width × height). A 2000×2000
     * image — larger than any sixel capture in the fleet — is 4M cells.
     */
    public const MAX_RASTER_CELLS = 4_000_000;

    /**
     * Maximum digit run accepted by the Sixel numeric scanner. Beyond this
     * an (int) cast saturates silently, so the decoder refuses instead.
     */
    public const MAX_NUMBER_DIGITS = 9;

    /**
     * Maximum bytes an `o=z` Kitty transmit may inflate to. Matches the
     * sugar-image 64 MiB payload precedent; terminal PNGs are kilobytes.
     */
    public const MAX_DECOMPRESSED_BYTES = 67_108_864;

    /**
     * Maximum pixels (width × height) a materializable image may declare.
     * 4096×4096 bounds GD allocation well below any runner's memory limit.
     *
     * Scope, ruled not missed: this cap is sized for the GD raster alone
     * (truecolour is ~4 bytes per pixel, so the ceiling pins allocation at
     * tens of MiB). It does not bound a consumer that mirrors the raster
     * into PHP arrays — nested row structures cost an order of magnitude
     * more per pixel, and a 16.7M-pixel image decoded all the way to rows
     * would sit at ~1GB+. A companion MAX_MATERIALIZED_PIXELS (≈4M) is the
     * named follow-up for that rows path; harness callers today hand the
     * PNG to GD and stop at the raster, so the larger budget is not yet
     * needed in force here.
     */
    public const MAX_IMAGE_PIXELS = 16_777_216;

    /**
     * Header-only budget check to run BEFORE GD materializes an image.
     *
     * imagecreatefromstring() on a PNG declaring, say, 100000x100000 pixels
     * dies with a memory fatal rather than returning false — parsing the
     * header first turns the bomb into a loud, catchable refusal.
     *
     * @param string $png        the raw PNG payload
     * @param string $notImageKey Lang key raised when the bytes are no image at all
     *
     * @throws MalformedGraphicsException when unmaterializable or over budget
     */
    public static function assertMaterializablePng(string $png, string $notImageKey): void
    {
        $info = @\getimagesizefromstring($png);
        if ($info === false) {
            throw new MalformedGraphicsException(Lang::t($notImageKey));
        }
        if ($info[0] * $info[1] > self::MAX_IMAGE_PIXELS) {
            throw new MalformedGraphicsException(Lang::t('graphics.image_too_large', [
                'width' => $info[0],
                'height' => $info[1],
                'max' => self::MAX_IMAGE_PIXELS,
            ]));
        }
    }

    private function __construct()
    {
    }
}
