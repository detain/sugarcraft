<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\CompletionCommand;
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

        $this->assertStringContainsString('complete -c candy-scriptui', $output);
        $this->assertStringContainsString('__fish_seen_subcommand_from', $output);
    }

    public function testUnsupportedShellIsRefusedWithExitCode(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $app = new Application();
            $command = $app->find('completion');
            $tester = new CommandTester($command);

            $status = $tester->execute(['--shell' => 'ksh'], ['decorated' => false]);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }
        $output = $tester->getDisplay();

        // The output of this command is eval'd by the shell: a refusal must
        // exit non-zero (so `source <(candy-scriptui completion ...)` does
        // not silently continue) and name the rejected shell.
        $this->assertSame(1, $status);
        $this->assertStringContainsString('unsupported shell: ksh', $output);
    }

    public function testNewlineBearingShellCannotForgeOutputLines(): void
    {
        $app = new Application();
        $command = $app->find('completion');
        $tester = new CommandTester($command);

        $forged = "bash\ncomplete -p candy-scriptui # injected";
        $status = $tester->execute(['--shell' => $forged], ['decorated' => false]);

        $this->assertSame(1, $status);
        $display = $tester->getDisplay();
        // The raw value must never reach the stream: it is echoed through
        // CompletionEscaper, which admits nothing but [A-Za-z0-9_-].
        $this->assertStringNotContainsString('injected', $display);
        $this->assertStringNotContainsString("complete -p", $display);
        $this->assertStringContainsString('unsupported shell: invalid-shell-name', $display);
    }

    public function testEverySupportedShellIsNamedByTheCommand(): void
    {
        $this->assertSame(['bash', 'zsh', 'fish'], CompletionCommand::SUPPORTED_SHELLS);
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
