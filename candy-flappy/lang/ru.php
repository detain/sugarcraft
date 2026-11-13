<?php

/**
 * Russian translations for candy-flappy. Keys mirror en.php exactly;
 * {placeholder} tokens, key names and separators are contract (LocaleParityTest).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'ui.score'               => 'счёт: {score}',
    'ui.best'                => ' рекорд: {best}',
    'ui.new_best'            => ' 🏆 НОВЫЙ РЕКОРД: {best}',
    'ui.plain_new_best'      => ' НОВЫЙ РЕКОРД: {best}',
    'ui.crash_full'          => '💥 {why} — нажмите r чтобы начать заново · q — выйти',
    'ui.crash_short'         => '💥 {why} — r заново · q выход',
    'ui.crash_floor'         => 'r заново · q выход',
    'ui.ready_full'          => 'пробел/⏎/↑/w — старт · q — выход',
    'ui.ready_short'         => 'пробел — старт · q — выход',
    'ui.live_full'           => 'пробел/⏎/↑/w — взмах · r — заново · q — выход',
    'ui.live_short'          => 'пробел — взмах · q — выход',
    'ui.plain_crash_full'    => 'x {why} - нажмите r чтобы начать заново - q - выйти',
    'ui.plain_crash_short'   => 'x {why} - r заново - q выход',
    'ui.plain_crash_floor'   => 'r заново - q выход',
    'ui.why_splat'            => 'шлёп',
    'ui.why_ceiling'          => 'удар в потолок',
    'ui.why_ground'           => 'удар о землю',
    'ui.why_pipe'             => 'удар о трубу',
    'ui.plain_why_splat'      => 'шлёп',
    'ui.plain_why_ceiling'    => 'удар в потолок',
    'ui.plain_why_ground'     => 'удар о землю',
    'ui.plain_why_pipe'       => 'удар о трубу',
    'ui.plain_ready_full'    => 'пробел/Enter/up/w - старт . q - выход',
    'ui.plain_ready_short'   => 'пробел - старт . q - выход',
    'ui.plain_live_full'     => 'пробел/Enter/up/w - взмах . r - заново . q - выход',
    'ui.plain_live_short'    => 'пробел - взмах . q - выход',
    'ui.quit_hint' => 'нажмите q для выхода.',
    'ui.quarantine'           => '⚠ Предыдущая таблица рекордов оказалась нечитаемой — сохранено в архив как {file}',
    'ui.plain_quarantine'     => 'x Предыдущая таблица рекордов оказалась нечитаемой - сохранено в архив как {file}',
    'notty.line1'            => 'candy-flappy нужен терминал, способный отобразить вывод.',
    'notty.line2'            => 'stdout не является интерактивным терминалом или обнаружен TERM=dumb.',
    'size.notice'            => 'candy-flappy нужен терминал {need}; этот — {have}.',
    'size.resume'            => 'Измените размер окна, и игра продолжится прямо здесь.',
];
