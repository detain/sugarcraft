<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\Cursor;
use CandyCore\Core\CursorShape;
use CandyCore\Core\KeyType;
use CandyCore\Core\ModeState;
use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\MouseMode;
use CandyCore\Core\Progress;
use CandyCore\Core\ProgressBarState;
use CandyCore\Core\ProgramOptions;
use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Core\View;
use PHPUnit\Framework\TestCase;

final class EnumsAndDtosTest extends TestCase
{
    public function testKeyTypeBackingValues(): void
    {
        $this->assertSame('char', KeyType::Char->value);
        $this->assertSame('up', KeyType::Up->value);
        $this->assertSame('down', KeyType::Down->value);
        $this->assertSame('left', KeyType::Left->value);
        $this->assertSame('right', KeyType::Right->value);
        $this->assertSame('enter', KeyType::Enter->value);
        $this->assertSame('escape', KeyType::Escape->value);
        $this->assertSame('tab', KeyType::Tab->value);
        $this->assertSame('backspace', KeyType::Backspace->value);
        $this->assertSame('space', KeyType::Space->value);
        $this->assertSame('delete', KeyType::Delete->value);
        $this->assertSame('home', KeyType::Home->value);
        $this->assertSame('end', KeyType::End->value);
        $this->assertSame('pageup', KeyType::PageUp->value);
        $this->assertSame('pagedown', KeyType::PageDown->value);
        $this->assertSame('f1', KeyType::F1->value);
        $this->assertSame('f12', KeyType::F12->value);
    }

    public function testKeyTypeFromValue(): void
    {
        $this->assertSame(KeyType::Up, KeyType::from('up'));
        $this->assertNull(KeyType::tryFrom('not_a_key'));
    }

    public function testMouseActionAndButtonEnums(): void
    {
        $this->assertSame('press', MouseAction::Press->value);
        $this->assertSame('release', MouseAction::Release->value);
        $this->assertSame('motion', MouseAction::Motion->value);

        $this->assertSame('left', MouseButton::Left->value);
        $this->assertSame('right', MouseButton::Right->value);
        $this->assertSame('middle', MouseButton::Middle->value);
        $this->assertSame('none', MouseButton::None->value);
        $this->assertSame('wheel_up', MouseButton::WheelUp->value);
        $this->assertSame('wheel_down', MouseButton::WheelDown->value);
        $this->assertSame('backward', MouseButton::Backward->value);
        $this->assertSame('forward', MouseButton::Forward->value);
    }

    public function testMouseModeEnum(): void
    {
        $this->assertSame('off', MouseMode::Off->value);
        $this->assertSame('cell_motion', MouseMode::CellMotion->value);
        $this->assertSame('all_motion', MouseMode::AllMotion->value);
    }

    public function testCursorShapeEnumValues(): void
    {
        $this->assertSame(2, CursorShape::Block->value);
        $this->assertSame(4, CursorShape::Underline->value);
        $this->assertSame(6, CursorShape::Bar->value);
    }

    public function testProgressBarStateEnumValues(): void
    {
        $this->assertSame(0, ProgressBarState::Remove->value);
        $this->assertSame(1, ProgressBarState::Normal->value);
        $this->assertSame(2, ProgressBarState::Error->value);
        $this->assertSame(3, ProgressBarState::Indeterminate->value);
        $this->assertSame(4, ProgressBarState::Warning->value);
    }

    public function testModeStateActiveStates(): void
    {
        $this->assertTrue(ModeState::Set->isActive());
        $this->assertTrue(ModeState::PermanentlySet->isActive());
        $this->assertFalse(ModeState::Reset->isActive());
        $this->assertFalse(ModeState::PermanentlyReset->isActive());
        $this->assertFalse(ModeState::NotRecognized->isActive());
    }

    public function testCursorDefaults(): void
    {
        $c = new Cursor();
        $this->assertNull($c->row);
        $this->assertNull($c->col);
        $this->assertSame(CursorShape::Block, $c->shape);
        $this->assertFalse($c->blink);
        $this->assertNull($c->color);
    }

    public function testCursorWithFields(): void
    {
        $c = new Cursor(row: 5, col: 10, shape: CursorShape::Bar, blink: true,
            color: Color::rgb(255, 255, 0));
        $this->assertSame(5, $c->row);
        $this->assertSame(10, $c->col);
        $this->assertSame(CursorShape::Bar, $c->shape);
        $this->assertTrue($c->blink);
        $this->assertNotNull($c->color);
    }

    public function testProgressDefaults(): void
    {
        $p = new Progress(ProgressBarState::Normal);
        $this->assertSame(ProgressBarState::Normal, $p->state);
        $this->assertSame(0, $p->percent);
    }

    public function testProgressCustomPercent(): void
    {
        $p = new Progress(ProgressBarState::Normal, 75);
        $this->assertSame(75, $p->percent);
    }

    public function testViewDefaults(): void
    {
        $v = new View('hello');
        $this->assertSame('hello', $v->body);
        $this->assertNull($v->cursor);
        $this->assertNull($v->windowTitle);
        $this->assertNull($v->progressBar);
        $this->assertNull($v->foregroundColor);
        $this->assertNull($v->backgroundColor);
        $this->assertNull($v->mouseMode);
        $this->assertNull($v->reportFocus);
        $this->assertNull($v->bracketedPaste);
    }

    public function testViewWithAllFields(): void
    {
        $v = new View(
            body: 'frame',
            cursor: new Cursor(row: 1, col: 1),
            windowTitle: 'app',
            progressBar: new Progress(ProgressBarState::Normal, 50),
            foregroundColor: Color::rgb(255, 255, 255),
            backgroundColor: Color::rgb(0, 0, 0),
            mouseMode: MouseMode::CellMotion,
            reportFocus: true,
            bracketedPaste: true,
        );
        $this->assertSame('frame', $v->body);
        $this->assertNotNull($v->cursor);
        $this->assertSame('app', $v->windowTitle);
        $this->assertSame(50, $v->progressBar?->percent);
        $this->assertSame(MouseMode::CellMotion, $v->mouseMode);
        $this->assertTrue($v->reportFocus);
        $this->assertTrue($v->bracketedPaste);
    }

    public function testProgramOptionsDefaults(): void
    {
        $opts = new ProgramOptions();
        $this->assertFalse($opts->useAltScreen);
        $this->assertTrue($opts->catchInterrupts);
        $this->assertTrue($opts->hideCursor);
        $this->assertSame(60.0, $opts->framerate);
        $this->assertSame(MouseMode::Off, $opts->mouseMode);
        $this->assertFalse($opts->reportFocus);
        $this->assertFalse($opts->bracketedPaste);
        $this->assertTrue($opts->unicodeMode);
        $this->assertFalse($opts->inlineMode);
        $this->assertFalse($opts->openTty);
        $this->assertNull($opts->input);
        $this->assertNull($opts->output);
        $this->assertNull($opts->loop);
        $this->assertNull($opts->environment);
        $this->assertNull($opts->windowSize);
        $this->assertNull($opts->colorProfile);
        $this->assertTrue($opts->catchPanics);
        $this->assertFalse($opts->withoutRenderer);
        $this->assertNull($opts->filter);
        $this->assertFalse($opts->cellDiffRenderer);
    }

    public function testProgramOptionsCustom(): void
    {
        $opts = new ProgramOptions(
            useAltScreen: true,
            framerate: 30.0,
            mouseMode: MouseMode::AllMotion,
            environment: ['HOME' => '/root'],
            windowSize: ['cols' => 80, 'rows' => 24],
            colorProfile: ColorProfile::TrueColor,
            catchPanics: false,
            withoutRenderer: true,
            cellDiffRenderer: true,
        );
        $this->assertTrue($opts->useAltScreen);
        $this->assertSame(30.0, $opts->framerate);
        $this->assertSame(MouseMode::AllMotion, $opts->mouseMode);
        $this->assertSame(['HOME' => '/root'], $opts->environment);
        $this->assertSame(['cols' => 80, 'rows' => 24], $opts->windowSize);
        $this->assertSame(ColorProfile::TrueColor, $opts->colorProfile);
        $this->assertFalse($opts->catchPanics);
        $this->assertTrue($opts->withoutRenderer);
        $this->assertTrue($opts->cellDiffRenderer);
    }
}
