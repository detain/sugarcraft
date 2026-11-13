<?php

/**
 * Spanish translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Sin elementos: la lista está vacía',
    'list.zero_viewport' => 'No se puede mostrar con ancho o alto de viewport cero',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (elemento truncado)',
];
