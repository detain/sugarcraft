<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\Kernel32;

/**
 * sc-01 (finding sugar-core-048, CRITICAL): the console-size FFI path.
 *
 * The shipped defect was two stacked bugs: `getConsoleScreenBufferInfo()`
 * read the window corners from byte offsets 4/8/6/10 of a raw blob (those
 * are dwCursorPosition.X, wAttributes, dwCursorPosition.Y and
 * srWindow.Left — a garbage rectangle), and the stride walk used
 * `\FFI::ptr()`, a method no PHP release defines, so every call fataled
 * and `WindowsBackend::size()`'s bare `catch (\Throwable)` answered
 * LAST_RESORT_SIZE 200x60 silently on every Windows run.
 *
 * These pins are Linux-executable by design: the struct typedefs compile
 * standalone (no kernel32.dll), the 22-byte CONSOLE_SCREEN_BUFFER_INFO
 * ABI is proven by writing named fields and comparing raw little-endian
 * bytes, and the rectangle→size mapping is a pure seam. The live-DLL leg
 * is the named Windows-box CI survivor (code shape + platform docs only,
 * never gated from Linux).
 */
final class Kernel32SizeTest extends TestCase
{
    // ─── cdef compiles + sizeof pin ──────────────────────────────────────────

    public function testStructCdefCompilesStandaloneAndMatchesWindowsAbi(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi required for the struct layout pins');
        }

        $ffi  = \FFI::cdef(Kernel32::SIZE_CDEF);
        $info = $ffi->new('CONSOLE_SCREEN_BUFFER_INFO');

        // COORD(2+2) + COORD(2+2) + WORD(2) + SMALL_RECT(4*2) + COORD(2+2) = 22.
        $this->assertSame(22, \FFI::sizeof($info));
    }

    public function testFieldReadsLandOnDocumentedByteOffsets(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi required for the struct layout pins');
        }
        if (unpack('v', pack('S', 1))[1] !== 1) {
            $this->markTestSkipped('byte-offset pin assumes a little-endian host');
        }

        $ffi  = \FFI::cdef(Kernel32::SIZE_CDEF);
        $info = $ffi->new('CONSOLE_SCREEN_BUFFER_INFO');

        $info->dwSize->X              = 120;
        $info->dwSize->Y              = 3000;
        $info->dwCursorPosition->X    = 7;
        $info->dwCursorPosition->Y    = 9;
        $info->wAttributes            = 15;
        $info->srWindow->Left         = 0;
        $info->srWindow->Top          = 2;
        $info->srWindow->Right        = 79;
        $info->srWindow->Bottom       = 25;
        $info->dwMaximumWindowSize->X = 120;
        $info->dwMaximumWindowSize->Y = 3000;

        $raw = \FFI::string($ffi->cast('char*', \FFI::addr($info)), 22);

        // The documented MSVC layout, short by short:
        // @0/2 dwSize, @4/6 cursor, @8 attributes, @10/12/14/16 srWindow
        // (Left, Top, Right, Bottom), @18/20 maximum window.
        $this->assertSame(
            pack('v11', 120, 3000, 7, 9, 15, 0, 2, 79, 25, 120, 3000),
            $raw,
            'named field writes must land on the Windows ABI byte offsets',
        );

        // Window geometry reads back as REAL fields — the 80x24 answer the
        // old offsets 4/8/6/10 walk could never produce from this struct.
        $derived = Kernel32::deriveSize(
            (int) $info->srWindow->Left,
            (int) $info->srWindow->Right,
            (int) $info->srWindow->Top,
            (int) $info->srWindow->Bottom,
        );
        $this->assertSame(['cols' => 80, 'rows' => 24], $derived);
    }

    // ─── deriveSize seam ─────────────────────────────────────────────────────

    /** @return array<string, array{0:int,1:int,2:int,3:int,4:?array{cols:int,rows:int}}> */
    public static function sizeMatrix(): array
    {
        return [
            'canonical full window'    => [0, 79, 0, 23, ['cols' => 80, 'rows' => 24]],
            'scrolled window'          => [3, 102, 1, 54, ['cols' => 100, 'rows' => 54]],
            'single cell'              => [0, 0, 0, 0, ['cols' => 1, 'rows' => 1]],
            'conhost unset corners'    => [0, -1, 0, -1, null],
            'all -1 garbage'           => [-1, -1, -1, -1, null],
            'inverted columns'         => [5, 3, 0, 23, null],
            'inverted rows'            => [0, 79, 5, 3, null],
            'negative left'            => [-2, 78, 0, 23, null],
        ];
    }

    /**
     * @param ?array{cols:int, rows:int} $expected
     */
    #[DataProvider('sizeMatrix')]
    public function testDeriveSizeValidatesBoundsNeverFabricates(
        int $left,
        int $right,
        int $top,
        int $bottom,
        ?array $expected,
    ): void {
        $this->assertSame($expected, Kernel32::deriveSize($left, $right, $top, $bottom));
    }

    // ─── source gates (the dead idioms must stay dead) ───────────────────────

    public function testSizePathContainsNoFfiPtrWalkAndBlobOffsets(): void
    {
        $src  = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Util/Tty/Kernel32.php');
        $this->assertMatchesRegularExpression(
            '/public function getConsoleScreenBufferInfo/s',
            $src,
        );
        $slice = preg_match(
            '/public function getConsoleScreenBufferInfo.*?(?=\n    public function createFile)/s',
            $src,
            $m,
        );
        $this->assertSame(1, $slice, 'method slice anchor');

        $this->assertStringNotContainsString('\FFI::ptr(', $m[0]);
        $this->assertStringNotContainsString('unsigned char[24]', $m[0]);
        $this->assertStringNotContainsString('? 79 :', $m[0], 'negative-corner fabrication must not return');
        $this->assertStringNotContainsString('? 23 :', $m[0], 'negative-corner fabrication must not return');
        $this->assertStringContainsString('$info->srWindow->Left', $m[0]);
        $this->assertStringContainsString('deriveSize(', $m[0]);
    }

    public function testLiveCdefWiresTheSameStructDefinitionTheTestPins(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Util/Tty/Kernel32.php');

        $this->assertStringContainsString(
            'self::SIZE_CDEF .',
            $src,
            'ffi() must concatenate SIZE_CDEF so the standalone pins exercise the real bytes',
        );
        $this->assertStringContainsString(
            'CONSOLE_SCREEN_BUFFER_INFO *lpInfo',
            $src,
            'the API signature must take the typed struct, not a void* blob',
        );
    }

    public function testWindowsBackendSizeNarrowsItsCatch(): void
    {
        $src   = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Util/Tty/WindowsBackend.php');
        $slice = preg_match(
            '/public function size\(\).*?(?=\n    public function |\n    \/\/ ─── )/s',
            $src,
            $m,
        );
        $this->assertSame(1, $slice, 'size() slice anchor');

        $this->assertStringNotContainsString(
            'catch (\Throwable)',
            $m[0],
            'a bare Throwable catch in size() is what made every Windows run silently 200x60',
        );
        $this->assertStringContainsString('catch (\FFI\Exception)', $m[0]);
    }
}
