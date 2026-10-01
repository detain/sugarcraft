<?php

/**
 * French translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'candy-flip : fichier introuvable : {path}',
    'decoder.no_gd'            => 'candy-flip : ext-gd est requis',
    'decoder.not_gif'          => 'candy-flip : ce n\'est pas un GIF',
    'decoder.grid_too_large'   => 'candy-flip : le produit de la grille de cellules dépasse le maximum ({max})',
    'decoder.grid_too_small'   => 'candy-flip : les dimensions de la grille de cellules doivent être positives',
    'decoder.truncated'        => 'candy-flip : GIF tronqué',
    'decoder.screen_too_large' => 'candy-flip : les dimensions d\'écran du GIF dépassent le maximum ({max} pixels)',
    'cli.usage'                => 'usage : candy-flip <gif> [solid|density]',
    'cli.no_autoload'          => 'candy-flip : impossible de trouver composer autoload.php',
];
