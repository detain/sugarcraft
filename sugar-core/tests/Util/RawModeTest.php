<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\RawMode;
use PHPUnit\Framework\TestCase;

/**
 * RawMode exercised against non-tty streams. CI has no controlling
 * terminal, so every assertion here verifies the guard: enable()/
 * disable() must be safe no-ops (never shell out, never throw) when
 * the stream is not a TTY.
 */
final class RawModeTest extends TestCase
{
    public function testEnableIsNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            RawMode::enable($r);
            $this->addToAssertionCount(1);
        } finally {
            fclose($r);
        }
    }

    public function testDisableIsNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            RawMode::disable($r);
            $this->addToAssertionCount(1);
        } finally {
            fclose($r);
        }
    }

    public function testToggleIsIdempotentOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        try {
            RawMode::enable($r);
            RawMode::enable($r);
            RawMode::disable($r);
            RawMode::disable($r);
            // No exception thrown and the stream is untouched.
            $this->assertIsResource($r);
        } finally {
            fclose($r);
        }
    }

    public function testEnableDoesNotShellOutOnNonTty(): void
    {
        // If RawMode shelled out for a non-tty stream it would clobber
        // the controlling terminal of the test runner. We can't observe
        // the absence of a shell_exec directly, but we can confirm the
        // guard short-circuits: a closed/invalid stream is treated as
        // "not a tty" by TtyDetect and the call is a no-op.
        $r = fopen('php://temp', 'r+');
        $this->assertNotFalse($r);
        RawMode::enable($r);
        RawMode::disable($r);
        $this->assertIsResource($r);
        fclose($r);
    }
    public function testEnableRecipeCarriesIxonAndHoldsIsigBack(): void
    {
        // The guard tests above can never observe the recipe string (CI has
        // no controlling terminal), so pin the source window of enable().
        // -ixon MUST be switched off: prompt libraries bind Ctrl+S (XOFF)
        // for forward search, and a discipline that swallows it freezes
        // output instead. -isig MUST stay enabled: consumers arm SIGINT as
        // their cancel channel by design; clearing ISIG would turn their
        // Ctrl+C into a byte. Abort-chord prompts run the full -isig
        // recipe themselves.
        $method = new \ReflectionMethod(RawMode::class, 'enable');
        $lines = file($method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $this->assertStringContainsString('-icanon -echo -ixon', $body);
        $this->assertStringNotContainsString('-isig', $body);
    }
    public function testDevicePathRefusesAnonymousKernelObjects(): void
    {
        // A pipe is a descriptor with no re-openable path: readlink
        // answers pipe:[N], which stty -F can never target. The seam
        // must say null so enable() throws instead of shell-exec'ing
        // against the inherited controlling terminal — the original
        // wrong-fd defect (sugar-core-054).
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair unavailable');
        }
        try {
            self::assertNull(RawMode::devicePath($pair[0]));
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testDevicePathResolvesARealTerminal(): void
    {
        if (\DIRECTORY_SEPARATOR !== '/' || !is_readable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is not available');
        }
        $master = fopen('/dev/ptmx', 'r+b');
        if ($master === false) {
            self::markTestSkipped('cannot open /dev/ptmx');
        }
        try {
            self::assertTrue(stream_isatty($master));
            self::assertSame('/dev/ptmx', RawMode::devicePath($master));
        } finally {
            fclose($master);
        }
    }

    public function testEnableDisableRoundTripOnARealTerminalRestoresTheExactSnapshot(): void
    {
        $result = $this->runPtyProbe('roundtrip');
        if (!\is_array($result) || isset($result['error'])) {
            self::markTestSkipped('live pty probe unavailable: ' . \json_encode($result));
        }
        self::assertSame(0, $result['preset_status'], 'could not preset -ixon on the probe pty');
        self::assertSame(0, $result['before_status']);
        self::assertSame($result['before'], $result['verify'], 'stty -g reads are not consistent on this pty');
        self::assertSame(0, $result['mid_status']);
        self::assertSame(0, $result['after_status']);
        // GNU stty -g renders settings as hex bit-columns: field 0 is
        // iflag (IXON = 0x400) and field 3 is lflag (ICANON = 0x02).
        // Numeric form is the only stable read across platforms/locales.
        $beforeIflag = \hexdec(\explode(':', $result['before'])[0] ?? 'ff');
        self::assertSame(0, $beforeIflag & 0x400, 'preset -ixon did not reach the device');
        $midLflag = \hexdec(\explode(':', $result['mid'])[3] ?? 'ff');
        self::assertSame(0, $midLflag & 0x02, 'raw mode never reached the device (ICANON still set)');


        self::assertSame($result['before'], $result['after'], 'disable() must restore the exact enable() snapshot');
        // The preset is not part of any sane-default set: an `stty sane`
        // regression re-enables ixon, a no-op restore leaves -icanon.
        $afterIflag = \hexdec(\explode(':', $result['after'])[0] ?? 'ff');
        self::assertSame(0, $afterIflag & 0x400, 'sane-style reset re-enabled IXON');
    }

    public function testEnableThrowsNamedErrorWhenSttyIsMissing(): void
    {
        // The old shell_exec discarded the exit status and the
        // 2>/dev/null redirect hid the shell's "command not found", so
        // a host without stty silently "enabled" raw mode while
        // changing nothing. Now the failure is loud and named.
        $result = $this->runPtyProbe('hostile');
        if (!\is_array($result) || !isset($result['threw'])) {
            self::markTestSkipped('live pty probe unavailable: ' . \json_encode($result));
        }
        self::assertSame(\RuntimeException::class, $result['threw']);
        self::assertStringContainsString('stty', $result['message']);
        // 127 = command-not-found: the status arm of stty() must be the
        // one that fires, not the empty-output coincidence.
        self::assertStringContainsString('127', $result['message']);
    }

    public function testSourceNeverShellsOutToSane(): void
    {
        // `stty sane` is the destroyed-user-termios defect itself (the
        // prose may name it to forbid it; no argument position may
        // carry it). The Windows no-op guard must be present — sc-02's
        // story owns the real console path.
        $source = file_get_contents((new \ReflectionClass(RawMode::class))->getFileName());
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('/[\x27"]sane[\x27"]/', $source);
        self::assertStringContainsString("PHP_OS_FAMILY === 'Windows'", $source);
    }

    /** @return array<string, mixed>|null */
    private function runPtyProbe(string $mode): ?array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $statusCheck = [];
        $foundStatus = -1;
        \exec('command -v script 2>/dev/null', $statusCheck, $foundStatus);
        if ($foundStatus !== 0 || $statusCheck === []) {
            return null;
        }
        $inner = \sprintf(
            'php %s %s',
            \escapeshellarg(__DIR__ . '/raw_mode_pty_probe.php'),
            \escapeshellarg($mode)
        );
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $process = \proc_open(
            ['script', '-q', '-e', '-c', $inner, '/dev/null'],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2)
        );
        if (!\is_resource($process)) {
            return null;
        }
        try {
            $stdout = (string) \stream_get_contents($pipes[1]);
        } finally {
            \fclose($pipes[1]);
            \proc_close($process);
        }
        foreach (\explode("\n", $stdout) as $line) {
            $decoded = \json_decode($line, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
