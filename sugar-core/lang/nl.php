<?php

/**
 * Dutch translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'color.rgb_out_of_range'      => 'RGB-component buiten bereik [0,255]: {value}',
    'color.invalid_hex'           => 'Ongeldige hex-kleur: {hex}',
    'color.ansi_out_of_range'     => 'ANSI-index buiten bereik [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256-index buiten bereik [0,255]: {index}',
    'ansi.invalid_fg_code'        => 'Ongeldige 16-kleur voorgrondcode: {code}',
    'ansi.invalid_bg_code'        => 'Ongeldige 16-kleur achtergrondcode: {code}',
    'ansi.component_out_of_range' => '{label} buiten bereik [0,255]: {value}',
    'program.proc_open_failed'    => 'proc_open mislukt voor: {cmd}',
    'color.parse_empty' => 'kleurenaam is leeg, kan niet worden ontleed',
    'color.parse_unknown' => 'onbekende kleurenaam: {name}',
    'ansi.invalid_scs_slot' => 'ongeldige SCS-slot: {slot} (verwacht 0-3 voor G0-G3)',
    'ansi.invalid_scs_designator' => 'ongeldige SCS-designator: {designator} (één byte in 0x30-0x7E verwacht)',
    'ansi.invalid_sixel_repeat' => 'aantal sixel-herhalingen mag niet negatief zijn: {count}',
    'semaphore.limit_invalid' => 'semafoonlimiet moet minstens 1 zijn, gekregen {limit}',
    'semaphore.limit_busy' => 'semafoon bezet: {held} actief, {waiting} wachtend',
    'semaphore.shutdown' => 'semafoon gesloten voordat een vergunning werd verleend',
    'semaphore.closed' => 'semafoon is gesloten en kan geen vergunning meer verlenen',
    'semaphore.derive_closed' => 'kan geen nieuwe pool afleiden van een gesloten semafoon',
    'lru_map.capacity_invalid' => 'LRU-capaciteit moet minstens 1 zijn, gekregen {capacity}',
    'lru_map.capacity_too_large' => 'LRU-capaciteit {capacity} overschrijdt groeibeperking {max}',
    'lru_map.evict_from_empty' => 'kan niets verwijderen uit een lege LRU-map',
    'errors.negative_not_allowed' => 'negatieve waarde niet toegestaan voor {name}',
    'errors.value_must_be_positive' => '{name} moet positief zijn, gekregen {value}',
    'errors.value_out_of_range' => '{name} moet tussen {min} en {max} zijn, gekregen {value}',

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
