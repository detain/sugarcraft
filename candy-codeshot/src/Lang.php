<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\Core\Util\Sanitize;

/**
 * Per-library translation facade for candy-codeshot.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'codeshot'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'codeshot';
    protected const DIR = __DIR__ . '/../lang';

    /**
     * Interpolated parameters are attacker-controlled text: flags, paths and
     * format names come from argv, and argv can carry raw ESC/CSI bytes. Every
     * `Lang::t()` message here lands on STDERR in front of a terminal, so a
     * forged OSC-8 hyperlink or CSI rebind would otherwise execute verbatim.
     * Sweeping each parameter through {@see Sanitize::untrustedForDisplay()}
     * at this single choke is cheaper than policing every call site — the
     * catalogs themselves stay authored (trusted) text.
     *
     * @param array<string, string> $params
     */
    public static function t(string $key, array $params = []): string
    {
        if ($params !== []) {
            $params = \array_map(
                static fn ($value): string => Sanitize::untrustedForDisplay((string) $value),
                $params,
            );
        }

        return parent::t($key, $params);
    }
}
