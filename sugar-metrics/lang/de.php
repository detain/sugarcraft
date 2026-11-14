<?php

/**
 * German translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'Metrik-Ziel konnte nicht geöffnet werden: {target}',
    'jsonstream.cannot_open_stderr'   => 'php://stderr konnte nicht geöffnet werden',
    'jsonstream.invalid_target'       => 'Ziel muss Pfad, Ressource oder null sein',
    'jsonstream.write_failed'         => 'Jsonstream-Schreiben fehlgeschlagen: {name}',
    'statsd.socket_not_resource'      => 'existingSocket muss eine Ressource sein',
    'statsd.connect_failed'           => 'StatsD-Verbindung zu {endpoint} fehlgeschlagen: {errstr} ({errno})',
    'prom.cannot_open'                => 'Prometheus textfile: Öffnen fehlgeschlagen {path}',
    'prom.rename_failed'              => 'Prometheus textfile: Umbenennung fehlgeschlagen: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} Child-Backend(s) fehlgeschlagen. Fehler: {errors}',
    'descriptor.help_empty'           => 'Hilfe des Deskriptors darf nicht leer sein',
    'descriptor.help_invalid'         => 'Hilfe des Deskriptors darf keine Steuerzeichen enthalten',
    'descriptor.label_empty'          => 'Deskriptor-Labelschlüssel müssen nicht leere Strings sein',
    'descriptor.label_invalid'        => 'Deskriptor-Labelschlüssel ist kein gültiger Prometheus-Labelname: {key}',
    'descriptor.name_empty'           => 'Name des Deskriptors darf nicht leer sein',
    'descriptor.name_invalid'         => 'Deskriptor-Name ist kein gültiger Prometheus-Metrikname: {name}',
    'descriptor.type_invalid'         => 'Der Deskriptor-Typ muss einer von counter, gauge, histogram, summary sein: {type}',
    'jsonstream.encode_failed'        => 'jsonstream konnte den Datensatz für Metrik nicht kodieren: {name}',
    'jsonstream.flush_failed'         => 'Jsonstream-Flush fehlgeschlagen',
    'memory.max_samples'              => 'maxSamplesPerKey muss >= 1 sein, erhalten: {value}',
    'memory.max_series'               => 'maxSeries muss >= 0 sein, erhalten: {value}',
    'multibackend.no_children'        => 'MultiBackend benötigt mindestens ein Child-Backend',
    'prom.buckets_invalid'            => 'Histogramm-Grenzwerte müssen endlich und streng steigend sein; erster Verstoß bei Index {index}',
    'prom.duplicate_series'           => 'Prometheus textfile: doppelte Serie {name} mit Labels {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily muss >= 1 sein, erhalten: {limit}',
    'prom.name_collision'             => 'Prometheus textfile: "{first}" und "{second}" sanitieren zum selben Metriknamen "{folded}"',
    'prom.reserved_label'             => 'Histogramm-Metrik "{name}" darf kein vom Aufrufer gesetztes "le"-Label erhalten',
    'prom.two_kinds'                  => 'Prometheus textfile: "{name}" wird als Histogramm und als {kind} ausgegeben; ein Metrikname kann nicht zwei Arten tragen',
    'prom.write_incomplete'           => 'Prometheus textfile: unvollständiger Schreibvorgang auf {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit muss >= 1 sein, erhalten: {limit}',
    'registry.negative_monotonic'     => 'monotonisches {kind} "{name}" lehnte negativen Wert {value} ab',
    'registry.register_conflict'      => 'Metrik "{name}" ist bereits als {existing} registriert; Umschreibung auf {incoming} nicht möglich',
    'statsd.write_failed'             => 'StatsD-Schreibvorgang auf {endpoint} fehlgeschlagen',
];
