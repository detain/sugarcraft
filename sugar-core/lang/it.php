<?php

/**
 * Italian translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'componente RGB fuori dall\'intervallo [0,255]: {value}',
    'color.invalid_hex'           => 'colore hex non valido: {hex}',
    'color.ansi_out_of_range'     => 'indice ansi fuori dall\'intervallo [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'indice ansi256 fuori dall\'intervallo [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'codice primo piano 16 colori non valido: {code}',
    'ansi.invalid_bg_code'        => 'codice sfondo 16 colori non valido: {code}',
    'ansi.component_out_of_range' => '{label} fuori dall\'intervallo [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open fallito per: {cmd}',
    'color.parse_empty' => 'impossibile analizzare un nome di colore vuoto',
    'color.parse_unknown' => 'nome di colore sconosciuto: {name}',
    'ansi.invalid_scs_slot' => 'slot SCS non valido: {slot} (atteso 0-3 per G0-G3)',
    'ansi.invalid_scs_designator' => 'designatore SCS non valido: {designator} (atteso un byte in 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'il conteggio di ripetizione sixel non deve essere negativo: {count}',
    'semaphore.limit_invalid' => 'il limite del semaforo deve essere almeno 1, ottenuto {limit}',
    'semaphore.limit_busy' => 'semaforo occupato: {held} in esecuzione, {waiting} in attesa',
    'semaphore.shutdown' => 'il semaforo è stato chiuso prima di concedere un permesso',
    'semaphore.closed' => 'il semaforo è chiuso e non può concedere un altro permesso',
    'semaphore.derive_closed' => 'impossibile derivare un nuovo pool da un semaforo chiuso',
    'lru_map.capacity_invalid' => 'la capacità LRU deve essere almeno 1, ottenuta {capacity}',
    'lru_map.capacity_too_large' => 'la capacità LRU {capacity} supera il limite di crescita {max}',
    'lru_map.evict_from_empty' => 'impossibile eliminare da una mappa LRU vuota',
    'errors.negative_not_allowed' => 'valore negativo non consentito per {name}',
    'errors.value_must_be_positive' => '{name} deve essere positivo, ottenuto {value}',
    'errors.value_out_of_range' => '{name} deve essere compreso tra {min} e {max}, ottenuto {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
];
