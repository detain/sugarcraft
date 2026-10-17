<?php

/**
 * Turkish translations for sugar-metrics.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'jsonstream.cannot_open_target' => 'metrik hedefi açılamadı: {target}',
    'jsonstream.cannot_open_stderr' => 'php://stderr açılamadı',
    'jsonstream.invalid_target'     => 'hedef bir yol, kaynak veya null olmalıdır',
    'statsd.socket_not_resource'    => 'existingSocket bir kaynak olmalıdır',
    'statsd.connect_failed'         => 'statsd bağlantısı başarısız: {errstr} ({errno})',
    'prom.cannot_open'              => 'prometheus textfile: açılamadı {path}',
    'prom.rename_failed'            => 'prometheus textfile: yeniden adlandırma başarısız: {tmp} -> {dest}',
];
