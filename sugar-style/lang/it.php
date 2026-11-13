<?php

/**
 * Italian translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'la larghezza deve essere >= 0',
    'tree.offset_nonneg'    => 'i limiti offset dell\'albero devono essere >= 0',
    'style.width_nonneg'    => 'la larghezza deve essere >= 0',
    'style.height_nonneg'   => 'l\'altezza deve essere >= 0',
    'style.maxwidth_nonneg' => 'maxWidth deve essere >= 0',
    'style.maxheight_nonneg' => 'maxHeight deve essere >= 0',
    'style.tabwidth_nonneg' => 'tabWidth deve essere >= 0',
    'style.spacing_nonneg' => 'spacing deve essere >= 0',
    'style.slot_unresolved' => 'gli slot colore adattivi {slots} non sono mai stati ridotti; chiamare resolveAdaptive(bool $isDark) prima del render',
    'style.hyperlink_id_delimiter' => 'l\'id del collegamento non deve contenere «;» o «:» (separatori di parametro OSC 8)',
    'style.markup_depth' => 'l\'annidamento del markup supera {levels} livelli',
    'style.write_invalid_stream' => 'impossibile scrivere: {destination} non è una risorsa stream valida',
    'style.write_short' => 'scrittura stream non riuscita: scritti {written} di {total} byte',
    'style.label_nonneg'    => 'i valori di {label} devono essere >= 0',
    'style.border_toggles_count' => 'border() richiede 0, 2 o 4 booleani; ottenuto: {count}',
    'style.expand_sides_count'   => '{label}() richiede 1, 2 o 4 interi; ottenuto: {count}',
];
