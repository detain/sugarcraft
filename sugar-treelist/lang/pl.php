<?php

/**
 * Polish translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Brak elementów: lista jest pusta',
    'list.zero_viewport' => 'Nie można wyświetlić przy zerowej szerokości lub wysokości okna widoku',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (pozycja skrócona)',
];
