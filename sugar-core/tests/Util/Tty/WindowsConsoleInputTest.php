<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty\Kernel32;
use SugarCraft\Core\Util\Tty\Kernel32Interface;
use SugarCraft\Core\Util\Tty\WindowsConsoleInput;

/**
 * Console input records → keystroke bytes, driven through FakeKernel32 so it
 * runs on every host.
 */
final class WindowsConsoleInputTest extends TestCase
{
    private function key(int $char, bool $down = true, int $repeat = 1): array
    {
        return ['type' => Kernel32Interface::KEY_EVENT, 'keyDown' => $down, 'repeat' => $repeat, 'vk' => 0, 'scan' => 0, 'char' => $char, 'ctrl' => 0];
    }

    public function testKeyDownCharsBecomeUtf8AndEverythingElseIsDropped(): void
    {
        $k = new FakeKernel32();
        $k->setReadConsoleInputSequence([
            $this->key(0x61),
            $this->key(0x61, down: false),
            ['type' => Kernel32Interface::WINDOW_BUFFER_SIZE_EVENT],
            $this->key(0),
            $this->key(0xE9),
            $this->key(0x1B),
            $this->key(0x5B),
            $this->key(0x41),
        ]);
        $in = new WindowsConsoleInput(10, $k);

        self::assertSame("a\u{e9}\e[A", $in->read());
        self::assertSame('', $in->read(), 'nothing pending reads as empty, never a wait');
    }

    public function testRepeatCountRepeatsTheChar(): void
    {
        $k = new FakeKernel32();
        $k->setReadConsoleInputSequence([$this->key(0x78, repeat: 3)]);

        self::assertSame('xxx', (new WindowsConsoleInput(10, $k))->read());
    }

    public function testSurrogatePairsJoinEvenAcrossReads(): void
    {
        $k = new FakeKernel32();
        $in = new WindowsConsoleInput(10, $k);

        // U+1F600 = D83D DE00, delivered one record per read.
        $k->setReadConsoleInputSequence([$this->key(0xD83D)]);
        self::assertSame('', $in->read());
        $k->setReadConsoleInputSequence([$this->key(0xDE00)]);
        self::assertSame("\u{1F600}", $in->read());

        $k->setReadConsoleInputSequence([$this->key(0xDE00)]);
        self::assertSame('', $in->read(), 'an orphan low surrogate carries no bytes');
    }

    public function testDrainsMoreThanOneBatch(): void
    {
        $k = new FakeKernel32();
        $k->setReadConsoleInputSequence(array_fill(0, 10, $this->key(0x7A)));

        self::assertSame(str_repeat('z', 10), (new WindowsConsoleInput(10, $k))->read(4));
    }

    public function testForStdinNeedsAConsoleHandle(): void
    {
        $k = new FakeKernel32();
        self::assertNull(WindowsConsoleInput::forStdin($k), 'GetConsoleMode failing means stdin is a pipe or file');

        $k->setConsoleModeStdin(0x1F7);
        self::assertInstanceOf(WindowsConsoleInput::class, WindowsConsoleInput::forStdin($k));
    }

    public function testKeyEventFieldsDecodeTheRecordLayout(): void
    {
        // bKeyDown=1, repeat=2, vk='A', scan=0x1E, char='a', ctrl state 0x0008 (LEFT_CTRL)
        $raw = pack('V', 1) . pack('v', 2) . pack('v', 0x41) . pack('v', 0x1E) . pack('v', 0x61) . pack('V', 0x0008);

        self::assertSame(
            ['keyDown' => true, 'repeat' => 2, 'vk' => 0x41, 'scan' => 0x1E, 'char' => 0x61, 'ctrl' => 0x0008],
            Kernel32::keyEventFields($raw),
        );
    }
}
