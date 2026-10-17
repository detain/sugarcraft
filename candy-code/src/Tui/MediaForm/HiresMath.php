<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * The §2.4 hires-fix target resolution, ported from A1111's
 * `images.calculate_target_resolution` (crush-media W3.5).
 *
 * Mirrors charmbracelet port lineage only in spirit — this one mirrors
 * stable-diffusion-webui processing.ImageData pre-processing. Three rules,
 * evaluated in order, exactly as the server computes them:
 *
 *  1. both resize dims zero/null → scale path: every dimension is
 *     floor(dim × hr_scale). (A1111 uses int(), which truncates toward zero
 *     for positive products — floor is the same thing here.)
 *  2. exactly one resize dim set → that dim is honoured and the OTHER axis
 *     follows the aspect: factor = setDim / originalDim, then BOTH dims are
 *     floored through it. The "set" dim itself can shift by a pixel when the
 *     factor does not divide evenly — that is upstream's arithmetic, kept
 *     verbatim so the preview matches what the server will actually render.
 *  3. both resize dims set → factor = max(x/W, y/H); both dims floor through
 *     it, guaranteeing the result covers the requested box — the server then
 *     center-crops the surplus. The client's job is only to send the numbers.
 *
 * NOT implemented: `use_old_hires_behavior` (an options-panel toggle that
 * replaces all three rules with a plain scale). It stays a documented
 * absence — the crush form speaks the modern path only.
 */
final class HiresMath
{
    /**
     * @return array{0: int, 1: int} [targetWidth, targetHeight]
     */
    public static function target(int $width, int $height, ?float $scale, ?int $resizeX, ?int $resizeY): array
    {
        $x = $resizeX ?? 0;
        $y = $resizeY ?? 0;

        if ($x === 0 && $y === 0) {
            // Rule 1 — pure scale (hr_scale defaults to 1.0 when unset, the
            // same fallback A1111 applies for a missing field).
            $factor = $scale ?? 1.0;

            return [self::floorProduct($width, $factor), self::floorProduct($height, $factor)];
        }

        if ($x === 0 || $y === 0) {
            // Rule 2 — one dim set, aspect-preserve on the other.
            $base = $x === 0 ? $height : $width;
            $set = $x === 0 ? $y : $x;
            $factor = $set / max(1, $base);

            return [self::floorProduct($width, $factor), self::floorProduct($height, $factor)];
        }

        // Rule 3 — both set: at-least-the-box, crop happens server-side.
        $factor = max($x / max(1, $width), $y / max(1, $height));

        return [self::floorProduct($width, $factor), self::floorProduct($height, $factor)];
    }

    private static function floorProduct(int $dimension, float $factor): int
    {
        return (int) floor($dimension * $factor);
    }
}
