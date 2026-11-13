<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Model\WriteModel;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * scriptui-17 — the README's headline example (`input --placeholder
 * 'Your name?'`) rendered NOTHING because the widget paints its hint only
 * while unfocused, and candy-scriptui's prompts hold focus for the whole
 * session. The models now own the ghost span themselves: a focused-empty
 * frame shows the dim hint, and the first keystroke erases it.
 */
final class PlaceholderGhostTest extends TestCase
{
    protected function tearDown(): void
    {
        ColourGate::resetAmbient();
    }

    public function testFocusedEmptyInputPaintsTheGhost(): void
    {
        $model = InputModel::newPrompt(placeholder: 'Your name?');

        self::assertSame('', $model->value());
        $frame = $model->view();
        // The hint is fully readable: first cell rides the block cursor,
        // the tail arrives as its own dim span.
        self::assertStringContainsString('Y', $frame);
        self::assertStringContainsString("\x1b[2mour name?", $frame);
        self::assertStringContainsString('> ', $frame);
    }

    public function testFirstKeystrokeErasesTheGhost(): void
    {
        $model = InputModel::newPrompt(placeholder: 'Your name?');
        [$model] = $model->update(new KeyMsg(KeyType::Char, 'A'));

        self::assertSame('A', $model->value());
        $frame = $model->view();
        self::assertStringNotContainsString('our name?', $frame);
        self::assertStringContainsString('A', $frame);
    }

    public function testGhostSurvivesEmptyBackspaceRoundTrip(): void
    {
        $model = InputModel::newPrompt(placeholder: 'Your name?');
        [$model] = $model->update(new KeyMsg(KeyType::Char, 'A'));
        [$model] = $model->update(new KeyMsg(KeyType::Backspace, ''));

        self::assertSame('', $model->value());
        self::assertStringContainsString("\x1b[2mour name?", $model->view());
    }

    public function testGhostIsPlainBytesUnderNoAnsi(): void
    {
        ColourGate::install(new ArgvInput(['candy-scriptui', 'input', '--no-ansi']));

        $frame = InputModel::newPrompt(placeholder: 'Your name?')->view();

        self::assertStringNotContainsString("\x1b", $frame);
        self::assertStringContainsString('Your name?', $frame);
    }

    public function testNoPlaceholderKeepsThePlainWidgetRender(): void
    {
        $model = InputModel::newPrompt(placeholder: '');

        self::assertSame(
            str_replace("\r", '', $model->input->view()),
            str_replace("\r", '', $model->view()),
        );
        self::assertStringNotContainsString("\x1b[2m", $model->view());
    }

    public function testFocusedEmptyWritePaintsTheGhost(): void
    {
        $model = WriteModel::newPrompt(placeholder: 'Start typing...');

        self::assertSame('', $model->value());
        self::assertStringContainsString('Start typing...', $model->view());
    }

    public function testFirstCharacterErasesTheWriteGhost(): void
    {
        $model = WriteModel::newPrompt(placeholder: 'Start typing...');
        [$model] = $model->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertStringNotContainsString('Start typing...', $model->view());
        self::assertStringContainsString('x', $model->view());
    }

    public function testWriteGhostIsPlainUnderNoAnsi(): void
    {
        ColourGate::install(new ArgvInput(['candy-scriptui', 'write', '--no-ansi']));

        $frame = WriteModel::newPrompt(placeholder: 'Start typing...')->view();

        self::assertStringNotContainsString("\x1b", $frame);
        self::assertStringContainsString('Start typing...', $frame);
    }
}
