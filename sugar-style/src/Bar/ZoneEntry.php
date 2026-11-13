<?php

declare(strict_types=1);

namespace SugarCraft\Style\Bar;

/**
 * The unit a {@see ZoneRow} places or drops whole: an ordered run of
 * {@see ZonePiece}s (a tab body and its close mark, say) that are kept or
 * collapsed together. Pieces are concatenated with no separator, so any gap
 * inside an entry is a plain unzoned piece.
 *
 * `$tag` is an opaque caller payload so an adopter can map a placed or
 * dropped entry back to its own model.
 *
 * Immutable value object.
 */
final class ZoneEntry
{
    /** @var list<ZonePiece> */
    public readonly array $pieces;

    public function __construct(array $pieces, public readonly mixed $tag = null)
    {
        $this->pieces = array_values($pieces);
    }

    /** @param list<ZonePiece> $pieces */
    public static function of(array $pieces, mixed $tag = null): self
    {
        return new self($pieces, $tag);
    }

    /** @return list<ZonePiece> */
    public function pieces(): array
    {
        return $this->pieces;
    }

    public function tag(): mixed
    {
        return $this->tag;
    }

    /** Total plain cell width of every piece. */
    public function width(): int
    {
        $w = 0;
        foreach ($this->pieces as $p) {
            $w += $p->width();
        }
        return $w;
    }
}
