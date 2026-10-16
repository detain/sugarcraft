<?php

/**
 * Polish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'pusty kolor',
    'style.unrecognised_color' => 'nierozpoznany kolor: {value}',
    'style.padding_token_int' => "token padding/margin musi być liczbą całkowitą; otrzymano: '{token}'",
    'style.padding_count'     => 'padding/margin wymaga 1, 2 lub 4 liczb całkowitych; otrzymano: {count}',
    'style.bad_entry'         => "--style wpisy muszą mieć formę 'klucz=wartość' lub 'element.prop=wartość'; otrzymano: '{raw}'",
    'style.unknown_prop'      => "nieznana właściwość stylu: '{prop}'",
    'process.spawn_failed'    => 'nie udało się uruchomić procesu potomnego',
    'border.unknown'          => 'nieznany styl obramowania: {name}',
    'log.unknown_level'       => 'nieznany poziom logowania: {name}',
    'spinner.unknown_style'   => 'nieznany styl spinera: {name}',
    'format.unknown_type'     => 'nieznany --type: {type}',
    'format.unknown_theme'    => 'nieznany motyw: {name}',
];
