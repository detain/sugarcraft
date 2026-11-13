<?php

/**
 * Czech translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB složka mimo rozsah [0,255]: {value}',
    'color.invalid_hex'           => 'Neplatná hex barva: {hex}',
    'color.ansi_out_of_range'     => 'ANSI index mimo rozsah [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256 index mimo rozsah [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'Neplatný kód popředí 16 barev: {code}',
    'ansi.invalid_bg_code'        => 'Neplatný kód pozadí 16 barev: {code}',
    'ansi.component_out_of_range' => '{label} mimo rozsah [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open selhala pro: {cmd}',
    'color.parse_empty' => 'nelze zpracovat prázdný název barvy',
    'color.parse_unknown' => 'neznámý název barvy: {name}',
    'ansi.invalid_scs_slot' => 'neplatný slot SCS: {slot} (očekáváno 0-3 pro G0-G3)',
    'ansi.invalid_scs_designator' => 'neplatný designátor SCS: {designator} (očekáván jeden bajt v 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'počet opakování sixel nesmí být záporný: {count}',
    'semaphore.limit_invalid' => 'limit semaforu musí být alespoň 1, získáno {limit}',
    'semaphore.limit_busy' => 'semafor je zaneprázdněn: {held} běží, {waiting} čeká',
    'semaphore.shutdown' => 'semafor byl uzavřen před udělením povolení',
    'semaphore.closed' => 'semafor je uzavřen a nemůže udělit další povolení',
    'semaphore.derive_closed' => 'z uzavřeného semaforu nelze odvodit nový fond',
    'lru_map.capacity_invalid' => 'kapacita LRU musí být alespoň 1, získáno {capacity}',
    'lru_map.capacity_too_large' => 'kapacita LRU {capacity} překračuje stráž růstu {max}',
    'lru_map.evict_from_empty' => 'z prázdné mapy LRU nelze vyřadit',
    'errors.negative_not_allowed' => 'záporná hodnota není pro {name} povolena',
    'errors.value_must_be_positive' => '{name} musí být kladné, získáno {value}',
    'errors.value_out_of_range' => '{name} musí být mezi {min} a {max}, získáno {value}',

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
