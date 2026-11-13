<?php

/**
 * Polish translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'Szerokość kolumny musi być >= 0',
    'tabs.bad_index'              => 'indeks zakładek poza zakresem',
    'tabs.neg_width'              => 'szerokość zakładek musi być >= 0',
    'table.dim_nonneg'            => 'szerokość/wysokość tabeli musi być >= 0',
    'table.page_size_nonneg'      => 'rozmiar strony musi być >= 0',
    'table.set_columns_type'      => 'setColumns oczekuje instancji Column',
    'table.sort_unknown_column'   => 'nie znaleziono kolumny sortowania: {column}',
    'progress.width_nonneg'       => 'szerokość postępu musi być >= 0',
    'tree.dim_nonneg'             => 'szerokość/wysokość drzewa musi być >= 0',
    'help.width_nonneg'           => 'szerokość pomocy musi być >= 0',
    'stopwatch.interval_positive' => 'interwał stopera musi być > 0',
    'timer.duration_nonneg'       => 'czas trwania timera musi być >= 0',
    'timer.interval_positive'     => 'interwał timera musi być > 0',
    'paginator.arabic_format_invalid' => 'format arabski musi być prawidłowym sprintf dwóch liczb całkowitych',
    'progress.bad_percent_format' => 'format procentów musi być prawidłowym sprintf z jedną liczbą całkowitą',
    'progress.bad_value_format' => 'format wartości musi być prawidłowym sprintf z dwiema liczbami całkowitymi',
    'progress.rune_cell_width' => 'znaki paska muszą mieć dokładnie jedną komórkę wyświetlania',
    'progress.color_func_type' => 'colorFunc musi zwracać Color',
    'tabs.next_tab' => 'następna karta',
    'tabs.prev_tab' => 'poprzednia karta',
    'tabs.jump_to' => 'karta {index}',
    'timer.paused_tag' => 'wstrzymane',
    'timer.timeout_tag' => 'przekroczono czas',
    'stopwatch.not_started_tag' => 'nie rozpoczęto',
    'stopwatch.paused_tag' => 'wstrzymane',
    'key.bad_binding' => 'nieprawidłowe powiązanie klawiszy {key}: użyj małej nazwy klawisza (ctrl+c, shift+tab, pageup, f5) albo jednego znakowi drukowalnego',
    'key.bad_binding_type' => 'klawisze powiązania muszą być łańcuchami, otrzymano {key}',
];
