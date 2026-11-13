<?php

/**
 * Arabic (AR) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'معلومات',
    'type.warning' => 'تحذير',
    'type.error'   => 'خطأ',
    'type.success' => 'نجاح',

    // Default dismiss prompt
    'dismiss' => 'اضغط أي مفتاح للإغلاق',

    // Notification counter - count-neutral phrasing (ar 3-10 needs the
    // plural إشعارات, never carried by a singular stem); {count} is a contract.
    'count' => 'عدد الإشعارات: {count}',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} أخرى',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[مقتطع]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} مخفية',
];
