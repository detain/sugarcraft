<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Video\Lang;

/**
 * The one-question answer for "can this terminal render this mode here?".
 *
 * SV-M35/035: `supports()` is the transport door (would the seat even receive
 * the bytes?) and the auto ladder applies its own step-downs; a host embedding
 * the player (candy-code's video screen is the motivating consumer, which
 * hand-rolled the matrix before this existed) needs the FULL honest verdict —
 * protocol claim, ext-gd presence, block-glyph safety and the geometry the
 * SV-H7/007 window owns — as one inspectable value, not a boolean that hides
 * which of five reasons said no.
 *
 * The verdict is DATA: match on the case, show `notice()`, or just ask
 * `isRenderable()`. No exception class is ever thrown for a plain "not here".
 *
 * NOTE (disclosed per the review step): this is a LOCAL enum — sugar-image
 * carries no `Support` surface to reuse (its enums are Dither/Optimization/
 * AsciiColorMode); the dep exists in composer.json for the renderers, not
 * for this shape.
 */
enum Support: string
{
    /** Renderable right now, on this seat, at this geometry. */
    case Supported = 'supported';

    /** The seat never claimed the graphics protocol the mode speaks. */
    case NeedsProtocol = 'needs-protocol';

    /** A graphics rung the seat speaks, but this PHP build has no ext-gd. */
    case NeedsGd = 'needs-gd';

    /** The rung draws Unicode block glyphs this seat cannot render safely. */
    case NeedsBlockGlyphs = 'needs-block-glyphs';

    /** Below the Player's cell floor (SV-H7/007 window). */
    case GeometryTooSmall = 'geometry-too-small';

    /** Above the Player's cell ceiling (SV-H7/007 window). */
    case GeometryTooLarge = 'geometry-too-large';

    public function isRenderable(): bool
    {
        return $this === self::Supported;
    }

    /**
     * The refusal in one sentence (localised; the branded diagnostic voice).
     *
     * @param array<string, string|int> $params mode/protocol/geometry context
     */
    public function notice(array $params = []): string
    {
        return Lang::t(match ($this) {
            self::Supported => 'render.support_supported',
            self::NeedsProtocol => 'render.support_needs_protocol',
            self::NeedsGd => 'render.support_needs_gd',
            self::NeedsBlockGlyphs => 'render.support_needs_block_glyphs',
            self::GeometryTooSmall => 'render.support_geometry_too_small',
            self::GeometryTooLarge => 'render.support_geometry_too_large',
        }, $params);
    }
}
