<?php

declare(strict_types=1);

namespace CandyCore\Kit\Tests;

use CandyCore\Kit\HelpText;
use CandyCore\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class HelpTextTest extends TestCase
{
    public function testRendersUsageAndSections(): void
    {
        $out = HelpText::render(
            usage: 'myapp [flags] <file>',
            sections: [
                'flags' => [
                    '-v, --verbose'   => 'enable verbose logging',
                    '--theme <name>'  => 'pick a colour theme',
                ],
                'commands' => [
                    'build'  => 'compile the project',
                    'serve'  => 'start the dev server',
                ],
            ],
            description: 'A CLI tool.',
            theme: Theme::plain(),
        );
        $this->assertStringContainsString('USAGE',           $out);
        $this->assertStringContainsString('myapp [flags]',   $out);
        $this->assertStringContainsString('A CLI tool.',     $out);
        $this->assertStringContainsString('FLAGS',           $out);
        $this->assertStringContainsString('--verbose',       $out);
        $this->assertStringContainsString('verbose logging', $out);
        $this->assertStringContainsString('COMMANDS',        $out);
        $this->assertStringContainsString('build',           $out);
        $this->assertStringContainsString('serve',           $out);
    }

    public function testEmptySectionsRenderUsageOnly(): void
    {
        $out = HelpText::render('myapp', [], theme: Theme::plain());
        $this->assertStringContainsString('USAGE', $out);
        $this->assertStringContainsString('myapp', $out);
    }

    public function testRenderRowsAlignsKeys(): void
    {
        $out = HelpText::renderRows([
            'a'   => 'short',
            'abc' => 'longer',
        ], Theme::plain());
        $lines = explode("\n", $out);
        // Both rows have the description starting at the same column.
        $aPos   = strpos($lines[0], 'short');
        $abcPos = strpos($lines[1], 'longer');
        $this->assertNotFalse($aPos);
        $this->assertNotFalse($abcPos);
        $this->assertSame($aPos, $abcPos);
    }
}
