<?php

/**
 * Arabic translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target'   => 'تعذر فتح هدف المقاييس: {target}',
    'jsonstream.cannot_open_stderr'   => 'تعذر فتح php://stderr',
    'jsonstream.invalid_target'       => 'الهدف يجب أن يكون مسارًا أو موردًا أو null',
    'jsonstream.write_failed'         => 'فشل كتابة jsonstream: {name}',
    'statsd.socket_not_resource'      => 'existingSocket يجب أن يكون موردًا',
    'statsd.connect_failed'           => 'فشل اتصال StatsD بـ {endpoint}: {errstr} ({errno})',
    'prom.cannot_open'                => 'prometheus textfile: تعذر فتح {path}',
    'prom.rename_failed'              => 'prometheus textfile: فشل إعادة التسمية: {tmp} -> {dest}',
    'multibackend.aggregate'          => 'MultiBackend: فشل {count} من الخلفيات الفرعية. الأخطاء: {errors}',
    'descriptor.help_empty'           => 'يجب ألا يكون نص المساعدة للموصِّف فارغًا',
    'descriptor.help_invalid'         => 'يجب ألا يحتوي نص المساعدة للموصِّف على محارف تحكم',
    'descriptor.label_empty'          => 'يجب أن تكون مفاتيح وسوم الموصِّف سلاسل غير فارغة',
    'descriptor.label_invalid'        => 'مفتاح وسم الموصِّف ليس اسم وسم Prometheus صالحًا: {key}',
    'descriptor.name_empty'           => 'يجب ألا يكون اسم الموصِّف فارغًا',
    'descriptor.name_invalid'         => 'اسم الموصِّف ليس اسم مقياس Prometheus صالحًا: {name}',
    'descriptor.type_invalid'         => 'يجب أن يكون نوع الوصف أحد counter أو gauge أو histogram أو summary: {type}',
    'jsonstream.encode_failed'        => 'تعذر على jsonstream ترميز السجل للمقياس: {name}',
    'jsonstream.flush_failed'         => 'فشل تفريغ jsonstream',
    'memory.max_samples'              => 'يجب أن يكون maxSamplesPerKey أكبر من أو يساوي 1، القيمة: {value}',
    'memory.max_series'               => 'يجب أن يكون maxSeries أكبر من أو يساوي 0، القيمة: {value}',
    'multibackend.no_children'        => 'يحتاج MultiBackend إلى خلفية فرعية واحدة على الأقل',
    'prom.buckets_invalid'            => 'يجب أن تكون حدود المدرج منتهية ومتزايدة بصرامة؛ أول انتهاك عند الفهرس {index}',
    'prom.duplicate_series'           => 'prometheus textfile: سلسلة مكررة {name} بوسوم {labels}',
    'prom.max_series'                 => 'يجب أن يكون maxSeriesPerFamily أكبر من أو يساوي 1، القيمة: {limit}',
    'prom.name_collision'             => 'prometheus textfile: "{first}" و"{second}" ينظَّفان إلى الاسم نفسه "{folded}"',
    'prom.reserved_label'             => 'لا يمكن لمقياس المدرج "{name}" قبول وسم "le" من المستدعي',
    'prom.two_kinds'                  => 'prometheus textfile: يتم إصدار "{name}" كمدرج وكـ {kind} معًا؛ لا يمكن لاسم مقياس واحد أن يحمل نوعين',
    'prom.write_incomplete'           => 'prometheus textfile: كتابة غير مكتملة إلى {path}: {reason}',
    'registry.cardinality_limit'      => 'يجب أن يكون cardinalityLimit أكبر من أو يساوي 1، القيمة: {limit}',
    'registry.negative_monotonic'     => 'رفضت الأداة الرتيبة {kind} "{name}" قيمة سالبة {value}',
    'registry.register_conflict'      => 'المقياس "{name}" مسجَّل بالفعل كـ {existing}؛ لا يمكن إعادة تسجيله كـ {incoming}',
    'statsd.write_failed'             => 'فشل كتابة StatsD إلى {endpoint}',
];
