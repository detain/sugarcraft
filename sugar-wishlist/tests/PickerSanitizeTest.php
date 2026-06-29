<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use SugarCraft\Wishlist\Picker;
use PHPUnit\Framework\TestCase;

/**
 * Tests that config-sourced strings are sanitized before terminal output.
 */
final class PickerSanitizeTest extends TestCase
{
    /**
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function makePicker(string $keys): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            public function setRawMode(bool $on): void { /* noop in tests */ }
        };
        return [$in, $out, $p];
    }

    public function testHostileDisplayLineIsSanitized(): void
    {
        // An endpoint name containing terminal escape sequences must not
        // leak raw ESC bytes into the output stream.
        $hostile = new Endpoint(
            name: "prod\x1b[2J\x1b]0;pwned\x07",
            host: 'prod.example.com',
        );

        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$hostile]);
        rewind($out);
        $output = stream_get_contents($out);

        // The raw CSI clear-screen sequence must not appear
        $this->assertStringNotContainsString("\x1b[2J", $output);
        // The OSC window-title sequence must not appear
        $this->assertStringNotContainsString("\x1b]0;", $output);
        // The bare BEL must not appear
        $this->assertStringNotContainsString("\x07", $output);
    }

    public function testHostileDescriptionIsSanitized(): void
    {
        $hostile = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            description: "top\x1b[2Ksecret\x07",
        );

        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$hostile]);
        rewind($out);
        $output = stream_get_contents($out);

        $this->assertStringNotContainsString("\x1b[2K", $output);
        $this->assertStringNotContainsString("\x07", $output);
    }

    public function testNormalUnicodeInNameIsPreserved(): void
    {
        // Multibyte unicode characters should pass through sanitization intact.
        $unicode = new Endpoint(
            name: 'café-server',
            host: 'café.example.com',
        );

        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$unicode]);
        rewind($out);
        $output = stream_get_contents($out);

        // The unicode characters should be preserved
        $this->assertStringContainsString('café', $output);
    }
}
