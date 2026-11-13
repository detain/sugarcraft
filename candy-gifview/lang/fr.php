<?php

/**
 * French translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'usage candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--lang=CODE] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview : impossible de trouver composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview : option inconnue --{flag}',
    'cli.empty_flag'  => 'candy-gifview : nom d\'option manquant après --',
    'cli.bad_value'   => 'candy-gifview : valeur invalide \'{value}\' pour {flag} (attendu : un nombre positif)',
    'cli.too_many'    => 'candy-gifview : argument inattendu \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview : --{flag} n\'attend aucune valeur',
    'cli.bad_preset' => 'candy-gifview: préréglage inconnu \'{preset}\' (attendu : solid ou density)',
    'cli.no_frames' => 'candy-gifview: aucune image à lire dans \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: impossible de lire \'{path}\' : {reason}',
    'cli.reason_frames_over' => 'l\'animation dépasse la limite de {max} images ; chargement refusé',
    'cli.reason_bytes_over' => 'le fichier dépasse la limite de {max} MiB',
    'cli.reason_cells_over' => 'l\'animation dépasse le budget de {max} cellules pour cette fenêtre',
    'cli.duration_static' => 'candy-gifview: --duration exige au moins deux images ; ce fichier se décode en {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd est requis pour décoder les GIF (installer php-gd)',
    'cli.decoding' => 'candy-gifview: décodage de \'{path}\'...',
    'cli.truncated' => 'candy-gifview: affichage des {max} premières images — ce lecteur ne lit pas les animations plus longues',
];
