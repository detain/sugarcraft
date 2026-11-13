<?php

/**
 * Japanese translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => '項目なし：リストは空です',
    'list.zero_viewport' => 'ビューポートの幅または高さがゼロのため表示できません',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '…（項目を切り詰めました）',
];
