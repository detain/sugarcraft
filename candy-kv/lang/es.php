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
    'cli.usage_set'             => 'Uso: {bin} set <clave> [valor] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Uso: {bin} get <clave> [--sanitize]',
    'cli.usage_delete'          => 'Uso: {bin} delete <clave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml|yml> <ruta> [--no-atomic]',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml|yml> [bd] [patrón]',
    'cli.deleted_n'             => '{count} entradas eliminadas.',
    'cli.unknown_command'       => 'Comando desconocido: {cmd}',
    'cli.deleted_none'           => 'Nada eliminado: ninguna entrada coincide con "{key}".',
    'cli.pattern_not_supported'  => 'get requiere una clave exacta, no un patrón. Use "{bin} list <pattern>" para patrones glob.',
    'store.did_you_mean'         => 'clave no encontrada; ¿quiso decir \'{key}\'?',
    'database.query_failed'      => 'La consulta a la base de datos falló.',
    'cli.key_not_found'          => 'clave no encontrada: {key}',
    'cli.deleted_one'            => '1 entrada eliminada.',
    'cli.imported_one'           => '1 entrada importada.',
    'cli.imported_n'             => '{count} entradas importadas.',
    'cli.would_delete_one'       => 'Se eliminaría 1 entrada.',
    'cli.would_delete_n'         => 'Se eliminarían {count} entradas.',
    'cli.pruned_n'               => '{count} entradas caducadas eliminadas.',
    'cli.pruned_one'             => '1 entrada caducada eliminada.',
    'cli.pruned_none'            => 'No hay entradas caducadas que eliminar.',
];
