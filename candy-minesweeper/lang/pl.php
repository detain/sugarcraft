<?php

/**
 * Polish translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'plansza jest za mała',
    'board.minecount_out_of_range' => 'liczba min poza zakresem',
    'board.rows_shape_mismatch' => 'dane siatki nie pasują do planszy {width}×{height}',
    'custom.min_rows' => 'wierszy musi być co najmniej 2',
    'custom.min_cols' => 'kolumn musi być co najmniej 2',
    'custom.max_rows' => 'wierszy może być co najwyżej 50',
    'custom.max_cols' => 'kolumn może być co najwyżej 50',
    'custom.min_mines' => 'wymagana jest co najmniej 1 mina',
    'custom.max_mines' => 'za dużo min — zostaw bezpieczne pole startowe',
    'ui.status' => 'miny: {mines}  ·  flagi: {flags}  ·  pozostało: {remaining}  ·  czas: {time}',
    'ui.boom' => '💥 bum — naciśnij r, aby zacząć od nowa',
    'ui.cleared' => '★ wygrane — naciśnij r, aby zagrać ponownie',
    'ui.help_rules' => 'liczba oznacza miny w pobliżu',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  ruch  ·  spacja / enter  odkryj',
    'ui.help_board' => 'lewy przycisk  otwórz · f / prawy  flaga · c / środkowy  otwórz okolice',
    'ui.help_session' => 'r  restart  ·  S  zapisz  ·  L  wczytaj  ·  q / Esc / Ctrl+C  wyjdź',
    'ui.saved' => 'Gra zapisana',
    'ui.restarted' => 'Plansza zrestartowana',
    'ui.loaded' => 'Gra wczytana',
    'ui.no_save' => 'Brak zapisu do wczytania',
    'ui.load_refused' => 'Zapis odrzucony (nieczytelny lub sfałszowany)',
    'ui.save_failed' => 'Nie udało się zapisać',
    'ui.chord_not_ready' => 'akord wymaga flag równych liczbie',
    'ui.resume' => 'Czeka zapis gry — naciśnij L, aby go wczytać',
    'ui.new_best' => 'NOWY REKORD',
    'ui.fit_notice' => 'candy-minesweeper potrzebuje terminala o wysokości {need} wierszy; ten ma {have}. Powiększ okno — gra wznowi się, gdy tylko się zmieści. q kończy.',
];
