<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * A config value that failed btop's validation.
 *
 * The message is the already-translated warning text: the loader collects it
 * verbatim into its warning list (btop's `load_warnings`), while a direct
 * {@see Config::with()} caller gets it thrown — the same string either way, so
 * the options menu and the startup warning box never disagree.
 */
final class InvalidOptionValue extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $option,
    ) {
        parent::__construct($message);
    }
}
