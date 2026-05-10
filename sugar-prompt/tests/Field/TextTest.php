<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Prompt\Field\Text;
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

    public function testWithValidationShowsErrorWhenPredicateReturnsFalse(): void
    {
        $t = Text::new('notes')->withValidation(
            static fn (string $v): bool => strlen($v) >= 5,
            'Must be at least 5 characters',
        );
        [$t, ] = $t->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('Must be at least 5 characters', $t->getError());
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'd'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertNull($t->getError());
    }

    public function testWithValidationShortAlias(): void
    {
        $t = Text::new('bio')->validation(
            static fn (string $v): bool => !empty($v) && str_contains($v, "\n"),
            'Must be multiple lines',
        );
        [$t, ] = $t->focus();
        // Single line fails
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('Must be multiple lines', $t->getError());
        // Add newline (Enter inside Text field)
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 't'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertNull($t->getError());
    }
}
