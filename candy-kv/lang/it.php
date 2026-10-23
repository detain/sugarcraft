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
    'cli.usage_set'             => 'Uso: {bin} set <chiave> [valore] [--ttl SECONDS]',
    'cli.usage_get'             => 'Uso: {bin} get <chiave>',
    'cli.usage_delete'          => 'Uso: {bin} delete <chiave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <percorso>',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [db] [pattern]',
    'cli.deleted_n'             => '{count} voci eliminate.',
    'cli.unknown_command'       => 'Comando sconosciuto: {cmd}',
    'cli.deleted_none'           => 'Niente eliminato: nessuna voce corrisponde a "{key}".',
    'cli.pattern_not_supported'  => 'get richiede una chiave esatta, non un pattern. Usa "{bin} list <pattern>" per i pattern glob.',
    'store.did_you_mean'         => 'chiave non trovata; intendevi \'{key}\'?',
    'database.query_failed'      => 'La query del database non è riuscita.',
];
