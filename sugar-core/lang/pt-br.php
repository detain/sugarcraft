<?php

/**
 * Brazilian Portuguese translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'componente RGB fora do intervalo [0,255]: {value}',
    'color.invalid_hex'           => 'cor hex inválida: {hex}',
    'color.ansi_out_of_range'     => 'índice ansi fora do intervalo [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'índice ansi256 fora do intervalo [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'código de primeiro plano de 16 cores inválido: {code}',
    'ansi.invalid_bg_code'        => 'código de fundo de 16 cores inválido: {code}',
    'ansi.component_out_of_range' => '{label} fora do intervalo [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open falhou para: {cmd}',
    'color.parse_empty' => 'não é possível analisar um nome de cor vazio',
    'color.parse_unknown' => 'nome de cor desconhecido: {name}',
    'ansi.invalid_scs_slot' => 'slot SCS inválido: {slot} (esperado 0-3 para G0-G3)',
    'ansi.invalid_scs_designator' => 'designador SCS inválido: {designator} (esperado um byte em 0x30-0x7E)',
    'ansi.invalid_sixel_repeat' => 'a contagem de repetição sixel não deve ser negativa: {count}',
    'semaphore.limit_invalid' => 'o limite do semáforo deve ser pelo menos 1, obtido {limit}',
    'semaphore.limit_busy' => 'semáforo ocupado: {held} em execução, {waiting} aguardando',
    'semaphore.shutdown' => 'o semáforo foi fechado antes de conceder uma permissão',
    'semaphore.closed' => 'o semáforo está fechado e não pode conceder outra permissão',
    'semaphore.derive_closed' => 'não é possível derivar um novo pool de um semáforo fechado',
    'lru_map.capacity_invalid' => 'a capacidade LRU deve ser pelo menos 1, obtida {capacity}',
    'lru_map.capacity_too_large' => 'a capacidade LRU {capacity} excede o limite de crescimento {max}',
    'lru_map.evict_from_empty' => 'não é possível remover de um mapa LRU vazio',
    'errors.negative_not_allowed' => 'valor negativo não permitido para {name}',
    'errors.value_must_be_positive' => '{name} deve ser positivo, obtido {value}',
    'errors.value_out_of_range' => '{name} deve estar entre {min} e {max}, obtido {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
];
