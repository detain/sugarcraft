<?php

/**
 * Spanish translations for sugar-skate.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Entrada establecida pero no legible',
    'store.cannot_read'         => 'No se puede leer el archivo: {path}',

    // bin/skate
    'cli.usage_set'             => 'Uso: {bin} set <clave> [valor]',
    'cli.usage_get'             => 'Uso: {bin} get <clave>',
    'cli.usage_delete'          => 'Uso: {bin} delete <clave>',
    'cli.usage_import'           => 'Uso: {bin} import <json|yaml> <ruta>',
    'cli.usage_export'            => 'Uso: {bin} export <json|yaml> [bd] [patrón]',
    'cli.deleted_n'             => '{count} entradas eliminadas.',
    'cli.unknown_command'       => 'Comando desconocido: {cmd}',
];
