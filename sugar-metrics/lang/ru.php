<?php

/**
 * Russian translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'невозможно открыть цель метрик: {target}',
    'jsonstream.cannot_open_stderr'   => 'невозможно открыть php://stderr',
    'jsonstream.invalid_target'       => 'цель должна быть путём, ресурсом или null',
    'jsonstream.write_failed'         => 'запись jsonstream не выполнена: {name}',
    'statsd.socket_not_resource'      => 'existingSocket должен быть ресурсом',
    'statsd.connect_failed'           => 'подключение StatsD к {endpoint} не удалось: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: невозможно открыть {path}',
    'prom.rename_failed'              => 'prometheus textfile: переименование не удалось: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: сбой {count} дочерних бэкэндов. Ошибки: {errors}',
    'descriptor.help_empty'           => 'описание дескриптора не должно быть пустым',
    'descriptor.help_invalid'         => 'описание дескриптора не должно содержать управляющие символы',
    'descriptor.label_empty'          => 'ключи меток дескриптора должны быть непустыми строками',
    'descriptor.label_invalid'        => 'ключ метки дескриптора не является допустимым именем метки Prometheus: {key}',
    'descriptor.name_empty'           => 'имя дескриптора не должно быть пустым',
    'descriptor.name_invalid'         => 'имя дескриптора не является допустимым именем метрики Prometheus: {name}',
    'descriptor.type_invalid'         => 'тип дескриптора должен быть одним из counter, gauge, histogram, summary: {type}',
    'jsonstream.encode_failed'        => 'jsonstream не смог закодировать запись метрики: {name}',
    'jsonstream.flush_failed'         => 'сброс буфера jsonstream не выполнен',
    'memory.max_samples'              => 'maxSamplesPerKey должен быть >= 1, получено {value}',
    'memory.max_series'               => 'maxSeries должен быть >= 0, получено {value}',
    'multibackend.no_children'        => 'MultiBackend требует хотя бы один дочерний бэкэнд',
    'prom.buckets_invalid'            => 'границы гистограммы должны быть конечными и строго возрастающими; первое нарушение на индексе {index}',
    'prom.duplicate_series'           => 'prometheus textfile: дублирующаяся серия {name} с метками {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily должен быть >= 1, получено {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" и "{second}" после санитизации дают одно имя метрики "{folded}"',
    'prom.reserved_label'             => 'метрика гистограммы "{name}" не может принимать метку "le" от вызывающего кода',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" выводится и как гистограмма, и как {kind}; одно имя метрики не может иметь два типа',
    'prom.write_incomplete'           => 'prometheus textfile: неполная запись в {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit должен быть >= 1, получено {limit}',
    'registry.negative_monotonic'     => 'монотонный {kind} "{name}" отклонил отрицательное значение {value}',
    'registry.register_conflict'      => 'метрика "{name}" уже зарегистрирована как {existing}; повторная регистрация как {incoming} невозможна',
    'statsd.write_failed'             => 'запись StatsD в {endpoint} не выполнена',
];
