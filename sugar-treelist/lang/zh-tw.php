<?php

/**
 * Traditional Chinese translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => '清單沒有可顯示的項目。',
    'list.zero_viewport' => '視窗的寬度或高度為零，因此無法顯示清單。',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '…（條目已截斷）',
    'list.no_content_width' => '行邊欄佔用了整個視窗，因此無法顯示清單。',
];
