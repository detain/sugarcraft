<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\PagerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for PagerCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY.
 * These tests verify option configuration.
 */
final class PagerCommandTest extends TestCase
{
    /**
     * Verify PagerCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('pager');

        $this->assertSame('pager', $command->getName());
    }

    /**
     * Verify the expected options are configured on PagerCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('file'));
        $this->assertTrue($definition->hasOption('width'));
        $this->assertTrue($definition->hasOption('height'));
        $this->assertTrue($definition->hasOption('show-line-numbers'));
        $this->assertTrue($definition->hasOption('match'));
        $this->assertTrue($definition->hasOption('soft-wrap'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));
    }

    /**
     * Verify --file (-f) accepts a value (the file path).
     */
    public function testFileOptionAcceptsValue(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('file');
        $this->assertNotNull($option);
        $this->assertTrue($option->acceptValue());
    }

    /**
     * Verify --show-line-numbers is a flag (no value required).
     */
    public function testShowLineNumbersOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('show-line-numbers');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }

    /**
     * Verify --soft-wrap is a flag (no value required).
     */
    public function testSoftWrapOptionIsFlag(): void
    {
        $app = new Application();
        $command = $app->find('pager');
        $definition = $command->getDefinition();

        $option = $definition->getOption('soft-wrap');
        $this->assertNotNull($option);
        $this->assertFalse($option->acceptValue());
    }


    public function testMissingFileFailsWithLocalizedMessageNotFatal(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        $path = '/definitely/not/here.phpunit-' . getmypid();
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\PagerCommand());
            $status = $tester->execute(['--file' => $path], ['decorated' => false]);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $display = $tester->getDisplay();
        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('Could not read file', $display);
        // Before the Lang import fix this path fataled: "Class ... not found".
        $this->assertStringNotContainsString('Class "', $display);

        // scriptui-53: the localized failure sentence is captured from the
        // real bin's STDERR (proving the fwrite routing AND the Lang import
        // together), not grepped out of the command's source text.
        [$childStatus, $stdout, $stderr] = self::runBinChild(['pager', '--file=' . $path]);
        $this->assertSame(1, $childStatus);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString("Could not read file: $path.", $stderr);
        $this->assertStringNotContainsString('Class "', $stderr);
        $this->assertStringNotContainsString('not found', $stderr, 'the historical Lang-import fatal must stay dead');
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

    /**
     * scriptui-21 — --soft-wrap pre-wrapping must preserve SGR sequences.
     * Width::wrap starts with Ansi::strip, so the old call silently
     * unstyled every colourised input; the byte-level contract is that
     * the codes survive, the visible text still wraps, and no physical
     * line exceeds the budget.
     */
    public function testSoftWrapPrewrapKeepsAnsiCodes(): void
    {
        $in  = "\x1b[31maaaa bbbb cccc dddd\x1b[0m";
        $out = PagerCommand::prewrapSoftWrap($in, 10);

        $this->assertStringContainsString("\x1b[31m", $out, 'red SGR must survive pre-wrap');
        $this->assertStringContainsString("\x1b[0m", $out, 'reset SGR must survive pre-wrap');
        $this->assertSame(
            'aaaa bbbb cccc dddd',
            strtr(\SugarCraft\Core\Util\Ansi::strip($out), "\n", ' '),
        );
    }

    public function testSoftWrapPrewrapBoundsVisibleLineWidth(): void
    {
        $in  = "prefix \x1b[1;33m" . str_repeat('word ', 40) . "\x1b[0m suffix";
        $out = PagerCommand::prewrapSoftWrap($in, 20);

        $this->assertGreaterThan(1, count(explode("\n", $out)), 'input must actually wrap');
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(
                20,
                \SugarCraft\Core\Util\Width::of($line),
                'wrapped line overflows budget: ' . var_export($line, true),
            );
        }
    }
}
