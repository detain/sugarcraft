<?php

/**
 * German translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'leere Farbe',
    'style.unrecognised_color' => 'nicht erkannte Farbe: {value}',
    'style.hex_digit_count' => "Hex-Farbe muss 3 oder 6 Hex-Ziffern haben (z. B. '#f80' oder '#ff8800'); erhalten: '{value}'",
    'style.padding_token_int' => "Padding/Margin-Token muss eine Ganzzahl sein; erhalten: '{token}'",
    'style.padding_count'     => 'Padding/Margin benötigt 1, 2 oder 4 Ganzzahlen; erhalten: {count}',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style-Einträge müssen 'schlüssel=wert' oder 'element.prop=wert' sein; erhalten: '{raw}'",
    'style.unknown_prop'      => "unbekannte Style-Eigenschaft: '{prop}'",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'Kindprozess konnte nicht gestartet werden',

    // Command/TableCommand.php
    'border.unknown'          => 'unbekannter Rahmenstil: {name}',

    // Log/LogLevel.php
    'log.unknown_level'       => 'unbekannte Log-Stufe: {name}',
    'log.encode_failed' => 'Logeintrag konnte nicht als json kodiert werden',
    'log.bad_format' => 'ungültige --format-Zeichenkette: {format}',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'unbekannter Spinner-Stil: {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => 'unbekannter --type: {type}',
    'format.unknown_theme'    => 'unbekanntes Theme: {name}',
    'io.read_failed' => 'Datei konnte nicht gelesen werden: {path}',
    'io.write_failed' => 'Datei konnte nicht geschrieben werden: {path}',
    'log.unknown_formatter' => 'unbekannter --formatter: {name}',
    'completion.unknown_shell' => 'nicht unterstützte Shell: {shell}',
    'confirm.unknown_default' => 'unbekannter --default-Wert: {value}',
    'cursor_mode.unknown' => 'unbekannter Cursor-Modus: {mode}',
];
