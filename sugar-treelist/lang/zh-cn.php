<?php

/**
 * Simplified Chinese translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => '无项目：列表为空',
    'list.zero_viewport' => '视口宽度或高度为零，无法显示',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '…（条目已截断）',
];
