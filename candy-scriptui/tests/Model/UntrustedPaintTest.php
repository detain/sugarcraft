<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\ConfirmModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Model\SpinModel;
use SugarCraft\ScriptUi\Model\WriteModel;
use SugarCraft\ScriptUi\Process\FakeProcess;

/**
 * Cluster 12 (untrusted paint): the model factories fold every caller-supplied
 * string through the cell sanitizer at entry, so no control/escape byte can
 * reach the rendered frame. These are RED before the sanitize chokepoint and
 * GREEN after.
 */
final class UntrustedPaintTest extends TestCase
{
    private const ESCAPED = "\x1b[31mred\x1b[0m";
    private const CONTROL = "bad\x00\x07name";

    public function testChooseModelNeutralisesEscapesInOptionsAndHeader(): void
    {
        $model = ChooseModel::fromOptions(
            [self::ESCAPED, self::CONTROL, 'clean'],
            header: "\x1b[1mH\x1b[0meeader",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[31m", $view, 'red SGR must not reach the frame');
        self::assertStringNotContainsString("\x1b[1m", $view, 'bold SGR from the header must not reach the frame');
        self::assertStringNotContainsString("\x00", $view);
        self::assertStringNotContainsString("\x07", $view);
        // The visible text survives sanitisation.
        self::assertStringContainsString('red', $view);
        self::assertStringContainsString('name', $view);
        self::assertStringContainsString('clean', $view);
    }

    public function testFilterModelNeutralisesEscapesInOptionsHeaderAndValue(): void
    {
        $model = FilterModel::fromOptions(
            [self::ESCAPED, 'plain'],
            header: "\x1b[5mblink\x1b[0m",
        );

        $view = $model->view();
        self::assertStringNotContainsString("\x1b[5m", $view);
        self::assertStringNotContainsString("\x1b[31m", $view);
        self::assertStringContainsString('plain', $view);

        // The pre-filled filter value is neutralised too — it then acts as
        // filter text on the sanitised bytes, as a user would experience.
        $seeded = FilterModel::fromOptions([self::ESCAPED, 'plain'], value: "seed\x07text");
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
