<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * P6.4 — fluent expect-style API over a {@see MasterPty}.
 *
 * The bulk of the suite walks a scripted bash login dialog through
 * the fluent chain. Structural tests (input validation, immutability)
 * run on FFI-less CI via a stub master.
 */
final class ExpectTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable on this host.');
        }
    }

    // ----- structural / validation ---------------------------------

    public function testOnRejectsNonPositiveReadChunk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Expect::on(new FixtureMaster(''), readChunk: 0);
    }

    public function testExpectRejectsEmptyNeedle(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $this->expectException(\InvalidArgumentException::class);
        $expect->expect('');
    }

    public function testExpectAnyRejectsEmptyArray(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $this->expectException(\InvalidArgumentException::class);
        $expect->expectAny([]);
    }

    public function testExpectPatternRejectsEmptyRegex(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $this->expectException(\InvalidArgumentException::class);
        $expect->expectPattern('');
    }

    public function testExpectPatternRejectsMalformedRegex(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $this->expectException(\InvalidArgumentException::class);
        // Missing closing delimiter — preg_match returns false.
        $expect->expectPattern('/(unclosed');
    }

    public function testInitialStateIsClean(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $this->assertSame('', $expect->buffer);
        $this->assertNull($expect->lastMatch);
        $this->assertNull($expect->before);
    }

    public function testSendReturnsNewInstance(): void
    {
        $master = new FixtureMaster('');
        $expect = Expect::on($master);
        $sent = $expect->send('hello');
        $this->assertNotSame($expect, $sent, 'send() must return a new instance');
        $this->assertSame(['hello'], $master->writes);
    }

    public function testSendLineAppendsEol(): void
    {
        $master = new FixtureMaster('');
        Expect::on($master)->sendLine('hi');
        $this->assertSame(["hi\n"], $master->writes);
    }

    public function testSendLineCustomEol(): void
    {
        $master = new FixtureMaster('');
        Expect::on($master)->sendLine('hi', "\r\n");
        $this->assertSame(["hi\r\n"], $master->writes);
    }

    public function testExpectMatchesPrebufferedNeedle(): void
    {
        // Master is empty but we hand-craft a buffer to skip the read
        // path. Validates the match/slice logic without needing IO.
        $expect = new Expect(
            master: new FixtureMaster(''),
            buffer: 'preamble login: tail',
        );
        $matched = $expect->expect('login: ', 0.01);
        $this->assertSame('login: ', $matched->lastMatch);
        $this->assertSame('preamble ', $matched->before);
        $this->assertSame('tail', $matched->buffer);
    }

    public function testExpectAnyPicksEarliestMatchInBuffer(): void
    {
        $expect = new Expect(
            master: new FixtureMaster(''),
            buffer: 'first password: then login: after',
        );
        $matched = $expect->expectAny(['login: ', 'password: '], 0.01);
        // password: is earlier in the buffer.
        $this->assertSame('password: ', $matched->lastMatch);
        $this->assertSame('first ', $matched->before);
        $this->assertSame('then login: after', $matched->buffer);
    }

    public function testExpectPatternMatchesPrebuffered(): void
    {
        $expect = new Expect(
            master: new FixtureMaster(''),
            buffer: "PID=2147 ready",
        );
        $matched = $expect->expectPattern('/PID=(\d+)/', 0.01);
        $this->assertSame('PID=2147', $matched->lastMatch);
        $this->assertSame('', $matched->before);
        $this->assertSame(' ready', $matched->buffer);
    }

    public function testExpectReadsFromMasterWhenBufferEmpty(): void
    {
        $master = new FixtureMaster("greetings login: tail");
        $matched = Expect::on($master)->expect('login: ', 1.0);
        $this->assertSame('login: ', $matched->lastMatch);
        $this->assertSame('greetings ', $matched->before);
    }

    public function testExpectTimeoutCarriesPartialBuffer(): void
    {
        // Fixture delivers 'partial ' then null forever — simulates a
        // hung server that wrote a banner but never the prompt.
        $master = new FixtureMaster('', ['partial ', null, null, null]);
        try {
            Expect::on($master)->expect('login: ', 0.2);
            $this->fail('expected ExpectTimeoutException');
        } catch (ExpectTimeoutException $e) {
            $this->assertSame(['login: '], $e->needles);
            $this->assertSame(0.2, $e->timeoutSec);
            $this->assertStringContainsString('partial', $e->buffer);
        }
    }

    public function testExpectEofWhenMasterClosesBeforeMatch(): void
    {
        $master = new FixtureMaster('', ['banner ', '']);
        try {
            Expect::on($master)->expect('login: ', 5.0);
            $this->fail('expected ExpectEofException');
        } catch (ExpectEofException $e) {
            $this->assertSame(['login: '], $e->needles);
            $this->assertStringContainsString('banner', $e->buffer);
        }
    }

    // ----- real-PTY scripted dialog --------------------------------

    public function testScriptedLoginDialogAgainstRealBashChild(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            // Tiny scripted server: prompts for login + password,
            // greets, exits 0. `IFS= read -r` so leading spaces stay
            // intact and a single \n terminates each read.
            $child = $pair->slave()->spawn([
                '/bin/bash',
                '-c',
                <<<'SH'
                printf 'login: '
                IFS= read -r user
                printf 'password: '
                IFS= read -r -s pass
                printf '\n'
                printf 'welcome, %s\n' "$user"
                SH,
            ], controllingTerminal: true);

            \stream_set_blocking($pair->master()->stream(), false);

            $state = Expect::on($pair->master())
                ->expect('login: ', 5.0)
                ->sendLine('alice')
                ->expect('password: ', 5.0)
                ->sendLine('secret')
                ->expect('welcome, alice', 5.0);

            $this->assertSame('welcome, alice', $state->lastMatch);
            $this->assertSame(0, $child->wait());
        } finally {
            $pair->master()->close();
        }
    }

    public function testExpectAnyRacesNeedlesAgainstRealChild(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn([
                '/bin/bash',
                '-c',
                "printf 'success: done\\n'",
            ]);

            $state = Expect::on($pair->master())
                ->expectAny(['error: ', 'success: '], 5.0);

            $this->assertSame('success: ', $state->lastMatch);
            $this->assertSame(0, $child->wait());
        } finally {
            $pair->master()->close();
        }
    }

    public function testExpectPatternCapturesPidFromRealChild(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn([
                '/bin/bash',
                '-c',
                "printf 'PID=%d ready\\n' \$\$",
            ]);

            $state = Expect::on($pair->master())
                ->expectPattern('/PID=(\d+)/', 5.0);

            $this->assertNotNull($state->lastMatch);
            $this->assertMatchesRegularExpression('/^PID=\d+$/', $state->lastMatch);
            $this->assertSame(0, $child->wait());
        } finally {
            $pair->master()->close();
        }
    }
}

/**
 * In-process master stub for the structural tests. Records writes and
 * doles out preloaded read chunks, so the expect/timeout/EOF paths can
 * be exercised without spawning a real child.
 */
final class FixtureMaster implements \SugarCraft\Pty\Contract\MasterPty
{
    /** @var list<string> */
    public array $writes = [];

    /**
     * @param list<string|null> $chunks  null = simulate timeout (read returns null)
     *                                   ''   = simulate EOF      (read returns '')
     *                                   str  = bytes available   (read returns the string)
     */
    public function __construct(
        private string $initial,
        private array $chunks = [],
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->initial !== '') {
            $out = $this->initial;
            $this->initial = '';
            return $out;
        }
        if ($this->chunks === []) {
            // No script left — emulate a quiet master so the
            // surrounding loop falls through to its timeout check.
            if ($timeout !== null) {
                \usleep((int) ($timeout * 1_000_000));
            }
            return null;
        }
        $chunk = \array_shift($this->chunks);
        if ($chunk === null && $timeout !== null) {
            // Honour the timeout so the deadline math in Expect makes
            // forward progress against wall-clock time.
            \usleep((int) ($timeout * 1_000_000));
        }
        return $chunk;
    }

    public function write(string $bytes): int
    {
        $this->writes[] = $bytes;
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { throw new \LogicException('FixtureMaster has no real stream'); }
    public function close(): void {}
    public function isClosed(): bool { return false; }
}
