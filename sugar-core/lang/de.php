<?php

/**
 * German translations for sugar-core.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'RGB-Komponente außerhalb des Bereichs [0,255]: {value}',
    'color.invalid_hex'           => 'Ungültige Hex-Farbe: {hex}',
    'color.ansi_out_of_range'     => 'ANSI-Index außerhalb des Bereichs [0,15]: {index}',
    'color.ansi256_out_of_range'  => 'ANSI256-Index außerhalb des Bereichs [0,255]: {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'Ungültiger 16-Farben-Vordergrundcode: {code}',
    'ansi.invalid_bg_code'        => 'Ungültiger 16-Farben-Hintergrundcode: {code}',
    'ansi.component_out_of_range' => '{label} außerhalb des Bereichs [0,255]: {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open fehlgeschlagen für: {cmd}',
    'color.parse_empty' => 'leerer Farbname kann nicht geparst werden',
    'color.parse_unknown' => 'unbekannter Farbname: {name}',
    'ansi.invalid_scs_slot' => 'ungültiger SCS-Slot: {slot} (erwartet 0-3 für G0-G3)',
    'ansi.invalid_scs_designator' => 'ungültiger SCS-Bezeichner: {designator} (ein Byte in 0x30-0x7E erwartet)',
    'ansi.invalid_sixel_repeat' => 'Sixel-Wiederholungszahl darf nicht negativ sein: {count}',
    'semaphore.limit_invalid' => 'Semaphore-Grenze muss mindestens 1 sein, erhalten: {limit}',
    'semaphore.limit_busy' => 'Semaphore belegt: {held} laufend, {waiting} wartend',
    'semaphore.shutdown' => 'Semaphore wurde geschlossen, bevor eine Genehmigung erteilt werden konnte',
    'semaphore.closed' => 'Semaphore ist geschlossen und kann keine weitere Genehmigung erteilen',
    'semaphore.derive_closed' => 'Aus einem geschlossenen Semaphore kann kein neuer Pool abgeleitet werden',
    'lru_map.capacity_invalid' => 'LRU-Kapazität muss mindestens 1 sein, erhalten: {capacity}',
    'lru_map.capacity_too_large' => 'LRU-Kapazität {capacity} überschreitet die Wachstumsgrenze {max}',
    'lru_map.evict_from_empty' => 'Aus einer leeren LRU-Zuordnung kann nichts entfernt werden',
    'errors.negative_not_allowed' => 'negativer Wert für {name} nicht erlaubt',
    'errors.value_must_be_positive' => '{name} muss positiv sein, erhalten: {value}',
    'errors.value_out_of_range' => '{name} muss zwischen {min} und {max} liegen, erhalten: {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
    'program.subscription_duplicate_id' => 'subscription id {id} was declared twice in one batch; ids must be unique across the model tree',
    'program.subscription_interval_invalid' => 'subscription {id} has an invalid {param}: it must be a positive number of seconds',
    'program.signal_signo_missing' => 'signal subscription {id} declares no signo: a signal subscription needs a positive signal number, or it can never fire',
    'editor.extension_invalid' => 'editor temp-file extension {extension} is not a label (letters, digits, dot, dash, underscore; max 32 after the dot)',
    'program.screen_stack_unsupported' => 'root model {root} does not own a screen stack, so {msg} was refused',
    'ansi.selection_invalid' => 'clipboard selection parameter {selection} is not an OSC 52 selector (c, p, s, 0-7, comma-separated combinations)',
    'ansi.clipboard_payload_too_large' => 'clipboard payload is {bytes} bytes, over the {limit}-byte OSC 52 bound',
];
