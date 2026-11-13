<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-releases (namespace `releases`).
 * Strings live in {@see ../lang/en.php}; hosts with their own catalog inject a
 * translator through `SelfUpdate::withTranslator()` / `UpdateCheck::withTranslator()`.
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'releases';
    protected const DIR = __DIR__ . '/../lang';
}
