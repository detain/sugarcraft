<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Top\Config\Config;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;

/**
 * What a {@see Panel} may read while handling input: the App's CURRENT
 * {@see Config}, the CURRENT {@see Layout} and this panel's own box
 * rectangle in it. The App builds a fresh one for every
 * {@see Panel::modal()} / {@see Panel::capturesKey()} / {@see Panel::update()}
 * call from its own state at that moment, so it already reflects every
 * toggle, option write and resize that came before — never cache it.
 *
 * P-E uses {@see procSelectMax()} for page up/down/home/end and {@see $box}
 * for mouse hit-testing; P-C/P-E never recompute the calcSizes port.
 */
final class PanelContext
{
    /**
     * @param ?Layout $layout null before the first WindowSizeMsg
     * @param ?Rect   $box    this panel's absolute 0-based box; null while hidden or unsized
     */
    public function __construct(
        public readonly Config $config,
        public readonly ?Layout $layout = null,
        public readonly ?Rect $box = null,
    ) {
    }

    /** True while this panel's box is laid out (shown and sized). */
    public function visible(): bool
    {
        return $this->box !== null;
    }

    /** btop `Proc::select_max`: process rows the proc box shows; 0 when unknown. */
    public function procSelectMax(): int
    {
        return $this->layout?->procSelectMax ?? 0;
    }

    /**
     * True when the terminal's 1-based mouse cell (`MouseMsg::$x`/`$y`)
     * lies inside this panel's box.
     */
    public function hit(int $mouseX, int $mouseY): bool
    {
        return $this->box !== null && $this->box->contains($mouseX - 1, $mouseY - 1);
    }
}
