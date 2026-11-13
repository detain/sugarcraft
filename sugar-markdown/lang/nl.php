<?php

/**
 * Dutch translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'kan themabestand niet lezen: {path}',
    'theme.file_too_large'         => 'themabestand overschrijdt het plafond van {bytes} bytes: {path}',
    'theme.json_invalid'           => 'ongeldige JSON in thema-bestand',
    'theme.json_object'            => 'thema-JSON moet naar een object decoderen',
    'theme.bad_color'              => 'ongeldige kleurspecificatie: {spec}',
    'renderer.unknown_style'       => 'onbekende standaardstijl: {name} — {styles}',
    'renderer.stream_invalid'      => 'write() vereist een streambron',
    'renderer.stream_not_writable' => 'write() vereist een beschrijfbare stream; modus "{mode}" ontvangen',
    'renderer.write_failed'        => 'schrijven van {bytes} bytes naar de outputstream mislukt',
    'renderer.chunk_invalid'       => 'de chunks van stream() moeten tekenreeksen zijn',
    'sink.closed'                  => 'de sink is gesloten',
    'sink.parent_missing'          => 'de uitvoermap bestaat niet: {dir}',
    'sink.path_unwritable'         => 'het uitvoerpad is niet beschrijfbaar: {path}',
    'sink.open_failed'             => 'kan het uitvoerpad niet openen: {path}',
    'sink.flush_failed'            => 'flushen van de outputstream mislukt',
    'writer.feed_after_close'      => 'feed() aangeroepen na close() — de writer is voltooid',
    'blockstack.pop_empty' => 'de blokstack is leeg',
    'theme.mapper_not_style' => 'Theme::mapStyles() verwacht dat de mapper een Style teruggeeft, ontvangen: {type}',
    'theme.with_slot_not_style' => 'Theme::with(): "{slot}" is geen Style-slot; geldige slots: {slots}',
];
