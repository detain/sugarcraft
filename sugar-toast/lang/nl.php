<?php

/**
 * Dutch (NL) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Info',
    'type.warning' => 'Waarschuwing',
    'type.error'   => 'Fout',
    'type.success' => 'Succes',

    // Default dismiss prompt
    'dismiss' => 'Druk op een willekeurige toets om te sluiten',

    // Notification counter
    'count' => '{count} melding(en)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} meer',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[afgekapt]',
];
