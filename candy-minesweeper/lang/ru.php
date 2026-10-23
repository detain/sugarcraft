<?php

/**
 * Russian translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'доска слишком мала',
    'board.minecount_out_of_range' => 'mineCount вне диапазона',
    'board.rows_shape_mismatch' => 'форма строк не соответствует ширине/высоте',
    'custom.min_rows' => 'строк должно быть не менее 2',
    'custom.min_cols' => 'столбцов должно быть не менее 2',
    'custom.max_rows' => 'строк должно быть не более 50',
    'custom.max_cols' => 'столбцов должно быть не более 50',
    'custom.min_mines' => 'требуется хотя бы 1 мина',
    'custom.max_mines' => 'слишком много мин — оставьте безопасную стартовую область',
    'ui.status' => 'мины: {mines}  ·  флаги: {flags}  ·  осталось: {remaining}  ·  время: {time}',
    'ui.boom' => '💥 бум — нажмите r для новой игры',
    'ui.cleared' => '★ поле раскрыто — нажмите r, чтобы сыграть снова',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  движение  ·  space / enter  открыть',
    'ui.help_board' => 'f  флаг  ·  c / средняя кнопка  аккорд  ·  правая кнопка  флаг',
    'ui.help_session' => 'r  заново  ·  S  сохранить  ·  L  загрузить  ·  q / Esc / Ctrl+C  выход',
];
