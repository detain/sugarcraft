<?php

/**
 * Brazilian Portuguese translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec indisponível; ext-pcntl necessária',
    'launcher.exec_failed'     => 'falha ao executar {bin}',
    'config.not_found'         => 'Configuração wishlist não encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: o valor de nível superior deve ser um array',
    'config.json_entry_object' => 'wishlist json: cada entrada deve ser um objeto',
    'config.yaml_continuation' => "wishlist yaml: continuação antes de qualquer bloco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: linha não analisável: {line}',
    'config.entry_missing_field' => 'entrada wishlist com campo obrigatório faltando: name ou host',
    'config.field_not_scalar'  => 'entrada do wishlist: o campo [{field}] deve ser um escalar, obtido {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint do wishlist: o campo [{field}] tem um valor inseguro com hífen inicial [{value}]',
    'endpoint.port_invalid'     => 'endpoint do wishlist [{host}]: porta inválida [{port}] — deve ser um inteiro de 1 a 65535',

    'cli.usage'                => 'Uso: candy-ssh-launcher [--config <caminho>] [--ssh <binário-ssh>]',
    'cli.unknown_arg'          => 'candy-ssh-launcher: argumento desconhecido: {arg}',
    'cli.no_config'            => 'candy-ssh-launcher: não foi encontrada configuração. Passe --config <caminho>.',
    'cli.error'                => 'candy-ssh-launcher: {message}',
    'cli.cancelled'            => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binário ssh não encontrado ou não executável: {bin}',
];
