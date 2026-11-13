<?php

/**
 * Portuguese translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'tema desconhecido: {name}',
    'render.theme_config_unreadable' => 'configuração do tema ilegível: {path}',
    'render.theme_config_too_large' => 'configuração do tema excede {limit} bytes: {path}',
    'glamour.json_invalid' => 'a configuração do tema não é um JSON válido',
    'glamour.json_object' => 'a configuração do tema deve ser um objeto JSON',
    'glamour.decoration_unsafe' => 'o campo {slot} do tema só pode conter quebras de linha, tabulações e espaços',
    'glamour.value_too_large' => 'a chave {key} do tema excede o limite máximo de {limit}',
    'render.no_input_tty' => 'sem entrada: indique um ficheiro ou envie Markdown pelo standard input',
    'render.no_input_empty' => 'sem entrada: o standard input está vazio',
    'render.pager_needs_tty' => 'o paginador necessita de um terminal; rendicione para a saída standard sem -p',
    'render.file_missing' => 'não é possível abrir {path}: ficheiro inexistente',
    'render.file_is_directory' => 'não é possível abrir {path}: é um diretório',
    'render.file_unreadable' => 'não é possível abrir {path}: ficheiro ilegível',
    'render.binary_input' => 'renderização de {path} recusada: não é um ficheiro de texto',
    'render.input_too_large' => 'a entrada excede {limit} bytes: {path}',
    'render.width_invalid' => 'valor de --width inválido: {value} (esperado um inteiro entre 0 e 9999; 0 desativa a quebra de linha)',
];
