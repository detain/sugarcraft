<?php

/**
 * Korean (KO) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => '정보',
    'type.warning' => '경고',
    'type.error'   => '오류',
    'type.success' => '성공',

    // Default dismiss prompt
    'dismiss' => '아무 키나 눌러 닫기',

    // Notification counter
    'count' => '알림 {count}개',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count}개 더',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[잘림]',
];
