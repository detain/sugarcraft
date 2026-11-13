<?php

/**
 * German translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Keine Einträge: Liste ist leer',
    'list.zero_viewport' => 'Anzeige mit Breite oder Höhe 0 nicht möglich',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (Eintrag gekürzt)',
];
