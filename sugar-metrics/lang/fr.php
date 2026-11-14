<?php

/**
 * French translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'impossible d\'ouvrir la cible des métriques : {target}',
    'jsonstream.cannot_open_stderr'   => 'impossible d\'ouvrir php://stderr',
    'jsonstream.invalid_target'       => 'la cible doit être un chemin, une ressource ou null',
    'jsonstream.write_failed'         => 'écriture jsonstream échouée : {name}',
    'statsd.socket_not_resource'      => 'existingSocket doit être une ressource',
    'statsd.connect_failed'           => 'connexion StatsD vers {endpoint} échouée : {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile : impossible d\'ouvrir {path}',
    'prom.rename_failed'              => 'prometheus textfile : échec du renommage : {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend : {count} backend(s) enfant(s) en échec. Erreurs : {errors}',
    'descriptor.help_empty'           => 'l\'aide du descripteur ne doit pas être vide',
    'descriptor.help_invalid'         => 'l\'aide du descripteur ne doit pas contenir de caractères de contrôle',
    'descriptor.label_empty'          => 'les clés d\'étiquette du descripteur doivent être des chaînes non vides',
    'descriptor.label_invalid'        => 'la clé de libellé du descripteur est un nom de libellé Prometheus invalide : {key}',
    'descriptor.name_empty'           => 'le nom du descripteur ne doit pas être vide',
    'descriptor.name_invalid'         => 'le nom du descripteur est un nom de métrique Prometheus invalide : {name}',
    'descriptor.type_invalid'         => 'le type du descripteur doit être l\'un de counter, gauge, histogram, summary : {type}',
    'jsonstream.encode_failed'        => 'jsonstream n\'a pas pu encoder l\'enregistrement de la métrique : {name}',
    'jsonstream.flush_failed'         => 'le vidage de jsonstream a échoué',
    'memory.max_samples'              => 'maxSamplesPerKey doit être >= 1, valeur reçue : {value}',
    'memory.max_series'               => 'maxSeries doit être >= 0, valeur reçue : {value}',
    'multibackend.no_children'        => 'MultiBackend exige au moins un backend enfant',
    'prom.buckets_invalid'            => 'les bornes de l\'histogramme doivent être finies et strictement croissantes ; première violation à l\'index {index}',
    'prom.duplicate_series'           => 'prometheus textfile : série dupliquée {name} avec les étiquettes {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily doit être >= 1, valeur reçue : {limit}',
    'prom.name_collision'             => 'prometheus textfile : « {first} » et « {second} » sont assainis vers le même nom de métrique « {folded} »',
    'prom.reserved_label'             => 'la métrique d\'histogramme "{name}" ne peut pas recevoir une étiquette "le" de l\'appelant',
    'prom.two_kinds'                  => 'prometheus textfile : « {name} » est émis comme histogramme et comme {kind} ; un nom de métrique ne peut pas porter deux types',
    'prom.write_incomplete'           => 'prometheus textfile : écriture incomplète vers {path} : {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit doit être >= 1, valeur reçue : {limit}',
    'registry.negative_monotonic'     => 'l\'instrument monotone {kind} "{name}" a refusé la valeur négative {value}',
    'registry.register_conflict'      => 'la métrique "{name}" est déjà enregistrée comme {existing} ; impossible de l\'enregistrer comme {incoming}',
    'statsd.write_failed'             => 'l\'écriture StatsD vers {endpoint} a échoué',
];
