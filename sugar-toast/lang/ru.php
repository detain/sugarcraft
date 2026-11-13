<?php

/**
 * Russian (RU) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Информация',
    'type.warning' => 'Предупреждение',
    'type.error'   => 'Ошибка',
    'type.success' => 'Успех',

    // Default dismiss prompt
    'dismiss' => 'Нажмите любую клавишу, чтобы закрыть',

    // Notification counter
    'count' => 'Уведомлений: {count}',

    // Action-fold row: buttons past the per-alert cap; {count} is a contract.
    'more' => 'ещё {count}',

    // Over-long message marker; the code prepends one space. Keep the leading ellipsis.
    'truncated' => '…[обрезано]',

    // Stack-fold row: alerts that do not fit the viewport budget; {count} is a contract.
    'hidden' => 'скрыто: {count}',
];
