<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Migration\V1ToV2Migrator;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Tests for CassetteHeader constructor validation.
 * The class is 0% method coverage - these tests cover all the validation branches.
 */
final class CassetteHeaderValidationTest extends TestCase
{
    /**
     * Decodes a cassette line the way the loader does — through a real file
     * read. Malformed `v`/`env`/`timestampMode` values cannot be written by
     * typed PHP code (that is exactly what the constructor guards defend), so
     * these tests feed the guards the same `mixed` the JSON boundary yields.
     */
    private static function decodeCassetteValue(string $json): mixed
    {
        $path = \tempnam(\sys_get_temp_dir(), 'candy-vcr-cassette-');
        self::assertIsString($path);
        \file_put_contents($path, $json);
        $decoded = \json_decode((string) Stream::read($path), true);
        \unlink($path);

        return $decoded;
    }

    /**
     * Builds a CassetteHeader through reflection. These tests feed the
     * constructor's runtime guards values the declared parameter types
     * statically forbid — exactly the hostile-cassette shapes the guards
     * exist to reject — so the call must bypass static argument checking.
     *
     * @param array<string, mixed> $args
     */
    private static function newHeaderRuntime(array $args): CassetteHeader
    {
        return (new \ReflectionClass(CassetteHeader::class))->newInstanceArgs($args);
    }

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
        // The mode arrives from a decoded cassette line, so model that boundary
        // (JSON → mixed) instead of a literal the declared type can never hold.
        $mode = self::decodeCassetteValue('"invalid"');
        self::newHeaderRuntime([
            'version' => 1,
            'createdAt' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'test',
            'timestampMode' => $mode,
        ]);
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
        // Decoding a JSON object with a numeric-string key yields a real int
        // key — exactly how a hand-edited cassette smuggles this past the type.
        $env = self::decodeCassetteValue('{"123": "value"}');
        self::newHeaderRuntime([
            'version' => 1,
            'createdAt' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'test',
            'env' => $env,
        ]);
    }

    public function testRejectsNonStringEnvValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        $env = self::decodeCassetteValue('{"KEY": 123}');
        self::newHeaderRuntime([
            'version' => 1,
            'createdAt' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'test',
            'env' => $env,
        ]);
    }

    public function testRejectsNonIntEnvValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');
        $env = self::decodeCassetteValue('{"KEY": ["array"]}');
        self::newHeaderRuntime([
            'version' => 1,
            'createdAt' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'test',
            'env' => $env,
        ]);
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
        // Pin the wire-format contract through a real serialization boundary:
        // assertSame against the constants directly would compare each constant
        // to its own literal (statically certain, so PHPStan flags it; and it
        // proves nothing beyond the source file). Here headers built from the
        // constants are encoded as on a cassette and decoded, so the literals
        // below are the only source of truth for the pinned values.
        $default = \json_decode(
            \json_encode(new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
                playbackSpeed: CassetteHeader::PLAYBACK_SPEED_DEFAULT,
            ), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $relative = \json_decode(
            \json_encode(new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
                timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
            ), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($default);
        $this->assertIsArray($relative);

        $this->assertSame(1, $default['version']);
        $this->assertSame('absolute', $default['timestampMode']);
        $this->assertSame('relative', $relative['timestampMode']);
        // assertEquals (not Same): json_encode(1.0) renders "1" or "1.0"
        // depending on the ini serialize_precision, so the decoded scalar's
        // int/float class is environment-dependent — the pinned contract is
        // the constant's value.
        $this->assertEquals(1.0, $default['playbackSpeed']);
    }

    /**
     * M2: readers used to accept ANY version stamp — a v99 cassette from a
     * future build parsed "successfully" with unknown semantics. The ceiling
     * refuses it at construction with a message that names the upgrade path.
     */
    public function testRefusesVersionsNewerThanThisBuildCanRead(): void
    {
        foreach ([3, 99] as $version) {
            try {
                new CassetteHeader(
                    version: $version,
                    createdAt: '2026-05-09T03:55:02Z',
                    cols: 80,
                    rows: 24,
                    runtime: 'test',
                );
                $this->fail("CassetteHeader v{$version} must not construct");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('newer than this build', $e->getMessage());
                $this->assertStringContainsString((string) $version, $e->getMessage());
            }
        }
    }

    public function testVersionCeilingIsExactlyTheMigrationTarget(): void
    {
        // The ceiling and the migrator chain must agree: v2 is readable
        // today, and the recorder still writes v1. Raising either side
        // without the other is caught here. The values are pinned through
        // the real serialization boundary — a direct assertSame against a
        // class constant compares each constant to its own literal
        // (statically certain, so PHPStan flags it), same discipline as
        // testConstants().
        $ceiling = \json_decode(
            \json_encode(new CassetteHeader(
                version: CassetteHeader::MAX_READABLE_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $stamped = \json_decode(
            \json_encode(new CassetteHeader(
                version: CassetteHeader::CURRENT_VERSION,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($ceiling);
        $this->assertIsArray($stamped);

        $this->assertSame(2, $ceiling['version'], 'the readable ceiling is exactly v2');
        $this->assertSame(1, $stamped['version'], "the recorder's stamp is exactly v1");
        $this->assertLessThanOrEqual(
            CassetteHeader::MAX_READABLE_VERSION,
            V1ToV2Migrator::TARGET_VERSION,
            'a migrator must not target a version this build cannot read',
        );
    }

    public function testFileReaderRefusesAFutureVersionHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('newer than this build');
        (new JsonlFormat())->decode(json_encode([
            'v' => 99,
            'created' => '2026-05-09T03:55:02Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'test',
        ], \JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * Finding 004: header metadata is printed verbatim by inspect/stats/
     * diff, so control characters are refused at the trust door itself.
     */
    public function testRejectsControlCharactersInPrintableHeaderStrings(): void
    {
        $forgery = [
            'ESC + SGR in createdAt' => ['createdAt', "2026-05-09T03:55:02Z\x1b[31mRED"],
            'OSC 52 + BEL in runtime' => ['runtime', "\x1b]52;c;aGVsbG8=\x07"],
            'NUL in runtime' => ['runtime', "evil\x00engine"],
            'newline in theme' => ['theme', "drac\nula"],
            'DEL in fontFamily' => ['fontFamily', "Mono\x7fpad"],
            'C1 pad in runtime' => ['runtime', "engine\u{80}pad"],
            'invalid UTF-8 in theme' => ['theme', "\xff\xfe"],
        ];

        foreach ($forgery as $case => [$field, $value]) {
            $args = [
                'version' => 1,
                'createdAt' => '2026-05-09T03:55:02Z',
                'cols' => 80,
                'rows' => 24,
                'runtime' => 'test',
            ];
            $args[$field] = $value;

            try {
                new CassetteHeader(...$args);
                self::fail("case '{$case}': forged header was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must not contain control characters', $e->getMessage(), $case);
                self::assertStringContainsString($field, $e->getMessage(), $case);
            }
        }
    }

    public function testLegitimateNonAsciiMetadataStillPassesTheDoor(): void
    {
        // Raw bytes 0x80..0x9F occur inside legitimate UTF-8 (U+2028 is
        // E2 80 A8) — the C1 half of the door is a code-point class, so
        // printable Unicode family names must survive.
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-09T03:55:02Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            fontFamily: 'Berliner Straße Grotesk',
        );
        $this->assertStringContainsString("\xE2\x80\xA8", $header->fontFamily ?? '');
    }
}
