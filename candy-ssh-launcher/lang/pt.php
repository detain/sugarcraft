<?php

/**
 * Portuguese translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'falha ao executar {bin}',
    'config.not_found'         => 'Configuração wishlist não encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: o valor de nível superior tem de ser um array',
    'config.json_entry_object' => 'wishlist json: cada entrada tem de ser um objeto',
    'config.yaml_continuation' => "wishlist yaml: continuação antes de qualquer bloco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: linha não analisável: {line}',
    'config.entry_missing_field' => 'entrada wishlist com campo obrigatório em falta: name ou host',
    'config.field_not_scalar'  => 'entrada do wishlist: o campo [{field}] deve ser um escalar, obtido {type}',
    'config.unreadable'       => 'não foi possível ler a configuração wishlist: {path}',
    'config.home_unset'       => 'não é possível expandir ~ sem $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: coleção de fluxo em linha não suportada: {value} (use uma lista aninhada)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint do wishlist: o campo [{field}] tem um valor inseguro com hífen inicial [{value}]',
    'endpoint.port_invalid'     => 'endpoint do wishlist [{host}]: porta inválida [{port}] — deve ser um inteiro de 1 a 65535',

    'cli.usage'                => 'Uso: candy-ssh-launcher [--config <caminho>] [--ssh <binário-ssh>] [--list] [-h|--help]',
    'cli.unknown_arg'          => 'candy-ssh-launcher: argumento desconhecido: {arg}',
    'cli.no_config'            => 'candy-ssh-launcher: não foi encontrada configuração. Passe --config <caminho>.',
    'cli.error'                => 'candy-ssh-launcher: {message}',
    'cli.cancelled'            => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binário ssh não encontrado ou não executável: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requer um valor',
    'cli.no_endpoints'   => 'candy-ssh-launcher: sem endpoints em {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (sem correspondências)',
    'picker.help'        => '  ↑/↓ selecionar · Enter conectar · Esc sair · digite para filtrar',
    'picker.count'       => '  ({shown} de {total})',
];
