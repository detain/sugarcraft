<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * {@see PosixBackend::descriptorForStream()} answers with a GENUINE file
 * descriptor, and the two sinks that used to be handed a resource id now go
 * through it.
 *
 * ## The defect
 *
 * `PosixBackend::size()`'s first arm and `PosixBackend::enableRawMode()` both
 * spelled the descriptor `$fd = (int) $this->stream` a line or two above
 * {@see \SugarCraft\Pty\SizeIoctl::query()} /
 * {@see \SugarCraft\Pty\TermiosFactory::open()}. An `(int)` cast of a PHP
 * stream yields its RESOURCE ID. MEASURED, PHP 8.3.6, fresh CLI process,
 * three takes, identical each time: `(int) STDIN` is 1, `(int) STDOUT` is 2,
 * `(int) STDERR` is 3, over descriptors 0, 1 and 2.
 *
 * Both were LATENT rather than broken, and the reason is the whole difficulty
 * of guarding them: `$this->stream` defaults to `STDIN`, and 0 and 1 name the
 * same device in an ordinary terminal, so the wrong number returns the right
 * answer. A guard therefore cannot use the ambient descriptors of a test run.
 * It has to build a stream whose resource id and whose descriptor name
 * DIFFERENT things, which is what the pty fixtures below do.
 *
 * ## Why every assertion here states its own discriminator
 *
 * A number matching a number proves nothing when the two could have coincided.
 * Each fixture asserts, first, that the arrangement it needs really holds --
 * that the resource id is NOT a descriptor on the same device -- and only then
 * asserts the result. Where the expectation is `null`, something in the same
 * test asserts a non-null on the same code path, because `null` is also what a
 * deleted method body returns.
 */
final class PosixBackendStreamDescriptorTest extends TestCase
{
    /** @var list<string> */
    private array $artifacts = [];

    /** @var list<resource> */
    private array $handles = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('PosixBackend is POSIX-only.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->handles as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->handles = [];

        foreach ($this->masters as $master) {
            $master->close();
        }
        $this->masters = [];

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
            if (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * ARM 1, AND IT IS LOAD-BEARING.
     *
     * The descriptor table is pointed at an empty directory, so arm 2 cannot
     * answer and only the identity arm can. That is not an artificial
     * condition: a container with `/proc` unmounted and no `/dev/fd` reaches
     * it, and MEASURED on this box, PHP 8.3.6, in a CLI process whose stdout
     * and stderr were both redirected onto one pipe, descriptors 1 and 2 had
     * IDENTICAL dev+ino -- so arm 2 on its own cannot tell `STDERR` from
     * `STDOUT` and answers 1 for both.
     */
    public function testTheStandardStreamsResolveWithoutAnyDescriptorTable(): void
    {
        $empty = $this->emptyDirectory();

        // The control: with no descriptor table, a stream that is NOT one of
        // the three standard constants must come back null. Without it, an
        // arm-1 body rewritten to "return 0 for anything" would satisfy every
        // expectation below.
        $memory = $this->openHandle('php://memory', 'r+');
        self::assertNull(
            PosixBackend::descriptorForStream($memory, $empty),
            'a non-standard stream resolved with no descriptor table to walk',
        );

        self::assertSame(1, PosixBackend::descriptorForStream(\STDOUT, $empty));
        self::assertSame(2, PosixBackend::descriptorForStream(\STDERR, $empty));

        // STDIN is closed outright by some suites' bootstraps -- see the
        // closed-descriptor-0 family in TtyDetect's doc-block -- so it is
        // asserted only when it is live, and the assertion above carries the
        // arm on its own when it is not.
        if (\defined('STDIN') && \is_resource(\STDIN)) {
            self::assertSame(0, PosixBackend::descriptorForStream(\STDIN, $empty));
        }
    }

    /**
     * ARM 2, against a stream whose resource id is provably not its
     * descriptor.
     *
     * A pty slave opened with `fopen()` is the sharp fixture: it is a real
     * terminal (so the sinks would accept a correct descriptor) and it is
     * opened late (so its resource id is a large number naming nothing).
     */
    public function testAnInjectedStreamResolvesToADescriptorOnTheSameDevice(): void
    {
        $slave = $this->openPtySlave();
        $stat  = fstat($slave);
        self::assertIsArray($stat);

        // THE DISCRIMINATOR. The old spelling must not accidentally be right,
        // or nothing below distinguishes the fix from the defect.
        $resourceId = (int) $slave;
        self::assertNotSame(
            [$stat['dev'], $stat['ino']],
            $this->deviceOfDescriptor($resourceId),
            'the resource id happens to name the same device as the stream; '
                . 'this fixture cannot tell the fix from the defect',
        );

        $fd = PosixBackend::descriptorForStream($slave);
        self::assertIsInt($fd, 'no descriptor was resolved for a live pty slave handle');
        self::assertNotSame($resourceId, $fd, 'the resolver handed back the resource id');
        self::assertSame(
            [$stat['dev'], $stat['ino']],
            $this->deviceOfDescriptor($fd),
            'the resolved descriptor does not name the same device as the stream',
        );

        // And the sink the production arm calls accepts it. This is the half
        // that makes the number a DESCRIPTOR rather than merely a number that
        // matched: posix_isatty() is the guard SizeIoctl::query() opens with.
        if (\function_exists('posix_isatty')) {
            self::assertTrue(posix_isatty($fd), 'the resolved descriptor is not a terminal');
            self::assertFalse(
                @posix_isatty($resourceId),
                'the resource id is a terminal here, so the fixture cannot discriminate',
            );
        }

        // The control for arm 2 specifically: point it at an empty table and
        // the same call must fail, so the answer above came from the walk and
        // not from a constant.
        self::assertNull(
            PosixBackend::descriptorForStream($slave, $this->emptyDirectory()),
            'a non-standard stream resolved with no descriptor table to walk',
        );
    }

    /**
     * A stream with no descriptor at all, and a dead handle, both answer null
     * -- with a live positive in the same test so that a resolver returning
     * null unconditionally cannot pass.
     */
    public function testAStreamWithNoDescriptorAndADeadHandleBothAnswerNull(): void
    {
        // MEASURED, PHP 8.3.6: `fstat()` on a `php://memory` stream reports
        // st_ino 0 and the stream owns no entry in the descriptor table, so
        // the walk matches nothing.
        $memory = $this->openHandle('php://memory', 'r+');
        self::assertNull(PosixBackend::descriptorForStream($memory));

        $file = $this->openTempFile();
        self::assertIsInt(
            PosixBackend::descriptorForStream($file),
            'a live file handle resolved to nothing, so the nulls above are not evidence',
        );

        fclose($file);
        self::assertNull(PosixBackend::descriptorForStream($file), 'a closed handle resolved to a descriptor');
        self::assertNull(PosixBackend::descriptorForStream(null), 'null resolved to a descriptor');
        self::assertNull(PosixBackend::descriptorForStream('0'), 'a string resolved to a descriptor');
    }

    /**
     * THE LOWEST matching descriptor is the one handed back.
     *
     * Two handles on one file are two descriptors with identical dev+ino, so
     * arm 2 has a genuine choice to make. The choice is documented on
     * {@see PosixBackend::descriptorForStream()} -- a long-lived low
     * descriptor is likelier to outlive the object than a late one -- and an
     * undocumented, unpinned choice is how the next reader comes to change it
     * for a reason that reads just as good.
     *
     * MEASURED (mutation m5) before this test existed: `sort()` swapped for
     * `rsort()`, whole candy-core suite, 824 tests / 7422 assertions / rc 0 --
     * SURVIVED. The preference was prose only.
     */
    public function testTheLowestDescriptorNamingTheDeviceIsTheOneReturned(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_core_r54a_two_');
        self::assertIsString($path);
        $this->artifacts[] = $path;

        $first  = $this->openHandle($path, 'r+');
        $second = $this->openHandle($path, 'r+');

        $low  = PosixBackend::descriptorForStream($first);
        $high = PosixBackend::descriptorForStream($second);
        self::assertIsInt($low, 'the first handle resolved to nothing');

        // The discriminator: the two handles must really be two descriptors,
        // or there is no choice being made and nothing is being asserted.
        $lowDevice = $this->deviceOfDescriptor($low);
        self::assertSame(
            $lowDevice,
            $this->deviceOfDescriptor($low + 1),
            'the second handle did not take the next descriptor; this fixture makes no choice',
        );

        self::assertSame($low, $high, 'the two handles resolved differently, so the answer is not the lowest match');
    }

    /**
     * A descriptor reused by a NEW stream is not resolved from a stale
     * `stat()`.
     *
     * PHP caches `stat()` by PATH, and `/proc/self/fd/4` is a path whose
     * TARGET changes the moment descriptor 4 is closed and reopened.
     * `size()` runs on every SIGWINCH in a process that opens and closes
     * files, so this is the shape the cache actually bites in.
     *
     * MEASURED, PHP 8.3.6, this box: with descriptor 4 pointing at one temp
     * file, `stat('/proc/self/fd/4')` warmed; the file closed; a second temp
     * file opened onto the same descriptor. The uncleared `stat()` then still
     * reported the FIRST file's inode. MEASURED (mutation m7) before this
     * test existed: `clearstatcache()` deleted, whole candy-core suite,
     * 824 / 7422 / rc 0 -- SURVIVED.
     */
    public function testADescriptorReusedByAnotherStreamIsNotResolvedFromACachedStat(): void
    {
        $table = $this->descriptorTable();

        $first     = $this->openTempFile();
        $firstStat = fstat($first);
        self::assertIsArray($firstStat);

        $descriptor = PosixBackend::descriptorForStream($first);
        self::assertIsInt($descriptor, 'the first handle resolved to nothing');
        fclose($first);

        $second     = $this->openTempFile();
        $secondStat = fstat($second);
        self::assertIsArray($secondStat);
        self::assertNotSame(
            $firstStat['ino'],
            $secondStat['ino'],
            'the two temp files share an inode; this fixture cannot discriminate',
        );

        // DISCRIMINATOR 1: the OS handed the freed descriptor straight back.
        // Deliberately an assertion and not a skip -- if a host stops doing
        // this, the reason this test cannot bite must be visible, not hidden.
        clearstatcache();
        $trueStat = stat($table . '/' . $descriptor);
        self::assertIsArray($trueStat);
        self::assertSame(
            $secondStat['ino'],
            $trueStat['ino'],
            'descriptor ' . $descriptor . ' was not reused by the second handle; '
                . 'this fixture cannot discriminate',
        );

        // DISCRIMINATOR 2: and PHP's cache really does still describe the
        // closed handle's file, which is the thing clearstatcache() exists
        // for here. Warmed by reading the path without clearing first.
        $warm = stat($table . '/' . $descriptor);
        self::assertSame($secondStat['ino'], $warm['ino']);
        clearstatcache();
        stat($table . '/' . $descriptor);

        self::assertSame(
            $descriptor,
            PosixBackend::descriptorForStream($second),
            'the resolver answered from a cached stat of a path whose descriptor had been '
                . 'reused, so it described a file that is no longer there',
        );
    }

    /**
     * SITE 2, END TO END: `size()` on an injected pty slave returns THAT
     * terminal's size.
     *
     * The environment variables are set to a DIFFERENT size on purpose. They
     * are `size()`'s second arm, i.e. exactly where the old body ended up:
     * `SizeIoctl::query()` opens with `posix_isatty($fd)` and throws for a
     * resource id, so the first arm threw and the env answer was returned.
     * Pinning the env to a known wrong value makes the failure deterministic
     * rather than "some other number".
     */
    public function testSizeReadsTheInjectedTerminalRatherThanTheEnvironment(): void
    {
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('SizeIoctl::query() needs ext-ffi.');
        }

        $slave = $this->openPtySlave();
        $path  = $this->slavePath;

        // 137x43 is not a size anything on a host would choose by accident,
        // and it is not the env answer below.
        exec('stty -F ' . escapeshellarg($path) . ' rows 43 cols 137 2>/dev/null', $ignored, $rc);
        if ($rc !== 0) {
            self::markTestSkipped('stty could not set the slave pty size on this host.');
        }

        $columns = getenv('COLUMNS');
        $lines   = getenv('LINES');
        putenv('COLUMNS=11');
        putenv('LINES=7');

        try {
            $backend = new PosixBackend($slave);
            self::assertTrue($backend->isTty(), 'the fixture handle is not a terminal');

            self::assertSame(
                ['cols' => 137, 'rows' => 43],
                $backend->size(),
                'size() did not read the terminal it was given. 11x7 is the COLUMNS/LINES arm, '
                    . 'which is where a resource id lands: SizeIoctl::query() throws on it.',
            );
        } finally {
            $this->restoreEnv('COLUMNS', $columns);
            $this->restoreEnv('LINES', $lines);
        }
    }

    /**
     * SITE 4, END TO END: `enableRawMode()` really puts the injected terminal
     * into raw mode, and `restore()` really takes it back out.
     *
     * Read back with `stty -F <slave path> -a` from THIS process rather than
     * through candy-pty, because a termios structure is opaque there and a
     * reading taken through the same binding that applied it would not be an
     * independent observation.
     */
    public function testEnableRawModeActsOnTheInjectedTerminal(): void
    {
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('TermiosFactory::open() needs ext-ffi for the FFI backend.');
        }

        $slave = $this->openPtySlave();
        $path  = $this->slavePath;

        self::assertFalse($this->isRaw($path), 'setup: the fixture terminal is already raw');

        $backend = new PosixBackend($slave);
        try {
            $backend->enableRawMode();
            self::assertTrue(
                $this->isRaw($path),
                'enableRawMode() did not reach the terminal it was given -- a resource id names '
                    . 'no descriptor, so TermiosFactory::open() acted on some other fd or threw',
            );
        } finally {
            $backend->restore();
        }

        self::assertFalse($this->isRaw($path), 'restore() did not take the terminal back out of raw mode');
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private string $slavePath = '';

    /** @return resource */
    private function openPtySlave()
    {
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is unavailable; there is no terminal device to inject.');
        }
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('candy-pty needs ext-ffi to allocate a pty pair.');
        }

        $pair = (new PosixPtySystem())->open();
        $this->slavePath = $pair->slave()->path();

        $slave = fopen($this->slavePath, 'r+');
        if ($slave === false) {
            self::markTestSkipped('could not open the pty slave path ' . $this->slavePath);
        }
        $this->handles[] = $slave;

        // The master is kept open for the lifetime of the test: closing it
        // hangs up the slave, and tearDown() closes it on every path out --
        // including a failing assertion, which is the path a leaked pty
        // master would otherwise be created on most often.
        $this->masters[] = $pair->master();

        return $slave;
    }

    /** @var list<\SugarCraft\Pty\Contract\MasterPty> */
    private array $masters = [];

    /** @return resource */
    private function openHandle(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        self::assertIsResource($handle, 'could not open ' . $path);
        $this->handles[] = $handle;

        return $handle;
    }

    /** @return resource */
    private function openTempFile()
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_core_r54a_fd_');
        self::assertIsString($path);
        $this->artifacts[] = $path;

        return $this->openHandle($path, 'r+');
    }

    /**
     * A directory with no numeric entries, so the descriptor-table walk can
     * find nothing in it. Process-unique: sibling lanes run their own suites
     * against this same /tmp.
     */
    private function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/sc_core_r54a_nofds_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true), 'could not create the empty control directory');
        $this->artifacts[] = $dir;

        return $dir;
    }

    /** The descriptor table this host publishes. */
    private function descriptorTable(): string
    {
        foreach (['/proc/self/fd', '/dev/fd'] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        self::markTestSkipped('this host publishes no descriptor table; the fixture cannot observe one');
    }

    /**
     * The device and inode a descriptor names, or null when it names nothing.
     *
     * @return array{0:int,1:int}|null
     */
    private function deviceOfDescriptor(int $fd): ?array
    {
        foreach (['/proc/self/fd/', '/dev/fd/'] as $prefix) {
            if (!is_dir(rtrim($prefix, '/'))) {
                continue;
            }
            clearstatcache();
            $stat = @stat($prefix . $fd);
            if (\is_array($stat)) {
                return [$stat['dev'], $stat['ino']];
            }

            return null;
        }

        self::markTestSkipped('this host publishes no descriptor table; the fixture cannot observe one');
    }

    private function isRaw(string $slavePath): bool
    {
        // BSD/macOS stty takes the device flag lowercase; GNU/Linux uses
        // uppercase. The wrong one produces empty output, which reads as
        // "not raw" whatever the terminal is actually doing.
        $flag = \PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $out  = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($slavePath) . ' -a 2>/dev/null'));

        self::assertNotSame('', $out, 'stty could not read the fixture terminal; no reading is possible');

        return str_contains($out, '-icanon') && str_contains($out, '-echo');
    }

    private function restoreEnv(string $name, string|false $previous): void
    {
        if ($previous === false) {
            putenv($name);

            return;
        }
        putenv($name . '=' . $previous);
    }
}
