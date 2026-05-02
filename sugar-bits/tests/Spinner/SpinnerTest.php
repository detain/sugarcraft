<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Spinner;

use CandyCore\Bits\Spinner\Spinner;
use CandyCore\Bits\Spinner\Style;
use CandyCore\Bits\Spinner\TickMsg;
use CandyCore\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class SpinnerTest extends TestCase
{
    public function testInitialFrameIsFirst(): void
    {
        $s = Spinner::new(Style::line());
        $this->assertSame('|', $s->view());
    }

    public function testInitReturnsTickCmd(): void
    {
        $s = Spinner::new(Style::line());
        $cmd = $s->init();
        $this->assertNotNull($cmd);
        $req = $cmd();
        $this->assertInstanceOf(TickRequest::class, $req);
        $this->assertEqualsWithDelta(0.1, $req->seconds, 1e-6);
    }

    public function testTickAdvancesFrameAndReschedules(): void
    {
        $s = Spinner::new(Style::line());
        [$next, $cmd] = $s->update(new TickMsg($s->id));
        $this->assertSame('/', $next->view());
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(TickRequest::class, $cmd());
    }

    public function testTickWraps(): void
    {
        $s = Spinner::new(Style::line()); // 4 frames
        $current = $s;
        for ($i = 0; $i < 4; $i++) {
            [$current, ] = $current->update(new TickMsg($s->id));
        }
        $this->assertSame('|', $current->view());
    }

    public function testIgnoresTickForOtherSpinner(): void
    {
        $a = Spinner::new(Style::line());
        $b = Spinner::new(Style::line());
        $this->assertNotSame($a->id, $b->id);
        [$next, $cmd] = $a->update(new TickMsg($b->id));
        $this->assertSame($a, $next);
        $this->assertNull($cmd);
    }

    public function testStyleFactoriesValid(): void
    {
        foreach ([Style::line(), Style::dot(), Style::miniDot(), Style::points(),
                  Style::pulse(), Style::globe(), Style::meter()] as $style) {
            $this->assertNotEmpty($style->frames);
            $this->assertGreaterThan(0.0, $style->fps);
        }
    }

    public function testStyleRejectsEmptyFrames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Style([], 10.0);
    }

    public function testStyleRejectsNonPositiveFps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Style(['x'], 0.0);
    }

    public function testNewStylePresets(): void
    {
        $this->assertNotEmpty(Style::jump()->frames);
        $this->assertNotEmpty(Style::moon()->frames);
        $this->assertNotEmpty(Style::monkey()->frames);
        $this->assertNotEmpty(Style::hamburger()->frames);
        $this->assertNotEmpty(Style::ellipsis()->frames);
        // Moon has 8 phases.
        $this->assertCount(8, Style::moon()->frames);
        // Ellipsis cycles through 4 frames including the empty one.
        $this->assertCount(4, Style::ellipsis()->frames);
    }
}
