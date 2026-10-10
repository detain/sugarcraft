<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Video;

use SugarCraft\Crush\Media\Capability\EndpointFamily;

/**
 * The video frame-count ladder (plan_crush_media §6.2, W5.3): a diffusion
 * video model only accepts frame counts of the form 8k+1, and each recorded
 * model class publishes a closed set of rungs — Wan 81/121, LTX 121/193/257
 * (every one of them ≡ 1 mod 8). The "snap slider to the valid ladder" law is
 * a CLIENT-side coercion: the server does not round for you, it rejects.
 *
 * SNAP DIRECTION IS UP. {@see snap()} coalesces to the nearest valid rung
 * GREATER THAN OR EQUAL to the request and reports the difference as an
 * informational line — silently shortening a user's shot would be the louder
 * lie. Past the top of a recorded ladder there is no rung to snap to, so the
 * request is refused rather than clamped: growing a generation past the
 * contract's recorded ceiling is a decision the caller has to make on
 * purpose, not one a ladder sneaks in.
 *
 * Families without a recorded rung set fall back to the bare 8k+1 stride law
 * with no ceiling; in practice the capability door in the tool only lets a
 * family with a video job contract reach this code at all.
 */
final class FrameLadder
{
    /** Temporal VAE stride: valid counts are exactly 8k+1. */
    public const STRIDE = 8;

    /**
     * Rung sets recorded from live model contracts (plan §6.2): every rung is
     * 8k+1 and the top rung is the ceiling we refuse to snap past.
     *
     * Keyed by the EndpointFamily WIRE STRING — PHP const arrays cannot key on
     * enum cases (TypeError, measured 8.3, W1-era law).
     *
     * @var array<string, list<int>>
     */
    public const LADDERS = [
        // Wan2.2 (81/121) and LTX-Video (121/193/257) rungs, unified: the
        // sglang-diffusion family fronts both model classes on one /v1/videos
        // route, so the contract records the union (mystage §7 field rows).
        'sglang-diffusion' => [81, 121, 193, 257],
    ];

    private function __construct(
        /** The frame count as the caller asked for it, before any snapping. */
        public readonly int $requested,
        /** The rung actually sent: `requested` itself when it was already valid. */
        public readonly int $frames,
        /** `frames - requested` — zero when the request sat on a rung. */
        public readonly int $diff,
        /** The informational line for the result text, null when nothing moved. */
        public readonly ?string $note,
    ) {
    }

    /**
     * Snap a requested frame count up to the family's ladder.
     *
     * @throws \InvalidArgumentException when the request is below 1 or sits
     *         above the top rung of a recorded ladder.
     */
    public static function snap(int $requested, EndpointFamily $family): self
    {
        if ($requested < 1) {
            throw new \InvalidArgumentException('a video frame count must be at least 1, got ' . $requested);
        }

        $ladder = self::LADDERS[$family->value] ?? null;
        if ($ladder === null) {
            return self::strideOnly($requested);
        }

        foreach ($ladder as $rung) {
            if ($rung >= $requested) {
                return self::of($requested, $rung, $family->value . ' ladder (' . implode('/', $ladder) . ')');
            }
        }

        $top = $ladder[\count($ladder) - 1];
        throw new \InvalidArgumentException(
            'the ' . $family->value . ' frame ladder tops out at ' . $top . ' frames and '
                . $requested . ' is above it; no rung exists to snap up to — the request is refused, not clamped',
        );
    }

    /** Bare 8k+1 stride law for a family with no recorded rung set. */
    private static function strideOnly(int $requested): self
    {
        $remainder = ($requested - 1) % self::STRIDE;
        $frames = $remainder === 0 ? $requested : $requested + (self::STRIDE - $remainder);

        return self::of($requested, $frames, '8k+1 stride');
    }

    private static function of(int $requested, int $frames, string $reason): self
    {
        $diff = $frames - $requested;
        $note = $diff === 0
            ? null
            : 'num_frames ' . $requested . ' is not on the ' . $reason . '; it was snapped up to '
                . $frames . ' (+' . $diff . ')';

        return new self($requested, $frames, $diff, $note);
    }
}
