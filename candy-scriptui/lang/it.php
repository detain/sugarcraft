<?php

/**
 * Italian translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'colore vuoto',
    'style.unrecognised_color' => 'colore non riconosciuto: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "il colore esadecimale deve avere 3 o 6 cifre esadecimali (ad es. '#f80' o '#ff8800'); ottenuto '{value}'",
    'style.padding_token_int' => "il token padding/margin deve essere un intero; ottenuto: '{token}'",
    'style.padding_count'     => 'padding/margin richiede 1, 2 o 4 interi; ottenuto: {count}',
    'style.bad_entry'         => "--style le voci devono essere 'chiave=valore' o 'elemento.prop=valore'; ottenuto: '{raw}'",
    'style.unknown_prop'      => "proprietà di stile sconosciuta: '{prop}'",
    'process.spawn_failed'    => 'avvio del processo figlio fallito',
    'border.unknown'          => 'stile bordo sconosciuto: {name}',
    'log.unknown_level'       => 'livello log sconosciuto: {name}',
    'log.encode_failed' => 'codifica della voce di log in json non riuscita',
    'log.bad_format' => 'stringa --format non valida: {format}',
    'spinner.unknown_style'   => 'stile spinner sconosciuto: {name}',
    'spinner.windows_unsupported' => 'spin non può eseguire comandi su Windows nativo: il backend dei processi non è supportato — usa WSL in alternativa.',
    'format.unknown_theme'    => 'tema sconosciuto: {name}',
    'io.read_failed' => 'impossibile leggere il file: {path}',
    'io.write_failed' => 'impossibile scrivere sul file: {path}',
    'log.unknown_formatter' => '--formatter sconosciuto: {name}',
    'completion.unknown_shell' => 'shell non supportata: {shell}',
    'confirm.unknown_default' => 'valore --default sconosciuto: {value}',
    'cursor_mode.unknown' => 'modalità cursore sconosciuta: {mode}',
    'runtime.no_terminal' => '{command} richiede un terminale interattivo; nessun terminale di controllo disponibile.',
    'runtime.bad_timeout' => 'Valore di --timeout non valido \'{value}\' — previsto un numero di secondi non negativo (0 disattiva il termine).',
    'runtime.unknown_choice' => 'Valore non valido per {option}: \'{value}\'. Consentiti: {allowed}.',
    'hints.choose' => '↑/↓ sposta · spazio alterna (multipla) · invio seleziona · esc annulla',
    'hints.filter' => 'digita per filtrare · ↑/↓ sposta · tab alterna (multipla) · invio seleziona · esc annulla',
    'hints.pager' => 'space/PgDn giù · PgUp/b su · g inizio · G fine · q esci',
    'format.template_unresolved' => 'template: {count} segnaposto espansi a vuoto: {names} — una variabile deve essere impostata e consentita tramite --allow-env.',
    'runtime.empty_input' => 'L\'input non conteneva righe; nulla da fare.',
];
