<?php

declare(strict_types=1);

namespace SugarCraft\Code\Util;

use SugarCraft\Core\Util\TokenEstimate as CoreTokenEstimate;

/**
 * S-X09 (R16): the script-weighted token estimate lives in sugar-core
 * (`SugarCraft\Core\Util\TokenEstimate`, origin credit in its docblock). This
 * is the app-side name every `SugarCraft\Code\Util\TokenEstimate` call site (and
 * its pinned figures) keeps resolving: a forwarding declaration, so the ONE
 * implementation is the core's and this file still declares the symbol its
 * PSR-4 path names. The calibration factor and the window-resolution law stay
 * app-side ({@see \SugarCraft\Code\Host\ContextMeter}).
 */
final class TokenEstimate
{
    public const MESSAGE_OVERHEAD = CoreTokenEstimate::MESSAGE_OVERHEAD;

    private function __construct()
    {
    }

    /** @param iterable<string> $contents */
    public static function ofMessages(iterable $contents): int
    {
        return CoreTokenEstimate::ofMessages($contents);
    }

    public static function ofText(string $text): int
    {
        return CoreTokenEstimate::ofText($text);
    }

    /** @param iterable<object{name: callable, description: callable, inputSchema: callable}> $tools */
    public static function ofToolSchemas(iterable $tools): int
    {
        return CoreTokenEstimate::ofToolSchemas($tools);
    }

    /** @param array<string, mixed> $inputSchema */
    public static function ofToolSchema(string $name, string $description, array $inputSchema): int
    {
        return CoreTokenEstimate::ofToolSchema($name, $description, $inputSchema);
    }
}
