<?php

/**
 * French translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'thème inconnu : {name}',
    'render.theme_config_unreadable' => 'configuration du thème illisible : {path}',
    'render.theme_config_too_large' => 'la configuration du thème dépasse {limit} octets : {path}',
    'glamour.json_invalid' => 'la configuration du thème n\'est pas un JSON valide',
    'glamour.json_object' => 'la configuration du thème doit être un objet JSON',
    'render.no_input_tty' => 'aucune entrée : indiquez un fichier ou envoyez du Markdown sur l\'entrée standard',
    'render.no_input_empty' => 'aucune entrée : l\'entrée standard est vide',
    'render.file_missing' => 'impossible d\'ouvrir {path} : fichier inexistant',
    'render.file_is_directory' => 'impossible d\'ouvrir {path} : c\'est un répertoire',
    'render.file_unreadable' => 'impossible d\'ouvrir {path} : fichier illisible',
    'render.binary_input' => 'rendu refusé pour {path} : ce n\'est pas un fichier texte',
    'render.input_too_large' => 'l\'entrée dépasse {limit} octets : {path}',
    'render.width_invalid' => 'valeur --width invalide : {value} (entier attendu entre 0 et 9999 ; 0 désactive le retour à la ligne)',
];
