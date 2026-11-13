<?php

/**
 * French translations for sugar-treelist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'list.no_items'    => 'Aucun élément : la liste est vide',
    'list.zero_viewport' => "Impossible d'afficher avec une largeur ou hauteur de viewport nulle",
    // Byte-cap marker: an item value over Model::MAX_ITEM_RENDER_BYTES
    // renders its first window plus this loud row (sugar-treelist-004).
    'list.render_truncated' => '… (élément tronqué)',
    'list.no_content_width' => 'Les gouttières de ligne consomment tout l’affichage, la liste ne peut donc pas être affichée.',
];
