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
    'score_card' => 'очки:  {score}
линии: {lines}
уровень: {level}',
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
    'paused'     => '[ пауза — нажмите p, чтобы продолжить ]',

    // Game-over banner
    'over.title'                => 'ИГРА ОКОНЧЕНА',
    'over.final_score'          => 'итоговый счёт: {points}',
    'over.restart_hint'         => 'нажмите r, чтобы начать заново — q, чтобы выйти',
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
    'vs_keys'        => 'сброс
удержание
пауза
заново
выход',
    'vs.record' => 'счёт: {wins} выиграно, {losses} проиграно',
    'intro.title' => 'добро пожаловать в candy-tetris',
    'intro.goal' => 'удаляйте линии — не достигайте верха',
    'intro.press' => 'нажмите {keys}, чтобы играть',
    'intro.vs' => 'дуэльный режим: candy-tetris -v',
    'key_or' => ' или ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: неизвестный режим "{flag}"',
    'help_text'    => 'candy-tetris - клон Тетриса

Использование: candy-tetris [параметры]
  (без арг.) Одиночная игра
  -v, --vs   Игра против компьютера
  -h, --help Показать эту справку
  --version  Показать версию
',
    'cli.no_tty' => 'candy-tetris нужен интерактивный терминал: запускайте его из TTY (не через канал или перенаправление) с TERM, отличным от dumb.',
    'launch.extras' => 'candy-tetris: принимается только один аргумент, но на {count} больше: {list}',
    'score.save.failed' => 'candy-tetris: не удалось сохранить рекорды ({problem})',
    'keys.ignored' => 'candy-tetris: нечитаемый файл клавиш игнорируется ({problem})',
    'scores.ignored' => 'candy-tetris: нечитаемый файл рекордов игнорируется ({problem})',
    'config.problem' => 'candy-tetris: {detail} в {path}',
    'cli.refusal' => 'candy-tetris требуется интерактивный терминал (TTY).
Играет без цвета: установите NO_COLOR.',
    'cli.too_small' => 'candy-tetris требуется терминал {size}; этот — {actual}.
Измените размер окна, и игра продолжится здесь.',
    'cli.too_small_vs' => 'candy-tetris VS требуется терминал {size}; этот — {actual}.
Измените размер окна, и поединок продолжится здесь.',
];
