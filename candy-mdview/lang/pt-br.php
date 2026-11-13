<?php

/**
 * Brazilian Portuguese translations for candy-mdview.
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
    'glamour.decoration_unsafe' => 'o campo {slot} do tema só pode conter novas linhas, tabulações e espaços',
    'glamour.value_too_large' => 'a chave {key} do tema excede o máximo de {limit}',
    'render.no_input_tty' => 'sem entrada: informe um arquivo ou envie Markdown pela entrada padrão',
    'render.no_input_empty' => 'sem entrada: a entrada padrão está vazia',
    'render.pager_needs_tty' => 'o paginador precisa de um terminal; renderize para a saída padrão sem -p',
    'render.file_missing' => 'não foi possível abrir {path}: arquivo inexistente',
    'render.file_is_directory' => 'não foi possível abrir {path}: é um diretório',
    'render.file_unreadable' => 'não foi possível abrir {path}: arquivo não legível',
    'render.binary_input' => 'renderização de {path} recusada: não é um arquivo de texto',
    'render.input_too_large' => 'a entrada excede {limit} bytes: {path}',
    'render.width_invalid' => 'valor de --width inválido: {value} (esperado um inteiro entre 0 e 9999; 0 desativa a quebra de linha)',
];
