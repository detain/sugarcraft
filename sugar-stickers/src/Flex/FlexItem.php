<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Flex;

/**
 * A single item inside a FlexBox.
 *
 * Holds content + layout constraints (ratio, basis).
 * Uses ratio-based sizing (like CSS flex-grow) for space distribution.
 */
final class FlexItem
{
    public readonly string $content;

    /** Relative size ratio — items with higher ratios take more space. */
    public readonly int $ratio;

    /** Fixed basis (0 = fill available space). */
    public readonly int $basis;

    /** Style applied to this item (ANSI string). */
    public readonly string $style;

    private function __construct(
        string $content,
        int $ratio = 1,
        int $basis = 0,
        string $style = '',
    ) {
        $this->content = $content;
        $this->ratio   = $ratio;
        $this->basis   = $basis;
        $this->style   = $style;
    }

    public static function new(string $content = ''): self
    {
        return new self($content);
    }

    public function withContent(string $content): self
    {
        return new self($content, $this->ratio, $this->basis, $this->style);
    }

    public function withRatio(int $ratio): self
    {
        return new self($this->content, $ratio, $this->basis, $this->style);
    }

    public function withBasis(int $basis): self
    {
        return new self($this->content, $this->ratio, $basis, $this->style);
    }

    public function withStyle(string $ansiStyle): self
    {
        self::assertSgrParams($ansiStyle);
        return new self($this->content, $this->ratio, $this->basis, $ansiStyle);
    }

    public function withWidth(int $width): self
    {
        return $this->withBasis($width);
    }

    /**
     * Reject any style that is not a bare SGR parameter string.
     *
     * $style is interpolated raw into a `CSI <style> m` sequence by
     * FlexBox::applyStyle(), so a caller-supplied value containing an ESC, an
     * OSC/DCS introducer, or arbitrary letters could terminate the SGR early
     * and inject attacker-controlled terminal control sequences. Constrain the
     * input to digits and ';' at the setter (empty string = "no style" is
     * still permitted).
     */
    private static function assertSgrParams(string $style): void
    {
        if (\preg_match('/^[0-9;]*$/', $style) !== 1) {
            throw new \InvalidArgumentException(
                'Style must be a bare SGR parameter string matching /^[0-9;]*$/ (digits and ";" only).'
            );
        }
    }
}
