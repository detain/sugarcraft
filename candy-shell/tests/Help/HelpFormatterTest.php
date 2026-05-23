<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Help\HelpFormatter;
use SugarCraft\Shell\Tests\Fixtures\Command\DemoCommand;

final class HelpFormatterTest extends TestCase
{
    private HelpFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HelpFormatter();
    }

    public function testFormatCommandWithAliasesAndExamples(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/DemoCommand.php';
        $command = new DemoCommand();
        $output = $this->formatter->format($command);

        $this->assertStringContainsString('demo', $output);
        $this->assertStringContainsString('A demo command with examples and aliases.', $output);
        $this->assertStringContainsString('Aliases:', $output);
        $this->assertStringContainsString('dm', $output);
        $this->assertStringContainsString('dem', $output);
        $this->assertStringContainsString('Examples:', $output);
        $this->assertStringContainsString('demo --verbose', $output);
        $this->assertStringContainsString('Run with verbose output.', $output);
        $this->assertStringContainsString('demo --quiet', $output);
        $this->assertStringContainsString('Run quietly.', $output);
    }

    public function testFormatSnapshot(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/DemoCommand.php';
        $command = new DemoCommand();
        $output = $this->formatter->format($command);

        $expected = <<<'EXPECTED'
<comment>demo</comment>
A demo command with examples and aliases.

<info>Aliases:</info> dm, dem

<info>Examples:</info>
  demo --verbose  — Run with verbose output.
  demo --quiet  — Run quietly.

EXPECTED;

        // Normalize line endings — git on Windows may convert LF→CRLF on checkout.
        $normalize = static fn (string $s): string => str_replace(["\r\n", "\r"], "\n", $s);

        $this->assertSame($normalize($expected), $normalize($output));
    }
}
