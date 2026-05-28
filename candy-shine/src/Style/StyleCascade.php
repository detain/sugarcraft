<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Style;

use SugarCraft\Sprinkles\Style;

/**
 * CSS-like cascade helper for merging parent and child styles.
 *
 * Rules:
 * - Child Style overrides parent on any explicitly-set attributes
 * - Unspecified attributes are inherited from parent
 *
 * Mirrors glamour's cascading style resolution where each nested block
 * can override specific style attributes while inheriting the rest.
 */
final class StyleCascade
{
    /**
     * Merge a child style into a parent style.
     * Child wins on any explicitly-set attribute; rest inherits from parent.
     */
    public static function merge(Style $parent, Style $child): Style
    {
        // The child style's inherit() method implements exactly this:
        // child properties that were explicitly set override parent values.
        return $child->inherit($parent);
    }
}
