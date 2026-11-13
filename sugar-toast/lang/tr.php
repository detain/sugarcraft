<?php

/**
 * Turkish (TR) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Bilgi',
    'type.warning' => 'Uyarı',
    'type.error'   => 'Hata',
    'type.success' => 'Başarılı',

    // Default dismiss prompt
    'dismiss' => 'Kapatmak için bir tuşa basın',

    // Notification counter
    'count' => '{count} bildirim',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => '+{count} daha',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[kesildi]',
];
