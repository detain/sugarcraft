<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * Fail-fast constructor guards (Mi-3/Mi-9): a cap that cannot hold a single
 * byte is always a mistake, and replaceMalformed mode without ext-mbstring
 * would silently degrade to "nothing is ever malformed" — both refuse at
 * construction instead of mis-parsing for the whole stream.
 */
final class ParserCtorGuardTest extends TestCase
{
    public function testZeroCapIsRefusedAtConstruction(): void
    {
        try {
            new Parser(new DebugHandler(), false, 0);
        } catch (\InvalidArgumentException $e) {
            self::assertSame('String buffer cap must be at least 1 byte; got 0.', $e->getMessage());

            return;
        }
        self::fail('expected InvalidArgumentException for a zero cap');
    }

    public function testNegativeCapIsRefusedAndNamesTheValue(): void
    {
        try {
            new Parser(new DebugHandler(), false, -5);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('got -5.', $e->getMessage());

            return;
        }
        self::fail('expected InvalidArgumentException for a negative cap');
    }

    public function testMinimumLegalCapIsAccepted(): void
    {
        // The guard is < 1, not <= 1: a 1-byte buffer is legal (it just
        // truncates payloads harder) and must still construct and parse.
        $handler = new DebugHandler();
        (new Parser($handler, false, 1))->feed("\x1b]0;ab\x07");
        self::assertSame('0', $handler->filter('osc')[0]['detail'] ?? null, 'cap 1 keeps only the first payload byte');
    }

    public function testMbstringGuardRefusesReplacementModeWithoutTheExtension(): void
    {
        // The runtime leg is unreachable on any box that has ext-mbstring
        // (this suite's own host included), so the throw site itself cannot
        // be driven red here. Structural pin instead: the guard clause must
        // exist, keyed on the flag AND the missing function, and must name
        // the remedy. Removing or loosening the clause reddens this test.
        $source = \file_get_contents(__DIR__ . '/../src/Parser/Parser.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/\$replaceMalformed && !function_exists\(\\\\?\'mb_check_encoding\'\)/',
            $source,
            'the mbstring ctor guard must stay keyed on the flag and the missing function',
        );
        self::assertStringContainsString(
            'install ext-mbstring or construct the parser without the flag',
            $source,
            'the guard must name the remedy',
        );
    }
}
