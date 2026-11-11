<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use FFI;
use PHPUnit\Framework\TestCase;

/**
 * Pins the accessor law for scalar out-parameters in the Win32 binding.
 *
 * PHP FFI exposes a scalar created by FFI::new() through its ->cdata
 * property only. Dimension access ($handle[0]) is reserved for pointer
 * and array CData and throws "Cannot use object of type FFI\CData as
 * array" on scalars — every scalar out-param read in
 * Kernel32ConPty therefore goes through ->cdata, never [0]. The Windows
 * 8.4 CI leg proved the alternative: the createPipe site still read
 * $read[0], so ConPtySystem::open() died at the first FFI call instead
 * of refusing or succeeding.
 *
 * The structural arm reads the source and needs no Windows and no ffi
 * extension, so it runs on every host; the empirical arm demonstrates
 * the language rule the structural arm polices.
 */
final class Kernel32ConPtyScalarAccessTest extends TestCase
{
    public function testEveryScalarOutParamReadGoesThroughCdataNotADimension(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Windows/Kernel32ConPty.php');
        self::assertIsString($source, 'the Win32 binding source should be readable');

        $offenders = [];
        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match('/\$[A-Za-z_]\w*\[0\]/', $line) === 1) {
                $offenders[] = 'line ' . ($number + 1) . ': ' . trim($line);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "scalar FFI out-parameters must be read through ->cdata; these lines use dimension access:\n"
                . implode("\n", $offenders),
        );
    }

    public function testAScalarCDataRoundTripsThroughCdataAndRefusesDimensionAccess(): void
    {
        if (!extension_loaded('ffi')) {
            self::markTestSkipped('the ffi extension is not loaded on this host');
        }

        $ffi = FFI::cdef('', null);
        $scalar = $ffi->new('intptr_t');

        $scalar->cdata = 7;
        self::assertSame(7, (int) $scalar->cdata);

        $caught = null;
        try {
            /** @phpstan-ignore offsetAccess.nonOffsetAccessible (proving the runtime refuses it) */
            $dimension = $scalar[0];
            self::fail('dimension access on a scalar CData should throw; read ' . var_export($dimension, true));
        } catch (\Error $error) {
            $caught = $error->getMessage();
        }

        self::assertIsString($caught);
        self::assertStringContainsString('Cannot use object of type FFI\CData as array', $caught);
    }
}
