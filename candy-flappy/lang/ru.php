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
    'ui.crash_full'          => '💥 шлёп — нажмите r, чтобы сыграть снова, q — выйти',
    'ui.crash_short'         => '💥 шлёп — r снова · q выход',
    'ui.ready_full'          => 'пробел/⏎/↑/w — старт · r — заново · q — выход',
    'ui.ready_short'         => 'пробел — старт · q — выход',
    'ui.live_full'           => 'пробел/⏎/↑/w — взмах · r — заново · q — выход',
    'ui.live_short'          => 'пробел — взмах · q — выход',
    'ui.plain_crash_full'    => 'x шлёп - нажмите r, чтобы сыграть снова, q - выйти',
    'ui.plain_crash_short'   => 'x шлёп - r снова - q выход',
    'ui.plain_ready_full'    => 'пробел/Enter/^/w - старт . r - заново . q - выход',
    'ui.plain_ready_short'   => 'пробел - старт . q - выход',
    'ui.plain_live_full'     => 'пробел/Enter/^/w - взмах . r - заново . q - выход',
    'ui.plain_live_short'    => 'пробел - взмах . q - выход',
    'ui.quit_hint' => 'нажмите q для выхода.',
    'notty.line1'            => 'candy-flappy нужен интерактивный терминал.',
    'notty.line2'            => 'Пригодная цветовая способность не обнаружена (stdout без TTY или TERM=dumb).',
    'size.notice'            => 'candy-flappy нужен терминал {need}; этот — {have}.',
    'size.resume'            => 'Измените размер окна, и игра продолжится прямо здесь.',
];
