<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\CompletionCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class CompletionCommandTest extends TestCase
{
    public function testBashShellEmitsBashScript(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $tester->execute(['--shell' => 'bash']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('complete -F', $output);
        $this->assertStringContainsString('compgen', $output);
    }

    public function testZshShellEmitsZshScript(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $tester->execute(['--shell' => 'zsh']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('#compdef', $output);
        $this->assertStringContainsString('_describe', $output);
    }

    public function testFishShellEmitsFishScript(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $tester->execute(['--shell' => 'fish']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('complete -c candyshell', $output);
        $this->assertStringContainsString('__fish_seen_subcommand_from', $output);
    }

    public function testUnsupportedShellShowsMessage(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $tester->execute(['--shell' => 'ksh']);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Unsupported shell', $output);
        $this->assertStringContainsString('bash', $output);
    }

    public function testDefaultShellFallsBackToBash(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('complete -F', $output);
        $this->assertStringContainsString('compgen', $output);
    }
}
