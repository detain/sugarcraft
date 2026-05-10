<?php

/**
 * English (default) translations for sugar-bits.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'tabs.bad_index'             => 'tab index out of range',
    'tabs.neg_width'             => 'tabs width must be >= 0',
    'column.width_nonneg'        => 'Column width must be >= 0',
    'viewport.dim_nonneg'        => 'viewport width/height must be >= 0',
    'viewport.width_nonneg'      => 'viewport width must be >= 0',
    'viewport.height_nonneg'     => 'viewport height must be >= 0',
    'list.dim_nonneg'            => 'list width/height must be >= 0',
    'spinner.empty_frames'       => 'spinner style needs at least one frame',
    'spinner.fps_positive'       => 'spinner fps must be > 0',
    'stopwatch.interval_positive' => 'stopwatch interval must be > 0',
    'timer.duration_nonneg'      => 'timer duration must be >= 0',
    'timer.interval_positive'    => 'timer interval must be > 0',
    'table.dim_nonneg'           => 'table width/height must be >= 0',
    'table.set_columns_type'     => 'setColumns expects Column instances',
    'progress.width_nonneg'      => 'progress width must be >= 0',
    'tree.dim_nonneg'            => 'tree width/height must be >= 0',
    'help.width_nonneg'          => 'help width must be >= 0',
    'scrollbar.total_nonneg'     => 'scrollbar total must be >= 0',
    'scrollbar.viewport_nonneg'  => 'scrollbar viewport must be >= 0',
    'scrollbar.position_range'   => 'scrollbar position must be in range [0, max(0, total - viewport)]',
];
