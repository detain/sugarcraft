<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Completion\ZshCompletion;

final class ZshCompletionTest extends TestCase
{
    public function testIsSupportedShell(): void
    {
        $this->assertTrue(ZshCompletion::isSupportedShell('zsh'));
        $this->assertFalse(ZshCompletion::isSupportedShell('bash'));
        $this->assertFalse(ZshCompletion::isSupportedShell('fish'));
    }

    public function testValidShells(): void
    {
        $this->assertSame(['zsh'], ZshCompletion::validShells());
    }

    public function testGenerateOutputsCompdef(): void
    {
        $app = new Application();
        $gen = new ZshCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('compdef', $script);
    }

    public function testGenerateIncludesRootCommands(): void
    {
        $app = new Application();
        $gen = new ZshCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('style', $script);
        $this->assertStringContainsString('completion', $script);
    }

    public function testGenerateIncludesCommandOptions(): void
    {
        $app = new Application();
        $gen = new ZshCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('--foreground', $script);
        $this->assertStringContainsString('--bold', $script);
    }

    public function testGenerateUsesZshSyntax(): void
    {
        $app = new Application();
        $gen = new ZshCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('#compdef', $script);
        $this->assertStringContainsString('_describe', $script);
    }
}
