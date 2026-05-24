<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-core.
 *
 * Wraps {@see \SugarCraft\Core\I18n\T} with the `'core'` namespace baked
 * in so that call sites stay short:
 *
 * ```php
 * throw new \InvalidArgumentException(
 *     Lang::t('color.invalid_hex', ['hex' => $hex])
 * );
 * ```
 *
 * The first call registers candy-core's `lang/` directory with the
 * shared {@see T} registry; subsequent calls are no-ops, so this helper
 * stays cheap to use anywhere user-facing text is generated.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'core';
    protected const DIR = __DIR__ . '/../lang';
}
