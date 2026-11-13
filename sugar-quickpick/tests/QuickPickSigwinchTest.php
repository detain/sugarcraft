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

namespace SugarCraft\QuickPick\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use SugarCraft\QuickPick\QuickPick;
use SugarCraft\Pty\SignalForwarder;

final class QuickPickSigwinchTest extends TestCase
{
    protected function tearDown(): void
    {
        // Signal dispositions are process-global; never leak a SIGWINCH handler
        // (or the async-dispatch flag) into the rest of the suite.
        SignalForwarder::reset();
        parent::tearDown();
    }

    /**
     * The source text of QuickPick::attachSigwinch(), sliced by reflection rather
     * than matched against the whole file, so a rename elsewhere cannot make
     * this pin vacuously true.
     */
    private static function attachSigwinchBody(): string
    {
        $method = new ReflectionMethod(QuickPick::class, 'attachSigwinch');
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
        $constant = (new ReflectionClass(QuickPick::class))->getReflectionConstant('STDIN_DESCRIPTOR');
        self::assertNotFalse($constant, 'QuickPick must name the descriptor it targets');

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
        $method = new ReflectionMethod(QuickPick::class, 'ttySize');
        $method->setAccessible(true);
        $size = $method->invoke(QuickPick::new(['a']));

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
            // 0, and that ioctl needs a terminal on the descriptor QuickPick names.
            // With a piped stdin the honest outcome is "cannot be exercised",
            // not "passed" — so skip rather than assert a vacuous no-op.
            $this->markTestSkipped('descriptor 0 is not a terminal; TIOCSWINSZ cannot succeed here.');
        }

        $forwarded = null;
        $h = QuickPick::new(['a'])->withOnResize(
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

    public function testTheHandlerInvalidatesTheWidthMemoAcrossTheCloneFamily(): void
    {
        // -082/-023(b): the invalidation half of the handler never ran in CI
        // (the tty test above skips on piped stdin). The seam below invokes the
        // exact closure attachSigwinch() registers, so the resize semantics are
        // asserted on any host.
        $attached = QuickPick::new(['alpha'])->show()->withOnResize(static function (int $cols, int $rows): void {
        });

        $handlerMethod = new ReflectionMethod(QuickPick::class, 'sigwinchHandler');
        $handlerMethod->setAccessible(true);
        $handler = $handlerMethod->invoke($attached);

        $memo = new ReflectionProperty(QuickPick::class, 'widthMemo');
        $memo->setAccessible(true);

        $bg = str_repeat(str_repeat(' ', 40) . "\n", 5);
        $attached->view($bg); // warm the memo (ttySize falls back to 80x24 on pipes)
        $this->assertNotNull($memo->getValue($attached)->width, 'render must populate the memo');

        // setOffset() invalidates nothing on its own; the clone shares the warm
        // memo, which is what makes the next assertions about the HANDLER.
        $later = $attached->withOffset(3, 0);
        $this->assertNotNull($memo->getValue($later)->width, 'clones share the memo by handle');

        $handler(100, 40);

        $this->assertNull($memo->getValue($attached)->width, 'the handler must clear the memo');
        $this->assertNull(
            $memo->getValue($later)->width,
            'the resize must reach clones made AFTER the attach — the attach-time binding '
            . 'must not leave the live fluent chain silently rendering a stale width',
        );
    }

    public function testTheHandlerStillForwardsGeometryThroughTheSeam(): void
    {
        $forwarded = null;
        $model = QuickPick::new(['a'])->withOnResize(
            static function (int $cols, int $rows) use (&$forwarded): void {
                $forwarded = [$cols, $rows];
            },
        );

        $handlerMethod = new ReflectionMethod(QuickPick::class, 'sigwinchHandler');
        $handlerMethod->setAccessible(true);
        $handlerMethod->invoke($model)(132, 43);

        $this->assertSame([132, 43], $forwarded, 'the geometry half must still reach the callback');
    }

    /**
     * S-31/-026: sugar-pty is SUGGESTED, not required. The installer seam
     * must compose the exact same handler semantics over any delivery: the
     * fake receives the named descriptor, a working geometry probe, and the
     * composed handler — and running that handler invalidates the shared
     * memo AND forwards to $onResize.
     */
    public function testTheInstallerSeamComposesTheFullHandlerOverAnyDelivery(): void
    {
        $forwarded = null;
        $model = QuickPick::new(['a'])->withOnResize(
            static function (int $cols, int $rows) use (&$forwarded): void {
                $forwarded = [$cols, $rows];
            },
        );

        $seen = [];
        $installed = $model->attachSigwinch(
            static function (int $fd, callable $probe, \Closure $handler) use (&$seen): bool {
                $seen = ['fd' => $fd, 'probe' => $probe, 'handler' => $handler];
                return true;
            },
        );

        $this->assertTrue($installed, 'a successful custom install must report true');
        $this->assertSame(0, $seen['fd'], 'the seam receives the named descriptor');
        $probeSize = ($seen['probe'])();
        $this->assertSame(
            ['cols', 'rows'],
            array_keys($probeSize),
            'the probe answers the SignalForwarder geometry shape',
        );

        $memo = new ReflectionProperty(QuickPick::class, 'widthMemo');
        $memo->setAccessible(true);
        $model->show()->view(str_repeat(str_repeat(' ', 40) . "\n", 5));
        $this->assertNotNull($memo->getValue($model)->width, 'render must populate the memo');

        ($seen['handler'])(99, 33);

        $this->assertNull($memo->getValue($model)->width, 'the delivered handler must clear the memo');
        $this->assertSame([99, 33], $forwarded, 'and forward geometry to the callback');
    }

    /**
     * S-31/-026: with sugar-pty installed in dev there is no way to run the
     * class_exists-false arm at runtime — pin the guard textually so
     * "unconditional SignalForwarder call" cannot creep back in. The arm's
     * contract is documented: attachSigwinch() returns false and the host
     * uses invalidateAutoWidth() (pinned below).
     */
    public function testTheDefaultInstallGuardsTheOptionalSignalForwarder(): void
    {
        $this->assertStringContainsString(
            'class_exists(SignalForwarder::class)',
            self::attachSigwinchBody(),
            'the sugar-pty touchpoint must stay guarded (suggest, not require)',
        );
    }

    /**
     * S-31/-026: manifest shape, read as JSON (never via composer):
     * sugar-pty gone from require, named in suggest, kept in require-dev so
     * the dev-side real-signal pins still run.
     */
    public function testComposerManifestKeepsSugarPtyOptional(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($manifest);
        $this->assertArrayNotHasKey('sugarcraft/sugar-pty', $manifest['require'] ?? []);
        $this->assertArrayHasKey('sugarcraft/sugar-pty', $manifest['suggest'] ?? []);
        $this->assertArrayHasKey('sugarcraft/sugar-pty', $manifest['require-dev'] ?? []);
    }

    /**
     * S-31/-026: the manual-invalidate contract — one public call drops the
     * memoized width for the WHOLE clone family (shared holder, S-10), so a
     * host owning its own SIGWINCH delivery needs no sugar-pty at all.
     */
    public function testInvalidateAutoWidthClearsTheSharedHolderAcrossTheFamily(): void
    {
        $model = QuickPick::new(['alpha'])->show();
        $model->view(str_repeat(str_repeat(' ', 40) . "\n", 5));

        $memo = new ReflectionProperty(QuickPick::class, 'widthMemo');
        $memo->setAccessible(true);
        $this->assertNotNull($memo->getValue($model)->width, 'render must populate the memo');

        $later = $model->withOffset(2, 0);

        $model->invalidateAutoWidth();

        $this->assertNull($memo->getValue($model)->width, 'the call must clear the memo');
        $this->assertNull($memo->getValue($later)->width, 'and reach clones through the shared holder');
    }
}
