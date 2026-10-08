<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Config\Config;

/**
 * The answer of a config-file write Cmd (btop Config::write). `$saved` is
 * the config that was written: the App clears its pending-write flag (btop
 * `write_new`) only while its current persisted values still equal it — a
 * change made while the write was in flight stays pending. A failure keeps
 * the flag and carries the reason.
 */
final class ConfigSavedMsg implements Msg
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $error = '',
        public readonly ?Config $saved = null,
    ) {
    }
}
