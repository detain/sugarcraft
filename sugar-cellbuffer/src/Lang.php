<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-cellbuffer.
 *
 * Extends the shared {@see \SugarCraft\Core\I18n\Lang} base with the
 * 'cellbuffer' namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'cellbuffer';
    protected const DIR = __DIR__ . '/../lang';
}
