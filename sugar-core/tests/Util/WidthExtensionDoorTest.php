<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Regression pins for the extension door's escape flag (F15c, review of
 * the O(delta) claim). When the door answers a growing key, the flag that
 * says whether that key leaves a sequence open must come from the appended
 * tail alone; a later extension that lands after an escape in the tail must
 * still fall back to a full measure.
 */
final class WidthExtensionDoorTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetDoor();
    }

    protected function tearDown(): void
    {
        $this->resetDoor();
    }

    public function testEscapeInAppendedTailIsSeenByTheNextExtension(): void
    {
        $this->assertSame(2, Width::string('ab'));
        $this->assertSame(5, Width::string('ab cd'));
        // The tail "e\x1b[31m" starts with a printable byte, so the door answers
        // this one and must record that the key now ends inside the SGR run.
        $this->assertSame(6, Width::string("ab cde\x1b[31m"));
        $this->assertTrue($this->flag());
        // With the flag set the door must not extend: the escape is removed in
        // full, not counted as five cells plus the tail.
        $this->assertSame(7, Width::string("ab cde\x1b[31mf"));
    }

    public function testInertTailKeepsTheFlagClear(): void
    {
        Width::string('plain');
        Width::string('plain text');
        $this->assertFalse($this->flag());
        $this->assertSame(11, Width::string("plain text!"));
    }

    public function testGrowingLadderMatchesTheStrippedLength(): void
    {
        $line = "ab\x1b[1mcd\x1b[0m ef\x1b[38;5;196mgh\x1b[0m";
        for ($n = 1; $n <= \strlen($line); $n++) {
            $prefix = \substr($line, 0, $n);
            $this->assertSame(
                \strlen(Ansi::strip($prefix)),
                Width::string($prefix),
                "prefix of $n bytes",
            );
        }
    }

    private function flag(): bool
    {
        $ref = new \ReflectionProperty(Width::class, 'extendKeyHasEsc');
        return (bool) $ref->getValue();
    }

    private function resetDoor(): void
    {
        foreach (['extendKey' => null, 'extendWidth' => 0, 'extendKeyHasEsc' => false] as $prop => $value) {
            (new \ReflectionProperty(Width::class, $prop))->setValue(null, $value);
        }
    }
}
