<?php

declare(strict_types=1);

namespace SugarCraft\Query\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Dispatched when an async admin data fetch begins.
 * Signals the UI to show a loading indicator.
 */
final readonly class AdminFetchStartedMsg implements Msg {}
