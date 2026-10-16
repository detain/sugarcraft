<?php

/**
 * French translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'couleur vide',
    'style.unrecognised_color' => 'couleur non reconnue : {value}',
    'style.padding_token_int' => "le token padding/margin doit être un entier ; reçu : '{token}'",
    'style.padding_count'     => 'padding/margin nécessite 1, 2 ou 4 entiers ; reçu : {count}',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style les entrées doivent être 'clé=valeur' ou 'élément.prop=valeur' ; reçu : '{raw}'",
    'style.unknown_prop'      => "propriété de style inconnue : '{prop}'",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'échec du lancement du processus fils',

    // Command/TableCommand.php
    'border.unknown'          => 'style de bordure inconnu : {name}',

    // Log/LogLevel.php
    'log.unknown_level'       => 'niveau de log inconnu : {name}',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'style de spinner inconnu : {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => '--type inconnu : {type}',
    'format.unknown_theme'    => 'thème inconnu : {name}',
];
