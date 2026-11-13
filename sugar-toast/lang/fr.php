<?php

/**
 * French (FR) translations for sugar-toast.
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
    'type.warning' => 'Avertissement',
    'type.error'   => 'Erreur',
    'type.success' => 'Succès',

    // Default dismiss prompt
    'dismiss' => 'Appuyez sur une touche pour fermer',

    // Notification counter
    'count' => '{count} notification(s)',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} de plus',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[tronqué]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} masquées',
];
