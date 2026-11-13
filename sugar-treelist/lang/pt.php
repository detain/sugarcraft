<?php

/**
 * Portuguese translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Sem itens: a lista está vazia',
    'list.zero_viewport' => 'Impossível exibir com largura ou altura do viewport igual a zero',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (item truncado)',
];
