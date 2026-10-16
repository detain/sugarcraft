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

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'unbekannter Spinner-Stil: {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => 'unbekannter --type: {type}',
    'format.unknown_theme'    => 'unbekanntes Theme: {name}',
];
