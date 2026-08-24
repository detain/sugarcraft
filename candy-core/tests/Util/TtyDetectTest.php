<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\TtyDetect;

/**
 * {@see TtyDetect::isAtty()} — the one predicate the whole tree asks.
 *
 * The class had no test of any kind before this file, which is a large part
 * of why it spent its life asking about the wrong file descriptor.
 *
 * ## The defect these tests exist for
 *
 * `isAtty()` used to derive its descriptor with `$fd = (int) $stream` and
 * hand that number to candy-pty. An `(int)` cast of a PHP stream yields its
 * RESOURCE ID, which is a different number from the file descriptor.
 * MEASURED, PHP 8.3.6, fresh CLI process: the resource ids of the three
 * standard streams are 1, 2 and 3 while the descriptors behind them are 0,
 * 1 and 2 — every one off by one. The wrong number nevertheless produced
 * the right answer in an ordinary terminal, because all three descriptors
 * name the same device there, and that is exactly what made the defect
 * invisible.
 *
 * ## Why the interesting test needs a real terminal device
 *
 * A test can only tell the two readings apart where they DISAGREE, and they
 * disagree only when one of the two numbers names a terminal and the other
 * does not. Two non-terminals give false either way; two terminals give
 * true either way. So {@see testARealTtyIsATtyEvenWhenItsResourceIdNamesANonTtyDescriptor()}
 * needs an actual tty, and gets one without a controlling terminal, an
 * ioctl or any FFI: MEASURED on this box, PHP 8.3.6, a plain
 * `fopen('/dev/ptmx', 'r+b')` yields a handle for which `stream_isatty()`
 * is true. That is the whole fixture.
 */
final class TtyDetectTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->open = [];
    }

    public function testNothingThatIsNotALiveStreamIsATty(): void
    {
        // The `resource|null` in the signature is a real cross-package
        // contract, not padding: SugarCraft\Mosaic\Detect::isInteractiveTty()
        // calls this with the result of a resolver that answers null for a
        // dead descriptor 0.
        self::assertFalse(TtyDetect::isAtty(null));

        $closed = fopen('php://memory', 'r+b');
        self::assertIsResource($closed);
        fclose($closed);
        self::assertFalse(TtyDetect::isAtty($closed), 'a closed handle answered true');
    }

    public function testAStreamThatIsNotATerminalIsNotATty(): void
    {
        foreach ($this->nonTerminals() as $label => $handle) {
            self::assertFalse(TtyDetect::isAtty($handle), $label . ' answered true');
        }
    }

    /**
     * THE GUARD FOR THE RESOURCE-ID DEFECT, in the one arrangement that can
     * tell the two readings apart.
     *
     * Both numbers are derived here rather than assumed. The resource id is
     * the `(int)` cast the defect used; the real descriptor is found by
     * matching the handle's `fstat()` device and inode against every entry
     * of `/proc/self/fd`. The pre-assertions then state the arrangement that
     * makes the fixture discriminating — the two numbers differ, the real
     * one is a terminal, the cast one is not — so a run in which the fixture
     * degenerated cannot pass as a run in which the code was right.
     *
     * Illustrative measurement, PHP 8.3.6, plain CLI process: resource id 5,
     * real descriptor 3. Under this suite the gap is far wider (id in the
     * hundreds against a descriptor in the tens), which is the point — the
     * gap is a property of how many streams happen to be open, and of
     * nothing else.
     */
    public function testARealTtyIsATtyEvenWhenItsResourceIdNamesANonTtyDescriptor(): void
    {
        $ptmx = $this->openTerminalDevice();

        $resourceId = (int) $ptmx;
        $realFd     = $this->descriptorBehind($ptmx);

        self::assertNotNull($realFd, 'could not locate the descriptor behind the terminal handle');
        self::assertNotSame(
            $realFd,
            $resourceId,
            'the resource id and the descriptor coincide here, so this fixture proves nothing',
        );

        // Ground truth, twice over: the stream is a terminal, and so is the
        // descriptor actually behind it.
        self::assertTrue(
            stream_isatty($ptmx),
            '/dev/ptmx did not report as a tty; the fixture cannot discriminate',
        );
        self::assertTrue(posix_isatty($realFd), 'descriptor ' . $realFd . ' is not a tty');

        // And the descriptor the CAST names is not a terminal — either it is
        // some other open file or it is not open at all. Either way, a reader
        // that trusts the cast gets the wrong answer here.
        self::assertFalse(
            posix_isatty($resourceId),
            'descriptor ' . $resourceId . ' is a tty, so this fixture proves nothing',
        );

        self::assertTrue(
            TtyDetect::isAtty($ptmx),
            'isAtty() answered about descriptor ' . $resourceId . ' (the resource id) '
                . 'instead of about descriptor ' . $realFd . ', which is the stream it was handed',
        );
    }

    /**
     * The answer must be `stream_isatty()`'s answer for every stream tried,
     * terminal and non-terminal alike.
     *
     * This is the broad net; the test above is the sharp one. Both are here
     * because agreement alone would also be satisfied by a body that always
     * returned false, which is why the terminal case is asserted positively
     * there rather than only compared here.
     */
    public function testTheAnswerAgreesWithStreamIsattyOnEveryStreamTried(): void
    {
        $streams = $this->nonTerminals();
        if (\DIRECTORY_SEPARATOR === '/' && is_readable('/dev/ptmx')) {
            $streams['/dev/ptmx'] = $this->openTerminalDevice();
        }

        $sawTrue = false;
        foreach ($streams as $label => $handle) {
            $expected = stream_isatty($handle);
            $sawTrue  = $sawTrue || $expected;
            self::assertSame($expected, TtyDetect::isAtty($handle), $label . ' disagreed');
        }

        self::assertTrue(
            $sawTrue || !is_readable('/dev/ptmx'),
            'a terminal device was available and yet no stream in the battery was a tty',
        );
    }

    /** @return array<string, resource> */
    private function nonTerminals(): array
    {
        $out = [];

        $devNull = fopen('/dev/null', 'rb');
        self::assertIsResource($devNull);
        $this->open[] = $devNull;
        $out['/dev/null'] = $devNull;

        $memory = fopen('php://memory', 'r+b');
        self::assertIsResource($memory);
        $this->open[] = $memory;
        $out['php://memory'] = $memory;

        $temp = tmpfile();
        self::assertIsResource($temp);
        $this->open[] = $temp;
        $out['tmpfile()'] = $temp;

        return $out;
    }

    /** @return resource */
    private function openTerminalDevice()
    {
        if (\DIRECTORY_SEPARATOR !== '/' || !is_readable('/dev/ptmx')) {
            self::markTestSkipped('/dev/ptmx is not available; no terminal device to open');
        }

        $ptmx = fopen('/dev/ptmx', 'r+b');
        self::assertIsResource($ptmx, '/dev/ptmx is readable but did not open');
        $this->open[] = $ptmx;

        return $ptmx;
    }

    /**
     * The file descriptor number actually behind $stream, or null.
     *
     * There is no portable userland call for this — which is precisely why
     * the production code does not try to derive one and asks
     * `stream_isatty()` about the stream instead. A test may be less
     * portable than the code it tests, so this walks `/proc/self/fd` and
     * matches on device + inode.
     *
     * ## WHAT THIS DOC-BLOCK USED TO SAY, AND WHY IT CHANGED
     *
     * WHAT IT SAID: dev+inode is "exact rather than name-based (two handles
     * on `/dev/ptmx` are different inodes)".
     *
     * WHAT IS TRUE NOW: the parenthetical was FALSE, and it was the whole
     * stated reason the match was safe. MEASURED, PHP 8.3.6: open
     * `/dev/ptmx` twice and both handles report the SAME dev+inode — the
     * walk returns `[3, 4]` for each of them, not one descriptor each. The
     * inode belongs to the `/dev/ptmx` device node, not to the pty master
     * an open creates. `/dev/null` aliases far more loudly (every handle on
     * it matches descriptor 0 as well as itself), but ptmx was the case the
     * justification specifically claimed was safe, and it is not.
     *
     * WHY THIS HELPER STILL EARNS ITS PLACE: dev+inode is still the right
     * comparison — it is exact where a name comparison is not — and the
     * fixture that uses it holds exactly ONE ptmx handle at a time
     * ({@see tearDown()} closes each one), so there is exactly one match
     * and the answer is correct. What was wrong was believing uniqueness
     * came from the DEVICE rather than from the fixture's discipline. Since
     * that discipline is the real invariant and nothing in the type system
     * enforces it, the helper now checks it: more than one match means
     * dev+inode does not identify a descriptor here, and any number it
     * returned would be a guess. It fails loudly instead of picking the
     * first, because a fixture built on a guessed descriptor proves nothing
     * while looking like it proves something.
     *
     * @param resource $stream
     */
    private function descriptorBehind($stream): ?int
    {
        $target = fstat($stream);
        if ($target === false) {
            return null;
        }

        $matches = [];
        foreach ((array) @scandir('/proc/self/fd') as $entry) {
            if (!\is_string($entry) || !ctype_digit($entry)) {
                continue;
            }
            $candidate = @stat('/proc/self/fd/' . $entry);
            if ($candidate === false) {
                continue;
            }
            if ($candidate['dev'] === $target['dev'] && $candidate['ino'] === $target['ino']) {
                $matches[] = (int) $entry;
            }
        }

        if ($matches === []) {
            return null;
        }

        if (\count($matches) > 1) {
            self::fail(
                'device+inode does not identify a single descriptor here: fds '
                    . implode(', ', $matches) . ' all match this handle, so the descriptor '
                    . 'behind it is ambiguous and any answer would be a guess. The fixture '
                    . 'must hold one handle on a device whose inode is not shared, or '
                    . 'identify the descriptor some other way.',
            );
        }

        return $matches[0];
    }
}
