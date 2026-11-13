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
        // The eval'd stream is DATA: the refusal goes to STDERR instead.
        $this->assertStringNotContainsString('unsupported shell', $output);

        // scriptui-53: the refusal is captured from the real bin's STDERR
        // at the fd boundary — exact sentence, clean eval'd stdout —
        // replacing the source grep of the Lang::t('runtime.unknown_choice'
        // call text.
        [$childStatus, $stdout, $stderr] = self::runBinChild(['completion', '--shell=ksh']);
        $this->assertSame(1, $childStatus);
        $this->assertSame('', $stdout, 'the eval stream must stay empty on refusal');
        $this->assertStringContainsString(
            "Invalid value for --shell: 'ksh'. Allowed: bash|zsh|fish.",
            $stderr,
        );
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
        $this->assertStringNotContainsString('unsupported shell', $display);
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

    /** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
    private static function runBinChild(array $args, string $stdin = ''): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname($bin),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'the bin child must spawn');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }
}
