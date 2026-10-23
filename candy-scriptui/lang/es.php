<?php

/**
 * Spanish translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'color vacío',
    'style.unrecognised_color' => 'color no reconocido: {value}',
    'style.hex_digit_count' => "el color hexadecimal debe tener 3 o 6 dígitos hexadecimales (p. ej. '#f80' o '#ff8800'); se obtuvo '{value}'",
    'style.padding_token_int' => "el token de padding/margin debe ser un entero; obtenido: '{token}'",
    'style.padding_count'     => 'padding/margin necesita 1, 2 o 4 enteros; obtenido: {count}',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style las entradas deben ser 'clave=valor' o 'elemento.prop=valor'; obtenido: '{raw}'",
    'style.unknown_prop'      => "propiedad de estilo desconocida: '{prop}'",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'error al iniciar el proceso hijo',

    // Command/TableCommand.php
    'border.unknown'          => 'estilo de borde desconocido: {name}',

    // Log/LogLevel.php
    'log.unknown_level'       => 'nivel de log desconocido: {name}',
    'log.encode_failed' => 'no se pudo codificar la entrada de registro a json',
    'log.bad_format' => 'cadena --format no válida: {format}',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'estilo de spinner desconocido: {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => '--type desconocido: {type}',
    'format.unknown_theme'    => 'tema desconocido: {name}',
    'io.read_failed' => 'no se pudo leer el archivo: {path}',
    'io.write_failed' => 'no se pudo escribir en el archivo: {path}',
    'log.unknown_formatter' => '--formatter desconocido: {name}',
    'completion.unknown_shell' => 'shell no admitido: {shell}',
    'confirm.unknown_default' => 'valor de --default desconocido: {value}',
    'cursor_mode.unknown' => 'modo de cursor desconocido: {mode}',
];
