<?php

/**
 * Year-first header fixture (dp-15): proves the template key lets a locale
 * reorder the composition without any code change. en month names are kept;
 * only the template moves.
 *
 * @return array<string, string>
 */

return array_merge(require __DIR__ . '/../../../lang/en.php', [
    'header' => '{year}-{month}',
]);
