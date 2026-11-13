<?php

/**
 * Czech translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Žádné položky: seznam je prázdný',
    'list.zero_viewport' => 'Nelze zobrazit při nulové šířce nebo výšce viewportu',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (položka zkrácena)',
];
