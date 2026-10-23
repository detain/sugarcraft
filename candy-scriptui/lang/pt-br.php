<?php

/**
 * Brazilian Portuguese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'cor vazia',
    'style.unrecognised_color' => 'cor não reconhecida: {value}',
    'style.hex_digit_count' => "a cor hexadecimal deve ter 3 ou 6 dígitos hexadecimais (por exemplo, '#f80' ou '#ff8800'); obtido '{value}'",
    'style.padding_token_int' => "o token de padding/margin deve ser um inteiro; obtido: '{token}'",
    'style.padding_count'     => 'padding/margin precisa de 1, 2 ou 4 inteiros; obtido: {count}',
    'style.bad_entry'         => "--style as entradas devem ser 'chave=valor' ou 'elemento.prop=valor'; obtido: '{raw}'",
    'style.unknown_prop'      => "propriedade de estilo desconhecida: '{prop}'",
    'process.spawn_failed'    => 'falha ao iniciar o processo filho',
    'border.unknown'          => 'estilo de borda desconhecido: {name}',
    'log.unknown_level'       => 'nível de log desconhecido: {name}',
    'log.encode_failed' => 'falha ao codificar a entrada de log para json',
    'log.bad_format' => 'string --format inválida: {format}',
    'spinner.unknown_style'   => 'estilo de spinner desconhecido: {name}',
    'format.unknown_type'     => '--type desconhecido: {type}',
    'format.unknown_theme'    => 'tema desconhecido: {name}',
    'io.read_failed' => 'não foi possível ler o arquivo: {path}',
    'io.write_failed' => 'não foi possível gravar no arquivo: {path}',
    'log.unknown_formatter' => '--formatter desconhecido: {name}',
    'completion.unknown_shell' => 'shell não suportado: {shell}',
    'confirm.unknown_default' => 'valor de --default desconhecido: {value}',
    'cursor_mode.unknown' => 'modo de cursor desconhecido: {mode}',
];
