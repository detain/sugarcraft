<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

/**
 * Whether a row-direction {@see FlexBox} breaks its items onto further lines.
 *
 * `Wrap` breaks greedily by each item's hypothetical main size (its basis when
 * set, otherwise its natural content width, or the 1-cell floor for a ratio-0
 * item). Each wrapped line is laid out
 * independently across the full width. Column-direction boxes ignore this
 * setting; see the README.
 *
 * There is no `WrapReverse`. sugar-dashboard's `FlexWrap::WrapReverse` is an
 * enum case that `FlexLayout` never branches on (its only wrap test is
 * `=== FlexWrap::Wrap`), so there is no behavior to carry over.
 */
enum Wrap
{
    case NoWrap;
    case Wrap;
}
