<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Completion\BashCompletion;

final class BashCompletionTest extends TestCase
{
    public function testIsSupportedShell(): void
    {
        $this->assertTrue(BashCompletion::isSupportedShell('bash'));
        $this->assertFalse(BashCompletion::isSupportedShell('zsh'));
        $this->assertFalse(BashCompletion::isSupportedShell('fish'));
    }

    public function testValidShells(): void
    {
        $this->assertSame(['bash'], BashCompletion::validShells());
    }

    public function testGenerateOutputsCompleteFunction(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('complete -F', $script);
    }

    public function testGenerateIncludesRootCommands(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('style', $script);
        $this->assertStringContainsString('completion', $script);
    }

    public function testGenerateContainsCaseStatement(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('case', $script);
        $this->assertStringContainsString('compgen', $script);
    }

    public function testGenerateIncludesCommandOptions(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('--foreground', $script);
        $this->assertStringContainsString('--bold', $script);
    }

    public function testScriptIsValidBashSyntax(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $tmpFile = sys_get_temp_dir() . '/bash_comp_test.sh';
        file_put_contents($tmpFile, $script);

        $result = proc_close(proc_open(
            "bash -n {$tmpFile}",
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        ));

        unlink($tmpFile);
        $this->assertSame(0, $result);
    }
}
