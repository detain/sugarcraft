<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * A {@see Sizer} that can also report its intrinsic (unconstrained) size.
 *
 * `Sizer::setSize()` alone does not promise a way to READ back what the item
 * wants or occupies, yet every layout container in this library measures its
 * children through `getInnerSize()` behind an `instanceof Sizer` guard. A
 * third-party `Sizer` implementation that omits the method therefore passed
 * the guard and fataled. This sub-interface makes the measurement contract
 * explicit: guard sizing reads with `instanceof SizedItem`, and any class
 * that genuinely answers `getInnerSize()` declares `SizedItem` instead of
 * bare `Sizer` (still an `instanceof Sizer`, so setSize-only callers are
 * unaffected).
 */
interface SizedItem extends Sizer
{
    /**
     * Intrinsic size of the item as currently configured: [width, height]
     * in terminal cells. Zeroes mean "measure me by rendering".
     *
     * @return array{0:int,1:int}
     */
    public function getInnerSize(): array;
}
