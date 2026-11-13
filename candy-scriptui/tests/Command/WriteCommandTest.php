<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for WriteCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY.
 * These tests verify option configuration.
 */
final class WriteCommandTest extends TestCase
{
    /**
     * Verify WriteCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('write');

        $this->assertSame('write', $command->getName());
    }

    /**
     * Verify the expected options are configured on WriteCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('placeholder'));
        $this->assertTrue($definition->hasOption('width'));
        $this->assertTrue($definition->hasOption('height'));
        $this->assertTrue($definition->hasOption('value'));
        $this->assertTrue($definition->hasOption('char-limit'));
        $this->assertTrue($definition->hasOption('max-lines'));
        $this->assertTrue($definition->hasOption('prompt'));
        $this->assertTrue($definition->hasOption('show-line-numbers'));
        $this->assertFalse($definition->hasOption('show-cursor-line')); // dead knob removed (M7)
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('end-of-buffer-character'));
        $this->assertTrue($definition->hasOption('cursor-mode'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));
    }

    /**
     * Verify --show-line-numbers is a flag (no value required).
     */
    public function testShowLineNumbersOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-line-numbers');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * --show-cursor-line was a self-admitted no-op against sugar-forms
     * TextArea (no cursor-line concept exists): removed, not renamed.
     */
    public function testShowCursorLineKnobIsGone(): void
    {
        $app = new Application();
        $command = $app->find('write');
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('show-cursor-line'));
    }


    public function testUnknownCursorModeIsRefused(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\WriteCommand());
            $status = $tester->execute(['--cursor-mode' => 'wobble'], ['decorated' => false]);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('unknown --cursor-mode', $tester->getDisplay());

        // scriptui-53: the refusal sentence is now captured from the real
        // bin's STDERR (fd boundary), not grepped as a fwrite(STDERR...)
        // spelling from the command's source text.
        [$status, $stdout, $stderr] = self::runBinChild(['write', '--cursor-mode=wobble']);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString(
            "Invalid value for --cursor-mode: 'wobble'. Allowed: blink|static|hidden.",
            $stderr,
        );
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
