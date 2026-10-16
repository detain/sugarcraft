<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\TextInput\ValidateOn;

/**
 * Plan 5.11 — per-error help. The `withErrorHelp()` string surfaces as one
 * `  ? ...` row directly under a field's `! ...` validation error row, and
 * only while the error is live. Byte-pinned per field (render contract).
 */
final class ErrorHelpTest extends TestCase
{
    /**
     * Each row: label, field already carrying an ACTIVE error plus help.
     *
     * @return array<string, array{0: object, 1: string}>
     */
    public static function erroredProvider(): array
    {
        return [
            'Input'       => [self::erroredInput(), '! boom'],
            'Text'        => [self::erroredText(), '! boom'],
            'MultiSelect' => [self::erroredMultiSelect(), '! Pick at most 1.'],
        ];
    }

    private static function erroredInput(): Input
    {
        $f = Input::new('k')
            ->withValidator(static fn (string $_v): ?string => 'boom')
            ->withErrorHelp('type letters only');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertSame('boom', $f->getError());
        return $f;
    }

    private static function erroredText(): Text
    {
        $f = Text::new('k')
            ->withValidator(static fn (string $_v): ?string => 'boom')
            ->withValidateOn(ValidateOn::Blur)
            ->withErrorHelp('markdown allowed');
        [$f, ] = $f->focus();
        $f = $f->blur();
        self::assertSame('boom', $f->getError());
        return $f;
    }

    private static function erroredMultiSelect(): MultiSelect
    {
        $f = MultiSelect::new('k')
            ->withOptions('a', 'b')
            ->withMax(1)
            ->withErrorHelp('choose exactly one');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        [$f, ] = $f->update(new KeyMsg(KeyType::Space)); // cap exceeded
        self::assertNotNull($f->getError());
        return $f;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('erroredProvider')]
    public function testHelpRowRendersDirectlyUnderTheErrorRow(object $field, string $errorLine): void
    {
        $help = $field->errorHelp();
        self::assertNotNull($help);
        $lines = explode("\n", $field->view());
        $i     = array_search($errorLine, $lines, true);
        self::assertIsInt($i, "error row '$errorLine' missing from view");
        self::assertSame('  ? ' . $help, $lines[$i + 1]);
    }

    /** @return array<string, array{0: object}> */
    public static function cleanProvider(): array
    {
        $ms = MultiSelect::new('k')->withOptions('a', 'b')->withErrorHelp('choose any');
        [$ms, ] = $ms->focus();
        [$ms, ] = $ms->update(new KeyMsg(KeyType::Space)); // legal, no cap set
        return [
            'Input'       => [Input::new('k')->withErrorHelp('only on failure')],
            'Text'        => [Text::new('k')->withErrorHelp('only on failure')],
            'MultiSelect' => [$ms],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cleanProvider')]
    public function testHelpFloatsNowhereWithoutAnError(object $field): void
    {
        self::assertNull($field->getError());
        self::assertStringNotContainsString('  ? ', $field->view());
    }

    public function testValidInputShowsNeitherErrorNorHelp(): void
    {
        $f = Input::new('k')
            ->withValidator(static fn (string $v): ?string => $v === 'ok' ? null : 'boom')
            ->withErrorHelp('type ok');
        [$f, ] = $f->focus();
        foreach (['o', 'k'] as $r) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Char, $r));
        }
        self::assertNull($f->getError());
        self::assertStringNotContainsString('?', $f->view());
        // And the same field failing renders the pair byte-exactly.
        [$bad, ] = $f->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertStringContainsString("! boom\n  ? type ok", $bad->view());
    }

    public function testDefaultHasNoHelpAndWitherRebuilds(): void
    {
        $f = Input::new('k');
        self::assertNull($f->errorHelp());
        $g = $f->withErrorHelp('h');
        self::assertNotSame($f, $g);
        self::assertSame('h', $g->errorHelp());
        self::assertNull($f->errorHelp(), 'wither must not mutate the source');
        self::assertNull($g->withErrorHelp(null)->errorHelp());
    }

    /**
     * E741 discipline: the slot rides the shared carrier, so it survives
     * every rebuild funnel — title mutate, focus+blur and a real keystroke.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('erroredProvider')]
    public function testHelpSurvivesEveryRebuildPath(object $field, string $_errorLine): void
    {
        $rebuilt = $field->withTitle('T');
        self::assertSame($field->errorHelp(), $rebuilt->errorHelp(), 'mutate dropped errorHelp');

        [$focused, ] = $field->focus();
        self::assertSame($field->errorHelp(), $focused->errorHelp(), 'focus dropped errorHelp');

        [$typed, ] = $focused->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertSame($field->errorHelp(), $typed->errorHelp(), 'keystroke dropped errorHelp');
    }

    public function testHelpIsRenderSanitised(): void
    {
        // Same display contract as the '! ' row: RenderSafe::clean strips C0
        // control bytes while intentional SGR survives — the help line
        // inherits that policy rather than inventing its own.
        $f = self::erroredInput()->withErrorHelp("bel\x07ling");
        self::assertStringContainsString('  ? belling', $f->view());
    }

    public function testEmptyHelpStringRendersNoStrayRow(): void
    {
        // An errored field with withErrorHelp('') must NOT print a lone
        // '  ? ' — blank help is indistinguishable from none at render time
        // (early-exit guard), even though the accessor keeps the raw string.
        $f = self::erroredInput()->withErrorHelp('');
        self::assertSame('', $f->errorHelp());
        self::assertStringNotContainsString('?', $f->view());
    }
}
