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
    'style.hex_digit_count' => "la couleur hexadécimale doit comporter 3 ou 6 chiffres hexadécimaux (p. ex. '#f80' ou '#ff8800') ; obtenu '{value}'",
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
    'log.encode_failed' => "échec de l'encodage de l'entrée de journal en json",
    'log.bad_format' => 'chaîne --format invalide : {format}',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'style de spinner inconnu : {name}',

    // Command/FormatCommand.php
    'format.unknown_type'     => '--type inconnu : {type}',
    'format.unknown_theme'    => 'thème inconnu : {name}',
    'io.read_failed' => 'impossible de lire le fichier : {path}',
    'io.write_failed' => "impossible d'écrire dans le fichier : {path}",
    'log.unknown_formatter' => '--formatter inconnu : {name}',
    'completion.unknown_shell' => 'shell non pris en charge : {shell}',
    'confirm.unknown_default' => 'valeur --default inconnue : {value}',
    'cursor_mode.unknown' => 'mode de curseur inconnu : {mode}',
];
