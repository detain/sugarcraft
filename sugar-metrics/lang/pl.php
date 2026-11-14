<?php

/**
 * Polish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'nie można otworzyć celu metryk: {target}',
    'jsonstream.cannot_open_stderr'   => 'nie można otworzyć php://stderr',
    'jsonstream.invalid_target'       => 'cel musi być ścieżką, zasobem lub null',
    'jsonstream.write_failed'         => 'zapis jsonstream nie powiódł się: {name}',
    'statsd.socket_not_resource'      => 'existingSocket musi być zasobem',
    'statsd.connect_failed'           => 'łączenie StatsD z {endpoint} nie powiodło się: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: nie można otworzyć {path}',
    'prom.rename_failed'              => 'prometheus textfile: zmiana nazwy nie powiodła się: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} backendów podrzędnych nie powiodło się. Błędy: {errors}',
    'descriptor.help_empty'           => 'opis deskryptora nie może być pusty',
    'descriptor.help_invalid'         => 'opis deskryptora nie może zawierać znaków sterujących',
    'descriptor.label_empty'          => 'klucze etykiet deskryptora muszą być niepustymi ciągami',
    'descriptor.label_invalid'        => 'klucz etykiety deskryptora nie jest prawidłową nazwą etykiety Prometheus: {key}',
    'descriptor.name_empty'           => 'nazwa deskryptora nie może być pusta',
    'descriptor.name_invalid'         => 'nazwa deskryptora nie jest prawidłową nazwą metryki Prometheus: {name}',
    'descriptor.type_invalid'         => 'typ deskryptora musi by jednym z counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream nie mógł zakodować rekordu metryki: {name}',
    'jsonstream.flush_failed'         => 'opróżnianie jsonstream nie powiodło się',
    'memory.max_samples'              => 'maxSamplesPerKey musi być >= 1, otrzymano {value}',
    'memory.max_series'               => 'maxSeries musi być >= 0, otrzymano {value}',
    'multibackend.no_children'        => 'MultiBackend wymaga co najmniej jednego backendu podrzędnego',
    'prom.buckets_invalid'            => 'granice histogramu muszą być skończone i ściśle rosnące; pierwsze naruszenie przy indeksie {index}',
    'prom.duplicate_series'           => 'prometheus textfile: zduplikowana seria {name} z etykietami {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily musi być >= 1, otrzymano {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" i "{second}" sanują się do tej samej nazwy metryki "{folded}"',
    'prom.reserved_label'             => 'metryka histogramu "{name}" nie może przyjąć etykiety "le" od wywołującego',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" jest emitowany jako histogram i jako {kind}; jedna nazwa metryki nie może mieć dwóch typów',
    'prom.write_incomplete'           => 'prometheus textfile: niekompletny zapis do {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit musi być >= 1, otrzymano {limit}',
    'registry.negative_monotonic'     => 'monotoniczny {kind} "{name}" odrzucił wartość ujemną {value}',
    'registry.register_conflict'      => 'metryka "{name}" jest już zarejestrowana jako {existing}; nie można zarejestrować jako {incoming}',
    'statsd.write_failed'             => 'zapis StatsD do {endpoint} nie powiódł się',
];
