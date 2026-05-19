<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Completion\FishCompletion;

final class FishCompletionTest extends TestCase
{
    public function testIsSupportedShell(): void
    {
        $this->assertTrue(FishCompletion::isSupportedShell('fish'));
        $this->assertFalse(FishCompletion::isSupportedShell('bash'));
        $this->assertFalse(FishCompletion::isSupportedShell('zsh'));
    }

    public function testValidShells(): void
    {
        $this->assertSame(['fish'], FishCompletion::validShells());
    }

    public function testGenerateOutputsCompleteCommands(): void
    {
        $app = new Application();
        $gen = new FishCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('complete -c candyshell', $script);
    }

    public function testGenerateIncludesSubcommands(): void
    {
        $app = new Application();
        $gen = new FishCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('__fish_seen_subcommand_from style', $script);
        $this->assertStringContainsString('__fish_seen_subcommand_from completion', $script);
    }

    public function testGenerateIncludesOptions(): void
    {
        $app = new Application();
        $gen = new FishCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('-l \'foreground\'', $script);
        $this->assertStringContainsString('-l \'bold\'', $script);
    }

    public function testScriptStartsWithComment(): void
    {
        $app = new Application();
        $gen = new FishCompletion();
        $script = $gen->generate($app);

        $this->assertStringStartsWith('# fish completion', $script);
    }
}
