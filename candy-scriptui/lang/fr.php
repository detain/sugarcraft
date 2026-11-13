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
    'style.unrecognised_color' => 'couleur non reconnue : {value} — allowed: {allowed}',
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
    'spinner.windows_unsupported' => 'spin ne peut pas exécuter de commandes sur Windows natif : le backend de processus ny est pas pris en charge — utilisez WSL à la place.',
    'spinner.output_truncated' => '…[truncated]',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'thème inconnu : {name}',
    'io.read_failed' => 'impossible de lire le fichier : {path}',
    'io.write_failed' => "impossible d'écrire dans le fichier : {path}",
    'log.unknown_formatter' => '--formatter inconnu : {name}',
    'completion.unknown_shell' => 'shell non pris en charge : {shell}',
    'confirm.unknown_default' => 'valeur --default inconnue : {value}',
    'cursor_mode.unknown' => 'mode de curseur inconnu : {mode}',
    'runtime.no_terminal' => '{command} nécessite un terminal interactif ; aucun terminal de contrôle disponible.',
    'runtime.bad_timeout' => 'Valeur de --timeout invalide « {value} » — attendu : un nombre de secondes positif ou nul (0 désactive le délai).',
    'runtime.bad_number' => 'Valeur de {flag} invalide « {value} » — un entier non négatif est attendu.',
    'runtime.pcntl_missing' => 'ext-pcntl n\'est pas chargé : SIGINT/SIGTERM tuent ce programme sans la correspondance de code de sortie 130/143.',
    'runtime.unknown_choice' => 'Valeur invalide pour {option} : « {value} ». Autorisées : {allowed}.',
    'hints.choose' => '↑/↓ déplacer · espace bascule (multi) · entrée sélectionner · échap annuler',
    'hints.filter' => ' taper pour filtrer · ↑/↓ déplacer · tabulation bascule (multi) · entrée sélectionner · échap annuler',
    'hints.pager' => 'space/PgDn bas · PgUp/b haut · g début · G fin · q quitter',
    'format.template_unresolved' => 'modèle : {count} espace(s) réservé(s) expandé(s) en vide : {names} — une variable doit être définie et autorisée via --allow-env.',
    'runtime.empty_input' => 'L\'entrée ne contenait aucune ligne; rien à faire.',
    'runtime.empty_input_filter' => 'rien à filtrer; l\'entrée ne contenait aucune ligne.',
    'runtime.conflicting_flags' => 'Les options {first} et {second} ne peuvent pas être combinées avec des valeurs différentes.',
    'runtime.empty_selection' => 'Aucun élément sélectionné — appuyez sur Espace pour cocher au moins un élément avant Entrée.',
];
