<?php

/**
 * Synthetic overlong-month fixture (dp-14): proves the header clips to the
 * calendar budget instead of bleeding past the grid edge.
 *
 * @return array<string, string>
 */

return [
    'month.9' => 'Septemberemberextraordinair',
] + require __DIR__ . '/../../../lang/en.php';
