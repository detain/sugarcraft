<?php

/**
 * Spanish / Castilian (ES-ES) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Información',
    'type.warning' => 'Advertencia',
    'type.error'   => 'Error',
    'type.success' => 'Éxito',

    // Default dismiss prompt
    'dismiss' => 'Pulse una tecla para descartar',

    // Notification counter
    'count' => '{count} notificación(es)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} más',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[truncado]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} ocultas',
];
