<?php

/**
 * Pins for the descriptor attachSigwinch() hands to the resize ioctl, and for
 * the body of the installed signal handler.
 *
 * The bug these guard: `SignalForwarder::attachSigwinchToFd((int) \STDIN, ...)`.
 * An `(int)` cast of a PHP stream is its RESOURCE ID, not its file descriptor,
 * so the call targeted descriptor 1 (STDOUT) while meaning descriptor 0. It only
 * appeared to work because stdout is usually the same terminal as stdin; with
 * stdout redirected, `ioctl(TIOCSWINSZ)` failed, the return code was swallowed,
 * and the user's $onResize callback simply never fired.
 */

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use SugarCraft\Hermit\Hermit;
use SugarCraft\Pty\SignalForwarder;

final class HermitSigwinchTest extends TestCase
{
    protected function tearDown(): void
    {
        // Signal dispositions are process-global; never leak a SIGWINCH handler
        // (or the async-dispatch flag) into the rest of the suite.
        SignalForwarder::reset();
        parent::tearDown();
    }

    /**
     * The source text of Hermit::attachSigwinch(), sliced by reflection rather
     * than matched against the whole file, so a rename elsewhere cannot make
     * this pin vacuously true.
     */
    private static function attachSigwinchBody(): string
    {
        $method = new ReflectionMethod(Hermit::class, 'attachSigwinch');
        $lines  = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    private static function stdinDescriptor(): int
    {
        $constant = (new ReflectionClass(Hermit::class))->getReflectionConstant('STDIN_DESCRIPTOR');
        self::assertNotFalse($constant, 'Hermit must name the descriptor it targets');

        return (int) $constant->getValue();
    }

    /**
     * Every `(int)` cast applied to a standard stream — the bug's exact shape.
     *
     * Read from the TOKEN stream rather than the raw text on purpose: this
     * class's comments quote `(int) \STDIN` to explain why it is wrong, and a
     * plain text scan would accuse the documentation of the defect it
     * documents. Comments carry no tokens here, so they cannot offend.
     *
     * @return list<string> "line (int) NAME" for each offender
     */
    private static function streamCasts(string $source): array
    {
        $found  = [];
        $tokens = \token_get_all($source);

        for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
            $cast = $tokens[$i];
            if (!\is_array($cast) || $cast[0] !== T_INT_CAST) {
                continue;
            }
            // Step over whitespace and an optional leading namespace separator.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && ($next[0] === T_WHITESPACE || $next[0] === T_NS_SEPARATOR)) {
                    continue;
                }
                if (\is_array($next)
                    && $next[0] === T_STRING
                    && \in_array(\strtoupper($next[1]), ['STDIN', 'STDOUT', 'STDERR'], true)
                ) {
                    $found[] = "line {$cast[2]}: (int) {$next[1]}";
                }
                break;
            }
        }

        return $found;
    }

    public function testTheResizeIoctlIsGivenTheStdinDescriptorZero(): void
    {
        $this->assertSame(0, self::stdinDescriptor());
    }

    public function testCastingTheStdinResourceIsNotItsDescriptor(): void
    {
        // The trap itself, pinned as a fact about PHP rather than left to a
        // comment: the resource id is 1, the descriptor is 0. If a future edit
        // "simplifies" the constant back to `(int) \STDIN`, the pin above and
        // the body scan below both go red, and this test explains why they did.
        if (!\is_resource(\STDIN)) {
            $this->markTestSkipped('STDIN is not a resource in this SAPI.');
        }

        $this->assertNotSame(
            self::stdinDescriptor(),
            (int) \STDIN,
            'the cast resolves to STDOUT — that coincidence WAS the bug',
        );
    }

    public function testAttachSigwinchBodyPassesTheNamedDescriptor(): void
    {
        $body = self::attachSigwinchBody();

        $this->assertStringContainsString(
            'self::STDIN_DESCRIPTOR',
            $body,
            'attachSigwinch() must target the named descriptor, not an inline number',
        );
        $this->assertSame(
            [],
            self::streamCasts($body),
            'an (int) cast of a stream yields the resource id, never the fd',
        );
    }

    public function testNoStreamCastReappearsAnywhereInSrc(): void
    {
        // Form guard for the whole lib: `(int) STDIN|STDOUT|STDERR` is always the
        // wrong answer when a descriptor is wanted.
        $directory = new RecursiveDirectoryIterator(
            (string) dirname(__DIR__) . '/src',
            FilesystemIterator::SKIP_DOTS,
        );
        $hits = [];
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            foreach (self::streamCasts(is_string($contents) ? $contents : '') as $offence) {
                $hits[] = $file->getFilename() . ' ' . $offence;
            }
        }

        $this->assertSame([], $hits, 'descriptor must come from a named constant, not a stream cast');
    }

    public function testSizeProviderYieldsUsableGeometryWithoutATerminal(): void
    {
        // The handler's read half runs on every SIGWINCH. It must return the
        // shape SignalForwarder unpacks — array{cols:int, rows:int} — and must
        // not throw when descriptor 0 is a pipe, because a throwing handler is
        // swallowed and would take the resize down silently with it.
        $method = new ReflectionMethod(Hermit::class, 'ttySize');
        $method->setAccessible(true);
        $size = $method->invoke(Hermit::new(['a']));

        $this->assertIsArray($size);
        $this->assertArrayHasKey('cols', $size);
        $this->assertArrayHasKey('rows', $size);
        $this->assertIsInt($size['cols']);
        $this->assertIsInt($size['rows']);
        $this->assertGreaterThan(0, $size['cols'], 'a zero width would render nothing');
        $this->assertGreaterThan(0, $size['rows'], 'a zero height would render nothing');
    }

    public function testTheHandlerBodyForwardsLiveGeometryWhenStdinIsATerminal(): void
    {
        if (!SignalForwarder::pcntlReady() || !\defined('SIGWINCH') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('ext-pcntl, SIGWINCH or ext-posix unavailable on this host.');
        }
        if (!\stream_isatty(\STDIN)) {
            // SignalForwarder calls $onResize only when ioctl(TIOCSWINSZ) returns
            // 0, and that ioctl needs a terminal on the descriptor Hermit names.
            // With a piped stdin the honest outcome is "cannot be exercised",
            // not "passed" — so skip rather than assert a vacuous no-op.
            $this->markTestSkipped('descriptor 0 is not a terminal; TIOCSWINSZ cannot succeed here.');
        }

        $forwarded = null;
        $h = Hermit::new(['a'])->withOnResize(
            static function (int $cols, int $rows) use (&$forwarded): void {
                $forwarded = ['cols' => $cols, 'rows' => $rows];
            },
        );

        $this->assertTrue($h->attachSigwinch(), 'handler must install on this host');

        \posix_kill(\posix_getpid(), SIGWINCH);
        SignalForwarder::dispatch();

        $this->assertIsArray($forwarded, 'the installed handler must reach the callback');
        $this->assertGreaterThan(0, $forwarded['cols'], 'handler must forward a real width');
        $this->assertGreaterThan(0, $forwarded['rows'], 'handler must forward a real height');
    }
}
