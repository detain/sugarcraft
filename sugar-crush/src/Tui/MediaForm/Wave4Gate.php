<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

/**
 * The single switch for the W3.7 img2img/inpaint surface (plan §W3.7).
 *
 * The whole control vocabulary already ships — registry rows, radio
 * coercion, §2.7 verbatim labels, the tabs — but Wave 4 is what lands image
 * ingress and the mask editor; until then there is no image to condition a
 * generation on. So both sections render as one note row, expose zero
 * editable widgets, and collect zero values: present-but-gated, exactly the
 * plan's ruling (mirrors the RESERVED→LIVE discipline of the wire error
 * roster). Flipping {@see enabled()} to true when W4 merges lights the
 * registration without touching either section's code.
 */
final class Wave4Gate
{
    private function __construct()
    {
    }

    /** True only once Wave 4 (image ingress + mask editor) ships. */
    public static function enabled(): bool
    {
        return false;
    }
}
