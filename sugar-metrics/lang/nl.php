<?php

/**
 * Dutch translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'kan metrics-doel niet openen: {target}',
    'jsonstream.cannot_open_stderr'   => 'kan php://stderr niet openen',
    'jsonstream.invalid_target'       => 'doel moet een pad, resource of null zijn',
    'jsonstream.write_failed'         => 'schrijven naar jsonstream mislukt: {name}',
    'statsd.socket_not_resource'      => 'existingSocket moet een resource zijn',
    'statsd.connect_failed'           => 'StatsD-verbinding naar {endpoint} mislukt: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: kan {path} niet openen',
    'prom.rename_failed'              => 'prometheus textfile: hernoemen mislukt: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} subbackend(s) mislukt. Fouten: {errors}',
    'descriptor.help_empty'           => 'hulptekst van descriptor mag niet leeg zijn',
    'descriptor.help_invalid'         => 'hulptekst van descriptor mag geen stuurcodes bevatten',
    'descriptor.label_empty'          => 'labelsleutels van descriptor moeten niet-lege strings zijn',
    'descriptor.label_invalid'        => 'labelsleutel van descriptor is geen geldige Prometheus-labelnaam: {key}',
    'descriptor.name_empty'           => 'naam van descriptor mag niet leeg zijn',
    'descriptor.name_invalid'         => 'descriptornaam is geen geldige Prometheus-metriknaam: {name}',
    'descriptor.type_invalid'         => 'het descriptor-type moet een zijn van counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream kon het record voor metrik niet coderen: {name}',
    'jsonstream.flush_failed'         => 'sporen van jsonstream mislukt',
    'memory.max_samples'              => 'maxSamplesPerKey moet >= 1 zijn, gekregen {value}',
    'memory.max_series'               => 'maxSeries moet >= 0 zijn, gekregen {value}',
    'multibackend.no_children'        => 'MultiBackend heeft minstens één subbackend nodig',
    'prom.buckets_invalid'            => 'histogramgrenzen moeten eindig en strikt oplopend zijn; eerste overtreding op index {index}',
    'prom.duplicate_series'           => 'prometheus textfile: dubbele serie {name} met labels {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily moet >= 1 zijn, gekregen {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" en "{second}" santizeerden tot dezelfde metriknaam "{folded}"',
    'prom.reserved_label'             => 'histogrammetrik "{name}" kan geen door de aanroeper geleverd "le"-label ontvangen',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" wordt als histogram en als {kind} uitgezonden; één metrieknaam kan niet twee soorten dragen',
    'prom.write_incomplete'           => 'prometheus textfile: onvolledige schrijfpoging naar {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit moet >= 1 zijn, gekregen {limit}',
    'registry.negative_monotonic'     => 'monotone {kind} "{name}" weigerde negatieve waarde {value}',
    'registry.register_conflict'      => 'metrik "{name}" is al geregistreerd als {existing}; herregistratie als {incoming} niet mogelijk',
    'statsd.write_failed'             => 'StatsD-schrijven naar {endpoint} mislukt',
];
