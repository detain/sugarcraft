<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * Ctrl+V's answer (audit 15b-15): the file the clipboard's image was saved to
 * ({@see \SugarCraft\Code\Support\ClipboardImage::save()}), or null when the clipboard held no
 * image a tool could hand over. {@see Chat} inserts the path into the draft as
 * an `@` mention, or says why there was nothing to paste.
 */
final class ClipboardImagePastedMsg implements Msg
{
    public function __construct(
        public readonly ?string $path,
    ) {}
}
