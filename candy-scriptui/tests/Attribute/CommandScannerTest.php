<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Tests\Fixtures\Command\AlphaCommand;
use SugarCraft\ScriptUi\Tests\Fixtures\Command\BetaCommand;
use SugarCraft\ScriptUi\Tests\Fixtures\Command\EpsilonCommand;
use SugarCraft\ScriptUi\Tests\Fixtures\Command\GammaCommand;
use Symfony\Component\Console\Command\Command;

final class CommandScannerTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testScannerFindsCommandAttributedClasses(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        require_once __DIR__ . '/../Fixtures/Command/BetaCommand.php';

        $discovered = $this->app->scan(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command::class
        );

        $this->assertContains(AlphaCommand::class, $discovered);
        $this->assertContains(BetaCommand::class, $discovered);
        $this->assertCount(2, $discovered);
    }

    public function testScannedCommandsAreRegisteredInApplication(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        require_once __DIR__ . '/../Fixtures/Command/BetaCommand.php';

        $this->app->scan(\SugarCraft\ScriptUi\Tests\Fixtures\Command::class);

        $alpha = $this->app->find('alpha');
        $beta = $this->app->find('beta');

        $this->assertInstanceOf(Command::class, $alpha);
        $this->assertInstanceOf(Command::class, $beta);
        $this->assertSame('alpha', $alpha->getName());
        $this->assertSame('beta', $beta->getName());
    }

    public function testScannedCommandHasDescription(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';

        $this->app->scan(\SugarCraft\ScriptUi\Tests\Fixtures\Command::class);

        $alpha = $this->app->find('alpha');
        $this->assertSame('Alpha test command.', $alpha->getDescription());
    }

    public function testScannerDiscoversAutoloadedLaterCommands(): void
    {
        // Regression: when a command class is loaded AFTER get_declared_classes()
        // snapshot but BEFORE the scanner finishes iterating, it must be discovered.
        // This is tested by having the tracking listener capture classes loaded
        // via explicit class_exists() calls during the scan.
        require_once __DIR__ . '/../Fixtures/Command/AlphaCommand.php';
        // BetaCommand is NOT require'd — simulate it being autoloaded mid-scan.

        $discovered = $this->app->scan(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command::class
        );

        // AlphaCommand was pre-loaded; it must appear.
        $this->assertContains(AlphaCommand::class, $discovered);
        // GammaCommand was NOT pre-loaded. For it to be discovered, something
        // must trigger its autoloader during the scan. We trigger it here by
        // calling class_exists AFTER the tracking listener is registered but
        // before scanning completes — this simulates a class that gets
        // autoloaded during the scan by external code.
        class_exists(GammaCommand::class);

        // Re-scan to pick up the now-loaded GammaCommand.
        $discovered2 = $this->app->scan(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command::class
        );

        $this->assertContains(GammaCommand::class, $discovered2);
        $gamma = $this->app->find('gamma');
        $this->assertInstanceOf(Command::class, $gamma);
        $this->assertSame('gamma', $gamma->getName());
    }

    /**
     * A command with a required non-nullable `string` ctor parameter should be
     * instantiated with '' (empty string) rather than throwing a TypeError.
     */
    public function testScannerHandlesRequiredBuiltinTypeParams(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/EpsilonCommand.php';

        // Should not throw TypeError; should fill string param with ''.
        $discovered = $this->app->scan(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command::class
        );

        // EpsilonCommand has a required string param — it should be filled and registered.
        $this->assertContains(EpsilonCommand::class, $discovered);
        $cmd = $this->app->find('required-string-command');
        $this->assertInstanceOf(Command::class, $cmd);
    }


    /**
     * docs-fix-1023 NIT: a #[Command] class the scanner cannot construct used
     * to be dropped in total silence — the tool would just be missing a
     * subcommand nobody could explain. scan() now records it in skipped()
     * (and warns on STDERR; scriptui-53 captures that stream at the process
     * boundary, so the roster AND the warning are behavioural now).
     */
    public function testScannerReportsUnconstructibleCommandsInsteadOfVanishingThem(): void
    {
        require_once __DIR__ . '/../Fixtures/Command/NeedsDependencyCommand.php';

        $scanner = new \SugarCraft\ScriptUi\Discovery\CommandScanner();
        $discovered = $scanner->scan(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command::class,
            $this->app,
        );

        $this->assertNotContains(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command\NeedsDependencyCommand::class,
            $discovered,
        );
        $this->assertContains(
            \SugarCraft\ScriptUi\Tests\Fixtures\Command\NeedsDependencyCommand::class,
            $scanner->skipped(),
            'an un-constructible command must surface in skipped()',
        );

        // scriptui-53: the loud pairing is captured at the fd boundary — a
        // real child scanning the fixture namespace answers with the skip
        // warning on its captured STDERR (stdout clean). (The old arm
        // grepped the warning text out of CommandScanner.php.)
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $root = \dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' require_once ' . var_export($root . '/tests/Fixtures/Command/NeedsDependencyCommand.php', true) . ';'
            . ' $app = new \SugarCraft\ScriptUi\Application(); $app->setAutoExit(false);'
            . ' (new \SugarCraft\ScriptUi\Discovery\CommandScanner())->scan('
            . var_export('SugarCraft\\ScriptUi\\Tests\\Fixtures\\Command', true) . ', $app);';
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $childPipes,
            $root,
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        $this->assertIsResource($proc, 'the probe child must spawn');
        fclose($childPipes[0]);
        $childStdout = (string) stream_get_contents($childPipes[1]);
        $childStderr = (string) stream_get_contents($childPipes[2]);
        fclose($childPipes[1]);
        fclose($childPipes[2]);
        $this->assertSame(0, proc_close($proc));
        $this->assertSame('', $childStdout, 'the skip warning is a diagnostic, never program output');
        $this->assertStringContainsString('candy-scriptui: skipping command', $childStderr,
            'the skip roster must stay paired with its loud STDERR warning');

        // scan() resets the roster between runs (no ghost entries).
        $scanner2 = new \SugarCraft\ScriptUi\Discovery\CommandScanner();
        $scanner2->scan('SugarCraft\\ScriptUi\\Tests\\FixtureNone\\', $this->app);
        $this->assertSame([], $scanner2->skipped());
    }
}
