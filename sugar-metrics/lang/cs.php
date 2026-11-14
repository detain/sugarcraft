<?php

/**
 * Czech translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'nelze otevřít cíl metrik: {target}',
    'jsonstream.cannot_open_stderr'   => 'nelze otevřít php://stderr',
    'jsonstream.invalid_target'       => 'cíl musí být cesta, prostředek nebo null',
    'jsonstream.write_failed'         => 'zápis jsonstream selhal: {name}',
    'statsd.socket_not_resource'      => 'existingSocket musí být prostředek',
    'statsd.connect_failed'           => 'připojení StatsD k {endpoint} selhalo: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: nelze otevřít {path}',
    'prom.rename_failed'              => 'prometheus textfile: přejmenování selhalo: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} dílčích backendů selhalo. Chyby: {errors}',
    'descriptor.help_empty'           => 'nápověva deskriptoru nesmí být prázdná',
    'descriptor.help_invalid'         => 'nápověva deskriptoru nesmí obsahovat řídicí znaky',
    'descriptor.label_empty'          => 'klíče štítků deskriptoru musí být neprázdné řetězce',
    'descriptor.label_invalid'        => 'klíč štítku deskriptoru není platný název štítku Prometheus: {key}',
    'descriptor.name_empty'           => 'název deskriptoru nesmí být prázdný',
    'descriptor.name_invalid'         => 'název deskriptoru není platný název metriky Prometheus: {name}',
    'descriptor.type_invalid'         => 'typ deskriptoru musi být jedním z counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream nezakódoval záznam metriky: {name}',
    'jsonstream.flush_failed'         => 'vyprázdnění jsonstream selhalo',
    'memory.max_samples'              => 'maxSamplesPerKey musí být >= 1, obdrženo {value}',
    'memory.max_series'               => 'maxSeries musí být >= 0, obdrženo {value}',
    'multibackend.no_children'        => 'MultiBackend vyžaduje alespoň jeden dílčí backend',
    'prom.buckets_invalid'            => 'meze histogramu musí být konečné a ostře rostoucí; první porušení na indexu {index}',
    'prom.duplicate_series'           => 'prometheus textfile: duplicitní řada {name} se štítky {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily musí být >= 1, obdrženo {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" a "{second}" se sanitují na stejný název metriky "{folded}"',
    'prom.reserved_label'             => 'histogramová metrika "{name}" nepřijme štítek "le" zadaný volajícím',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" je emitován jako histogram i jako {kind}; jeden název metriky nemůže nést dva druhy',
    'prom.write_incomplete'           => 'prometheus textfile: neúplný zápis do {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit musí být >= 1, obdrženo {limit}',
    'registry.negative_monotonic'     => 'monotonická {kind} "{name}" zamítla zápornou hodnotu {value}',
    'registry.register_conflict'      => 'metrika "{name}" je již registrována jako {existing}; nelze ji registrovat znovu jako {incoming}',
    'statsd.write_failed'             => 'zápis StatsD do {endpoint} selhal',
];
