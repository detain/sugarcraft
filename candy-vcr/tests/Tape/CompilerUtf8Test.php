<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;

/**
 * Regression: UTF-8 characters in `Type "..."` reach the terminal.
 *
 * Bug fixed in d070e742 (Compiler::charToByte): codepoints above 0x7e
 * used to be dropped, so `Type "café"` lost the é and `Type "日本"`
 * became empty. The fix returns the original UTF-8 multibyte string
 * for codepoints >= 0xa0.
 */
final class CompilerUtf8Test extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testTypeWithLatin1AccentEmitsUtf8Bytes(): void
    {
        $result = Compiler::parseSource('Type "café"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $bytes = $this->concatInputBytes($cassette->events);

        // 'c' (0x63), 'a' (0x61), 'f' (0x66), 'é' (UTF-8: 0xc3 0xa9)
        $this->assertSame("caf\xc3\xa9", $bytes, 'UTF-8 é must round-trip into the Input event stream');
        $this->assertStringContainsString("\xc3\xa9", $bytes, 'é UTF-8 bytes must be present');
    }

    public function testTypeWithThreeByteUtf8EmitsAllThreeBytes(): void
    {
        $result = Compiler::parseSource('Type "日"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $bytes = $this->concatInputBytes($cassette->events);

        // 日 = U+65E5 → UTF-8: 0xe6 0x97 0xa5
        $this->assertSame("\xe6\x97\xa5", $bytes, '3-byte UTF-8 codepoint must round-trip in full');
    }

    /**
     * @param list<\SugarCraft\Vcr\Event> $events
     */
    private function concatInputBytes(array $events): string
    {
        $out = '';
        foreach ($events as $event) {
            if ($event->kind !== EventKind::Input) {
                continue;
            }
            $b = $event->payload['b'] ?? '';
            if (is_string($b)) {
                $out .= $b;
            }
        }
        return $out;
    }
}
