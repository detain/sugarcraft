<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\ConfirmModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Model\WriteModel;
use SugarCraft\ScriptUi\Process\FakeProcess;

/**
 * Cluster 12 (untrusted paint): no control or non-SGR escape byte from a
 * caller-supplied string may ride the rendered frame. The folding lane
 * differs per model by design (scriptui-15): the CHROME models fold at
 * entry (their prompts/titles are pure display), while choose/filter keep
 * the option payload RAW for the data channel and clean at the display
 * sites instead — ItemList::view and the marked/fuzzy bodies fold through
 * RenderSafe at render, exactly like sugar-forms Select.
 */
final class UntrustedPaintTest extends TestCase
{
    private const CONTROL = "bad\x00\x07name";

    public function testChooseModelEscapesTheFrameButKeepsTheRawDataChannel(): void
    {
        // scriptui-15 (re-pins cluster 12): options are no longer folded
        // at entry — the payload must survive raw for pipes. What the
        // frame may NEVER carry is injection-capable bytes: C0 controls
        // and non-SGR escape sequences. TRUE SGR (ESC [ digits/; m) is
        // legitimate styling and survives by ItemList's documented
        // display-site contract — the same posture sugar-forms Select
        // takes.
        $model = ChooseModel::fromOptions(
            ["\x1b]0;pwned\x07title", self::CONTROL, 'clean'],
            header: "\x1b[2J",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b]0;", $view, 'OSC payload must not reach the wire');
        self::assertStringNotContainsString("\x1b[2J", $view, 'screen-clear CSI from the header must not reach the wire');
        self::assertStringNotContainsString("\x00", $view);
        self::assertStringNotContainsString("\x07", $view);
        self::assertStringContainsString('title', $view);
        self::assertStringContainsString('name', $view);
        self::assertStringContainsString('clean', $view);

        // The data channel round-trips untouched.
        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame("\x1b]0;pwned\x07title", $submitted->selected());
    }

    public function testFilterModelEscapesTheFrameButKeepsTheRawDataChannel(): void
    {
        $model = FilterModel::fromOptions(
            ["\x1b]0;evil\x07red", 'plain'],
            header: "\x1b[2J",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b]0;", $view);
        self::assertStringNotContainsString("\x1b[2J", $view);
        self::assertStringNotContainsString("\x07", $view);
        self::assertStringContainsString('plain', $view);

        $submitted = $model->update(new KeyMsg(KeyType::Enter, ''))[0];
        self::assertSame("\x1b]0;evil\x07red", $submitted->selected());

        // The pre-filled filter value is still neutralised — it echoes
        // raw onto the '/' input line, so it lives on the paint channel.
        $seeded = FilterModel::fromOptions(['a', 'plain'], value: "seed\x07text");
        self::assertStringNotContainsString("\x07", $seeded->view());
        self::assertStringContainsString('seed', $seeded->view());
    }

    public function testConfirmModelNeutralisesTitleAndLabels(): void
    {
        $model = ConfirmModel::newPrompt(
            title: "\x1b[31mDelete?\x1b[0m",
            affirmative: "Ye\x00s",
            negative: "\x07No",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[31m", $view);
        self::assertStringNotContainsString("\x00", $view);
        self::assertStringNotContainsString("\x07", $view);
        self::assertStringContainsString('Delete?', $view);
    }

    public function testInputModelNeutralisesPromptValueAndHeader(): void
    {
        $model = InputModel::newPrompt(
            header: "\x1b[1mhead\x1b[0m",
            placeholder: "\x1b[31mph\x1b[0m",
            prompt: "pr\x07ompt",
            value: "pre\x1b[0mfilled",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[1m", $view);
        self::assertStringNotContainsString("\x1b[31m", $view);
        self::assertStringNotContainsString("\x07", $view);
        // cellValue makes the ESC byte VISIBLE (U+00B7) rather than deleting
        // the sequence: the data letters survive punctuated (chrome SGR like
        // the cursor block stays legitimately live).
        self::assertStringContainsString('pre', $view);
        self::assertStringContainsString('filled', $view);
    }

    public function testWriteModelNeutralisesPromptValueAndHeader(): void
    {
        $model = WriteModel::newPrompt(
            header: "\x1b[1mhead\x1b[0m",
            placeholder: "ph\x00",
            prompt: "\x1b[32mgo\x1b[0m",
            value: "bo\x07dy",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[1m", $view);
        self::assertStringNotContainsString("\x1b[32m", $view);
        self::assertStringNotContainsString("\x00", $view);
        self::assertStringNotContainsString("\x07", $view);
    }

    public function testSpinModelNeutralisesTitle(): void
    {
        $model = SpinModel::spawn(new FakeProcess(), "\x1b[31mworking\x1b[0m");

        self::assertStringNotContainsString("\x1b[", $model->title);
        self::assertStringContainsString('working', $model->title);
    }

    public function testPagerModelStripsAnsiAndMapsCRFromContent(): void
    {
        $model = PagerModel::fromContent("a\x1b[31mb\x1b[0mc\rd\r\ne");

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[31m", $view, 'SGR must not survive into the viewport');
        self::assertStringNotContainsString("\r", $view, 'carriage returns must be folded to line breaks');
        self::assertStringContainsString('abc', $view);
    }
}
