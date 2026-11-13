<?php

/**
 * Arabic translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'لا توجد عناصر: القائمة فارغة',
    'list.zero_viewport' => 'تعذر العرض بسبب عرض أو ارتفاع منطقة العرض الصفري',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (تم اقتطاع العنصر)',
    'list.no_content_width' => 'حواف الأسطر تستهلك كامل منطقة العرض، لذلك لا يمكن عرض القائمة.',
];
