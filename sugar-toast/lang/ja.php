<?php

/**
 * Japanese (JA) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => '情報',
    'type.warning' => '警告',
    'type.error'   => 'エラー',
    'type.success' => '成功',

    // Default dismiss prompt
    'dismiss' => '任意のキーで閉じる',

    // Notification counter
    'count' => '通知 {count} 件',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '他{count}件',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[省略]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => '+{count}件非表示',
];
