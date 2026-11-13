<?php

/**
 * French translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation : fichier introuvable : {path}',
    'decoder.no_gd'              => 'sugar-animation : installez l\'extension image GD (ext-gd) pour décoder les GIF ; la lecture d\'art .ansi n\'en a pas besoin',
    'decoder.not_gif'            => 'sugar-animation : ce n\'est pas un GIF',
    'decoder.unreadable'         => 'sugar-animation : fichier illisible : {path}',
    'decoder.input_too_large'    => 'sugar-animation : fichier trop volumineux ({max} au maximum)',
    'decoder.not_a_file' => 'sugar-animation : n’est pas un fichier : {path}',
    'decoder.too_many_frames' => 'sugar-animation : nombre d’images trop élevé ({max} au maximum)',
    'decoder.total_cells_too_large' => 'sugar-animation : budget total de cellules dépassé ({max} cellules au maximum)',
    'decoder.grid_too_large'     => 'sugar-animation : grille de cellules trop grande (maximum {max} cellules)',
    'decoder.grid_too_small'     => 'sugar-animation : les dimensions de la grille de cellules doivent être positives',
    'decoder.truncated'          => 'sugar-animation : GIF tronqué',
    'decoder.screen_too_large'   => 'sugar-animation : les dimensions d\'écran du GIF dépassent le maximum ({max} pixels)',
    'decoder.screen_too_small'   => 'sugar-animation : les dimensions d\'écran GIF doivent être positives',
    'artlibrary.not_a_directory' => 'sugar-animation : n\'est pas un répertoire : {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation : impossible de lister le répertoire : {dir}',
    'ansiart.cannot_read'        => 'sugar-animation : impossible de lire le fichier d\'art : {path}',
    'ansiart.read_failed'        => 'sugar-animation : échec de la lecture du fichier d\'art : {path}',
];
