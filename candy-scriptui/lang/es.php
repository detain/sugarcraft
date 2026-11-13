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
    'style.unrecognised_color' => 'color no reconocido: {value} — allowed: {allowed}',
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
    'spinner.windows_unsupported' => 'spin no puede ejecutar comandos en Windows nativo: el backend de procesos no es compatible allí — use WSL en su lugar.',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'tema desconocido: {name}',
    'io.read_failed' => 'no se pudo leer el archivo: {path}',
    'io.write_failed' => 'no se pudo escribir en el archivo: {path}',
    'log.unknown_formatter' => '--formatter desconocido: {name}',
    'completion.unknown_shell' => 'shell no admitido: {shell}',
    'confirm.unknown_default' => 'valor de --default desconocido: {value}',
    'cursor_mode.unknown' => 'modo de cursor desconocido: {mode}',
    'runtime.no_terminal' => '{command} necesita una terminal interactiva; no hay terminal de control disponible.',
    'runtime.bad_timeout' => 'Valor de --timeout no válido \'{value}\': se espera un número de segundos no negativo (0 desactiva el plazo).',
    'runtime.unknown_choice' => 'Valor no válido para {option}: \'{value}\'. Permitidos: {allowed}.',
    'hints.choose' => '↑/↓ mover · espacio alterna (múltiple) · enter seleccionar · esc cancelar',
    'hints.filter' => 'escribe para filtrar · ↑/↓ mover · tab alterna (múltiple) · enter seleccionar · esc cancelar',
    'hints.pager' => 'space/PgDn abajo · PgUp/b arriba · g inicio · G fin · q salir',
    'format.template_unresolved' => 'plantilla: {count} marcador(es) expandido(s) a vacío: {names} — una variable debe estar definida y permitida con --allow-env.',
    'runtime.empty_input' => 'La entrada no contenía líneas; nada que hacer.',
    'runtime.conflicting_flags' => 'Las opciones {first} y {second} no se pueden combinar con valores diferentes.',
];
