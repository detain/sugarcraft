<?php

/**
 * Brazilian Portuguese translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'falha ao executar {bin}',
    'config.not_found'         => 'Configuração wishlist não encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: o valor de nível superior deve ser um array',
    'config.json_entry_object' => 'wishlist json: cada entrada deve ser um objeto',
    'config.yaml_continuation' => "wishlist yaml: continuação antes de qualquer bloco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: linha não analisável {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: mapeamento de bloco sob `{field}:` na linha {line}: {found} — este campo deve ser uma lista de itens `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: aspa não fechada no valor: {found}',
    'config.entry_missing_field' => 'entrada da wishlist #{index}: falta um campo obrigatório: {field}',
    'config.unknown_key' => 'campo desconhecido `{key}` ignorado — não é uma chave documentada',
    'config.unknown_key_nearmiss' => 'campo desconhecido `{key}` ignorado — quis dizer `{field}`?',
    'config.duplicate_name' => 'nome de endpoint duplicado `{name}` — todas as linhas são carregadas; nomes são identificadores',
    'config.field_not_scalar'  => 'entrada do wishlist: o campo [{field}] deve ser um escalar, obtido {type}',
    'config.too_large'          => 'a configura\u00e7\u00e3o da lista excede o limite de {limit} bytes: {path}',
    'config.list_too_long'      => 'item da lista: o campo [{field}] cont\u00e9m mais dos {limit} itens permitidos pelo carregador',
    'config.field_not_list'       => 'item da lista: o campo [{field}] deve ser uma lista de valores, não um mapa chave-valor',
    'config.encoding_utf16'       => '{path} está codificado em UTF-16 — guarde a configuração novamente como UTF-8',
    'config.unreadable'       => 'não foi possível ler a configuração wishlist: {path}',
    'config.home_unset'       => 'não é possível expandir ~ sem $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: coleção de fluxo em linha não suportada: {value} (use uma lista aninhada)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint do wishlist: o campo [{field}] tem um valor inseguro com hífen inicial [{value}]',
    'endpoint.host_empty' => 'o endpoint `{name}` tem um host vazio — uma linha selecionável precisa de um nome de host',
    'endpoint.name_empty' => 'o endpoint tem um nome vazio — uma linha selecionável precisa de um nome',
    'endpoint.option_empty' => 'o endpoint `{name}` tem uma entrada de opção vazia — um valor -o em branco é recusado',
    'endpoint.port_invalid'     => 'endpoint do wishlist [{host}]: porta inválida [{port}] — deve ser um inteiro de 1 a 65535',

    'cli.usage'                => 'Uso: candy-ssh-launcher [--config <caminho>] [--ssh <binário-ssh>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'          => 'candy-ssh-launcher: argumento desconhecido: {arg}',
    'cli.no_config'            => 'candy-ssh-launcher: não foi encontrada configuração. Crie ~/.config/wishlist.yml (consulte o início rápido do README) ou passe --config <caminho>.',
    'cli.error'                => 'candy-ssh-launcher: {message}',
    'cli.cancelled'            => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binário ssh não encontrado ou não executável: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requer um valor',
    'cli.no_endpoints'   => 'candy-ssh-launcher: sem endpoints em {path}',
    'cli.not_a_terminal' => 'o seletor precisa de um terminal interativo: stdin e stdout devem ser TTY. Use --list para saída legível por máquina.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (sem correspondências — Backspace ou Esc)',
    'picker.help'        => '↑/↓ selecionar · Enter conectar · Esc sair · digite para filtrar',
    'picker.help_keys'   => '⌫ apagar · Ctrl+U/W limpar · PageUp/PageDown página · Inicio/Fim saltar',
    'picker.count'       => '  ({shown} de {total})',
];
