<?php

/**
 * Spanish translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'La entrada se escribió pero ya no es legible (caducada, eliminada o corrupta)',
    'store.cannot_read'         => 'No se puede leer el archivo: {path}',

    // bin/candy-kv
    'cli.usage_set'             => 'Uso: {bin} set <clave> [valor] [--ttl SECONDS]',
    'cli.usage_get'             => 'Uso: {bin} get <clave>',
    'cli.usage_delete'          => 'Uso: {bin} delete <clave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <ruta>',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [bd] [patrón]',
    'cli.deleted_n'             => '{count} entradas eliminadas.',
    'cli.unknown_command'       => 'Comando desconocido: {cmd}',
    'cli.deleted_none'           => 'Nada eliminado: ninguna entrada coincide con "{key}".',
    'cli.pattern_not_supported'  => 'get requiere una clave exacta, no un patrón. Use "{bin} list <pattern>" para patrones glob.',
    'store.did_you_mean'         => 'clave no encontrada; ¿quiso decir \'{key}\'?',
    'database.query_failed'      => 'La consulta a la base de datos falló.',
];
