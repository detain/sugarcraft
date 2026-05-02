<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testInitialEmpty(): void
    {
        $t = Text::new('notes');
        $this->assertSame('', $t->value());
        $this->assertSame('notes', $t->key());
    }

    public function testInsertAndNewline(): void
    {
        [$t, ] = Text::new('notes')->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame("a\nb", $t->value());
    }

    public function testConsumesEnterWhenFocused(): void
    {
        [$t, ] = Text::new('x')->focus();
        $this->assertTrue($t->consumes(new KeyMsg(KeyType::Enter)));
    }

    public function testDoesNotConsumeEnterWhenUnfocused(): void
    {
        $t = Text::new('x');
        $this->assertFalse($t->consumes(new KeyMsg(KeyType::Enter)));
    }

    public function testValidatorRunsOnUpdate(): void
    {
        [$t, ] = Text::new('x')
            ->withValidator(static fn(string $v): ?string => strlen($v) >= 3 ? null : 'too short')
            ->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('too short', $t->getError());
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertNull($t->getError());
    }

    public function testTitleAndDescriptionRender(): void
    {
        $t = Text::new('x')->withTitle('Bio')->withDescription('A short paragraph.');
        $view = $t->view();
        $this->assertStringContainsString('Bio', $view);
        $this->assertStringContainsString('A short paragraph.', $view);
    }

    public function testCharLimitForwarded(): void
    {
        [$t, ] = Text::new('x')->withCharLimit(2)->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame('ab', $t->value());
    }
}
