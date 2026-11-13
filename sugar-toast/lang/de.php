<?php

/**
 * German (DE) translations for sugar-toast.
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
    'type.warning' => 'Warnung',
    'type.error'   => 'Fehler',
    'type.success' => 'Erfolg',

    // Default dismiss prompt
    'dismiss' => 'Zum Schließen eine Taste drücken',

    // Notification counter
    'count' => '{count} Benachrichtigung(en)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} weitere',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[gekürzt]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} ausgeblendet',
];
