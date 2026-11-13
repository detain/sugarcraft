<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Window;
use SugarCraft\Dashboard\Output\Truncate;

/**
 * Fail-closed roster for the sugar-dashboard-018 consolidation:
 * every renderer that fits a string into a cell budget must route
 * through Output\Truncate — no private truncateToWidth bodies, and no
 * hand-appended ellipsis next to the canonical's (the Window
 * double-ellipsis bug class).
 */
final class TruncateConsolidationTest extends TestCase
{
    public function testZeroPrivateTruncateToWidthCopiesRemain(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/function truncateToWidth\s*\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'private truncateToWidth copies must route through Output\\Truncate');
    }

    public function testLayoutsNeverHandAppendAnEllipsis(): void
    {
        // The ellipsis belongs to the canonical alone; a call site that
        // writes `. '…'` beside a truncation is the double-marker bug.
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/src/Layout';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $codeWithoutComments = preg_replace('/(^|[^:\w])\/\/[^\n]*|\/\*[\s\S]*?\*\//', '$1', $code);
            if (preg_match("/\.\\s*'…'/", (string) $codeWithoutComments)) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Layout may not append its own ellipsis beside Truncate');
    }

    public function testTruncatedWindowTitleCarriesExactlyOneEllipsis(): void
    {
        $window = Window::new(Text::new('body'), str_repeat('X', 120))->setSize(40, 8);

        $rendered = $window->render();

        $this->assertSame(
            1,
            substr_count($rendered, '…'),
            'a cut title must be marked once, not twice (Window double-ellipsis bug class)'
        );
    }

    public function testFitsStyledTitlePassesThroughByteIdentical(): void
    {
        // The canonical never destroys styles when nothing is cut.
        $styled = "\x1b[1mTitle\x1b[0m";

        $this->assertSame($styled, Truncate::truncate($styled, 40));
    }

    public function testPlainCutNeverAddsAnEllipsis(): void
    {
        // The consolidated call sites (the former fifteen no-marker copies)
        // keep the marker-free contract explicitly.
        $this->assertStringNotContainsString('…', Truncate::truncate('a very long plain line', 8, ''));
        $this->assertSame('a very lo', Truncate::truncate('a very long plain line', 9, ''));
    }
}
