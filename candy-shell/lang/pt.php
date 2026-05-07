<?php

/**
 * Portuguese translations for candy-shell.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'cor vazia',
    'style.unrecognised_color' => 'cor não reconhecida: {value}',
    'style.padding_token_int' => "o token de padding/margin tem de ser um inteiro; obtido: '{token}'",
    'style.padding_count'     => 'padding/margin precisa de 1, 2 ou 4 inteiros; obtido: {count}',
    'style.bad_entry'         => "--style as entradas têm de ser 'chave=valor' ou 'elemento.prop=valor'; obtido: '{raw}'",
    'style.unknown_prop'      => "propriedade de estilo desconhecida: '{prop}'",
    'process.spawn_failed'    => 'falha ao iniciar o processo filho',
    'border.unknown'          => 'estilo de borda desconhecido: {name}',
    'log.unknown_level'       => 'nível de registo desconhecido: {name}',
    'spinner.unknown_style'   => 'estilo de spinner desconhecido: {name}',
    'format.unknown_type'     => '--type desconhecido: {type}',
    'format.unknown_theme'    => 'tema desconhecido: {name}',
];
