<?php

/**
 * Portuguese (PT) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Informação',
    'type.warning' => 'Aviso',
    'type.error'   => 'Erro',
    'type.success' => 'Sucesso',

    // Default dismiss prompt
    'dismiss' => 'Prima uma tecla para dispensar',

    // Notification counter
    'count' => '{count} notificação(ões)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} mais',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[truncado]',
];
