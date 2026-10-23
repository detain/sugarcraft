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
    'config.yaml_unparseable'  => 'wishlist yaml : ligne non analysable : {line}',
    'config.entry_missing_field' => 'entrée wishlist avec champ requis manquant : name ou host',
    'config.field_not_scalar'  => 'entrée wishlist : le champ [{field}] doit être un scalaire, obtenu {type}',
    'config.unreadable'       => 'impossible de lire la configuration wishlist : {path}',
    'config.home_unset'       => 'impossible de développer ~ sans $HOME : {path}',
    'config.yaml_inline_flow' => 'wishlist yaml : collection de flux en ligne non prise en charge : {value} (utilisez une liste imbriquée)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist : le champ [{field}] a une valeur non sûre commençant par un tiret [{value}]',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}] : port invalide [{port}] — doit être un entier de 1 à 65535',


    // bin/candy-ssh-launcher
    'cli.usage'         => 'Usage : candy-ssh-launcher [--config <chemin>] [--ssh <binaire-ssh>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher : argument inconnu : {arg}',
    'cli.no_config'     => 'candy-ssh-launcher : aucune configuration trouvée. Passez --config <chemin>.',
    'cli.error'         => 'candy-ssh-launcher : {message}',
    'cli.cancelled'     => 'candy-ssh-launcher : annulé.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher : binaire ssh introuvable ou non exécutable : {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher : {arg} nécessite une valeur',
    'cli.no_endpoints'   => 'candy-ssh-launcher : aucun endpoint dans {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtre : ',
    'picker.no_matches'  => '  (aucune correspondance)',
    'picker.help'        => '  ↑/↓ sélection · Entrée connexion · Échap quitter · taper pour filtrer',
    'picker.count'       => '  ({shown} sur {total})',
];
