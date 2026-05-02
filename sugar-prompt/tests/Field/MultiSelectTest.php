<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\MultiSelect;
use PHPUnit\Framework\TestCase;

final class MultiSelectTest extends TestCase
{
    private function focused(): MultiSelect
    {
        $f = MultiSelect::new('foods')->withOptions('Pizza', 'Burger', 'Salad');
        [$f, ] = $f->focus();
        return $f;
    }

    public function testInitiallyEmpty(): void
    {
        $f = MultiSelect::new('x')->withOptions('a', 'b');
        $this->assertSame([], $f->value());
    }

    public function testSpaceTogglesCurrent(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame(['Pizza'], $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame([], $f->value());
    }

    public function testCursorMovesAndTogglesNext(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame(['Burger'], $f->value());
    }

    public function testValueOrderMatchesDeclarationOrder(): void
    {
        $f = $this->focused();
        // Toggle in reverse order to confirm output preserves declaration order.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'G'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'g'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame(['Pizza', 'Salad'], $f->value());
    }

    public function testMaxConstraint(): void
    {
        $f = $this->focused()->withMax(1);
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame(['Pizza'], $f->value());
        $this->assertNotNull($f->getError());
    }

    public function testMinConstraint(): void
    {
        $f = $this->focused()->withMin(1);
        // No selections yet — toggle once and back off to trigger min violation.
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertNull($f->getError());
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame([], $f->value());
        $this->assertNotNull($f->getError());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $f = MultiSelect::new('x')->withOptions('a');
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame([], $f->value());
    }

    public function testCursorClamps(): void
    {
        $f = $this->focused();
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $f->cursor);
        for ($i = 0; $i < 10; $i++) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        }
        $this->assertSame(2, $f->cursor);
    }

    public function testTitleAndDescriptionRender(): void
    {
        $f = MultiSelect::new('x')
            ->withOptions('a')
            ->withTitle('Pick foods')
            ->withDescription('any number');
        $view = $f->view();
        $this->assertStringContainsString('Pick foods', $view);
        $this->assertStringContainsString('any number', $view);
    }
}
