<?php

/**
 * Czech (CS) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Informace',
    'type.warning' => 'Varování',
    'type.error'   => 'Chyba',
    'type.success' => 'Úspěch',

    // Default dismiss prompt
    'dismiss' => 'Stisknutím libovolné klávesy zavřete',

    // Notification counter
    'count' => '{count} oznámení',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} dalších',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[zkráceno]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} skrytých',
];
