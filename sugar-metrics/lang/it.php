<?php

/**
 * Italian translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'impossibile aprire la destinazione delle metriche: {target}',
    'jsonstream.cannot_open_stderr'   => 'impossibile aprire php://stderr',
    'jsonstream.invalid_target'       => 'la destinazione deve essere un percorso, una risorsa o null',
    'jsonstream.write_failed'         => 'scrittura jsonstream non riuscita: {name}',
    'statsd.socket_not_resource'      => 'existingSocket deve essere una risorsa',
    'statsd.connect_failed'           => 'connessione StatsD a {endpoint} fallita: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: impossibile aprire {path}',
    'prom.rename_failed'              => 'prometheus textfile: ridenominazione fallita: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} backend figlio/i falliti. Errori: {errors}',
    'descriptor.help_empty'           => 'la guida del descrittore non deve essere vuota',
    'descriptor.help_invalid'         => 'la guida del descrittore non deve contenere caratteri di controllo',
    'descriptor.label_empty'          => 'le chiavi di etichetta del descrittore devono essere stringhe non vuote',
    'descriptor.label_invalid'        => 'la chiave di etichetta del descrittore non è un nome di etichetta Prometheus valido: {key}',
    'descriptor.name_empty'           => 'il nome del descrittore non deve essere vuoto',
    'descriptor.name_invalid'         => 'il nome del descrittore non è un nome metrica Prometheus valido: {name}',
    'descriptor.type_invalid'         => 'il tipo del descrittore deve essere uno tra counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream non ha potuto codificare il record della metrica: {name}',
    'jsonstream.flush_failed'         => 'flush di jsonstream fallito',
    'memory.max_samples'              => 'maxSamplesPerKey deve essere >= 1, ricevuto {value}',
    'memory.max_series'               => 'maxSeries deve essere >= 0, ricevuto {value}',
    'multibackend.no_children'        => 'MultiBackend richiede almeno un backend figlio',
    'prom.buckets_invalid'            => 'i limiti dellistogramma devono essere finiti e strettamente crescenti; prima violazione allindice {index}',
    'prom.duplicate_series'           => 'prometheus textfile: serie duplicata {name} con etichette {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily deve essere >= 1, ricevuto {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" e "{second}" vengono sanificati nello stesso nome metrica "{folded}"',
    'prom.reserved_label'             => 'la metrica istogramma "{name}" non può ricevere unetichetta "le" dal chiamante',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" viene emesso sia come istogramma sia come {kind}; un nome di metrica non può avere due tipi',
    'prom.write_incomplete'           => 'prometheus textfile: scrittura incompleta su {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit deve essere >= 1, ricevuto {limit}',
    'registry.negative_monotonic'     => '{kind} monotono "{name}" ha rifiutato il valore negativo {value}',
    'registry.register_conflict'      => 'la metrica "{name}" è già registrata come {existing}; impossibile registrarla come {incoming}',
    'statsd.write_failed'             => 'scrittura StatsD su {endpoint} fallita',
];
