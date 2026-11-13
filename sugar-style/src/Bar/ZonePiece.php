<?php

declare(strict_types=1);

namespace SugarCraft\Style\Bar;

use SugarCraft\Core\Util\Width;

/**
 * One independently clickable run of a {@see ZoneRow}: its PLAIN text (the
 * only thing ever measured), an optional zone id, and an optional paint
 * callback that turns the plain text into styled output.
 *
 * Immutable value object.
 */
final class ZonePiece
{
    /** @var (\Closure(string): string)|null */
    public readonly ?\Closure $paint;

    /**
     * @param ?callable(string): string $paint plain text -> painted text; null paints the text raw
     */
    public function __construct(
        public readonly string $plain,
        public readonly ?string $id = null,
        ?callable $paint = null,
    ) {
        $this->paint = $paint === null ? null : \Closure::fromCallable($paint);
    }

    /**
     * @param ?callable(string): string $paint
     */
    public static function of(string $plain, ?string $id = null, ?callable $paint = null): self
    {
        return new self($plain, $id, $paint);
    }

    /** Plain text. */
    public function plain(): string
    {
        return $this->plain;
    }

    /** Zone id, or null for an unclickable piece. */
    public function id(): ?string
    {
        return $this->id;
    }

    /** Cell width of the plain text. */
    public function width(): int
    {
        return Width::string($this->plain);
    }

    /** The painted text (the plain text when no callback is bound). */
    public function painted(): string
    {
        return $this->paint === null ? $this->plain : ($this->paint)($this->plain);
    }
}
