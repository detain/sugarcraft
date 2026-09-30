<?php

/**
 * Portuguese translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec indisponível; ext-pcntl necessário',
    'launcher.exec_failed'     => 'falha ao executar {bin}',
    'config.not_found'         => 'Configuração wishlist não encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: o valor de nível superior tem de ser um array',
    'config.json_entry_object' => 'wishlist json: cada entrada tem de ser um objeto',
    'config.yaml_continuation' => "wishlist yaml: continuação antes de qualquer bloco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: linha não analisável: {line}',
    'config.entry_missing_field' => 'entrada wishlist com campo obrigatório em falta: name ou host',
    'config.field_not_scalar'  => 'entrada do wishlist: o campo [{field}] deve ser um escalar, obtido {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint do wishlist: o campo [{field}] tem um valor inseguro com hífen inicial [{value}]',
    'endpoint.port_invalid'     => 'endpoint do wishlist [{host}]: porta inválida [{port}] — deve ser um inteiro de 1 a 65535',

    'cli.usage'                => 'Uso: wishlist [--config <caminho>] [--ssh <binário-ssh>]',
    'cli.unknown_arg'          => 'wishlist: argumento desconhecido: {arg}',
    'cli.no_config'            => 'wishlist: não foi encontrada configuração. Passe --config <caminho>.',
    'cli.error'                => 'wishlist: {message}',
    'cli.cancelled'            => 'wishlist: cancelado.',
    'cli.ssh_not_executable' => 'wishlist: binário ssh não encontrado ou não executável: {bin}',
];
