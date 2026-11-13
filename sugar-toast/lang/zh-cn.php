<?php

/**
 * Chinese, Simplified (ZH-CN) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => '信息',
    'type.warning' => '警告',
    'type.error'   => '错误',
    'type.success' => '成功',

    // Default dismiss prompt
    'dismiss' => '按任意键关闭',

    // Notification counter
    'count' => '{count} 条通知',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '另外 {count} 项',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[已截断]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count} 项已隐藏',
];
