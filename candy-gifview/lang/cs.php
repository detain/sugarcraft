<?php

/**
 * Czech translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Použití: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: nelze najít composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: neznámá volba --{flag}',
    'cli.empty_flag'  => 'candy-gifview: chybí název volby za --',
    'cli.bad_value'   => 'candy-gifview: neplatná hodnota \'{value}\' pro {flag} (očekává se kladné číslo)',
    'cli.too_many'    => 'candy-gifview: neočekávaný argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} nepřijímá hodnotu',
    'cli.bad_preset' => 'candy-gifview: neznámá předvolba \'{preset}\' (očekává se solid nebo density)',
    'cli.no_frames' => 'candy-gifview: v \'{path}\' nejsou žádné snímky k přehrání',
    'cli.cannot_play' => 'candy-gifview: nelze přehrát \'{path}\': {reason}',
    'cli.reason_frames_over' => 'animace překračuje limit {max} snímků; načtení zamítnuto',
    'cli.reason_bytes_over' => 'soubor je větší než limit {max} MiB',
    'cli.reason_cells_over' => 'animace potřebuje pro toto okno více než rozpočet {max} buněk',
    'cli.duration_static' => 'candy-gifview: --duration potřebuje alespoň dva snímky; tento soubor se dekóduje na {count}',
    'cli.need_gd' => 'candy-gifview: pro dekódování GIF je potřeba ext-gd (nainstalujte php-gd)',
    'cli.decoding' => 'candy-gifview: dekoduji \'{path}\'...',
    'cli.truncated' => 'candy-gifview: zobrazuji prvních {max} snímků — delší animace tento prohlížeč nečte',
];
