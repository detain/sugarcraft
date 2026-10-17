<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when async suggestions are ready (after debounce + fetch).
 * Carries the field key and the fetched suggestions list.
 */
final readonly class SuggestionsReadyMsg implements Msg
{
    /**
     * @param string $fieldKey Which field the suggestions are for
     * @param list<string> $suggestions The fetched suggestion list
     */
    public function __construct(
        public string $fieldKey,
        public array $suggestions,
    ) {
    }
}
