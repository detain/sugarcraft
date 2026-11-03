<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Tests\Fixtures\Autowire\AutowireDefaultCommand;
use SugarCraft\ScriptUi\Tests\Fixtures\Autowire\AutowireNullableCommand;
use SugarCraft\ScriptUi\Tests\Fixtures\Enum\EnumFlagCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Pins for review 2026-11-02 CC5 (scan namespace normalisation), M10
 * (`#[Flag(enum:)]` runtime enforcement) and senior m-3 (autowire null-
 * over-reject + default-clobber). Each fix has a red-first behaviour test.
 */
final class ScannerNamespaceAutowireAndEnumTest extends TestCase
{
    private const ENUM_NS   = 'SugarCraft\\ScriptUi\\Tests\\Fixtures\\Enum';
    private const AUTOWIRE_NS = 'SugarCraft\\ScriptUi\\Tests\\Fixtures\\Autowire';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Fixtures/Enum/SuitType.php';
        require_once __DIR__ . '/../Fixtures/Enum/EnumFlagCommand.php';
        require_once __DIR__ . '/../Fixtures/Autowire/AutowireMarker.php';
        require_once __DIR__ . '/../Fixtures/Autowire/AutowireNullableCommand.php';
        require_once __DIR__ . '/../Fixtures/Autowire/AutowireDefaultCommand.php';
    }

    // --- CC5: namespace normalisation -------------------------------------

    public function testScanAcceptsTheTrailingSeparatorFormTheReadmeTeaches(): void
    {
        // README:117 uses 'My\\Namespace\\'. The old ltrim().'\\' made it a
        // double separator matching zero classes — a silent no-op.
        $app        = new Application();
        $discovered = $app->scan(self::ENUM_NS . '\\');

        $this->assertSame([EnumFlagCommand::class], $discovered);
        $this->assertTrue($app->has('enum-fixture'));
    }

    public function testScanAcceptsTheSeparatorlessForm(): void
    {
        $app        = new Application();
        $discovered = $app->scan(self::ENUM_NS);

        $this->assertSame([EnumFlagCommand::class], $discovered);
    }

    public function testScanAcceptsALeadingSeparatorForm(): void
    {
        $app        = new Application();
        $discovered = $app->scan('\\' . self::ENUM_NS . '\\');

        $this->assertSame([EnumFlagCommand::class], $discovered);
    }

    public function testAnEmptyNamespaceStillDiscoversNothingButIsGuarded(): void
    {
        $app = new Application();
        // trim('\\') of '\\' is '' → guarded early-return, no fatal.
        $this->assertSame([], $app->scan('\\'));
    }

    public function testZeroMatchNamespaceWarnsStructurally(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Discovery/CommandScanner.php');
        $this->assertStringContainsString(
            'no classes found under namespace',
            $src,
            'a vacuum scan must stay paired with its loud STDERR warning (CC5)',
        );
    }

    // --- M10: enum runtime enforcement ------------------------------------

    public function testScannerRegistersTheEnumConstraintOnTheApplication(): void
    {
        $app = new Application();
        $app->scan(self::ENUM_NS);

        // Reachable, typed surface: a bad value is refused, not forwarded.
        // ArgvInput mirrors the production invocation shape (ArrayInput +
        // 'command' key is this Application's known-bad in-process path —
        // see ApplicationHelpTest's note; it is tracked separately, not here).
        $out  = new BufferedOutput();
        $code = $app->run(
            new ArgvInput(['candy-scriptui', 'enum-fixture', '--suit', 'pineapple']),
            $out,
        );

        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString(
            "Invalid value for --suit: 'pineapple'. Allowed: hearts|spades.",
            $out->fetch(),
        );
    }

    public function testValidEnumValueRunsTheCommand(): void
    {
        $app = new Application();
        $app->scan(self::ENUM_NS);

        $out  = new BufferedOutput();
        $code = $app->run(
            new ArgvInput(['candy-scriptui', 'enum-fixture', '--suit', 'hearts']),
            $out,
        );

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('hearts', $out->fetch());
    }

    // --- senior m-3: autowire null-over-reject + default-clobber ---------

    public function testRequiredNullableConstructorParamIsInjectedNotNull(): void
    {
        $app        = new Application();
        $discovered = $app->scan(self::AUTOWIRE_NS);

        // Old code injected null then rejected it (in_array(null)) → skipped.
        $this->assertContains(AutowireNullableCommand::class, $discovered);
        $this->assertInstanceOf(
            Command::class,
            $app->find('autowire-nullable'),
        );
    }

    public function testAuthorDefaultsAreNotClobberedWithZeroValues(): void
    {
        $app = new Application();
        $app->scan(self::AUTOWIRE_NS);

        $cmd = $app->find('autowire-defaults');
        // The optional $label param keeps its declared default; the old loop
        // pushed '' over it.
        $this->assertSame('author-default', $cmd->getDescription());
    }
}
