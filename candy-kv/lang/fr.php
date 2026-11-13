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
    'cli.usage_set'             => 'Usage : {bin} set <clé> [valeur] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Usage : {bin} get <clé> [--sanitize]',
    'cli.usage_delete'          => 'Usage : {bin} delete <clé>',
    'cli.usage_import'           => 'Usage : {bin} import <json|yaml|yml> <chemin> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'Usage : {bin} export <json|yaml|yml> [bdd] [motif]',
    'cli.deleted_n'             => '{count} entrées supprimées.',
    'cli.unknown_command'       => 'Commande inconnue : {cmd}',
    'cli.deleted_none'           => 'Aucune suppression : aucune entrée ne correspond à "{key}".',
    'cli.dryrun_none'            => 'Rien ne serait supprimé : aucune entrée ne correspond à "{key}".',
    'cli.pattern_not_supported'  => 'get attend une clé exacte, pas un motif. Utilisez "{bin} list <pattern>" pour les motifs glob.',
    'cli.db_deleted'             => 'Base de données "{name}" supprimée.',
    'cli.db_not_found'           => 'Base de données introuvable : {db}',
    'cli.db_did_you_mean'        => 'Vouliez-vous dire la base de données \'{db}\' ?',
    'store.did_you_mean'         => 'Clé introuvable ; vouliez-vous dire \'{key}\' ?',
    'cli.key_not_found'          => 'Clé introuvable : {key}',
    'cli.deleted_one'            => '1 entrée supprimée.',
    'cli.imported_one'           => '1 entrée importée.',
    'cli.imported_n'             => '{count} entrées importées.',
    'cli.overwrote_one'          => '1 entrée écrasée.',
    'cli.overwrote_n'            => '{count} entrées écrasées.',
    'cli.would_delete_one'       => '1 entrée serait supprimée.',
    'cli.would_delete_n'         => '{count} entrées seraient supprimées.',
    'cli.pruned_n'               => '{count} entrées expirées supprimées.',
    'cli.pruned_one'             => '1 entrée expirée supprimée.',
    'cli.pruned_none'            => 'Aucune entrée expirée à supprimer.',
];
