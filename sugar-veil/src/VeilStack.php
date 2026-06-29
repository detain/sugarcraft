<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

/**
 * Ordered collection of Veil instances rendered by z-index.
 *
 * Sorts veils by z-index ascending and composites them in order so
 * higher z-index veils appear on top of lower ones.
 */
final class VeilStack implements \Countable
{
    /** @var list<Veil> */
    private array $veils = [];

    /**
     * Create a new empty VeilStack.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Add a veil to the stack.
     */
    public function add(Veil $veil): self
    {
        $next = clone $this;
        $next->veils[] = $veil;
        return $next;
    }

    /**
     * Remove all veils from the stack.
     */
    public function clear(): self
    {
        $next = clone $this;
        $next->veils = [];
        return $next;
    }

    /**
     * Remove veils matching the given predicate.
     *
     * @param \Closure(Veil): bool $predicate
     */
    public function removeWhere(\Closure $predicate): self
    {
        $next = clone $this;
        $next->veils = array_values(array_filter($next->veils, static fn(Veil $v): bool => !$predicate($v)));
        return $next;
    }

    /**
     * Composite all veils onto the background in z-index order (lowest first).
     *
     * Each veil is composited onto the result of the previous one, so veils
     * with higher z-index end up on top.
     *
     * @param string $background The base content
     * @param Position $vertical Vertical position anchor
     * @param Position $horizontal Horizontal position anchor
     * @param int $xOffset Additional columns rightward (+) / leftward (-)
     * @param int $yOffset Additional lines downward (+) / upward (-)
     * @return string The composited output
     */
    public function composite(
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        // Sort by z-index ascending so lowest renders first
        $sorted = $this->sorted();
        $result = $background;
        foreach ($sorted as $veil) {
            $result = $veil->composite($result, $vertical, $horizontal, $xOffset, $yOffset);
        }
        return $result;
    }

    /**
     * Composite all veils at fixed TOP,LEFT positions.
     *
     * NOTE: This method is currently a pass-through — each veil composites
     * the accumulated result as foreground over the original background.
     * Since Veil stores no per-veil content, the practical effect is that
     * the original background is returned unchanged when all veils have
     * empty content. Use composite() directly for per-veil positioning.
     *
     * @param string $background The base content
     * @return string The composited output
     */
    public function compositeAll(string $background): string
    {
        $sorted = $this->sorted();
        $result = $background;
        foreach ($sorted as $veil) {
            $result = $veil->composite($result, $background, Position::TOP, Position::LEFT);
        }
        return $result;
    }

    /**
     * Return veils sorted by z-index ascending.
     *
     * @return list<Veil>
     */
    public function sorted(): array
    {
        $veils = $this->veils;
        \usort($veils, static fn(Veil $a, Veil $b): int => $a->zIndex() <=> $b->zIndex());
        return $veils;
    }

    /**
     * @return list<Veil>
     */
    public function all(): array
    {
        return $this->veils;
    }

    public function isEmpty(): bool
    {
        return $this->veils === [];
    }

    public function count(): int
    {
        return \count($this->veils);
    }

    /**
     * Filter veils by a predicate.
     *
     * @param \Closure(Veil): bool $predicate
     */
    public function filter(\Closure $predicate): self
    {
        $next = clone $this;
        $next->veils = array_values(array_filter($next->veils, $predicate));
        return $next;
    }

    /**
     * Get the highest z-index in the stack.
     */
    public function maxZIndex(): int
    {
        if ($this->veils === []) {
            return 0;
        }
        $max = $this->veils[0]->zIndex();
        foreach ($this->veils as $veil) {
            if ($veil->zIndex() > $max) {
                $max = $veil->zIndex();
            }
        }
        return $max;
    }

    /**
     * Get the lowest z-index in the stack.
     */
    public function minZIndex(): int
    {
        if ($this->veils === []) {
            return 0;
        }
        $min = $this->veils[0]->zIndex();
        foreach ($this->veils as $veil) {
            if ($veil->zIndex() < $min) {
                $min = $veil->zIndex();
            }
        }
        return $min;
    }
}
