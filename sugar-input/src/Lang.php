<?php

declare(strict_types=1);

namespace SugarCraft\Input;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-input.
 *
 * Bakes the 'input' namespace into sugar-core's shared translation base:
 * lookups resolve exact locale → base language → English → raw key. The
 * catalogs live under `lang/` (en.php is the shipped source of truth).
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'input';
    protected const DIR = __DIR__ . '/../lang';
}
