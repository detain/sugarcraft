<?php

/**
 * English (default) translations for sugar-toast.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Info',
    'type.warning' => 'Warning',
    'type.error'   => 'Error',
    'type.success' => 'Success',

    // Default dismiss prompt
    'dismiss' => 'Press any key to dismiss',

    // Notification counter - count-neutral phrasing; the {count}
    // placeholder is a contract, only values translate.
    'count' => 'Notifications: {count}',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} more',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[truncated]',
];
