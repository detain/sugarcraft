<?php

/**
 * Italian translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: impossibile trovare composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opzione sconosciuta --{flag}',
    'cli.empty_flag'  => 'candy-gifview: nome dell\'opzione mancante dopo --',
    'cli.bad_value'   => 'candy-gifview: valore non valido \'{value}\' per {flag} (è previsto un numero positivo)',
    'cli.too_many'    => 'candy-gifview: argomento imprevisto \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} non accetta valori',
    'cli.bad_preset' => 'candy-gifview: preset sconosciuto \'{preset}\' (atteso solid o density)',
    'cli.no_frames' => 'candy-gifview: nessun fotogramma da riprodurre in \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: impossibile riprodurre \'{path}\': {reason}',
    'cli.reason_frames_over' => 'l\'animazione supera il limite di {max} fotogrammi; caricamento rifiutato',
    'cli.reason_bytes_over' => 'il file supera il limite di {max} MiB',
    'cli.reason_cells_over' => 'l\'animazione richiede più del budget di {max} celle per questa finestra',
    'cli.duration_static' => 'candy-gifview: --duration richiede almeno due fotogrammi; questo file viene decodificato in {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd è necessario per decodificare i GIF (installare php-gd)',
    'cli.decoding' => 'candy-gifview: decodifica di \'{path}\'...',
    'cli.truncated' => 'candy-gifview: mostra i primi {max} fotogrammi — questo visualizzatore non legge animazioni più lunghe',
];
