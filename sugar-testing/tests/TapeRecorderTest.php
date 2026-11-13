<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\Concerns\TemporaryDirectoryTrait;
use SugarCraft\Testing\Tape\TapeRecorder;

final class TapeRecorderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tapePath;

    protected function getTempDirSuffix(): string
    {
        return 'tape';
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->tapePath = $this->tmpDir . '/demo.tape';
    }

    protected function tearDown(): void
    {
        $this->tapePath = '';
        $this->tearDownTemporaryDirectory();
    }

    public function testToFactory(): void
    {
        $recorder = TapeRecorder::new($this->tapePath);

        $this->assertInstanceOf(TapeRecorder::class, $recorder);
    }

    public function testHeaderWritesVhsHeader(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Set Theme "TokyoNight"', $content);
        $this->assertStringContainsString('Set Width 800', $content);
        $this->assertStringContainsString('Set Height 480', $content);
    }

    public function testHeaderRespectsCustomValues(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header(theme: 'Dracula', width: 1024, height: 768, fontSize: 16)
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Set Theme "Dracula"', $content);
        $this->assertStringContainsString('Set Width 1024', $content);
        $this->assertStringContainsString('Set Height 768', $content);
        $this->assertStringContainsString('Set FontSize 16', $content);
    }

    public function testTypeWritesKeyCommand(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->type('echo hello')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Type "echo hello"', $content);
    }

    public function testEnterWritesEnterCommand(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->enter()
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Enter', $content);
    }

    public function testSleepWritesSleepCommand(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->sleep(1.5)
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Sleep 1.5', $content);
    }

    public function testResizeWritesComment(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->resize(120, 30)
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('# Resize 120x30', $content);
    }

    public function testCommentWritesCommentLine(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->comment('This is a demo')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('# This is a demo', $content);
    }

    public function testLineAppendsVerbatim(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->line('Custom line here')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Custom line here', $content);
    }

    public function testFullTapeHasCorrectStructure(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->comment('Demo tape')
            ->type('php examples/counter.php')
            ->enter()
            ->sleep(1)
            ->type('q')
            ->enter()
            ->save();

        $content = file_get_contents($this->tapePath);
        $lines = explode("\n", trim($content));

        $this->assertSame('Set Theme "TokyoNight"', $lines[0]);
        $this->assertSame('', $lines[4]);
        $this->assertSame('# Demo tape', $lines[5]);
        $this->assertSame('Type "php examples/counter.php"', $lines[6]);
        $this->assertSame('Enter', $lines[7]);
    }

    public function testSaveCreatesParentDirectories(): void
    {
        $nestedPath = $this->tmpDir . '/nested/path/demo.tape';

        TapeRecorder::new($nestedPath)
            ->header()
            ->save();

        $this->assertFileExists($nestedPath);
    }

    public function testKeyMsgToVhsWithCharacterKey(): void
    {
        $msg = new KeyMsg(type: KeyType::Char, rune: 'a');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('"a"', $result);
    }

    public function testKeyMsgToVhsWithQuotedCharacter(): void
    {
        $msg = new KeyMsg(type: KeyType::Char, rune: '"');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('"\\""', $result);
    }

    public function testKeyMsgToVhsWithEnter(): void
    {
        $msg = new KeyMsg(type: KeyType::Enter, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Enter', $result);
    }

    public function testKeyMsgToVhsWithEscape(): void
    {
        $msg = new KeyMsg(type: KeyType::Escape, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Escape', $result);
    }

    public function testKeyMsgToVhsWithBackspace(): void
    {
        $msg = new KeyMsg(type: KeyType::Backspace, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Backspace', $result);
    }

    public function testKeyMsgToVhsWithTab(): void
    {
        $msg = new KeyMsg(type: KeyType::Tab, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Tab', $result);
    }

    public function testKeyMsgToVhsWithUp(): void
    {
        $msg = new KeyMsg(type: KeyType::Up, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Up', $result);
    }

    public function testKeyMsgToVhsWithDown(): void
    {
        $msg = new KeyMsg(type: KeyType::Down, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Down', $result);
    }

    public function testKeyMsgToVhsWithLeft(): void
    {
        $msg = new KeyMsg(type: KeyType::Left, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Left', $result);
    }

    public function testKeyMsgToVhsWithRight(): void
    {
        $msg = new KeyMsg(type: KeyType::Right, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Right', $result);
    }

    public function testKeyMsgToVhsWithHome(): void
    {
        $msg = new KeyMsg(type: KeyType::Home, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Home', $result);
    }

    public function testKeyMsgToVhsWithEnd(): void
    {
        $msg = new KeyMsg(type: KeyType::End, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('End', $result);
    }

    public function testKeyMsgToVhsWithPageUp(): void
    {
        $msg = new KeyMsg(type: KeyType::PageUp, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('PageUp', $result);
    }

    public function testKeyMsgToVhsWithPageDown(): void
    {
        $msg = new KeyMsg(type: KeyType::PageDown, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('PageDown', $result);
    }

    public function testKeyMsgToVhsWithDelete(): void
    {
        $msg = new KeyMsg(type: KeyType::Delete, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Delete', $result);
    }

    public function testKeyMsgToVhsWithInsert(): void
    {
        $msg = new KeyMsg(type: KeyType::Insert, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('Insert', $result);
    }

    public function testKeyMsgToVhsWithF1(): void
    {
        $msg = new KeyMsg(type: KeyType::F1, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('F1', $result);
    }

    public function testKeyMsgToVhsWithF12(): void
    {
        $msg = new KeyMsg(type: KeyType::F12, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertSame('F12', $result);
    }

    public function testKeyMsgToVhsReturnsNullForUnsupported(): void
    {
        $msg = new KeyMsg(type: KeyType::CapsLock, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertNull($result);
    }

    public function testKeyMsgToVhsRefusesChordsInsteadOfDroppingTheModifier(): void
    {
        // Ctrl+C must not silently record as a plain "c" — with no
        // verifiable tape spelling for chords (the double-brace key-token
        // form cannot be confirmed against the pinned vhs here), the
        // documented unsupported-answer is null, visibly.
        $this->assertNull(TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Char, rune: 'c', ctrl: true)));
        $this->assertNull(TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Char, rune: 'x', alt: true)));
        $this->assertNull(TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Up, ctrl: true)));
        $this->assertNull(TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Enter, alt: true)));
    }

    public function testKeyMsgToVhsKeepsShiftFoldedRunesAndMapsSpace(): void
    {
        // Terminals report shift+letter as the shifted rune itself, so the
        // chord guard must leave shifted characters alone (regression pin);
        // the Space key joins the named roster.
        $this->assertSame('"A"', TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Char, rune: 'A', shift: true)));
        $this->assertSame('Space', TapeRecorder::keyMsgToVhs(new KeyMsg(type: KeyType::Space)));
    }

    public function testSaveRefusesSymlinkedTarget(): void
    {
        // Callers pass generated paths, but a planted symlink must never
        // redirect the tape bytes somewhere else.
        $realTarget = $this->tmpDir . '/elsewhere.txt';
        file_put_contents($realTarget, 'original');
        symlink($realTarget, $this->tapePath);

        $recorder = TapeRecorder::new($this->tapePath)->header();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('through a symlink');

        try {
            $recorder->save();
        } finally {
            $this->assertSame('original', file_get_contents($realTarget));
        }
    }

    public function testSaveLeavesNoStrayTempFiles(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->type('hi')
            ->save();

        $entries = [];
        foreach (new FilesystemIterator($this->tmpDir) as $entry) {
            $entries[] = $entry->getFilename();
        }
        sort($entries);

        $this->assertSame(['demo.tape'], $entries);
        $this->assertStringStartsWith('Set Theme "TokyoNight"', (string) file_get_contents($this->tapePath));
    }

    public function testHeaderReIssueWithDifferentSetThrowsNamingBoth(): void
    {
        // The old silent no-op meant a corrected header() call could never
        // take effect; the conflict must surface, naming what is on tape.
        $recorder = TapeRecorder::new($this->tapePath)->header();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already emitted with a different set');

        try {
            $recorder->header(theme: 'Dracula', width: 1024);
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('TokyoNight', $error->getMessage());
            $this->assertStringContainsString('Dracula', $error->getMessage());

            throw $error;
        }
    }

    public function testHeaderIdenticalReIssueIsANoOp(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header()
            ->header()
            ->save();

        $written = (string) file_get_contents($this->tapePath);

        $this->assertSame(1, substr_count($written, 'Set Theme'));
    }

    public function testSleepRejectsNegativeAndNonFiniteDelays(): void
    {
        foreach ([-1.0, NAN, \INF] as $bad) {
            try {
                TapeRecorder::new($this->tapePath)->sleep($bad);
                $this->fail('sleep() accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('finite non-negative', $error->getMessage());
            }
        }
    }

    public function testSleepFormatsCanonicalDecimal(): void
    {
        TapeRecorder::new($this->tapePath)->header()->sleep(2.0)->sleep(0.0005)->save();

        $written = (string) file_get_contents($this->tapePath);

        // int-valued floats never print a float tail, and the 4th decimal
        // rounds into the dialect's three-decimal resolution.
        $this->assertStringContainsString("Sleep 2\n", $written);
        $this->assertStringContainsString("Sleep 0.001\n", $written);
        $this->assertStringNotContainsString('Sleep 2.0', $written);
    }

    public function testHeaderEmitsTypingSpeedAfterHeightWhenPassed(): void
    {
        TapeRecorder::new($this->tapePath)
            ->header(width: 100, height: 80, fontSize: 12, typingSpeed: 0.05)
            ->save();

        $written = (string) file_get_contents($this->tapePath);

        $this->assertStringContainsString("Set Height 80\nSet TypingSpeed 0.05\n", $written);
    }

    public function testHeaderWithoutTypingSpeedCarriesNoDirective(): void
    {
        // The parameter is optional precisely so existing tapes stay
        // byte-identical: null must not shift a single byte.
        TapeRecorder::new($this->tapePath)->header()->save();

        $written = (string) file_get_contents($this->tapePath);

        $this->assertStringNotContainsString('TypingSpeed', $written);
    }

    public function testHeaderTypingSpeedRefusesNonFiniteAndNegative(): void
    {
        foreach ([NAN, \INF, -0.5] as $bad) {
            try {
                TapeRecorder::new($this->tapePath)->header(typingSpeed: $bad);
                $this->fail('header() accepted typingSpeed ' . var_export($bad, true));
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('finite non-negative delay', $error->getMessage());
            }
        }
    }

    public function testHeaderTypingSpeedParticipatesInTheConflictGuard(): void
    {
        $recorder = TapeRecorder::new($this->tapePath)->header(typingSpeed: 0.05);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already emitted with a different set');

        $recorder->header();
    }

    public function testDeprecatedToAliasStillTargetsTheSamePath(): void
    {
        // The builder factory moved to ::new(); ::to() remains as a
        // delegating alias so existing tapes keep generating.
        TapeRecorder::to($this->tapePath)
            ->header()
            ->save();

        $this->assertFileExists($this->tapePath);
        $this->assertStringStartsWith('Set Theme "TokyoNight"', (string) file_get_contents($this->tapePath));
    }
}
