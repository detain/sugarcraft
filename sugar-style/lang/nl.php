<?php

/**
 * Dutch translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'breedte moet >= 0 zijn',
    'tree.offset_nonneg'    => 'boom-offsetgrenzen moeten >= 0 zijn',
    'style.width_nonneg'    => 'breedte moet >= 0 zijn',
    'style.height_nonneg'   => 'hoogte moet >= 0 zijn',
    'style.maxwidth_nonneg' => 'maxWidth moet >= 0 zijn',
    'style.maxheight_nonneg' => 'maxHeight moet >= 0 zijn',
    'style.tabwidth_nonneg' => 'tabWidth moet >= 0 zijn',
    'style.spacing_nonneg' => 'spacing moet >= 0 zijn',
    'style.slot_unresolved' => 'de adaptieve kleursleuven {slots} zijn nooit samengevouwen; roep resolveAdaptive(bool $isDark) aan vóór het renderen',
    'style.hyperlink_id_delimiter' => 'hyperlink-id mag geen ";" of ":" bevatten (OSC 8-parameter scheidingstekens)',
    'style.markup_depth' => 'markup-nesting overschrijdt {levels} niveaus',
    'style.write_invalid_stream' => 'schrijven niet mogelijk: {destination} is geen geldige streambron',
    'style.write_short' => 'stream-write mislukt: {written} van de {total} bytes geschreven',
    'style.label_nonneg'    => '{label}-waarden moeten >= 0 zijn',
    'style.border_toggles_count' => 'border() neemt 0, 2 of 4 booleans; gekregen: {count}',
    'style.expand_sides_count'   => '{label}() neemt 1, 2 of 4 integers; gekregen: {count}',
];
