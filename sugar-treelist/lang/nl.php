<?php

/**
 * Dutch translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Geen items: lijst is leeg',
    'list.zero_viewport' => 'Kan niet weergeven met nul breedte of hoogte voor viewport',
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (item ingekort)',
    'list.no_content_width' => 'De goten van de regels vullen het hele viewport, dus de lijst kan niet worden weergegeven.',
];
