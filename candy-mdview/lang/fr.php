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
    'render.theme_config_unrecognized' => 'la configuration de thème ne correspond ni au schéma plat ni au schéma glamour : {path}',
    'glamour.json_invalid' => 'la configuration du thème n\'est pas un JSON valide',
    'glamour.json_object' => 'la configuration du thème doit être un objet JSON',
    'glamour.decoration_unsafe' => 'le champ {slot} du thème ne doit contenir que retours à la ligne, tabulations et espaces',
    'glamour.value_too_large' => 'la clé {key} du thème dépasse le maximum de {limit}',
    'render.no_input_tty' => 'aucune entrée : indiquez un fichier, envoyez du Markdown sur l\'entrée standard ou lancez avec --help',
    'render.no_input_empty' => 'aucune entrée : l\'entrée standard est vide',
    'render.pager_needs_tty' => 'le paginateur exige un terminal ; rendez plutôt en sortie standard (retirez -p)',
    'render.file_missing' => 'impossible d\'ouvrir {path} : fichier inexistant',
    'render.file_is_directory' => 'impossible d\'ouvrir {path} : c\'est un répertoire',
    'render.file_unreadable' => 'impossible d\'ouvrir {path} : fichier illisible',
    'render.binary_input' => 'rendu refusé pour {path} : ce n\'est pas un fichier texte',
    'render.input_too_large' => 'l\'entrée dépasse {limit} octets : {path}',
    'render.fetch_failed' => 'impossible de récupérer {url} : {reason}',
    'render.directory_empty' => 'aucun document Markdown dans {path} : recherche README.md, README.markdown, index.md ou un unique *.md',
    'render.directory_ambiguous' => 'aucun document unique dans {path} : trouvé {found} — précisez l\'un d\'eux',
    'render.directory_chosen' => 'rendu de {chosen} à partir de l\'argument de répertoire {path}',
    'render.width_invalid' => 'valeur --width invalide : {value} (entier attendu entre 0 et 9999 ; 0 désactive le retour à la ligne)',
];
