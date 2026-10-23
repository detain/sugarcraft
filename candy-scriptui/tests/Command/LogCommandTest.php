<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Command\LogCommand;
use Symfony\Component\Console\Command\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Coverage for {@see LogCommand::resolveTimeFormat()} — Go-style
 * time-constant aliases mapped to PHP date() format strings.
 */
final class LogCommandTest extends TestCase
{
    public function testResolveKitchen(): void
    {
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('kitchen'));
    }

    public function testResolveRfc3339(): void
    {
        $this->assertSame("Y-m-d\\TH:i:sP", LogCommand::resolveTimeFormat('rfc3339'));
    }

    public function testResolveDateOnly(): void
    {
        $this->assertSame('Y-m-d', LogCommand::resolveTimeFormat('dateonly'));
    }

    public function testResolveTimeOnly(): void
    {
        $this->assertSame('H:i:s', LogCommand::resolveTimeFormat('timeonly'));
    }

    public function testResolveDateTime(): void
    {
        $this->assertSame('Y-m-d H:i:s', LogCommand::resolveTimeFormat('datetime'));
    }

    public function testResolveAnsic(): void
    {
        $this->assertSame('D M j H:i:s Y', LogCommand::resolveTimeFormat('ansic'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('Kitchen'));
        $this->assertSame('g:ia', LogCommand::resolveTimeFormat('KITCHEN'));
    }

    public function testUnknownPassesThrough(): void
    {
        // Custom date() format strings should round-trip unchanged.
        $this->assertSame('Y-m-d', LogCommand::resolveTimeFormat('Y-m-d'));
        $this->assertSame('H:i:s', LogCommand::resolveTimeFormat('H:i:s'));
    }

    public function testFormatLineRunsResolutionForAlias(): void
    {
        // Sanity check: passing 'kitchen' through formatLine should
        // produce a time fragment matching PHP's `g:ia` pattern, not
        // the literal string 'kitchen'.
        $line = LogCommand::formatLine(\SugarCraft\ScriptUi\Log\LogLevel::Info, 'hi', '', 'kitchen', 'logfmt');
        $this->assertStringContainsString('time=', $line);
        $this->assertDoesNotMatchRegularExpression('/time=kitchen/', $line);
    }

    /**
     * A malformed --format (trailing '%') makes sprintf() raise a
     * ValueError. The command must fail gracefully with a translated
     * error, not crash with an uncaught exception.
     */
    public function testMalformedFormatTrailingPercentFailsGracefully(): void
    {
        $tester = self::runLog(['message' => ['hello'], '--format' => '%']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('invalid --format string', $tester->getDisplay());
    }

    /**
     * A positional spec referencing a missing arg (`%2$s`) raises an
     * ArgumentCountError from sprintf(); it must be caught too.
     */
    public function testMalformedFormatMissingArgFailsGracefully(): void
    {
        $tester = self::runLog(['message' => ['hello'], '--format' => '%2$s']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('invalid --format string', $tester->getDisplay());
    }

    public function testValidFormatWrapsMessage(): void
    {
        $tester = self::runLog(['message' => ['hello'], '--format' => '[%s]']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('[hello]', $tester->getDisplay());
    }

    /** @param array<string,mixed> $args */
    private static function runLog(array $args): \Symfony\Component\Console\Tester\CommandTester
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('log');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $tester->execute($args);
        return $tester;
    }


    public function testUnknownFormatterIsRefused(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\LogCommand());
            $status = $tester->execute(
                ['message' => ['hello'], '--formatter' => 'xml'],
                ['decorated' => false],
            );
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $this->assertSame(1, $status);
        $this->assertStringContainsString('unknown --formatter: xml', $tester->getDisplay());
    }

    public function testFileWriteFailureIsLoudNotSilentZero(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        try {
            $target = '/proc/candy-scriptui-no-such-dir/probe.log';
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\LogCommand());
            $status = $tester->execute(
                ['message' => ['hello'], '--file' => $target],
                ['decorated' => false],
            );
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $this->assertSame(1, $status, 'a failed --file append must not exit 0');
        $this->assertStringContainsString('could not write to file: ' . $target, $tester->getDisplay());
    }
}
