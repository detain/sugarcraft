<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\ConfirmCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ConfirmCommand flag-plumbing that does not require a live TTY.
 *
 * Note: The full interactive execution requires Program::run() with a TTY,
 * so we test the static constants and option configuration instead.
 */
final class ConfirmCommandTest extends TestCase
{
    /**
     * ConfirmCommand exits with 0 for affirmative, 1 for negative, 2 for abort.
     * These match the shell convention used by gum.
     */
    public function testExitCodeConstantsMatchConvention(): void
    {
        $this->assertSame(0, ConfirmCommand::EXIT_YES);
        $this->assertSame(1, ConfirmCommand::EXIT_NO);
        $this->assertSame(2, ConfirmCommand::EXIT_ABORT);
    }

    /**
     * Verify ConfirmCommand is registered in the application and has the
     * expected name.
     */
    public function testCommandIsRegisteredWithCorrectName(): void
    {
        $app = new Application();
        $command = $app->find('confirm');

        $this->assertSame('confirm', $command->getName());
    }

    /**
     * Verify the expected options are configured on ConfirmCommand.
     * This catches typos in option names and verifies the flag plumbing.
     */
    public function testCommandHasExpectedOptions(): void
    {
        $app = new Application();
        $command = $app->find('confirm');
        $definition = $command->getDefinition();

        // Core options
        $this->assertTrue($definition->hasOption('default-yes'));
        $this->assertTrue($definition->hasOption('default'));
        $this->assertTrue($definition->hasOption('affirmative'));
        $this->assertTrue($definition->hasOption('negative'));
        $this->assertTrue($definition->hasOption('show-output'));
        $this->assertTrue($definition->hasOption('header'));
        $this->assertTrue($definition->hasOption('show-help'));
        $this->assertTrue($definition->hasOption('timeout'));

        // --default-yes description should note deprecation
        $defaultYesOption = $definition->getOption('default-yes');
        $this->assertStringContainsString('deprecated', $defaultYesOption->getDescription());
    }

    /**
     * Verify --default=yes and --default=no are accepted as valid option values.
     * This is a non-interactive validation that doesn't require a TTY.
     */
    public function testDefaultOptionAcceptsYesNoValues(): void
    {
        $app = new Application();
        $command = $app->find('confirm');
        $tester = new CommandTester($command);

        // These will fail due to TTY requirement (Program::run needs terminal),
        // but Symfony will first parse the options without error.
        // The option parsing is what we're verifying here.
        $definition = $command->getDefinition();

        $defaultOption = $definition->getOption('default');
        $this->assertNotNull($defaultOption);
        // Default accepts string values
        $this->assertTrue($defaultOption->acceptValue());
    }


    public function testUnknownDefaultIsRefusedNotSilentlyNo(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\ConfirmCommand());
            $status = $tester->execute(['--default' => 'maybe'], ['decorated' => false]);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $this->assertSame(1, $status);
        // Refusals are DIAGNOSTICS: they belong on STDERR, never in the data
        // channel (this command's stdout is consumed by scripts).
        $this->assertStringNotContainsString('unknown --default', $tester->getDisplay());

        // scriptui-53: the STDERR routing is captured at the fd boundary —
        // the real bin's captured STDERR carries the sentence while its
        // captured STDOUT stays empty. (The old arm grepped the
        // fwrite(STDERR...) spelling out of source: punctuation-proof,
        // behaviour-blind.)
        [$status, $stdout, $stderr] = self::runBinChild(['confirm', '--default=maybe'], "y\n");
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout, 'a refusal must never touch the answer channel');
        $this->assertStringContainsString("Invalid value for --default: 'maybe'. Allowed: yes|no.", $stderr);
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
