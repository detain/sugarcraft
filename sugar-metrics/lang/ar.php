<?php

/**
 * Arabic translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'تعذر فتح هدف المقاييس: {target}',
    'jsonstream.cannot_open_stderr' => 'تعذر فتح php://stderr',
    'jsonstream.invalid_target'     => 'الهدف يجب أن يكون مسارًا أو موردًا أو null',
    'statsd.socket_not_resource'    => 'existingSocket يجب أن يكون موردًا',
    'statsd.connect_failed'         => 'فشل اتصال statsd: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: تعذر فتح {path}',
    'prom.rename_failed'            => 'prometheus textfile: فشل إعادة التسمية: {tmp} -> {dest}',
];
