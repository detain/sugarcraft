<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg\MouseMsg;

/**
 * Optional {@see Panel} extension: the panel's mapped buttons — btop's
 * `Input::mouse_mappings` entries it registers (net `b`/`n`/`z`/`a`/`y`,
 * mem `disks`/`io`, the proc box's title and bottom-row buttons).
 *
 * btop resolves a click against EVERY box's mappings before
 * Input::process runs, so a click on one box's button turns into that key
 * and is never also seen as a plain click by another box. The App mirrors
 * that: for a bare left press it asks the visible panels (layout order)
 * whether the click lands on one of their buttons; the first that says
 * yes receives the MouseMsg ALONE, and nobody else sees it — which is what
 * stops a click on the net `auto` button from clearing the proc
 * selection. An unclaimed click is still broadcast.
 *
 * Answer only for buttons actually painted this frame, from the same
 * geometry the painter uses; never claim the rest of the box (list rows,
 * scrollbars), whose clicks other panels must keep seeing.
 */
interface ClickCapture
{
    public function capturesClick(MouseMsg $msg, PanelContext $context): bool;
}
