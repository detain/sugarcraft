<?php

/**
 * Italian translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Colore vuoto.',
    'style.unrecognised_color' => 'Colore non riconosciuto: {value} — Consentiti: {allowed}.',
    'style.hex_digit_count' => "Il colore esadecimale deve avere 3 o 6 cifre esadecimali (ad es. '#f80' o '#ff8800'); ottenuto '{value}'.",
    'style.padding_token_int' => "Il token padding/margin deve essere un intero; ottenuto: '{token}'.",
    'style.padding_count'     => 'Padding/margin richiede 1, 2 o 4 interi; ottenuto: {count}.',
    'style.bad_entry'         => "--style le voci devono essere 'chiave=valore' o 'elemento.prop=valore'; ottenuto: '{raw}'.",
    'style.unknown_prop'      => "Proprietà di stile sconosciuta: '{prop}'.",
    'process.spawn_failed'    => 'Avvio del processo figlio fallito',
    'border.unknown'          => 'Stile bordo sconosciuto: {name}.',
    'log.unknown_level'       => 'Livello log sconosciuto: {name}.',
    'log.encode_failed' => 'Codifica della voce di log in json non riuscita.',
    'log.bad_format' => 'Stringa --format non valida: {format}.',
    'spinner.unknown_style'   => 'Stile spinner sconosciuto: {name}.',
    'spinner.windows_unsupported' => 'Spin non può eseguire comandi su Windows nativo: il backend dei processi non è supportato — usa WSL in alternativa.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Tema sconosciuto: {name}.',
    'io.read_failed' => 'Impossibile leggere il file: {path}.',
    'io.write_failed' => 'Impossibile scrivere sul file: {path}.',
    'runtime.no_terminal' => '{command} richiede un terminale interattivo; nessun terminale di controllo disponibile.',
    'runtime.bad_timeout' => "Valore di --timeout non valido '{value}' — previsto un numero di secondi non negativo o una durata come 5s/250ms/1m30s (0 disattiva il termine).",
    'runtime.bad_number' => "Valore di {flag} non valido '{value}' — era previsto un numero intero non negativo.",
    'runtime.pcntl_missing' => 'ext-pcntl non è caricato: SIGINT/SIGTERM interrompono questo programma senza la mappatura di uscita 130/143.',
    'runtime.unknown_choice' => "Valore non valido per {option}: '{value}'. Consentiti: {allowed}.",
    'hints.choose' => '↑/↓ muovi · / filtra · Invio seleziona · Esc annulla',
    'hints.choose.multi' => '↑/↓ muovi · Spazio alterna · / filtra · Invio seleziona · Esc annulla',
    'hints.filter' => 'filtra · ↑/↓ muovi · Tab scegli · Invio · Esc',
    'hints.pager' => '↑/↓ j/k muovi · ←/→ h/l scorri · PgUp/b PgDn/Spazio pagina · Ctrl+U/D mezza',
    'hints.pager.more' => 'g/G o Home/End inizio/fine · q/Esc esci',
    'format.template_unresolved' => 'Template: {count} segnaposto espansi a vuoto: {names} — una variabile deve essere impostata e consentita tramite --allow-env.',
    'runtime.empty_input' => "L'input non conteneva righe; nulla da fare.",
    'runtime.empty_input_filter' => "Niente da filtrare; l'input non conteneva righe.",
    'runtime.empty_input_pager' => "Niente da sfogliare; l'input non conteneva righe.",
    'runtime.conflicting_flags' => 'Le opzioni {first} e {second} non possono essere combinate con valori diversi.',
    'runtime.empty_selection' => 'Nessun elemento selezionato — premere Spazio per spuntare almeno un elemento prima di Invio.',
];
