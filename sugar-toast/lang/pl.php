<?php

/**
 * Polish (PL) translations for sugar-toast.
 *
 * Keys and {count} placeholders are contract: they ride lang/en.php
 * verbatim (only values are translated).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Toast type labels
    'type.info'    => 'Informacja',
    'type.warning' => 'Ostrzeżenie',
    'type.error'   => 'Błąd',
    'type.success' => 'Sukces',

    // Default dismiss prompt
    'dismiss' => 'Naciśnij dowolny klawisz, aby zamknąć',

    // Notification counter - count-neutral phrasing (pl cannot carry three
    // plural forms in a parenthetical); {count} is a contract.
    'count' => 'Powiadomienia: {count}',
];
