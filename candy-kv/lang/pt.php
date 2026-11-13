<?php

/**
 * Portuguese translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'A entrada foi gravada, mas já não é legível (expirada, eliminada ou corrompida)',
    'store.cannot_read'         => 'Não é possível ler o ficheiro: {path}',
    'cli.usage_set'             => 'Uso: {bin} set <chave> [valor] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Uso: {bin} get <chave> [--sanitize]',
    'cli.usage_delete'          => 'Uso: {bin} delete <chave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <caminho> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [bd] [padrão]',
    'cli.deleted_n'             => '{count} entradas eliminadas.',
    'cli.unknown_command'       => 'Comando desconhecido: {cmd}',
    'cli.deleted_none'           => 'Nada eliminado: nenhuma entrada corresponde a "{key}".',
    'cli.dryrun_none'            => 'Nada seria eliminado: nenhuma entrada corresponde a "{key}".',
    'cli.pattern_not_supported'  => 'get exige uma chave exata, não um padrão. Utilize "{bin} list <pattern>" para padrões glob.',
    'cli.db_deleted'             => 'Base de dados "{name}" eliminada.',
    'cli.db_not_found'           => 'Base de dados não encontrada: {db}',
    'cli.db_did_you_mean'        => 'Queria dizer a base de dados \'{db}\'?',
    'store.did_you_mean'         => 'Chave não encontrada; queria dizer \'{key}\'?',
    'cli.key_not_found'          => 'Chave não encontrada: {key}',
    'cli.deleted_one'            => '1 entrada eliminada.',
    'cli.imported_one'           => '1 entrada importada.',
    'cli.imported_n'             => '{count} entradas importadas.',
    'cli.overwrote_one'          => '1 entrada substituída.',
    'cli.overwrote_n'            => '{count} entradas substituídas.',
    'cli.would_delete_one'       => '1 entrada seria eliminada.',
    'cli.would_delete_n'         => '{count} entradas seriam eliminadas.',
    'cli.pruned_n'               => '{count} entradas expiradas removidas.',
    'cli.pruned_one'             => '1 entrada expirada removida.',
    'cli.pruned_none'            => 'Nenhuma entrada expirada para remover.',
];
