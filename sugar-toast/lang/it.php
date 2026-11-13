<?php

/**
 * Italian (IT) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Informazioni',
    'type.warning' => 'Avviso',
    'type.error'   => 'Errore',
    'type.success' => 'Successo',

    // Default dismiss prompt
    'dismiss' => 'Premi un tasto per chiudere',

    // Notification counter
    'count' => '{count} notifica(e)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} altri',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[troncato]',
];
