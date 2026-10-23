<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for sugar-datepicker.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'datepicker'` namespace baked in. Translation arrays live under the
 * package's lang/ directory (lang/en.php is the source of truth).
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'datepicker';
    protected const DIR = __DIR__ . '/../lang';
}
