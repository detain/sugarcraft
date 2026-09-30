<?php

/**
 * French translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec non disponible ; ext-pcntl requis',
    'launcher.exec_failed'     => 'échec de l\'exécution de {bin}',
    'config.not_found'         => 'Configuration wishlist introuvable : {path}',
    'config.json_top_level'    => 'wishlist json : la valeur de premier niveau doit être un tableau',
    'config.json_entry_object' => 'wishlist json : chaque entrée doit être un objet',
    'config.yaml_continuation' => "wishlist yaml : continuation avant tout bloc '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml : ligne non analysable : {line}',
    'config.entry_missing_field' => 'entrée wishlist avec champ requis manquant : name ou host',
    'config.field_not_scalar'  => 'entrée wishlist : le champ [{field}] doit être un scalaire, obtenu {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist : le champ [{field}] a une valeur non sûre commençant par un tiret [{value}]',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}] : port invalide [{port}] — doit être un entier de 1 à 65535',


    // bin/wishlist
    'cli.usage'         => 'Usage : wishlist [--config <chemin>] [--ssh <binaire-ssh>]',
    'cli.unknown_arg'   => 'wishlist : argument inconnu : {arg}',
    'cli.no_config'     => 'wishlist : aucune configuration trouvée. Passez --config <chemin>.',
    'cli.error'         => 'wishlist : {message}',
    'cli.cancelled'     => 'wishlist : annulé.',
    'cli.ssh_not_executable' => 'wishlist : binaire ssh introuvable ou non exécutable : {bin}',
];
