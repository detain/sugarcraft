<?php

/**
 * Russian UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'очки:  %d
линии: %d
уровень: %d',
    'help_card'  => 'движение
поворот вправо
поворот влево
мягкое падение
жёсткое падение
удержание
пауза
заново (финал)
выход',
    'next_label' => 'след:',
    'hold_label' => 'холд:',
    'paused'     => '[ пауза — нажмите p чтобы продолжить ]',

    // Game-over banner
    'over.title'                => 'ИГРА ОКОНЧЕНА',
    'over.final_score'          => 'итоговый счёт: {points}',
    'over.restart_hint'         => 'нажмите r чтобы начать заново — q чтобы выйти',
    'over.reason'               => 'причина: {reason}',
    'over.reason.spawn_blocked' => 'нет места для следующей фигуры',
    'over.reason.top_out'       => 'стопка достигла потолка',
    'over.reason.crushed'       => 'раздавлен мусором',

    // On-frame cues
    'hold_used'  => '(использован)',
    'combo_line' => 'комбо x%d',
    'b2b_line'   => 'B2B готов',
    'over.best'    => 'рекорд: {points}',
    'over.new_best' => 'новый рекорд!',

    // Versus renderer
    'label.player'   => 'ИГРОК',
    'label.computer' => 'КОМПЬЮТЕР',
    'win.player'     => 'ВЫ ПОБЕДИЛИ!',
    'win.computer'   => 'ПОБЕДИЛ КОМПЬЮТЕР!',
    'over.quit_hint' => 'нажмите q чтобы выйти',
    'vs_keys'        => 'сброс
удержание
пауза
заново
выход',
    'vs.record' => 'счёт: {wins} выиграно, {losses} проиграно',
    'intro.title' => 'добро пожаловать в candy-tetris',
    'intro.goal' => 'удаляйте линии - не достигайте верха',
    'intro.press' => 'нажмите {keys} чтобы играть',
    'intro.vs' => 'дуэльный режим: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: неизвестный режим "{flag}"',
    'help_text'    => 'candy-tetris - клон Тетриса

Использование: candy-tetris [параметры]
  (без арг.) Одиночная игра
  -v, --vs   Игра против компьютера
  -h, --help Показать эту справку
  --version  Показать версию
'
];
