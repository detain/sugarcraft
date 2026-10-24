<?php

/**
 * Czech translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'nelze číst soubor tématu: {path}',
    'theme.json_invalid'           => 'neplatný JSON v souboru motivu',
    'theme.json_object'            => 'téma JSON musí dekódovat do objektu',
    'theme.bad_color'              => 'neplatné zadání barvy: {spec}',
    'renderer.unknown_style'       => 'neznámý standardní styl: {name}',
    'renderer.stream_invalid'      => 'write() vyžaduje zdroj proudu',
    'renderer.stream_not_writable' => 'write() vyžaduje zapisovatelný proud; obdržen režim "{mode}"',
    'renderer.write_failed'        => 'zápis {bytes} bajtů do výstupního proudu selhal',
    'renderer.chunk_invalid'       => 'části stream() musí být řetězce',
    'sink.closed'                  => 'sink je uzavřen',
    'sink.parent_missing'          => 'výstupní adresář neexistuje: {dir}',
    'sink.path_unwritable'         => 'výstupní cesta není zapisovatelná: {path}',
    'sink.open_failed'             => 'nelze otevřít výstupní cestu: {path}',
    'sink.flush_failed'            => 'vyprázdnění výstupního proudu selhalo',
    'writer.feed_after_close'      => 'feed() volán po close() — writer je dokončen',
];
