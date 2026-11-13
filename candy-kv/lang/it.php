<?php

/**
 * Italian translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'La voce è stata scritta ma non è più leggibile (scaduta, eliminata o corrotta)',
    'store.cannot_read'         => 'Impossibile leggere il file: {path}',
    'cli.usage_set'             => 'Uso: {bin} set <chiave> [valore] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Uso: {bin} get <chiave> [--sanitize]',
    'cli.usage_delete'          => 'Uso: {bin} delete <chiave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <percorso> [--no-atomic] [--if-exists=MODE]',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [db] [pattern]',
    'cli.deleted_n'             => '{count} voci eliminate.',
    'cli.unknown_command'       => 'Comando sconosciuto: {cmd}',
    'cli.deleted_none'           => 'Niente eliminato: nessuna voce corrisponde a "{key}".',
    'cli.pattern_not_supported'  => 'get richiede una chiave esatta, non un pattern. Usa "{bin} list <pattern>" per i pattern glob.',
    'store.did_you_mean'         => 'chiave non trovata; intendevi \'{key}\'?',
    'database.query_failed'      => 'La query del database non è riuscita.',
    'cli.key_not_found'          => 'chiave non trovata: {key}',
    'cli.deleted_one'            => '1 voce eliminata.',
    'cli.imported_one'           => '1 voce importata.',
    'cli.imported_n'             => '{count} voci importate.',
    'cli.overwrote_one'          => '1 voce sovrascritta.',
    'cli.overwrote_n'            => '{count} voci sovrascritte.',
    'cli.would_delete_one'       => 'Verrà eliminata 1 voce.',
    'cli.would_delete_n'         => 'Verranno eliminate {count} voci.',
    'cli.pruned_n'               => 'Rimosse {count} voci scadute.',
    'cli.pruned_one'             => 'Rimossa 1 voce scaduta.',
    'cli.pruned_none'            => 'Nessuna voce scaduta da rimuovere.',
];
