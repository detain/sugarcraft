<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Options for the Kitty graphics protocol renderer.
 *
 * @experimental Upstream marks z-index and virtual images as experimental.
 */
final class KittyOptions
{
    /**
     * Action: "T" = transmit, "p" = place a previously transmitted image.
     */
    private const ACTION_TRANSMIT = 'T';
    private const ACTION_PLACE    = 'p';

    private function __construct(
        private readonly string $action,
        private readonly int $imageId,
        private readonly int $zIndex,
        private readonly int $compress,
        private readonly int $cellWidth,
        private readonly int $cellHeight,
        private readonly int $offsetX,
        private readonly int $offsetY,
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
        );
    }

    /**
     * Set compression: 100 = none, 1 = zlib.
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
        );
    }

    /** @internal */
    public function toArray(): array
    {
        return [
            'a' => $this->action,
            'i' => $this->imageId !== 0 ? $this->imageId : null,
            'z' => $this->zIndex !== 0 ? $this->zIndex : null,
            'f' => $this->compress !== 100 ? $this->compress : null,
            's' => $this->cellWidth ?: null,
            'v' => $this->cellHeight ?: null,
            'x' => $this->offsetX ?: null,
            'y' => $this->offsetY ?: null,
        ];
    }

    /** @internal */
    public function isPlace(): bool
    {
        return $this->action === self::ACTION_PLACE;
    }
}
