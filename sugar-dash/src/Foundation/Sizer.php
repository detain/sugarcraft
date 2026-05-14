<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * An Item that knows its own dimensions.
 *
 * The grid propagates allocated width/height to Sizer instances via
 * SetSize before calling render(), so sized components can adjust
 * their output to the available space.
 */
interface Sizer extends Item
{
    /**
     * Set the allocated dimensions for this item.
     *
     * @return $this for fluent composition
     */
    public function setSize(int $width, int $height): Sizer;
}
