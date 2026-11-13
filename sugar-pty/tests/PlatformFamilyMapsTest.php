<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\ControllingTerminal;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Pty\TermiosFactory;

/**
 * PTY-27: the per-family maps (soname / errno accessor / winsize /
 * TIOCSCTTY / O_NOCTTY) each carry real values for the families sugar-pty
 * claims and THROW for every family it has not validated — no more
 * ternary tails handing Solaris and unknown BSDs the Linux number.
 *
 * Linux values are runner-measured; Darwin values are runner-measured;
 * FreeBSD values are transcribed from the public `sys/ttydefaults.h` /
 * `sys/filio.h` / `fcntl.h` encodings and remain UNEXERCISED until a BSD
 * CI seat (the refusal-to-guess half is exercised right here, on any
 * host, because the seams take the family as data).
 */
final class PlatformFamilyMapsTest extends TestCase
{
    public function testLinuxLegsKeepTheirMeasuredValues(): void
    {
        $this->assertSame(['set' => 0x5414, 'get' => 0x5413], SizeIoctl::requestsFor('Linux'));
        $this->assertSame(0x540E, ControllingTerminal::cttyRequestFor('Linux'));
        $this->assertSame(0o400, TermiosFactory::oNoCttyFor('Linux'));
        $this->assertSame('__errno_location', Libc::errnoSymbolFor('Linux'));
        $this->assertSame(Libc::DEFAULT_LINUX, Libc::libraryPathFor('Linux'));
    }

    public function testDarwinLegsKeepTheirMeasuredValues(): void
    {
        $this->assertSame(['set' => 0x80087467, 'get' => 0x40087468], SizeIoctl::requestsFor('Darwin'));
        $this->assertSame(0x20007461, ControllingTerminal::cttyRequestFor('Darwin'));
        $this->assertSame(0x20000, TermiosFactory::oNoCttyFor('Darwin'));
        $this->assertSame('__error', Libc::errnoSymbolFor('Darwin'));
        $this->assertSame(Libc::DEFAULT_DARWIN, Libc::libraryPathFor('Darwin'));
    }

    public function testFreebsdHasItsOwnValuesAndNotADarwinCopy(): void
    {
        // The trap this row exists for: 't 105' vs Darwin's 't 103' set
        // request — a "share the BSD encoding" shortcut would have
        // copy-pasted 0x80087467 and murdered every FreeBSD resize.
        $freebsd = SizeIoctl::requestsFor('BSD');
        $this->assertSame(0x80087469, $freebsd['set'], 'FreeBSD TIOCSWINSZ is _IOW(t,105)');
        $this->assertSame(0x40087468, $freebsd['get'], 'TIOCGWINSZ (_IOR(t,104)) does match Darwin');
        $this->assertNotSame(SizeIoctl::DARWIN_TIOCSWINSZ, $freebsd['set']);

        // TIOCSCTTY genuinely shares Darwin's number (_IO('t', 97)).
        $this->assertSame(0x20007461, ControllingTerminal::cttyRequestFor('BSD'));

        // O_NOCTTY does NOT: 0o200 there; Linux's 0o400 is O_NOFOLLOW
        // on FreeBSD, the symlink-semantics landmine sugar-pty-026 named.
        $this->assertSame(0o200, TermiosFactory::oNoCttyFor('BSD'));
        $this->assertNotSame(TermiosFactory::oNoCttyFor('Linux'), TermiosFactory::oNoCttyFor('BSD'));

        $this->assertSame('__error', Libc::errnoSymbolFor('BSD'));
        $this->assertNotSame([], Libc::FREEBSD_LIBRARY_CANDIDATES);
    }

    public function testFreebsdSonameResolverRefusesToGuessWhenNothingExists(): void
    {
        foreach (Libc::FREEBSD_LIBRARY_CANDIDATES as $candidate) {
            if (\is_file($candidate)) {
                $this->assertSame($candidate, Libc::libraryPathFor('BSD'));

                return;
            }
        }
        // On Linux (every CI runner today) none of these files exist:
        // the BSD route must throw its own honest message, not return
        // libc.so.6.
        $this->expectException(PtyException::class);
        $this->expectExceptionMessage('SUGARCRAFT_LIBC');
        Libc::libraryPathFor('BSD');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unmappedFamilies(): array
    {
        return [
            'Solaris' => ['Solaris'],
            'Windows' => ['Windows'],
            'Cygnus' => ['Cygnus'],
            'garbage' => ['not-a-family'],
        ];
    }

    /**
     * @dataProvider unmappedFamilies
     */
    public function testUnmappedFamiliesFailFastOnEverySeam(string $family): void
    {
        $seams = [
            'winsize' => static fn () => SizeIoctl::requestsFor($family),
            'ctty' => static fn () => ControllingTerminal::cttyRequestFor($family),
            'noctty' => static fn () => TermiosFactory::oNoCttyFor($family),
            'errno' => static fn () => Libc::errnoSymbolFor($family),
            'soname' => static fn () => Libc::libraryPathFor($family),
        ];

        foreach ($seams as $name => $call) {
            try {
                $call();
                $this->fail("seam {$name} accepted unmapped family {$family}");
            } catch (PtyException $e) {
                $this->assertStringContainsString($family, $e->getMessage(), "{$name} refusal must name the family");
            }
        }
    }

    public function testHostAccessorsDelegateToTheMaps(): void
    {
        // The live zero-arg accessors must be exactly the host family's
        // mapped values — proof the old ternaries are gone, on any host.
        $this->assertSame(SizeIoctl::requestsFor(\PHP_OS_FAMILY)['set'], SizeIoctl::setRequest());
        $this->assertSame(SizeIoctl::requestsFor(\PHP_OS_FAMILY)['get'], SizeIoctl::getRequest());
        $this->assertSame(TermiosFactory::oNoCttyFor(\PHP_OS_FAMILY), TermiosFactory::oNoCtty());
        $this->assertSame(Libc::errnoSymbolFor(\PHP_OS_FAMILY), Libc::errnoSymbol());
    }
}
