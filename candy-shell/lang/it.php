<?php

/**
 * Italian translations for candy-shell.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'colore vuoto',
    'style.unrecognised_color' => 'colore non riconosciuto: {value}',
    'style.padding_token_int' => "il token padding/margin deve essere un intero; ottenuto: '{token}'",
    'style.padding_count'     => 'padding/margin richiede 1, 2 o 4 interi; ottenuto: {count}',
    'style.bad_entry'         => "--style le voci devono essere 'chiave=valore' o 'elemento.prop=valore'; ottenuto: '{raw}'",
    'style.unknown_prop'      => "proprietà di stile sconosciuta: '{prop}'",
    'process.spawn_failed'    => 'avvio del processo figlio fallito',
    'border.unknown'          => 'stile bordo sconosciuto: {name}',
    'log.unknown_level'       => 'livello log sconosciuto: {name}',
    'spinner.unknown_style'   => 'stile spinner sconosciuto: {name}',
    'format.unknown_type'     => '--type sconosciuto: {type}',
    'format.unknown_theme'    => 'tema sconosciuto: {name}',
];
