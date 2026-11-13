<?php

/**
 * Russian translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Нет элементов: список пуст',
    'list.zero_viewport' => 'Невозможно отобразить при нулевой ширине или высоте области просмотра',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (запись усечена)',
    'list.no_content_width' => 'Поля строк занимают весь вьюпорт, поэтому список не может быть отображён.',
];
