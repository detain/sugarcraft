<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

use SugarCraft\Charts\Lang;
use SugarCraft\Core\Util\Width;

/**
 * Single-cell ingress door for runes the renderers paint one cell at a
 * time (point glyphs, scatter/heatmap fills, candle bodies/wicks, legend
 * indicators).
 *
 * Every such rune is swept through {@see UntrustedText::guard()} and then
 * validated to occupy exactly one display cell. A multi-width rune (e.g. a
 * CJK ideograph) is a single-cell *claim* the cell grid cannot honour:
 * `Canvas::setCell` clamps it into one cell while the frame emitter writes
 * the full string, so every touched row overruns the declared width. The
 * empty rune is refused for the same reason — a painted cell must display
 * something, and an invisible fill silently erases the series.
 *
 * WHY the door lives in the private constructors (one sweep point per
 * class, covering `new()` and every `with*()` rebuild alike): the lib's
 * "validated at ingestion, trusted internally" law — a guarded, measured
 * rune stored in a readonly property can never be re-assembled into an
 * unguarded instance.
 */
final class Rune
{
    private function __construct() {}

    /**
     * Guard `$rune` and assert the result is exactly one display cell.
     *
     * @param string $name human-readable door name carried in the refusal
     *                     message (an API identifier, not translated prose)
     *
     * @throws \InvalidArgumentException when the guarded rune is empty or
     *         spans more (or fewer) than one display cell
     */
    public static function cell(string $rune, string $name): string
    {
        $guarded = UntrustedText::guard($rune);
        if ($guarded === '') {
            throw new \InvalidArgumentException(Lang::t('rune.empty', ['name' => $name]));
        }
        $cells = Width::of($guarded);
        if ($cells !== 1) {
            throw new \InvalidArgumentException(Lang::t('rune.width_one', [
                'name'  => $name,
                'cells' => (string) $cells,
            ]));
        }

        return $guarded;
    }
}
