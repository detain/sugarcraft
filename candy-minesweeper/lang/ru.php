<?php

/**
 * Russian translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'доска слишком мала',
    'board.minecount_out_of_range' => 'количество мин вне диапазона',
    'board.rows_shape_mismatch' => 'данные сетки не соответствуют доске {width}×{height}',
    'custom.min_rows' => 'строк должно быть не менее 2',
    'custom.min_cols' => 'столбцов должно быть не менее 2',
    'custom.max_rows' => 'строк должно быть не более 50',
    'custom.max_cols' => 'столбцов должно быть не более 50',
    'custom.min_mines' => 'требуется хотя бы 1 мина',
    'custom.max_mines' => 'слишком много мин — оставьте безопасную стартовую область',
    'ui.status' => 'мины: {mines}  ·  флаги: {flags}  ·  осталось: {remaining}  ·  время: {time}',
    'ui.status_stats' => '{preset}  ·  флаги: {flags}  ·  осталось: {remaining}  ·  время: {time}  ·  рекорд: {best}',
    'ui.preset_easy' => 'лёгкий',
    'ui.preset_standard' => 'стандартный',
    'ui.preset_medium' => 'средний',
    'ui.preset_expert' => 'эксперт',
    'ui.boom' => '💥 бум — нажмите r для новой игры',
    'ui.cleared' => '★ поле раскрыто — нажмите r, чтобы сыграть снова',
    'ui.help_rules' => 'цифра — число мин рядом',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  движение  ·  space / enter  открыть',
    'ui.help_board' => 'левая кнопка  открыть · f / правая кнопка  флаг · c / средняя кнопка  вокруг',
    'ui.help_session' => 'r  заново  ·  S  сохранить  ·  L  загрузить  ·  q / Esc / Ctrl+C  выход',
    'ui.saved' => 'Игра сохранена',
    'ui.restarted' => 'Поле перезапущено',
    'ui.loaded' => 'Сохранение загружено',
    'ui.no_save' => 'Нет сохранений для загрузки',
    'ui.load_refused' => 'Сохранение отклонено (нечитаемо или подделано)',
    'ui.save_failed' => 'Ошибка сохранения',
    'ui.chord_not_ready' => 'для аккорда флаги должны равняться числу',
    'ui.resume' => 'Сохранение ждёт — нажмите L для загрузки',
    'ui.new_best' => 'НОВЫЙ РЕКОРД',
    'ui.beat_best' => '{prev} → НОВЫЙ РЕКОРД',
    'ui.fit_notice' => 'candy-minesweeper требует терминал высотой {need} строк; этот содержит {have}. Увеличьте окно — игра продолжится, как только всё поместится. q — выход.',
];
