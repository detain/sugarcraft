<?php

/**
 * Polish translations for sugar-style.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'table.width_nonneg'    => 'szerokość musi być >= 0',
    'tree.offset_nonneg'    => 'granice przesunięcia drzewa muszą być >= 0',
    'style.width_nonneg'    => 'szerokość musi być >= 0',
    'style.height_nonneg'   => 'wysokość musi być >= 0',
    'style.maxwidth_nonneg' => 'maxWidth musi być >= 0',
    'style.maxheight_nonneg' => 'maxHeight musi być >= 0',
    'style.tabwidth_nonneg' => 'tabWidth musi być >= 0',
    'style.spacing_nonneg' => 'spacing musi być >= 0',
    'style.slot_unresolved' => 'sloty kolorów adaptacyjnych {slots} nigdy nie zostały złożone; wywołaj resolveAdaptive(bool $isDark) przed renderowaniem',
    'style.hyperlink_id_delimiter' => 'id hiperłącza nie może zawierać ";" ani ":" (separatory parametrów OSC 8)',
    'style.markup_depth' => 'zagnieżdżenie markupu przekracza {levels} poziomów',
    'style.write_invalid_stream' => 'nie można zapisać: {destination} nie jest prawidłowym zasobem strumienia',
    'style.write_short' => 'zapis do strumienia nie powiódł się: zapisano {written} z {total} bajtów',
    'style.label_nonneg'    => 'wartości {label} muszą być >= 0',
    'style.border_toggles_count' => 'border() przyjmuje 0, 2 lub 4 wartości logiczne; otrzymano: {count}',
    'style.expand_sides_count'   => '{label}() przyjmuje 1, 2 lub 4 liczby całkowite; otrzymano: {count}',
];
