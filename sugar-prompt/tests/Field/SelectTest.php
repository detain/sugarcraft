<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Select;
use PHPUnit\Framework\TestCase;

final class SelectTest extends TestCase
{
    public function testInitialValueIsFirstOption(): void
    {
        $f = Select::new('lang')->withOptions('PHP', 'Go', 'Rust');
        $this->assertSame('PHP', $f->value());
    }

    public function testArrowsChangeSelection(): void
    {
        [$f, ] = Select::new('lang')->withOptions('PHP', 'Go', 'Rust')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertSame('Go', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertSame('Rust', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame('Go', $f->value());
    }

    public function testTitleInView(): void
    {
        $f = Select::new('lang')
            ->withTitle('Pick a language')
            ->withOptions('A', 'B');
        $this->assertStringContainsString('Pick a language', $f->view());
    }

    public function testEmptyOptions(): void
    {
        $f = Select::new('x');
        $this->assertNull($f->value());
    }
}
