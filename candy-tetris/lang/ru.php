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
поворот 180
мягкое падение
жёсткое падение
удержание
пауза
заново (финал)
выход',
    'next_label' => 'след:',
    'hold_label' => 'холд:',
    'paused'     => '[ пауза — нажмите {keys}, чтобы продолжить ]',

    // Game-over banner
    'over.title'                => 'ИГРА ОКОНЧЕНА',
    'over.final_score'          => 'итоговый счёт: {points}',
    'over.restart_hint'         => 'нажмите {restart}, чтобы начать заново — {quit}, чтобы выйти',
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
    'over.games' => 'завершённых игр: {games}',

    // Versus renderer
    'label.player'   => 'ИГРОК',
    'label.computer' => 'КОМПЬЮТЕР',
    'win.player'     => 'ВЫ ПОБЕДИЛИ!',
    'win.computer'   => 'ПОБЕДИЛ КОМПЬЮТЕР!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'снятые линии отправляют сопернику мусор — по одному отверстию в ряду',
    'vs.record' => 'счёт: {wins} выиграно, {losses} проиграно',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'вы',
    'vs.strip.computer' => 'компьютер',
    'vs.strip.score' => 'счёт:',
    'vs.strip.lines' => 'линии:',
    'vs.strip.level' => 'уровень:',
    'intro.title' => 'добро пожаловать в candy-tetris',
    'intro.goal' => 'удаляйте линии — не достигайте верха',
    'intro.press' => 'нажмите {keys}, чтобы играть',
    // candy-tetris-006/-095 (ru): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'нажмите {keys} в любой момент, чтобы увидеть клавиши',
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
    // candy-tetris-006/-095 (ru): --help keys section header.
    'help.keys' => 'клавиши:',
    // candy-tetris-006/-095 (ru): the press that closes the key reference.
    'help.close' => 'нажмите {keys} ещё раз, чтобы закрыть',
    // candy-tetris-006/-095 (ru): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'рамке нужен терминал не меньше {cols}x{rows}',
    // candy-tetris-006/-095 (ru): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'клавиши: {keys}',
    // candy-tetris-054/-083 (ru): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.quit' => 'нажмите {keys} ещё раз для выхода',
    // candy-tetris-054/-083 (ru): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.restart' => 'нажмите {keys} ещё раз для перезапуска',
    // candy-tetris-092/-025 (ru): game-feel moments and the on-frame slot copy.
    'moment.lines.one' => '1 линия!',
    'moment.lines.many' => '{count} линии!',
    'moment.level' => 'уровень {level}!',
    'moment.garbage' => 'входящий мусор!',
    'cli.no_tty' => 'candy-tetris нужен интерактивный терминал: запускайте его из TTY (не через канал или перенаправление) с TERM, отличным от dumb.',
    'launch.extras' => 'candy-tetris: принимается только один аргумент, но на {count} больше: {list}',
    'score.save.failed' => 'candy-tetris: не удалось сохранить рекорды ({problem})',
    'keys.ignored' => 'candy-tetris: нечитаемый файл клавиш игнорируется ({problem})',
    'scores.ignored' => 'candy-tetris: нечитаемый файл рекордов игнорируется ({problem})',
    'config.problem' => '{detail} в {path}',
    'cli.refusal' => 'candy-tetris требуется интерактивный терминал (TTY).
Играет без цвета: установите NO_COLOR.',
    'cli.too_small' => 'candy-tetris требуется терминал {size}; этот — {actual}.
Измените размер окна, и игра продолжится здесь.',
    'cli.too_small_vs' => 'candy-tetris VS требуется терминал {size}; этот — {actual}.
Измените размер окна, и поединок продолжится здесь.',
];
