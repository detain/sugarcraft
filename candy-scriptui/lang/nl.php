<?php

/**
 * Dutch translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'lege kleur',
    'style.unrecognised_color' => 'niet-herkende kleur: {value}',
    'style.hex_digit_count' => "hexkleur moet 3 of 6 hexadecimale cijfers hebben (bijv. '#f80' of '#ff8800'); verkregen '{value}'",
    'style.padding_token_int' => "padding/margin-token moet een integer zijn; gekregen: '{token}'",
    'style.padding_count'     => 'padding/margin vereist 1, 2 of 4 integers; gekregen: {count}',
    'style.bad_entry'         => "--style-entries moeten 'key=value' of 'element.prop=value' zijn; gekregen: '{raw}'",
    'style.unknown_prop'      => "onbekende style-eigenschap: '{prop}'",
    'process.spawn_failed'    => 'kon kindproces niet starten',
    'border.unknown'          => 'onbekende randstijl: {name}',
    'log.unknown_level'       => 'onbekend logniveau: {name}',
    'log.encode_failed' => 'kon logvermelding niet naar json coderen',
    'log.bad_format' => 'ongeldige --format-tekenreeks: {format}',
    'spinner.unknown_style'   => 'onbekende spinnerstijl: {name}',
    'format.unknown_type'     => 'onbekend --type: {type}',
    'format.unknown_theme'    => 'onbekend thema: {name}',
    'io.read_failed' => 'kon bestand niet lezen: {path}',
    'io.write_failed' => 'kon niet naar bestand schrijven: {path}',
    'log.unknown_formatter' => 'onbekende --formatter: {name}',
    'completion.unknown_shell' => 'niet-ondersteunde shell: {shell}',
    'confirm.unknown_default' => 'onbekende --default-waarde: {value}',
    'cursor_mode.unknown' => 'onbekende cursormodus: {mode}',
];
