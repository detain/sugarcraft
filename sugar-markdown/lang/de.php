<?php

/**
 * German translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'Theme-Datei konnte nicht gelesen werden: {path}',
    'theme.json_invalid'           => 'ungültiges JSON in Theme-Datei',
    'theme.json_object'            => 'Theme-JSON muss zu einem Objekt dekodiert werden',
    'theme.bad_color'              => 'ungültige Farbangabe: {spec}',
    'renderer.unknown_style'       => 'unbekannter Standardstil: {name}',
    'renderer.stream_invalid'      => 'write() benötigt eine Stream-Ressource',
    'renderer.stream_not_writable' => 'write() benötigt einen beschreibbaren Stream, Modus "{mode}" erhalten',
    'renderer.write_failed'        => 'Schreiben von {bytes} Bytes in den Ausgabestream fehlgeschlagen',
    'renderer.chunk_invalid'       => 'die Chunks von stream() müssen Zeichenketten sein',
    'sink.closed'                  => 'der Sink ist geschlossen',
    'sink.parent_missing'          => 'das Ausgabeverzeichnis existiert nicht: {dir}',
    'sink.path_unwritable'         => 'der Ausgabepfad ist nicht beschreibbar: {path}',
    'sink.open_failed'             => 'der Ausgabepfad konnte nicht geöffnet werden: {path}',
    'sink.flush_failed'            => 'das Leeren des Ausgabestreams ist fehlgeschlagen',
    'writer.feed_after_close'      => 'feed() nach close() aufgerufen — der Writer ist beendet',
];
