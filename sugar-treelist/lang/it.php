<?php

/**
 * Italian translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Nessun elemento: la lista è vuota',
    'list.zero_viewport' => 'Impossibile visualizzare con larghezza o altezza del viewport nulla',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (voce troncata)',
];
