<?php

/**
 * Portuguese translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'A entrada foi gravada mas não é mais legível (expirada, removida ou corrompida)',
    'store.cannot_read'         => 'Não é possível ler o ficheiro: {path}',
    'cli.usage_set'             => 'Uso: {bin} set <chave> [valor] [--ttl SECONDS]',
    'cli.usage_get'             => 'Uso: {bin} get <chave>',
    'cli.usage_delete'          => 'Uso: {bin} delete <chave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <caminho>',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [bd] [padrão]',
    'cli.deleted_n'             => '{count} entradas eliminadas.',
    'cli.unknown_command'       => 'Comando desconhecido: {cmd}',
    'cli.deleted_none'           => 'Nada removido: nenhuma entrada corresponde a "{key}".',
    'cli.pattern_not_supported'  => 'get exige uma chave exata, não um padrão. Use "{bin} list <pattern>" para padrões glob.',
    'store.did_you_mean'         => 'chave não encontrada; quis dizer \'{key}\'?',
    'database.query_failed'      => 'A consulta ao banco de dados falhou.',
];
