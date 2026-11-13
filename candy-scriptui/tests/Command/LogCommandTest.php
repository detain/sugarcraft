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
        $this->assertStringNotContainsString('format string', $tester->getDisplay());

        // scriptui-53: the graceful refusal is captured at the fd boundary —
        // the real bin translates the malformed format into the localized
        // sentence ON captured STDERR, keeping captured STDOUT clean.
        // (The old arm grepped the fwrite(STDERR...) spelling from source.)
        [$status, $stdout, $stderr] = self::runBinChild(['log', '--format=%', 'hello']);
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Invalid --format string: %.', $stderr);
    }

    /**
     * A positional spec referencing a missing arg (`%2$s`) raises an
     * ArgumentCountError from sprintf(); it must be caught too.
     */
    public function testMalformedFormatMissingArgFailsGracefully(): void
    {
        $tester = self::runLog(['message' => ['hello'], '--format' => '%2$s']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringNotContainsString('format string', $tester->getDisplay());
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
        $this->assertStringNotContainsString('unknown --formatter', $tester->getDisplay());

        // scriptui-53: the advertised set is now proven BEHAVIOURALLY. The
        // real bin's captured STDERR answers with the exact refusal sentence
        // (a stale 'function|json|logfmt|text' typo cannot match it), and
        // every name the sentence advertises is accepted on a live run —
        // advertisement and acceptance checked against the same observable
        // the user sees, not against a source literal.
        [$status, $stdout, $stderr] = self::runBinChild(['log', '--formatter=xml', 'hello']);
        $this->assertSame(1, $status);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString(
            "Invalid value for --formatter: 'xml'. Allowed: text|json|logfmt.",
            $stderr,
        );
        foreach (['text', 'json', 'logfmt'] as $advertised) {
            [$okStatus, $okStdout] = self::runBinChild(['log', '--formatter=' . $advertised, 'hello']);
            $this->assertSame(0, $okStatus, $advertised . ' is advertised as allowed and must run');
            $this->assertStringNotContainsString('Invalid value for --formatter', $okStdout);
        }
    }

    public function testFileWriteFailureIsLoudNotSilentZero(): void
    {
        $previous = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        $target = '/proc/candy-scriptui-no-such-dir/probe.log';
        try {
            $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\LogCommand());
            $status = $tester->execute(
                ['message' => ['hello'], '--file' => $target],
                ['decorated' => false],
            );
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($previous);
        }

        $this->assertSame(1, $status, 'a failed --file append must not exit 0');
        $this->assertStringNotContainsString('Could not write to file', $tester->getDisplay());

        // scriptui-53: the loud failure is captured from the real bin's
        // STDERR at the fd boundary (route AND sentence in one observable),
        // replacing the source grep of the io.write_failed fwrite call.
        [$childStatus, $stdout, $stderr] = self::runBinChild(['log', '--file=' . $target, 'hello']);
        $this->assertSame(1, $childStatus);
        $this->assertSame('', $stdout, 'the entry must not leak to stdout when the file leg fails');
        $this->assertStringContainsString("Could not write to file: $target.", $stderr);
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
