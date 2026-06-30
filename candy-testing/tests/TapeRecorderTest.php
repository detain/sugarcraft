<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\Tape\TapeRecorder;

final class TapeRecorderTest extends TestCase
{
    private string $tmpDir;
    private string $tapePath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-testing-tape-' . getmypid();
        mkdir($this->tmpDir, 0755, true);
        $this->tapePath = $this->tmpDir . '/demo.tape';
    }

    protected function tearDown(): void
    {
        $dir = $this->tmpDir;
        $this->tmpDir = '';
        $this->tapePath = '';
        if ($dir !== '' && is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
    }

    public function testToFactory(): void
    {
        $recorder = TapeRecorder::to($this->tapePath);

        $this->assertInstanceOf(TapeRecorder::class, $recorder);
    }

    public function testHeaderWritesVhsHeader(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Set Theme "TokyoNight"', $content);
        $this->assertStringContainsString('Set Width 800', $content);
        $this->assertStringContainsString('Set Height 480', $content);
    }

    public function testHeaderRespectsCustomValues(): void
    {
        TapeRecorder::to($this->tapePath)
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
        TapeRecorder::to($this->tapePath)
            ->header()
            ->type('echo hello')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Type "echo hello"', $content);
    }

    public function testEnterWritesEnterCommand(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->enter()
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Enter', $content);
    }

    public function testSleepWritesSleepCommand(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->sleep(1.5)
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Sleep 1.5', $content);
    }

    public function testResizeWritesComment(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->resize(120, 30)
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('# Resize 120x30', $content);
    }

    public function testCommentWritesCommentLine(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->comment('This is a demo')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('# This is a demo', $content);
    }

    public function testLineAppendsVerbatim(): void
    {
        TapeRecorder::to($this->tapePath)
            ->header()
            ->line('Custom line here')
            ->save();

        $content = file_get_contents($this->tapePath);

        $this->assertStringContainsString('Custom line here', $content);
    }

    public function testFullTapeHasCorrectStructure(): void
    {
        TapeRecorder::to($this->tapePath)
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

        TapeRecorder::to($nestedPath)
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

    public function testKeyMsgToVhsReturnsNullForUnsupported(): void
    {
        $msg = new KeyMsg(type: KeyType::Home, rune: '');

        $result = TapeRecorder::keyMsgToVhs($msg);

        $this->assertNull($result);
    }
}
