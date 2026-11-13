<?php

/**
 * French translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'échec de l\'exécution de {bin}',
    'config.not_found'         => 'Configuration wishlist introuvable : {path}',
    'config.json_top_level'    => 'wishlist json : la valeur de premier niveau doit être un tableau',
    'config.json_entry_object' => 'wishlist json : chaque entrée doit être un objet',
    'config.yaml_continuation' => "wishlist yaml : continuation avant tout bloc '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml : ligne non analysable {line} : {found}',
    'config.yaml_block_mapping' => 'wishlist yaml : mappage en bloc sous `{field}:` à la ligne {line} : {found} — ce champ doit être une liste d\'entrées `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml : guillemet non fermé dans la valeur : {found}',
    'config.entry_missing_field' => 'entrée de la wishlist #{index} : champ obligatoire manquant : {field}',
    'config.unknown_key' => 'champ inconnu `{key}` ignoré — clé non documentée',
    'config.unknown_key_nearmiss' => 'champ inconnu `{key}` ignoré — vouliez-vous dire `{field}` ?',
    'config.duplicate_name' => "wishlist : nom d'endpoint dupliqué `{name}` — toutes les lignes sont chargées ; les noms sont des identifiants",
    'config.field_not_scalar'  => 'entrée wishlist : le champ [{field}] doit être un scalaire, obtenu {type}',
    'config.too_large'          => 'le fichier de configuration dépasse la limite de {limit} octets : {path}',
    'config.list_too_long'      => 'entrée de la liste : le champ [{field}] contient plus des {limit} éléments autorisés par le chargeur',
    'config.field_not_list'       => 'entrée de la liste : le champ [{field}] doit être une liste de valeurs, pas une table clé-valeur',
    'config.encoding_utf16'       => '{path} est encodé en UTF-16 — ré-enregistrez la configuration en UTF-8',
    'config.unreadable'       => 'impossible de lire la configuration wishlist : {path}',
    'config.home_unset'       => 'impossible de développer ~ sans $HOME : {path}',
    'config.yaml_inline_flow' => 'wishlist yaml : collection de flux en ligne non prise en charge : {value} (utilisez une liste imbriquée)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist : le champ [{field}] a une valeur non sûre commençant par un tiret [{value}]',
    'endpoint.host_empty' => "l'endpoint `{name}` a un hôte vide — une ligne sélectionnable a besoin d'un nom d'hôte",
    'endpoint.name_empty' => "l'endpoint a un nom vide — une ligne sélectionnable exige un nom",
    'endpoint.option_empty' => "l'endpoint `{name}` a une entrée d'option vide — une valeur -o vide est refusée",
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}] : port invalide [{port}] — doit être un entier de 1 à 65535',


    // bin/candy-ssh-launcher
    'cli.usage'         => 'Usage : candy-ssh-launcher [--config <chemin>] [--ssh <binaire-ssh>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher : argument inconnu : {arg}',
    'cli.no_config'     => 'candy-ssh-launcher : aucune configuration trouvée. Créez ~/.config/wishlist.yml (voir le démarrage rapide du README) ou passez --config <chemin>.',
    'cli.error'         => 'candy-ssh-launcher : {message}',
    'cli.cancelled'     => 'candy-ssh-launcher : annulé.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher : binaire ssh introuvable ou non exécutable : {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher : {arg} nécessite une valeur',
    'cli.no_endpoints'   => 'candy-ssh-launcher : aucun endpoint dans {path}',
    'cli.not_a_terminal' => 'le sélecteur nécessite un terminal interactif : stdin et stdout doivent être des TTY. Utilisez --list pour une sortie lisible par machine.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtre : ',
    'picker.no_matches'  => '  (aucune correspondance — Retour arrière ou Esc)',
    'picker.help'        => '↑/↓ sélection · Entrée connexion · Échap quitter · taper pour filtrer',
    'picker.help_keys'   => '⌫ effacer · Ctrl+U/W vider · PgUp/PgDn page · Début/Fin sauter',
    'picker.count'       => '  ({shown} sur {total})',
];
