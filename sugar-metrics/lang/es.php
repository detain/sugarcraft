<?php

/**
 * Spanish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'no se puede abrir el destino de métricas: {target}',
    'jsonstream.cannot_open_stderr'   => 'no se puede abrir php://stderr',
    'jsonstream.invalid_target'       => 'el destino debe ser ruta, recurso o null',
    'jsonstream.write_failed'         => 'escritura jsonstream fallida: {name}',
    'statsd.socket_not_resource'      => 'existingSocket debe ser un recurso',
    'statsd.connect_failed'           => 'la conexión de StatsD con {endpoint} falló: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: no se puede abrir {path}',
    'prom.rename_failed'              => 'prometheus textfile: error al renombrar: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} backends hijos fallaron. Errores: {errors}',
    'descriptor.help_empty'           => 'la ayuda del descriptor no debe estar vacía',
    'descriptor.help_invalid'         => 'la ayuda del descriptor no debe contener caracteres de control',
    'descriptor.label_empty'          => 'las claves de etiqueta del descriptor deben ser cadenas no vacías',
    'descriptor.label_invalid'        => 'la clave de etiqueta del descriptor no es un nombre de etiqueta Prometheus válido: {key}',
    'descriptor.name_empty'           => 'el nombre del descriptor no debe estar vacío',
    'descriptor.name_invalid'         => 'el nombre del descriptor no es un nombre de métrica Prometheus válido: {name}',
    'descriptor.type_invalid'         => 'el tipo del descriptor debe ser uno de counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream no pudo codificar el registro de la métrica: {name}',
    'jsonstream.flush_failed'         => 'el vaciado de jsonstream falló',
    'memory.max_samples'              => 'maxSamplesPerKey debe ser >= 1, se obtuvo {value}',
    'memory.max_series'               => 'maxSeries debe ser >= 0, se obtuvo {value}',
    'multibackend.no_children'        => 'MultiBackend requiere al menos un backend hijo',
    'prom.buckets_invalid'            => 'los límites del histograma deben ser finitos y estrictamente crecientes; primera violación en el índice {index}',
    'prom.duplicate_series'           => 'prometheus textfile: serie duplicada {name} con etiquetas {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily debe ser >= 1, se obtuvo {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" y "{second}" se sanean al mismo nombre de métrica "{folded}"',
    'prom.reserved_label'             => 'la métrica de histograma "{name}" no puede recibir una etiqueta "le" del llamante',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" se emite como histograma y como {kind}; un nombre de métrica no puede tener dos tipos',
    'prom.write_incomplete'           => 'prometheus textfile: escritura incompleta en {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit debe ser >= 1, se obtuvo {limit}',
    'registry.negative_monotonic'     => '{kind} monótono "{name}" rechazó el valor negativo {value}',
    'registry.register_conflict'      => 'la métrica "{name}" ya está registrada como {existing}; no se puede re-registrar como {incoming}',
    'statsd.write_failed'             => 'la escritura de StatsD en {endpoint} falló',
];
