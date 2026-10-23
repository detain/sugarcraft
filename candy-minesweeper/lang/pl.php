<?php

/**
 * Polish translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'plansza jest za mała',
    'board.minecount_out_of_range' => 'mineCount poza zakresem',
    'board.rows_shape_mismatch' => 'kształt wierszy nie pasuje do szerokości/wysokości',
    'custom.min_rows' => 'wierszy musi być co najmniej 2',
    'custom.min_cols' => 'kolumn musi być co najmniej 2',
    'custom.max_rows' => 'wierszy może być co najwyżej 50',
    'custom.max_cols' => 'kolumn może być co najwyżej 50',
    'custom.min_mines' => 'wymagana jest co najmniej 1 mina',
    'custom.max_mines' => 'za dużo min — zostaw bezpieczne pole startowe',
    'ui.status' => 'miny: {mines}  ·  flagi: {flags}  ·  pozostało: {remaining}  ·  czas: {time}',
    'ui.boom' => '💥 bum — naciśnij r, aby zacząć od nowa',
    'ui.cleared' => '★ wygrane — naciśnij r, aby zagrać ponownie',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  ruch  ·  spacja / enter  odkryj',
    'ui.help_board' => 'f  flaga  ·  c / środkowy przycisk  chord  ·  prawy przycisk  flaga',
    'ui.help_session' => 'r  restart  ·  S  zapisz  ·  L  wczytaj  ·  q / Esc / Ctrl+C  wyjdź',
];
