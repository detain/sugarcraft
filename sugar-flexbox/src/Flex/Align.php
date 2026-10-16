<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

/** {@see FlexBox} cross-axis item alignment — equivalent to CSS `align-items`. */
enum Align {
    case Start;
    case Center;
    case End;
    case Stretch;
}
