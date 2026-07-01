<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Testing\Snapshot\GoldenFile;
use SugarCraft\Testing\Tests\Concerns\TemporaryDirectoryTrait;

final class AssertionsTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $fixturesDir;

    protected function getTempDirSuffix(): string
    {
        return 'assertions';
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->fixturesDir = $this->tmpDir . '/fixtures';
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->fixturesDir = '';
        $this->tearDownTemporaryDirectory();
    }

    public function testAssertGoldenAnsiPassesOnExactMatch(): void
    {
        $goldenPath = $this->fixturesDir . '/match.golden';
        $content = "\x1b[1;32mOK\x1b[0m";
        GoldenFile::save($goldenPath, $content);

        // Should not throw.
        Assertions::assertGoldenAnsi($goldenPath, $content);
        $this->assertTrue(true);
    }

    public function testAssertGoldenAnsiFailsOnMismatch(): void
    {
        $goldenPath = $this->fixturesDir . '/mismatch.golden';
        GoldenFile::save($goldenPath, "\x1b[1;31mExpected\x1b[0m");

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        Assertions::assertGoldenAnsi($goldenPath, "\x1b[1;33mActual\x1b[0m");
    }

    public function testAssertGoldenAnsiCreatesWhenUpdateGoldensSetAndMissing(): void
    {
        $goldenPath = $this->fixturesDir . '/new.golden';

        $actual = "\x1b[1;34mNew\x1b[0m";
        $oldEnv = getenv('UPDATE_GOLDENS');
        putenv('UPDATE_GOLDENS=1');

        try {
            Assertions::assertGoldenAnsi($goldenPath, $actual);

            $this->assertSame($actual, GoldenFile::load($goldenPath));
        } finally {
            if ($oldEnv === false) {
                putenv('UPDATE_GOLDENS');
            } else {
                putenv("UPDATE_GOLDENS={$oldEnv}");
            }
        }
    }

    public function testAssertGoldenAnsiFailsWithClearMessageWhenMissingAndNoUpdate(): void
    {
        $goldenPath = $this->fixturesDir . '/nonexistent.golden';

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/No golden file found/');
        Assertions::assertGoldenAnsi($goldenPath, "\x1b[1mTest\x1b[0m");
    }

    public function testAssertCellGridPassesOnExactMatch(): void
    {
        $expected = [
            0 => [
                0 => ['rune' => 'H', 'width' => 1],
                1 => ['rune' => 'i', 'width' => 1],
            ],
            1 => [
                0 => ['rune' => ' ', 'width' => 1],
                1 => ['rune' => '!', 'width' => 1],
            ],
        ];

        $buffer = Buffer::new(2, 2)
            ->withCellAt(0, 0, Cell::new('H'))
            ->withCellAt(1, 0, Cell::new('i'))
            ->withCellAt(0, 1, Cell::new(' '))
            ->withCellAt(1, 1, Cell::new('!'));

        // Should not throw.
        Assertions::assertCellGrid($expected, $buffer);
        $this->assertTrue(true);
    }

    public function testAssertCellGridFailsOnDimensionMismatch(): void
    {
        $expected = [
            0 => [0 => ['rune' => 'A', 'width' => 1]],
        ];

        $buffer = Buffer::new(3, 3);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/Dimension mismatch/');
        Assertions::assertCellGrid($expected, $buffer);
    }

    public function testAssertCellGridFailsWithCellCoordinatesOnMismatch(): void
    {
        $expected = [
            0 => [
                0 => ['rune' => 'X', 'width' => 1],
                1 => ['rune' => 'Y', 'width' => 1],
            ],
        ];

        $buffer = Buffer::new(2, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'));

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/\\(0, 0\\)/');
        Assertions::assertCellGrid($expected, $buffer);
    }

    public function testAssertAnsiEqualsPassesOnExactMatch(): void
    {
        $expected = "\x1b[1;31mRed\x1b[0m \x1b[1;32mGreen\x1b[0m";

        Assertions::assertAnsiEquals($expected, $expected);
        $this->assertTrue(true);
    }

    public function testAssertAnsiEqualsFailsWithReadableDiff(): void
    {
        $expected = "\x1b[1;31mRed\x1b[0m";
        $actual = "\x1b[1;33mYellow\x1b[0m";

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/\\+.*Yellow/');
        Assertions::assertAnsiEquals($expected, $actual);
    }

    public function testAssertCellGridSkipsNullExpectedCells(): void
    {
        $expected = [
            0 => [
                0 => ['rune' => 'A', 'width' => 1],
                1 => null,
            ],
        ];

        $buffer = Buffer::new(2, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'));

        Assertions::assertCellGrid($expected, $buffer);
        $this->assertTrue(true);
    }

    public function testAssertAnsiEqualsDiffShowsUnchangedLines(): void
    {
        $expected = "\x1b[1;31mError\x1b[0m\nNormal line\n\x1b[1;33mWarn\x1b[0m";
        $actual = "\x1b[1;31mError\x1b[0m\nNormal line\n\x1b[1;32mSuccess\x1b[0m";

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/  Normal line/');
        Assertions::assertAnsiEquals($expected, $actual);
    }
}
