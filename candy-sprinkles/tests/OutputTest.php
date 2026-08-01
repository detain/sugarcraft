<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Output;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function testSprintConcatenatesWithSpaces(): void
    {
        $this->assertSame('hello world',     Output::sprint('hello', 'world'));
        $this->assertSame('a b c',           Output::sprint('a', 'b', 'c'));
        $this->assertSame('',                Output::sprint());
        $this->assertSame('only',            Output::sprint('only'));
    }

    public function testPrintfFormatsLikeSprintf(): void
    {
        $this->assertSame('count=42 ratio=0.50', Output::printf('count=%d ratio=%0.2f', 42, 0.5));
        $this->assertSame('no args here',        Output::printf('no args here'));
    }

    public function testFprintWritesToCallerSuppliedStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);
        Output::fprint($stream, 'foo', 'bar', 'baz');
        rewind($stream);
        $this->assertSame('foo bar baz', stream_get_contents($stream));
        fclose($stream);
    }

    public function testFprintHonoursPreStyledContent(): void
    {
        // Output::* is style-agnostic — already-styled strings pass
        // through verbatim (this keeps the helper composable with
        // Style::new()->render('...').
        $stream = fopen('php://memory', 'w+');
        Output::fprint($stream, "\x1b[1mhi\x1b[0m");
        rewind($stream);
        $this->assertSame("\x1b[1mhi\x1b[0m", stream_get_contents($stream));
        fclose($stream);
    }
}
