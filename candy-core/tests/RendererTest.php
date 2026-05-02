<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testWritesFrameOnFirstRender(): void
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);

        $r = new Renderer($out);
        $r->render('hello');

        rewind($out);
        $written = stream_get_contents($out);
        $this->assertStringContainsString('hello', $written);
        $this->assertStringContainsString("\x1b[", $written);
        fclose($out);
    }

    public function testSkipsRedundantWrite(): void
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);

        $r = new Renderer($out);
        $r->render('hello');
        $afterFirst = ftell($out);
        $r->render('hello');
        $afterSecond = ftell($out);

        $this->assertSame($afterFirst, $afterSecond);
        fclose($out);
    }

    public function testWritesAgainAfterReset(): void
    {
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);

        $r = new Renderer($out);
        $r->render('a');
        $r->reset();
        $r->render('a');

        rewind($out);
        $written = stream_get_contents($out);
        // 'a' should appear twice (cursor-home + erase + 'a' twice).
        $this->assertSame(2, substr_count($written, 'a'));
        fclose($out);
    }
}
