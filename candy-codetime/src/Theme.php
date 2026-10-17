<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\Style\Theme as StyleTheme;

/**
 * candy-codetime theme wrapper around sugar-style Theme.
 */
final class Theme
{
    private StyleTheme $inner;

    public function __construct(?StyleTheme $inner = null)
    {
        $this->inner = $inner ?? StyleTheme::dark();
    }

    public static function dark(): self
    {
        return new self(StyleTheme::dark());
    }

    public static function light(): self
    {
        return new self(StyleTheme::light());
    }

    public function inner(): StyleTheme
    {
        return $this->inner;
    }
}
