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
    'cli.usage'                => 'Uso: wishlist [--config <caminho>] [--ssh <binário-ssh>]',
    'cli.unknown_arg'          => 'wishlist: argumento desconhecido: {arg}',
    'cli.no_config'            => 'wishlist: não foi encontrada configuração. Passe --config <caminho>.',
    'cli.error'                => 'wishlist: {message}',
    'cli.cancelled'            => 'wishlist: cancelado.',
];
