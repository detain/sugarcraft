<?php

/**
 * German translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'Breite muss >= 0 sein',
    'tree.offset_nonneg'    => 'Tree-Offset-Grenzen müssen >= 0 sein',
    'style.width_nonneg'    => 'Breite muss >= 0 sein',
    'style.height_nonneg'   => 'Höhe muss >= 0 sein',
    'style.maxwidth_nonneg' => 'maxWidth muss >= 0 sein',
    'style.maxheight_nonneg' => 'maxHeight muss >= 0 sein',
    'style.tabwidth_nonneg' => 'tabWidth muss >= 0 sein',
    'style.spacing_nonneg' => 'spacing muss >= 0 sein',
    'style.slot_unresolved' => 'die adaptiven Farb-Slots {slots} wurden nie aufgelöst; vor dem Rendern resolveAdaptive(bool $isDark) aufrufen',
    'style.hyperlink_id_delimiter' => 'Hyperlink-ID darf kein ";" oder ":" enthalten (OSC-8-Parameter-Trenner)',
    'style.markup_depth' => 'Markup-Verschachtelung überschreitet {levels} Ebenen',
    'style.write_invalid_stream' => 'schreiben nicht möglich: {destination} ist keine gültige Stream-Ressource',
    'style.write_short' => 'Stream-Schreiben fehlgeschlagen: {written} von {total} Bytes geschrieben',
    'style.label_nonneg'    => '{label}-Werte müssen >= 0 sein',
    'style.border_toggles_count' => 'border()-Seiten-Toggles benötigen 0, 2 oder 4 Booleans; erhalten: {count}',
    'style.expand_sides_count'   => '{label}() benötigt 1, 2 oder 4 Ganzzahlen; erhalten: {count}',
];
