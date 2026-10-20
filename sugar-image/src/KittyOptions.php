<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Options for the Kitty graphics protocol renderer.
 */
final class KittyOptions
{
    /**
     * Action: "T" = transmit, "p" = place a previously transmitted image.
     */
    private const ACTION_TRANSMIT = 'T';
    private const ACTION_PLACE    = 'p';

    /**
     * F-11: the kitty graphics protocol caps any single APC frame at 4 KiB —
     * a longer frame is truncated by the terminal, corrupting the image.
     */
    public const MAX_FRAME_BYTES = 4096;

    /**
     * F-11: default raw (pre-base64) payload bytes per data-chunk frame.
     * intdiv(4096 - 9 - 128, 4) * 3 = 2967: 9 is the data-chunk envelope
     * (APC 2 + 'G' 1 + 'm=1' 3 + ';' 1 + ST 2), 128 is reserved for
     * worst-case co-located parameters, and the result is floored to a
     * multiple of 3 so every chunk base64-encodes without '=' padding
     * (only the final chunk ever pads). Worst emitted data frame is
     * 9 + 4*ceil(2967/3) = 3965 bytes — 131 bytes of margin, above the
     * 16-byte floor F-11 requires. Mirrors chafa's raw-byte chunking in
     * chafa/internal/chafa-kitty-renderer.c build_image_chunks().
     */
    public const DEFAULT_CHUNK_BYTES = 2967;

    /**
     * F-11: largest legal override: intdiv(4096 - 9, 4) * 3 — the envelope
     * only, no parameter margin (frame stays under the cap by 3 bytes).
     */
    public const MAX_CHUNK_BYTES = 3063;

    private function __construct(
        private readonly string $action,
        private readonly int $imageId,
        private readonly int $zIndex,
        private readonly int $compress,
        private readonly int $cellWidth,
        private readonly int $cellHeight,
        private readonly int $offsetX,
        private readonly int $offsetY,
        /**
         * When true, renders with action 'p' (place virtual image) instead
         * of 'T' (transmit). On first render with a given image id this stores
         * the image in the terminal; subsequent renders with the same id+offset
         * reference the stored copy.
         *
         * @see https://sw.kovidgoyal.net/kitty/graphics-protocol/#transmission
         */
        private readonly bool $useVirtual,
        /**
         * F-11: raw payload bytes per data-chunk frame, already normalized to
         * a multiple of 3; null means {@see self::DEFAULT_CHUNK_BYTES}.
         */
        private readonly ?int $chunkBytes,
    ) {}

    /**
     * Default options: transmit image inline.
     */
    public static function transmit(int $imageId = 0): self
    {
        return new self(
            action:     self::ACTION_TRANSMIT,
            imageId:    $imageId,
            zIndex:     0,
            compress:   100,
            cellWidth:  0,
            cellHeight: 0,
            offsetX:    0,
            offsetY:    0,
            useVirtual: false,
            chunkBytes: null,
        );
    }

    /**
     * Place a previously transmitted virtual image.
     *
     * @param int $imageId  The ID of the image to place (from a prior transmit with id=)
     * @param int $x        Cell offset from left (columns)
     * @param int $y        Cell offset from top (rows)
     */
    public static function place(int $imageId, int $x = 0, int $y = 0): self
    {
        return new self(
            action:     self::ACTION_PLACE,
            imageId:    $imageId,
            zIndex:     0,
            compress:   100,
            cellWidth:  0,
            cellHeight: 0,
            offsetX:    $x,
            offsetY:    $y,
            useVirtual: false,
            chunkBytes: null,
        );
    }

    /**
     * Set z-index (stacking order, higher renders on top).
     */
    public function withZIndex(int $z): self
    {
        return new self(
            action:     $this->action,
            imageId:    $this->imageId,
            zIndex:     $z,
            compress:   $this->compress,
            cellWidth:  $this->cellWidth,
            cellHeight: $this->cellHeight,
            offsetX:    $this->offsetX,
            offsetY:    $this->offsetY,
            useVirtual: $this->useVirtual,
            chunkBytes: $this->chunkBytes,
        );
    }

    /**
     * Request zlib compression for the PNG payload.
     *
     * Per the kitty graphics protocol, `f` is the DATA FORMAT (100 = PNG)
     * and transmission compression travels on the separate `o` key — so a
     * compressed transmit is emitted as `f=100,o=z`. (Until M2/round-LL
     * this pair wrongly spelled compression as `f=1`; the sugar-testing
     * KittyStream decoder flipped in the same change.)
     *
     * @param int $compress  100 = none (default), 1 = zlib compressed (o=z).
     *                        Any other value is inert: the wire carries no
     *                        compression LEVEL, only the zlib (o=z) signal,
     *                        and emitting `f=N` for N outside the spec's
     *                        format table would mislabel the payload.
     *                        zlib is worthwhile for large images on slow links.
     */
    public function withCompression(int $compress): self
    {
        return new self(
            action:     $this->action,
            imageId:    $this->imageId,
            zIndex:     $this->zIndex,
            compress:   $compress,
            cellWidth:  $this->cellWidth,
            cellHeight: $this->cellHeight,
            offsetX:    $this->offsetX,
            offsetY:    $this->offsetY,
            useVirtual: $this->useVirtual,
            chunkBytes: $this->chunkBytes,
        );
    }

    /**
     * Enable virtual-image placement mode (a=p instead of a=T).
     */
    public function withUseVirtual(bool $useVirtual = true): self
    {
        return new self(
            action:     $this->action,
            imageId:    $this->imageId,
            zIndex:     $this->zIndex,
            compress:   $this->compress,
            cellWidth:  $this->cellWidth,
            cellHeight: $this->cellHeight,
            offsetX:    $this->offsetX,
            offsetY:    $this->offsetY,
            useVirtual: $useVirtual,
            chunkBytes: $this->chunkBytes,
        );
    }

    /**
     * Set the source display area in cells: the kitty `s=` (columns) and
     * `v=` (rows) transmit params.
     *
     * These size the region the terminal maps the image onto. They were
     * carried on every instance but had no setter, so the fields stayed 0 and
     * `s=`/`v=` were never emitted; this is the seam that makes them settable.
     *
     * @param int $width   Cell columns (`s=`); 0 clears (terminal default).
     * @param int $height  Cell rows (`v=`); 0 clears (terminal default).
     * @throws \InvalidArgumentException  if either dimension is negative
     */
    public function withCellDimensions(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(
                "Cell dimensions must be non-negative, got {$width}×{$height}"
            );
        }

        return new self(
            action:     $this->action,
            imageId:    $this->imageId,
            zIndex:     $this->zIndex,
            compress:   $this->compress,
            cellWidth:  $width,
            cellHeight: $height,
            offsetX:    $this->offsetX,
            offsetY:    $this->offsetY,
            useVirtual: $this->useVirtual,
            chunkBytes: $this->chunkBytes,
        );
    }

    /**
     * F-11: override the raw payload bytes carried per data-chunk frame.
     *
     * The transport surface is this options object; wiring the value from
     * runtime terminal capability (chafa's 63-byte Screen packets are that
     * consumer — F-12) is a later seam, not part of F-11.
     *
     * @param int|null $chunkBytes  null restores {@see self::DEFAULT_CHUNK_BYTES};
     *                              overrides are floored to a multiple of 3 so
     *                              chunk boundaries never introduce base64 padding.
     * @throws \InvalidArgumentException  if the override is below one base64
     *                              group (3 bytes) or would push a data-chunk
     *                              frame past the 4 KiB spec cap.
     */
    public function withChunkBytes(?int $chunkBytes): self
    {
        if ($chunkBytes === null) {
            $normalized = null;
        } elseif ($chunkBytes < 3 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new \InvalidArgumentException(
                "Kitty chunk size must be between 3 and " . self::MAX_CHUNK_BYTES . " bytes, got {$chunkBytes}"
            );
        } else {
            $normalized = $chunkBytes - ($chunkBytes % 3);
        }

        return new self(
            action:     $this->action,
            imageId:    $this->imageId,
            zIndex:     $this->zIndex,
            compress:   $this->compress,
            cellWidth:  $this->cellWidth,
            cellHeight: $this->cellHeight,
            offsetX:    $this->offsetX,
            offsetY:    $this->offsetY,
            useVirtual: $this->useVirtual,
            chunkBytes: $normalized,
        );
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'a' => $this->action,
            'i' => $this->imageId !== 0 ? $this->imageId : null,
            'z' => $this->zIndex !== 0 ? $this->zIndex : null,
            // 'o' is the spec's transmission-compression key; 'f' stays the
            // data format and is emitted by the renderer as a constant 100.
            'o' => $this->isCompressed() ? 'z' : null,
            's' => $this->cellWidth ?: null,
            'v' => $this->cellHeight ?: null,
            'x' => $this->offsetX ?: null,
            'y' => $this->offsetY ?: null,
        ];
    }

    /** @internal */
    public function isCompressed(): bool
    {
        return $this->compress === 1;
    }

    /** @internal */
    public function isPlace(): bool
    {
        return $this->action === self::ACTION_PLACE;
    }

    /** @internal */
    public function useVirtual(): bool
    {
        return $this->useVirtual;
    }

    /** @internal */
    public function chunkBytes(): ?int
    {
        return $this->chunkBytes;
    }
}
