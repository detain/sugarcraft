<?php

/**
 * Turkish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'metrik hedefi açılamadı: {target}',
    'jsonstream.cannot_open_stderr'   => 'php://stderr açılamadı',
    'jsonstream.invalid_target'       => 'hedef bir yol, kaynak veya null olmalıdır',
    'jsonstream.write_failed'         => 'jsonstream yazımı başarısız: {name}',
    'statsd.socket_not_resource'      => 'existingSocket bir kaynak olmalıdır',
    'statsd.connect_failed'           => 'StatsD bağlantısı başarısız ({endpoint}): {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: açılamadı {path}',
    'prom.rename_failed'              => 'prometheus textfile: yeniden adlandırma başarısız: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: {count} alt arka uç başarısız. Hatalar: {errors}',
    'descriptor.help_empty'           => 'tanımlayıcı yardımı boş olmamalıdır',
    'descriptor.help_invalid'         => 'tanımlayıcı yardımı denetim karakterleri içermemelidir',
    'descriptor.label_empty'          => 'tanımlayıcı etiket anahtarları boş olmayan dizeler olmalıdır',
    'descriptor.label_invalid'        => 'tanımlayıcı etiket anahtarı geçerli bir Prometheus etiket adı değil: {key}',
    'descriptor.name_empty'           => 'tanımlayıcı adı boş olmamalıdır',
    'descriptor.name_invalid'         => 'tanımlayıcı adı geçerli bir Prometheus metrik adı değil: {name}',
    'descriptor.type_invalid'         => 'tanimlayici turi counter, gauge, histogram, summary deerlerinden biri olmalidr: {type}',
    'jsonstream.encode_failed'        => 'jsonstream, metrik kaydını kodlayamadı: {name}',
    'jsonstream.flush_failed'         => 'jsonstream akıtma başarısız',
    'memory.max_samples'              => 'maxSamplesPerKey >= 1 olmalıdır, alınan {value}',
    'memory.max_series'               => 'maxSeries >= 0 olmalıdır, alınan {value}',
    'multibackend.no_children'        => 'MultiBackend en az bir alt arka uç gerektirir',
    'prom.buckets_invalid'            => 'histogram sınırları sonlu ve kesin artan olmalıdır; ilk ihlal dizin {index}',
    'prom.duplicate_series'           => 'prometheus textfile: yinelenen seri {name}, etiketler {labels}',
    'prom.max_series'                 => 'maxSeriesPerFamily >= 1 olmalıdır, alınan {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" ve "{second}" aynı metrik adına temizlendi: "{folded}"',
    'prom.reserved_label'             => 'histogram metriği "{name}", çağıranın verdiği "le" etiketini kabul edemez',
    'prom.two_kinds'                  => 'prometheus textfile: "{name}" hem histogram hem de {kind} olarak yayınlanıyor; bir metrik adı iki tür taşıyamaz',
    'prom.write_incomplete'           => 'prometheus textfile: eksik yazma, {path}: {reason}',
    'registry.cardinality_limit'      => 'cardinalityLimit >= 1 olmalıdır, alınan {limit}',
    'registry.negative_monotonic'     => 'monotonik {kind} "{name}" için negatif değer {value} reddedildi',
    'registry.register_conflict'      => '"{name}" metriği zaten {existing} olarak kayıtlı; {incoming} olarak yeniden kaydedilemez',
    'statsd.write_failed'             => 'StatsD yazması başarısız ({endpoint})',
];
