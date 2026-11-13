<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\VersionCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-40 — the `version` subcommand must answer with the exact byte
 * string the global `--version` leg prints, exit 0, and stay registered.
 */
final class VersionCommandTest extends TestCase
{
    public function testVersionCommandExitsZeroAndPrintsLongVersion(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new StringInput('version'), $out);

        self::assertSame(0, $status);
        // The template carries decoration markup; compare the RENDERED bytes
        // the same way the global leg renders them (leg-identity is pinned
        // separately below).
        $expected = new BufferedOutput();
        $expected->writeln($app->getLongVersion());
        self::assertSame(trim($expected->fetch()), trim($out->fetch()));
    }

    public function testVersionSubcommandIsByteIdenticalToTheVersionFlagLeg(): void
    {
        $sub = new BufferedOutput();
        $appA = new Application();
        $appA->setAutoExit(false);
        $rcSub = $appA->run(new StringInput('version'), $sub);

        $flag = new BufferedOutput();
        $appB = new Application();
        $appB->setAutoExit(false);
        $rcFlag = $appB->run(new StringInput('--version'), $flag);

        self::assertSame($rcFlag, $rcSub);
        self::assertSame(trim($flag->fetch()), trim($sub->fetch()));
    }

    public function testVersionCommandShape(): void
    {
        $command = new VersionCommand();

        self::assertSame('version', $command->getName());
        self::assertNotContains('--long', array_keys($command->getDefinition()->getOptions()));
    }

    public function testVersionCommandDoesNotDieAsAmbiguousCommand(): void
    {
        // Pre-fix behaviour: `candy-scriptui version` fell through to the
        // did-you-mean path and exited non-zero. Pin the cured exit code on
        // the real CLI token shape.
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new ArgvInput(['candy-scriptui', 'version']), $out);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^candy-scriptui \d+\.\d+\.\d+/m', trim($out->fetch()));
    }
}
