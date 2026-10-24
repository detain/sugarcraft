<?php

/**
 * Italian translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'impossibile leggere il file tema: {path}',
    'theme.json_invalid'           => 'JSON non valido nel file del tema',
    'theme.json_object'            => 'il JSON del tema deve decodificare in un oggetto',
    'theme.bad_color'              => 'specifica di colore non valida: {spec}',
    'renderer.unknown_style'       => 'stile standard sconosciuto: {name}',
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
];
