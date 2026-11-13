<?php

/**
 * Spanish translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'componente RGB fuera de rango [0,255]: {value}',
    'color.invalid_hex'           => 'color hex inválido: {hex}',
    'color.ansi_out_of_range'     => 'índice ansi fuera de rango [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'índice ansi256 fuera de rango [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'código de primer plano de 16 colores inválido: {code}',
    'ansi.invalid_bg_code'        => 'código de fondo de 16 colores inválido: {code}',
    'ansi.component_out_of_range' => '{label} fuera de rango [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open falló para: {cmd}',
    'color.parse_empty' => 'no se puede analizar un nombre de color vacío',
    'color.parse_unknown' => 'nombre de color desconocido: {name}',
    'ansi.invalid_scs_slot' => 'slot SCS no válido: {slot} (se esperan 0-3 para G0-G3)',
    'ansi.invalid_scs_designator' => 'designador SCS no válido: {designator} (se espera un byte en 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'el recuento de repetición sixel no debe ser negativo: {count}',
    'semaphore.limit_invalid' => 'el límite del semáforo debe ser al menos 1, obtenido {limit}',
    'semaphore.limit_busy' => 'semáforo ocupado: {held} en ejecución, {waiting} en espera',
    'semaphore.shutdown' => 'el semáforo se cerró antes de conceder un permiso',
    'semaphore.closed' => 'el semáforo está cerrado y no puede conceder otro permiso',
    'semaphore.derive_closed' => 'no se puede derivar un nuevo grupo de un semáforo cerrado',
    'lru_map.capacity_invalid' => 'la capacidad LRU debe ser al menos 1, obtenida {capacity}',
    'lru_map.capacity_too_large' => 'la capacidad LRU {capacity} supera el tope de crecimiento {max}',
    'lru_map.evict_from_empty' => 'no se puede desalojar un mapa LRU vacío',
    'errors.negative_not_allowed' => 'no se permiten valores negativos para {name}',
    'errors.value_must_be_positive' => '{name} debe ser positivo, obtenido {value}',
    'errors.value_out_of_range' => '{name} debe estar entre {min} y {max}, obtenido {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
];
