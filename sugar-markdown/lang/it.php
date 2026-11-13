<?php

/**
 * Italian translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'impossibile leggere il file tema: {path}',
    'theme.file_too_large'         => 'il file tema supera il limite di {bytes} byte: {path}',
    'theme.json_invalid'           => 'JSON non valido nel file del tema',
    'theme.json_object'            => 'il JSON del tema deve decodificare in un oggetto',
    'theme.bad_color'              => 'specifica di colore non valida: {spec}',
    'renderer.unknown_style'       => 'stile standard sconosciuto: {name} — {styles}',
    'renderer.stream_invalid'      => 'write() richiede una risorsa di flusso',
    'renderer.stream_not_writable' => 'write() richiede un flusso scrivibile; ricevuta la modalità "{mode}"',
    'renderer.write_failed'        => 'scrittura di {bytes} byte nel flusso di output non riuscita',
    'renderer.chunk_invalid'       => 'i frammenti di stream() devono essere stringhe',
    'sink.closed'                  => 'il sink è chiuso',
    'sink.parent_missing'          => 'la directory di output non esiste: {dir}',
    'sink.path_unwritable'         => 'il percorso di output non è scrivibile: {path}',
    'sink.open_failed'             => 'impossibile aprire il percorso di output: {path}',
    'sink.flush_failed'            => 'flush del flusso di output non riuscito',
    'writer.feed_after_close'      => 'feed() chiamato dopo close() — il writer è terminato',
    'blockstack.pop_empty' => 'lo stack di blocchi è vuoto',
    'theme.mapper_not_style' => 'Theme::mapStyles() prevede che il mapper restituisca un Style, ottenuto: {type}',
    'theme.with_slot_not_style' => 'Theme::with(): "{slot}" non è uno slot Style; slot validi: {slots}',
];
