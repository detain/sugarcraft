<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\CassetteHeader;

/**
 * Tests for CassetteHeader constructor validation.
 * The class is 0% method coverage - these tests cover all the validation branches.
 */
final class CassetteHeaderValidationTest extends TestCase
{
    public function testRejectsVersionZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version must be >= 1');
        new CassetteHeader(
            version: 0,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
    }

    public function testRejectsNegativeVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version must be >= 1');
        new CassetteHeader(
            version: -1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
    }

    public function testRejectsZeroCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions must be positive');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 0,
            rows: 24,
            runtime: 'test',
        );
    }

    public function testRejectsZeroRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions must be positive');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 0,
            runtime: 'test',
        );
    }

    public function testRejectsNegativeCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dimensions must be positive');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: -10,
            rows: 24,
            runtime: 'test',
        );
    }

    public function testRejectsWidthPxZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('widthPx must be >= 1');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            widthPx: 0,
        );
    }

    public function testRejectsWidthPxNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('widthPx must be >= 1');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            widthPx: -100,
        );
    }

    public function testRejectsHeightPxZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('heightPx must be >= 1');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            heightPx: 0,
        );
    }

    public function testRejectsHeightPxNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('heightPx must be >= 1');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            heightPx: -100,
        );
    }

    public function testRejectsInvalidTimestampMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("timestampMode must be 'absolute' or 'relative'");
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            timestampMode: 'invalid',
        );
    }

    public function testRejectsEmptyEnvKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('env keys must be non-empty strings');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            env: ['' => 'value'],
        );
    }

    public function testRejectsNonStringEnvKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('env keys must be non-empty strings');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            env: [123 => 'value'],
        );
    }

    public function testRejectsNonStringEnvValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            env: ['KEY' => 123],
        );
    }

    public function testRejectsNonIntEnvValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            env: ['KEY' => ['array']],
        );
    }

    public function testAllOptionalParameters(): void
    {
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
            env: ['FOO' => 'bar', 'BAZ' => 'qux'],
            typingSpeed: 50.0,
            theme: 'TokyoNight',
            playbackSpeed: 2.0,
            fontSize: 16,
            fontFamily: 'JetBrainsMono',
            widthPx: 1200,
            heightPx: 600,
        );

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $header->timestampMode);
        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $header->env);
        $this->assertSame(50.0, $header->typingSpeed);
        $this->assertSame('TokyoNight', $header->theme);
        $this->assertSame(2.0, $header->playbackSpeed);
        $this->assertSame(16, $header->fontSize);
        $this->assertSame('JetBrainsMono', $header->fontFamily);
        $this->assertSame(1200, $header->widthPx);
        $this->assertSame(600, $header->heightPx);
    }

    public function testConstants(): void
    {
        $this->assertSame(1, CassetteHeader::CURRENT_VERSION);
        $this->assertSame('absolute', CassetteHeader::TIMESTAMP_MODE_ABSOLUTE);
        $this->assertSame('relative', CassetteHeader::TIMESTAMP_MODE_RELATIVE);
        $this->assertSame(1.0, CassetteHeader::PLAYBACK_SPEED_DEFAULT);
    }
}
