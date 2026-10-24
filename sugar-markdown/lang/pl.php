<?php

/**
 * Polish translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'nie można odczytać pliku motywu: {path}',
    'theme.json_invalid'           => 'nieprawidłowy JSON w pliku motywu',
    'theme.json_object'            => 'JSON motywu musi dekodować się do obiektu',
    'theme.bad_color'              => 'nieprawidłowa specyfikacja koloru: {spec}',
    'renderer.unknown_style'       => 'nieznany styl standardowy: {name}',
    'renderer.stream_invalid'      => 'write() wymaga zasobu strumienia',
    'renderer.stream_not_writable' => 'write() wymaga strumienia możliwego do zapisu; otrzymano tryb "{mode}"',
    'renderer.write_failed'        => 'zapis {bytes} bajtów do strumienia wyjściowego nie powiódł się',
    'renderer.chunk_invalid'       => 'fragmenty stream() muszą być łańcuchami',
    'sink.closed'                  => 'sink jest zamknięty',
    'sink.parent_missing'          => 'katalog wyjściowy nie istnieje: {dir}',
    'sink.path_unwritable'         => 'ścieżka wyjściowa nie jest zapisywalna: {path}',
    'sink.open_failed'             => 'nie można otworzyć ścieżki wyjściowej: {path}',
    'sink.flush_failed'            => 'opróżnienie strumienia wyjściowego nie powiodło się',
    'writer.feed_after_close'      => 'feed() wywołany po close() — writer został zakończony',
];
