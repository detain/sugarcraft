<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Libc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * PTY-28 (sugar-pty-011 + sugar-pty-083): `Libc::libutil()` honours
 * `SUGARCRAFT_LIBC` FIRST — but only after proving the candidate
 * really exports `openpty`, because a blind `FFI::cdef` against a
 * library lacking the symbol succeeds on lazy-binding hosts and the
 * later call segfaults (measured on glibc PHP 8.3). And the function
 * is pinned as the test surface it is: zero production callers.
 */
final class LibutilOverrideTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private ?string $savedOverride = null;

    protected function setUp(): void
    {
        $env = \getenv('SUGARCRAFT_LIBC');
        $this->savedOverride = \is_string($env) ? $env : null;
    }

    protected function tearDown(): void
    {
        if ($this->savedOverride === null) {
            \putenv('SUGARCRAFT_LIBC');
        } else {
            \putenv('SUGARCRAFT_LIBC=' . $this->savedOverride);
        }
        Libc::reset();
    }

    /** Independent dlopen+dlsym verdict — deliberately NOT Libc's own probe. */
    private static function exportsOpenpty(string $library): bool
    {
        $probe = \FFI::cdef('void *dlopen(const char *filename, int flag); void *dlsym(void *handle, const char *symbol); int dlclose(void *handle);');
        $h = $probe->dlopen($library, 1);
        if ($h === null) {
            return false;
        }
        $s = $probe->dlsym($h, 'openpty');
        $probe->dlclose($h);

        return $s !== null;
    }

    public function testNoOverridePrefersTheClassicLibutilSoname(): void
    {
        $this->requirePtySyscalls();
        \putenv('SUGARCRAFT_LIBC');
        Libc::reset();

        $ffi = Libc::libutil();
        $this->assertInstanceOf(\FFI::class, $ffi);

        if (self::exportsOpenpty(Libc::DEFAULT_LINUX_UTILS)) {
            $this->assertSame(Libc::DEFAULT_LINUX_UTILS, Libc::libutilSource());
        } else {
            // A host where the classic soname died (future merged
            // glibc packaging): the libc fallback must carry it.
            $this->assertSame(Libc::DEFAULT_LINUX, Libc::libutilSource());
        }
    }

    public function testOverrideThatExportsOpenptyIsSelectedFirst(): void
    {
        $this->requirePtySyscalls();

        // Any absolute, basename-valid, openpty-exporting libc copy.
        $candidates = [
            '/lib/x86_64-linux-gnu/libc.so.6',
            '/usr/lib/x86_64-linux-gnu/libc.so.6',
            '/lib64/libc.so.6',
            \SugarCraft\Pty\Libc::DEFAULT_LINUX,
        ];
        $chosen = null;
        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && self::exportsOpenpty($candidate)) {
                $chosen = $candidate;
                break;
            }
        }
        if ($chosen === null) {
            $this->markTestSkipped('no openpty-exporting libc copy found to use as override');
        }

        \putenv('SUGARCRAFT_LIBC=' . $chosen);
        Libc::reset();
        $ffi = Libc::libutil();

        $this->assertInstanceOf(\FFI::class, $ffi);
        $this->assertSame($chosen, Libc::libutilSource(), 'the env override must win when it exports openpty');
    }

    public function testOverrideWithoutOpenptyDoesNotShadowTheRealProvider(): void
    {
        $this->requirePtySyscalls();

        // A dlopen-able, validation-passing library that is not a libc.
        // On classic glibc it lacks openpty (the probe must refuse it —
        // a naive override-first would bind it, cdef succeeds on lazy
        // hosts, first call segfaults). On merged glibc (>= 2.38) the
        // symbol resolves through its deps and selecting it is honest;
        // the test arms itself accordingly below.
        $decoySource = null;
        foreach (['/usr/lib/x86_64-linux-gnu/libz.so.1', '/lib/x86_64-linux-gnu/libz.so.1', '/usr/lib/x86_64-linux-gnu/libm.so.6'] as $maybe) {
            if (\is_file($maybe)) {
                $decoySource = $maybe;
                break;
            }
        }
        if ($decoySource === null) {
            $this->markTestSkipped('no validation-passing non-libc library available as decoy on this host');
        }

        $dir = \sys_get_temp_dir() . '/pty28-' . \posix_getpid();
        \mkdir($dir, 0o700, true);
        $decoy = $dir . '/libcoverridedecoy.so';
        \copy($decoySource, $decoy);

        try {
            \putenv('SUGARCRAFT_LIBC=' . $decoy);
            Libc::reset();
            $ffi = Libc::libutil();

            $this->assertInstanceOf(\FFI::class, $ffi);
            $decoyExports = self::exportsOpenpty($decoy);
            if (!$decoyExports) {
                // Classic glibc: openpty lives only in libutil — the
                // probe must refuse the decoy and the classic soname
                // (or libc) must carry the selection instead.
                $this->assertNotSame($decoy, Libc::libutilSource(), 'an override lacking openpty must never be selected');
                $this->assertTrue(self::exportsOpenpty((string) Libc::libutilSource()));

                return;
            }

            // Merged glibc (>= 2.38): the symbol IS reachable through
            // the decoy's dependency graph, so selecting it is honest —
            // prove the verdict never lies by actually calling through
            // whatever handle came back. A segfault here kills the
            // runner and the mutation story tells you why.
            $this->assertSame(
                $decoy,
                Libc::libutilSource(),
                'an override whose graph exports openpty must win (override-first law)',
            );
            $m = $ffi->new('int[1]');
            $s = $ffi->new('int[1]');
            $rc = $ffi->openpty(\FFI::addr($m[0]), \FFI::addr($s[0]), null, null, null);
            $this->assertSame(0, $rc, 'openpty through the selected handle must work, not segfault');
            if ($m[0] >= 0) {
                $ffi->close($m[0]);
            }
            if ($s[0] >= 0) {
                $ffi->close($s[0]);
            }
        } finally {
            @\unlink($decoy);
            @\rmdir($dir);
        }
    }

    public function testMalformedOverrideRefusalPropagatesThroughLibutil(): void
    {
        // The security contract: validation throws BEFORE any candidate
        // is loaded — libutil must not swallow it.
        \putenv('SUGARCRAFT_LIBC=/etc/passwd');
        Libc::reset();

        $this->expectException(PtyException::class);
        Libc::libutil();
    }

    public function testLibutilIsMarkedTestSurfaceAndHasZeroProductionCallers(): void
    {
        $doc = (new \ReflectionMethod(Libc::class, 'libutil'))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
        $this->assertStringContainsString('TEST SURFACE', $doc);

        $callers = [];
        $root = \dirname(__DIR__, 2);
        $sites = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($sites as $file) {
            if ($file->isDir() || $file->getFilename() === 'Libc.php') {
                continue;
            }
            if (\preg_match('/(?:Libc::|->)libutil\s*\(/', (string) \file_get_contents($file->getPathname()))) {
                $callers[] = $file->getPathname();
            }
        }
        foreach (['bin/pty-shim.php'] as $bin) {
            $path = $root . '/' . $bin;
            if (\is_file($path) && \preg_match('/libutil\s*\(/', (string) \file_get_contents($path))) {
                $callers[] = $path;
            }
        }

        $this->assertSame([], $callers, 'libutil() gained a production caller — drop the test-surface marker honestly or move the use to the quartet path');
    }
}
