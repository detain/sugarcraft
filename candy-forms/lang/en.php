<?php

declare(strict_types=1);

return [
    'viewport.dim_nonneg' => 'Dimensions must be non-negative',
    'viewport.width_nonneg' => 'Width must be non-negative',
    'viewport.height_nonneg' => 'Height must be non-negative',
    'list.dim_nonneg' => 'Dimensions must be non-negative',
    'spinner.empty_frames' => 'spinner style needs at least one frame',
    'spinner.fps_positive' => 'spinner fps must be > 0',
    'scrollbar.total_nonneg' => 'scrollbar total must be >= 0',
    'scrollbar.viewport_nonneg' => 'scrollbar viewport must be >= 0',
    'scrollbar.position_range' => 'scrollbar position must be in range [0, max(0, total - viewport)]',
];
