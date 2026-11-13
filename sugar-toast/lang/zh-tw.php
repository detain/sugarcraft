<?php

/**
 * Chinese, Traditional (ZH-TW) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => '資訊',
    'type.warning' => '警告',
    'type.error'   => '錯誤',
    'type.success' => '成功',

    // Default dismiss prompt
    'dismiss' => '按任意鍵關閉',

    // Notification counter
    'count' => '{count} 則通知',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '另外 {count} 項',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[已截斷]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} 項已隱藏',
];
