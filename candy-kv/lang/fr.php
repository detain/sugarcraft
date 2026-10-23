<?php

/**
 * French translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'L\'entrée a été écrite mais n\'est plus lisible (expirée, supprimée ou corrompue)',
    'store.cannot_read'         => 'Impossible de lire le fichier : {path}',

    // bin/candy-kv
    'cli.usage_set'             => 'Usage : {bin} set <clé> [valeur] [--ttl SECONDS]',
    'cli.usage_get'             => 'Usage : {bin} get <clé>',
    'cli.usage_delete'          => 'Usage : {bin} delete <clé>',
    'cli.usage_import'           => 'Usage : {bin} import <json|yaml|yml> <chemin>',
    'cli.usage_export'            => 'Usage : {bin} export <json|yaml|yml> [bdd] [motif]',
    'cli.deleted_n'             => '{count} entrées supprimées.',
    'cli.unknown_command'       => 'Commande inconnue : {cmd}',
    'cli.deleted_none'           => 'Rien supprimé : aucune entrée ne correspond à "{key}".',
    'cli.pattern_not_supported'  => 'get attend une clé exacte, pas un motif. Utilisez "{bin} list <pattern>" pour les motifs glob.',
    'store.did_you_mean'         => 'clé introuvable ; vouliez-vous dire \'{key}\' ?',
    'database.query_failed'      => 'La requête base de données a échoué.',
];
