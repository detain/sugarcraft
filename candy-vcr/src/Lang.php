<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-vcr.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'vcr'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}. The CLI front door (`bin/candy-vcr`) selects the
 * locale from LC_ALL / LC_MESSAGES / LANG; library callers that never set
 * one get English.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'vcr';
    protected const DIR = __DIR__ . '/../lang';
}
