<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\ImageSource;

/**
 * Optional capability interface for renderers that can emit their bytes one
 * row (or frame) at a time instead of materialising the whole encode (D-05).
 *
 * The lib already encodes incrementally inside {@see SixelRenderer} — its
 * band stream and {@see SixelRenderer::encodeBandStream()} pull index rows
 * from a lazy generator and hand each 6-pixel band to a write callback as
 * soon as it exists — but that laziness was never reachable from the async
 * side: {@see \SugarCraft\Image\AsyncRenderer} implementations wrapped the
 * one-shot {@see Renderer::render()} call, so a large image froze the input
 * loop for the whole encode with no progress, no cancellation, and no budget
 * to interleave other work against.
 *
 * This interface exposes the row-at-a-time shape. {@see
 * \SugarCraft\Image\PacedAsyncRenderer} consumes it: it pumps the generator
 * against a Deadline SLICE budget, yielding the loop between slices so other
 * watchers run while the encode stays alive across ticks.
 *
 * Contract: concatenating every yielded chunk in order reproduces
 * {@see Renderer::render()} byte-for-byte for the same arguments. A chunk is
 * an append-order byte run, not necessarily a clean display line — pixel
 * protocols may yield protocol frames (Sixel bands, Kitty transmission
 * chunks) as their natural unit. Consumers must treat chunks as opaque.
 *
 * Why a capability interface instead of a required `Renderer` method: PHP
 * interfaces carry no default bodies, so declaring renderRows() on
 * {@see Renderer} would force an implementation into every backend plus the
 * tmux decorator — churn with no benefit to the renderers that cannot be
 * sliced (Chafa's child-process stream is a separate seam). Same posture as
 * the pre-existing optional {@see ConfigFingerprintProvider}: implementers
 * advertise what they can do, callers branch on `instanceof`.
 */
interface IncrementalRenderer
{
    /**
     * Encode the image lazily, yielding byte chunks in append order.
     *
     * Nothing runs before the first advance; each advance produces at most
     * the next chunk (heavy stages — decode, palette, resize — happen at the
     * first slice, exactly as in the one-shot path). Abandoning the generator
     * mid-stream leaves the bytes already yielded as the only output: a
     * truncated, never-terminated transmission. Callers that abandon should
     * do so only knowing the consumer's destination is going away too.
     *
     * @param ImageSource $image  Source image
     * @param int         $width  Target width in terminal cells
     * @param int|null    $height Target height in terminal cells
     *                            (null = auto from aspect ratio)
     * @return \Generator<int,string> append-order chunks whose concatenation
     *                               equals render()'s return value
     */
    public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator;
}
