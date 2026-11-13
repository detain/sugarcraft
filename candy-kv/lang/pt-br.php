<?php

/**
 * Brazilian Portuguese translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'A entrada foi gravada, mas não pode mais ser lida (expirada, excluída ou corrompida)',
    'store.cannot_read'         => 'Não é possível ler o arquivo: {path}',
    'cli.usage_set'             => 'Uso: {bin} set <chave> [valor] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Uso: {bin} get <chave> [--sanitize]',
    'cli.usage_delete'          => 'Uso: {bin} delete <chave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <caminho> [--no-atomic] [--if-exists=MODE]',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [bd] [padrão]',
    'cli.deleted_n'             => '{count} entradas removidas.',
    'cli.unknown_command'       => 'Comando desconhecido: {cmd}',
    'cli.deleted_none'           => 'Nada excluído: nenhuma entrada corresponde a "{key}".',
    'cli.pattern_not_supported'  => 'get exige uma chave exata, não um padrão. Use "{bin} list <pattern>" para padrões glob.',
    'cli.db_deleted'             => 'Banco de dados "{name}" excluído.',
    'cli.db_not_found'           => 'banco de dados não encontrado: {db}',
    'cli.db_did_you_mean'        => 'você quis dizer o banco de dados \'{db}\'?',
    'store.did_you_mean'         => 'chave não encontrada; você quis dizer \'{key}\'?',
    'cli.key_not_found'          => 'chave não encontrada: {key}',
    'cli.deleted_one'            => '1 entrada excluída.',
    'cli.imported_one'           => '1 entrada importada.',
    'cli.imported_n'             => '{count} entradas importadas.',
    'cli.overwrote_one'          => '1 entrada sobrescrita.',
    'cli.overwrote_n'            => '{count} entradas sobrescritas.',
    'cli.would_delete_one'       => '1 entrada seria excluída.',
    'cli.would_delete_n'         => '{count} entradas seriam excluídas.',
    'cli.pruned_n'               => '{count} entradas expiradas removidas.',
    'cli.pruned_one'             => '1 entrada expirada removida.',
    'cli.pruned_none'            => 'Nenhuma entrada expirada para remover.',
];
