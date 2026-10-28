<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\DiffReview;

/**
 * What a {@see DiffReviewAction} asks the wiring to do: reload the patch for
 * a different base checkpoint (or turn view), or close the overlay.
 */
enum DiffReviewActionKind: string
{
    case Reload = 'reload';
    case Close = 'close';
}
