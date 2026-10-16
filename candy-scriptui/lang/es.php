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

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'estilo de spinner desconocido: {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => '--type desconocido: {type}',
    'format.unknown_theme'    => 'tema desconocido: {name}',
];
