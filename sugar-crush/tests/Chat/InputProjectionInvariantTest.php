<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * E744 WS6 — the view-surface ruling, pinned as a behavioral invariant.
 *
 * The renderer keeps painting the draft from {@see Chat::$inputBuf}
 * (sanitizer contract + byte-pinned chrome make a wholesale
 * {@see TextArea::view()} swap the wrong tool), but since E744 the widget is
 * the single editing authority: every routed chord — typing, paste
 * replacement, and the copy-on-Ctrl+C selection path — leaves the frame's
 * projection byte-identical to the widget's own value. This file is the drift
 * tripwire: if a future route mutates one owner without the other, the
 * renderer paints a draft the model does not hold.
 *
 * @internal
 */
final class InputProjectionInvariantTest extends TestCase
{
    public function testTheDraftMirrorIsTheWidgetValueThroughEveryEditingRoute(): void
    {
        $chat = new Chat();

        $routes = [
            new KeyMsg(KeyType::Char, 'h'),
            new KeyMsg(KeyType::Char, 'i'),
            new PasteMsg(' there'),
            new KeyMsg(KeyType::Backspace),
            new PasteMsg('!'),
        ];

        foreach ($routes as $route) {
            [$chat] = $chat->update($route);
            $this->assertSame($chat->input->value(), $chat->inputBuf, get_class($route) . ' left the mirror out of step');
        }

        $this->assertSame('hi ther!', $chat->inputBuf, 'and the composed draft is what the routes mean');
    }

    public function testAPasteOntoASelectionReplacesThroughTheWidgetAndStaysInStep(): void
    {
        $chat = new Chat(input: $this->selectedTextArea('keep DROP keep', 5, 9));

        [$pasted, $cmd] = $chat->update(new PasteMsg('X'));

        $this->assertNull($cmd, 'paste is a pure model edit');
        $this->assertSame('keep X keep', $pasted->input->value(), 'the selected span was replaced, not inserted around');
        $this->assertSame($pasted->input->value(), $pasted->inputBuf, 'and the frame projection followed the widget');
    }

    public function testTheCopyChordEditsNothingAndTheMirrorHolds(): void
    {
        $chat = new Chat(input: $this->selectedTextArea('copy me please', 0, 7));

        [$kept, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        $this->assertNotNull($cmd, 'with a selection Ctrl+C is the copy chord, not the quit chord');
        $this->assertInstanceOf(RawMsg::class, $cmd(), 'relayed as the OSC 52 write');
        $this->assertSame('copy me please', $kept->input->value(), 'the copy leaves the draft untouched');
        $this->assertSame($kept->input->value(), $kept->inputBuf, 'mirror intact through the chord');
    }

    public function testEitherSeedDerivesTheOtherAtConstruction(): void
    {
        $fromBuf = new Chat(inputBuf: 'seeded');
        $this->assertSame('seeded', $fromBuf->input->value(), 'a buffer seed feeds the widget');

        $fromWidget = new Chat(input: TextArea::new()->withCharLimit(0)->setValue('widget wins'));
        $this->assertSame('widget wins', $fromWidget->inputBuf, 'a widget seed re-derives the buffer');
        $this->assertSame($fromWidget->input->value(), $fromWidget->inputBuf);
    }

    private function selectedTextArea(string $value, int $anchorCol, int $caretCol): TextArea
    {
        // charLimit(0): freshInput law — the draft is unbounded.
        $input = TextArea::new()->withCharLimit(0)->setValue($value);
        [$input] = $input->focus();

        return $input->withSelect(0, $anchorCol)->setCursor(0, $caretCol);
    }
}
