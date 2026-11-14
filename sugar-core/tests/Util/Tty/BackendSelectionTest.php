<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Core\Util\Tty\WindowsBackend;

/**
 * Backend-selection truth pins (sc-40, finding sugar-core-056).
 *
 * The defect was TWO selection implementations with different check ORDER:
 * the instance path (`Tty::backend()`) tested DIRECTORY_SEPARATOR first —
 * so native Windows PHP launched from Git-Bash (which sets MSYSTEM but is a
 * '\\' process) got a WindowsBackend instance — while the static path
 * (`Tty::concreteBackendClass()`, driving openTty/restoreLast/onResize/
 * drainSignals) tested the WSL/Mintty/Cygwin env probes first and answered
 * PosixBackend for the same process. Two APIs, two truths, one terminal.
 *
 * Both paths now feed one pure decision function. The full 16-shape matrix
 * runs on ANY host because the function takes booleans — this is exactly
 * the "Windows-branch selection tested via fixtures on Linux" posture the
 * suite takes elsewhere; the live native-Windows boxes stay with the owner's
 * CI-survivor family (sc-02's named leg), not this row.
 */
final class BackendSelectionTest extends TestCase
{
    /** @param class-string<\SugarCraft\Core\Util\Tty\Backend> $expected */
    private function decide(bool $nativeWindows, bool $wsl, bool $mintty, bool $cygwin): string
    {
        $method = new ReflectionMethod(Tty::class, 'decideBackendClass');
        $method->setAccessible(true);

        /** @var class-string<\SugarCraft\Core\Util\Tty\Backend> $class */
        $class = $method->invoke(null, $nativeWindows, $wsl, $mintty, $cygwin);

        return $class;
    }

    public static function provideSelectionMatrix(): array
    {
        $rows = [];
        foreach ([true, false] as $native) {
            foreach ([true, false] as $wsl) {
                foreach ([true, false] as $mintty) {
                    foreach ([true, false] as $cygwin) {
                        $posixFronted = $wsl || $mintty || $cygwin;
                        $rows[sprintf('native=%d wsl=%d mintty=%d cygwin=%d', (int) $native, (int) $wsl, (int) $mintty, (int) $cygwin)] = [
                            $native,
                            $wsl,
                            $mintty,
                            $cygwin,
                            $posixFronted ? PosixBackend::class : ($native ? WindowsBackend::class : PosixBackend::class),
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @dataProvider provideSelectionMatrix
     *
     * @param class-string<\SugarCraft\Core\Util\Tty\Backend> $expected
     */
    public function testEveryDetectionShapeResolvesTheDocumentedBackend(
        bool $nativeWindows,
        bool $wsl,
        bool $mintty,
        bool $cygwin,
        string $expected,
    ): void {
        self::assertSame($expected, $this->decide($nativeWindows, $wsl, $mintty, $cygwin));
    }

    /**
     * THE divergence shape, named: native Windows + Git-Bash env. Pre-fix
     * the instance path answered WindowsBackend here while the static path
     * answered PosixBackend; the order law (env probes bind before the
     * separator arm) makes both answer PosixBackend from one function.
     */
    public function testMinttyOnNativeWindowsLosesToTheEnvironmentNotTheSeparator(): void
    {
        self::assertSame(PosixBackend::class, $this->decide(true, false, true, false));
        self::assertSame(PosixBackend::class, $this->decide(true, false, false, true));
        self::assertSame(PosixBackend::class, $this->decide(true, true, false, false));
    }

    public function testPlainNativeWindowsStillTakesTheFfiBackend(): void
    {
        self::assertSame(WindowsBackend::class, $this->decide(true, false, false, false));
    }

    public function testPlainPosixTakesThePosixBackend(): void
    {
        self::assertSame(PosixBackend::class, $this->decide(false, false, false, false));
    }

    /**
     * On THIS host the two public-facing paths must agree for real, not just
     * in the pure function: the constructed façade's backend and the static
     * class answer are the same class.
     */
    public function testInstancePathAndStaticPathAgreeOnThisHost(): void
    {
        $ttyRefl  = new ReflectionClass(Tty::class);
        $instance = $ttyRefl->getProperty('backend');
        $instance->setAccessible(true);
        $classMethod = $ttyRefl->getMethod('concreteBackendClass');
        $classMethod->setAccessible(true);

        $tty = new Tty(STDIN);

        self::assertInstanceOf($classMethod->invoke(null), $instance->getValue($tty));
        self::assertSame($classMethod->invoke(null), $instance->getValue($tty)::class);
    }

    /**
     * Structural pin (mutation-armed guard against a re-duplicated order):
     * exactly the two entry points derive the separator boolean, and exactly
     * the two of them consult the single decision function. A re-inlined
     * if-chain in either path changes one of these counts.
     */
    public function testSelectionOrderLivesInExactlyOneFunction(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(Tty::class))->getFileName());

        self::assertSame(
            2,
            substr_count($source, "DIRECTORY_SEPARATOR === '\\\\'"),
            'the separator boolean may be derived once per public entry point (backend + concreteBackendClass) and nowhere else',
        );
        self::assertSame(
            2,
            substr_count($source, 'self::decideBackendClass('),
            'both entry points must delegate to decideBackendClass() — an inlined order is the sc-40 defect returning',
        );
    }
}
