<?php

declare(strict_types=1);

namespace SugarCraft\Tick;

use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * sugar-tick theme wrapper around candy-sprinkles Theme.
 */
final class Theme
{
    private SprinklesTheme $inner;

    public function __construct(?SprinklesTheme $inner = null)
    {
        $this->inner = $inner ?? SprinklesTheme::dark();
    }

    public static function dark(): self
    {
        return new self(SprinklesTheme::dark());
    }

    public static function light(): self
    {
        return new self(SprinklesTheme::light());
    }

    public function inner(): SprinklesTheme
    {
        return $this->inner;
    }
}
