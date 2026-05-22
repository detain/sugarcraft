<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Parser\OscHandlerImpl;

final class OscHandlerImplTest extends TestCase
{
    private OscHandlerImpl $osc;

    protected function setUp(): void
    {
        $this->osc = new OscHandlerImpl();
    }

    public function testTitleStoresLastSeenTitle(): void
    {
        $this->osc->title('Hello Terminal');

        $this->assertSame('Hello Terminal', $this->osc->lastTitle());
    }

    public function testTitleEmptyByDefault(): void
    {
        $this->assertSame('', $this->osc->lastTitle());
    }

    public function testHyperlinkIsNoOp(): void
    {
        $this->osc->hyperlink('https://example.com', 'id123');

        $this->assertSame('', $this->osc->lastTitle());
    }

    public function testMultipleTitlesOverwrite(): void
    {
        $this->osc->title('First');
        $this->osc->title('Second');

        $this->assertSame('Second', $this->osc->lastTitle());
    }
}
