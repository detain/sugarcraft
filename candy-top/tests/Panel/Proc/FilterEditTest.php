<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Panel\Proc\FilterEdit;
use SugarCraft\Top\Panel\Proc\ProcUnits;

final class FilterEditTest extends TestCase
{
    private static function key(KeyType $type, string $rune = '', bool $ctrl = false): KeyMsg
    {
        return new KeyMsg($type, $rune, ctrl: $ctrl);
    }

    public function testNewPutsTheCaretAtTheEnd(): void
    {
        $e = FilterEdit::new('nginx');
        $this->assertSame(['nginx', 5], [$e->text, $e->caret]);
    }

    public function testEditingCommands(): void
    {
        $e = FilterEdit::new('ab');
        $e = $e->command(self::key(KeyType::Char, 'c'));
        $this->assertSame(['abc', 3], [$e?->text, $e?->caret]);
        $e = $e?->command(self::key(KeyType::Left))?->command(self::key(KeyType::Left));
        $this->assertSame(1, $e?->caret);
        $e = $e?->command(self::key(KeyType::Space));
        $this->assertSame(['a bc', 2], [$e?->text, $e?->caret]);
        $e = $e?->command(self::key(KeyType::Backspace));
        $this->assertSame(['abc', 1], [$e?->text, $e?->caret]);
        $e = $e?->command(self::key(KeyType::Delete));
        $this->assertSame(['ac', 1], [$e?->text, $e?->caret]);
        $e = $e?->command(self::key(KeyType::Home));
        $this->assertSame(0, $e?->caret);
        $this->assertSame($e, $e?->command(self::key(KeyType::Backspace)), 'backspace at 0 is a no-op');
        $e = $e?->command(self::key(KeyType::End));
        $this->assertSame(2, $e?->caret);
        $this->assertSame($e, $e?->command(self::key(KeyType::Delete)), 'delete at the end is a no-op');
        $e = $e?->command(self::key(KeyType::Right));
        $this->assertSame(2, $e?->caret, 'right clamps');
        $e = $e?->command(self::key(KeyType::Char, 'é'));
        $this->assertSame(['acé', 3], [$e?->text, $e?->caret], 'one multibyte cluster');
    }

    public function testNonEditKeysAreRejected(): void
    {
        $e = FilterEdit::new('x');
        $this->assertNull($e->command(self::key(KeyType::Up)));
        $this->assertNull($e->command(self::key(KeyType::Tab)));
        $this->assertNull($e->command(self::key(KeyType::Char, 'a', ctrl: true)));
        $this->assertNull($e->command(self::key(KeyType::Char, 'ab')));
    }

    public function testViewUnderlinesTheCaretCell(): void
    {
        $this->assertSame('ab' . FilterEdit::UL . ' ' . FilterEdit::UUL, FilterEdit::new('ab')->view(10));
        $mid = FilterEdit::new('abc')->command(self::key(KeyType::Left));
        $this->assertSame('ab' . FilterEdit::UL . 'c' . FilterEdit::UUL, $mid?->view(10));
        $this->assertSame(FilterEdit::UL . ' ' . FilterEdit::UUL, FilterEdit::new('')->view(6));
    }

    public function testViewKeepsTheCaretInsideTheLimit(): void
    {
        $view = FilterEdit::new('abcdefghij')->view(6);
        $this->assertSame('fghij' . FilterEdit::UL . ' ' . FilterEdit::UUL, $view);
        $home = FilterEdit::new('abcdefghij')->command(self::key(KeyType::Home));
        $this->assertSame(FilterEdit::UL . 'a' . FilterEdit::UUL . 'bcdef', $home?->view(6));
    }

    public function testHumanizerMatchesBtopDigits(): void
    {
        $this->assertSame('0 Byte', ProcUnits::human(0));
        $this->assertSame('1.50 KiB', ProcUnits::human(1536));
        $this->assertSame('10.0 GiB', ProcUnits::human(10 * 1024 ** 3));
        $this->assertSame('11.7 MiB', ProcUnits::human(12_345_678));
        $this->assertSame('2.00 KiB/s', ProcUnits::human(2048, false, 0, true));
        $this->assertSame('500B', ProcUnits::human(500, true));
        $this->assertSame('1.5K', ProcUnits::human(1536, true));
        $this->assertSame('1.0K', ProcUnits::human(1023, true));
        $this->assertSame('976K', ProcUnits::human(999_999, true));
        $this->assertSame('117M', ProcUnits::human(117 * 1024 ** 2, true));
        $this->assertSame('1.0G', ProcUnits::human(1023 * 1024 ** 2, true));
        $this->assertSame('1.5k', ProcUnits::human(1500, true, 0, false, true), 'base_10_sizes');
        $this->assertSame('0B', ProcUnits::human(-5, true), 'negative clamps to 0');
    }
}
