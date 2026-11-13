<?php

/**
 * French translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'Couleur vide.',
    'style.unrecognised_color' => 'Couleur non reconnue : {value} — Autorisées : {allowed}.',
    'style.hex_digit_count' => "La couleur hexadécimale doit comporter 3 ou 6 chiffres hexadécimaux (p. ex. '#f80' ou '#ff8800') ; obtenu '{value}'.",
    'style.padding_token_int' => "Le token padding/margin doit être un entier ; reçu : '{token}'.",
    'style.padding_count'     => 'Padding/margin nécessite 1, 2 ou 4 entiers ; reçu : {count}.',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style les entrées doivent être 'clé=valeur' ou 'élément.prop=valeur' ; reçu : '{raw}'.",
    'style.unknown_prop'      => "Propriété de style inconnue : '{prop}'.",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'Échec du lancement du processus fils',

    // Command/TableCommand.php
    'border.unknown'          => 'Style de bordure inconnu : {name}.',

    // Log/LogLevel.php
    'log.unknown_level'       => 'Niveau de log inconnu : {name}.',
    'log.encode_failed' => "Échec de l'encodage de l'entrée de journal en json.",
    'log.bad_format' => 'Chaîne --format invalide : {format}.',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'Style de spinner inconnu : {name}.',
    'spinner.windows_unsupported' => 'Spin ne peut pas exécuter de commandes sur Windows natif : le backend de processus ny est pas pris en charge — utilisez WSL à la place.',
    'spinner.output_truncated' => '…[truncated]',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'Thème inconnu : {name}.',
    'io.read_failed' => 'Impossible de lire le fichier : {path}.',
    'io.write_failed' => "Impossible d'écrire dans le fichier : {path}.",
    'runtime.no_terminal' => '{command} nécessite un terminal interactif ; aucun terminal de contrôle disponible.',
    'runtime.bad_timeout' => 'Valeur de --timeout invalide « {value} » — attendu : un nombre de secondes positif ou nul ou une durée comme 5s/250ms/1m30s (0 désactive le délai).',
    'runtime.bad_number' => 'Valeur de {flag} invalide « {value} » — un entier non négatif est attendu.',
    'runtime.pcntl_missing' => "ext-pcntl n'est pas chargé : SIGINT/SIGTERM tuent ce programme sans la correspondance de code de sortie 130/143.",
    'runtime.unknown_choice' => 'Valeur invalide pour {option} : « {value} ». Autorisées : {allowed}.',
    'hints.choose' => '↑/↓ déplacer · / filtrer · Entrée valider · Échap annuler',
    'hints.choose.multi' => '↑/↓ déplacer · Espace cocher · / filtrer · Entrée valider · Échap annuler',
    'hints.filter' => 'filtrer · ↑/↓ déplacer · Tab choisir · Entrée · Esc',
    'hints.pager' => '↑/↓ j/k déplacer · ←/→ h/l défiler · PgUp/b PgDn/Espace page · Ctrl+U/D demi',
    'hints.pager.more' => 'g/G ou Home/End début/fin · q/Échap quitter',
    'format.template_unresolved' => 'Modèle : {count} espace(s) réservé(s) expandé(s) en vide : {names} — une variable doit être définie et autorisée via --allow-env.',
    'runtime.empty_input' => "L'entrée ne contenait aucune ligne; rien à faire.",
    'runtime.empty_input_filter' => "Rien à filtrer; l'entrée ne contenait aucune ligne.",
    'runtime.empty_input_pager' => "Rien à paginer; l'entrée ne contenait aucune ligne.",
    'runtime.conflicting_flags' => 'Les options {first} et {second} ne peuvent pas être combinées avec des valeurs différentes.',
    'runtime.empty_selection' => 'Aucun élément sélectionné — appuyez sur Espace pour cocher au moins un élément avant Entrée.',
];
