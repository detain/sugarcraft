<?php

declare(strict_types=1);

namespace CandyCore\Bits\FilePicker;

/**
 * Sort criterion for {@see FilePicker::withSortMode()}. Directories
 * always group first regardless of mode, matching Bubbles' default
 * behaviour; the mode controls the secondary sort within each group.
 */
enum SortMode: string
{
    case Name  = 'name';
    case Size  = 'size';
    case MTime = 'mtime';
}
