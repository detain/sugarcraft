<?php

/**
 * French translations for sugar-skate.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Entrée définie mais non lisible',
    'store.cannot_read'         => 'Impossible de lire le fichier : {path}',

    // bin/skate
    'cli.usage_set'             => 'Usage : {bin} set <clé> [valeur]',
    'cli.usage_get'             => 'Usage : {bin} get <clé>',
    'cli.usage_delete'          => 'Usage : {bin} delete <clé>',
    'cli.usage_import'           => 'Usage : {bin} import <json|yaml> <chemin>',
    'cli.usage_export'            => 'Usage : {bin} export <json|yaml> [bdd] [motif]',
    'cli.deleted_n'             => '{count} entrées supprimées.',
    'cli.unknown_command'       => 'Commande inconnue : {cmd}',
];
