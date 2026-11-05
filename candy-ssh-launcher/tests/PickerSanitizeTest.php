<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
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
            /** @param unused */
            public function setRawMode(bool $_on): void { /* noop in tests */ }
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

    public function testEncodedC1InNameAndDescriptionAreNotEchoed(): void
    {
        // A UTF-8 encoded C1 byte (U+009B CSI) is invisible to the old local
        // 7-bit sweep and reaches the terminal unharmed; the canonical
        // sanitizer removes the encoded-C1/CSI shims outright.
        $hostile = new Endpoint(
            name: "prod\xc2\x9b4m",
            host: "h\xc2\x8b.test",
            description: "d\xc2\x9b31m",
        );
        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$hostile]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringNotContainsString("\xc2\x9b", $output);
        $this->assertStringNotContainsString("\xc2\x8b", $output);
        $this->assertStringNotContainsString("\x1b[4m", $output);
    }

    public function testNewlinesCannotForgeExtraVisualRows(): void
    {
        // CR/LF/TAB survive sanitizing (they are layout, not escape), so the
        // picker folds them to spaces: a hostile field renders as one row.
        $forging = new Endpoint(
            name: "alpha\nbeta\rgamma\tdelta",
            host: 'alpha.test',
        );
        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$forging]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('alpha beta gamma delta', $output);
    }

    public function testForgedRowLineCannotReintroduceCsi(): void
    {
        // Belt pair: an escape hidden behind a newline must not survive the
        // fold into the rendered row either.
        $forging = new Endpoint(
            name: "prod\n\x1b[2Jevil",
            host: 'prod.test',
        );
        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$forging]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringNotContainsString("\x1b[2J", $output);
    }

    public function testZeroWidthJoinerIsMarkedVisibly(): void
    {
        // Zero-width characters make two different names look identical; the
        // display policy marks them as visible <U+XXXX> sentinels.
        $invisible = new Endpoint(
            name: "safe\xe2\x80\x8bserver",
            host: 'safe.test',
        );
        [, $out, $p] = $this->makePicker("\r");
        $p->pick([$invisible]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('<U+200B>', $output);
    }
}
