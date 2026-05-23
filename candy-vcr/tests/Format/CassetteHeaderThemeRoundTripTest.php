<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Regression: CassetteHeader::$theme round-trips through JsonlFormat.
 *
 * Bug fixed in d070e742: $theme was added to CassetteHeader but the
 * format serializers never persisted it, so a tape's `Set Theme
 * "Dracula"` survived only inside one process. The fix encodes/decodes
 * the theme field in the JSON header. Without it, every recorded
 * cassette would fall back to the default theme on replay.
 */
final class CassetteHeaderThemeRoundTripTest extends TestCase
{
    public function testThemeIsPreservedAcrossWriteAndRead(): void
    {
        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'SugarCraft/Vcr',
            theme: 'Dracula',
        );
        $cassette = new Cassette($header, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);

        $format = new JsonlFormat();
        $encoded = $format->encode($cassette);
        $this->assertStringContainsString('"theme":"Dracula"', $encoded, 'theme must be present in encoded header');

        $loaded = $format->decode($encoded);
        $this->assertSame('Dracula', $loaded->header->theme);
    }

    public function testNullThemeIsOmittedFromEncodedHeader(): void
    {
        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'SugarCraft/Vcr',
            theme: null,
        );
        $cassette = new Cassette($header, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);

        $format = new JsonlFormat();
        $encoded = $format->encode($cassette);
        $this->assertStringNotContainsString('"theme"', $encoded, 'absent theme must not be serialized');

        $loaded = $format->decode($encoded);
        $this->assertNull($loaded->header->theme);
    }

    public function testWriteAndReadFromDisk(): void
    {
        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: '2026-05-22T00:00:00+00:00',
            cols: 80,
            rows: 24,
            runtime: 'SugarCraft/Vcr',
            theme: 'TokyoNight',
        );
        $cassette = new Cassette($header, [
            new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'hello']),
        ]);

        $path = sys_get_temp_dir() . '/candy-vcr-theme-rt-' . bin2hex(random_bytes(4)) . '.jsonl';
        try {
            $format = new JsonlFormat();
            $format->write($cassette, $path);
            $loaded = $format->read($path);
            $this->assertSame('TokyoNight', $loaded->header->theme);
        } finally {
            @unlink($path);
        }
    }
}
