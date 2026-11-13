<?php

/**
 * Korean translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => '항목 없음: 목록이 비어 있습니다',
    'list.zero_viewport' => '뷰포트 너비 또는 높이가 0이어서 표시할 수 없음',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (항목 잘림)',
    'list.no_content_width' => '여백이 뷰포트 전체를 차지하여 목록을 표시할 수 없습니다.',
];
