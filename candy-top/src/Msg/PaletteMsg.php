<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Theme\Palette;

/**
 * A theme loaded off the update path (the file read happens in a Cmd) for
 * the config it was asked for — `$key` is {@see \SugarCraft\Top\App::themeKey()}
 * of that config. The App installs it only while its config still asks for
 * the same theme, so a stale load racing a newer change never wins.
 */
final class PaletteMsg implements Msg
{
    public function __construct(
        public readonly Palette $palette,
        public readonly string $key,
    ) {
    }
}
