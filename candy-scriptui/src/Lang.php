<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi;

use SugarCraft\Core\I18n\Lang as BaseLang;

/**
 * Per-library translation facade for candy-scriptui.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'scriptui'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'scriptui';
    protected const DIR = __DIR__ . '/../lang';

    /**
     * Interpolated parameters are attacker-shaped argv on most refusal
     * paths: a crafted option value must never paint the terminal with
     * live SGR bytes or forge extra diagnostic lines with embedded
     * newlines. Folding every string param through the shared
     * control-byte stand-in mapper at THIS chokepoint keeps every
     * Lang::t site honest without repeating the sanitize by hand.
     *
     * @param array<array-key, mixed> $params
     */
    public static function t(string $key, array $params = []): string
    {
        foreach ($params as $name => $value) {
            if (\is_string($value)) {
                $params[$name] = \SugarCraft\Core\Util\Sanitize::cellValue($value);
            }
        }

        return parent::t($key, $params);
    }
}
